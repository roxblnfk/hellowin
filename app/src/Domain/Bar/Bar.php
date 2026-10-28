<?php

declare(strict_types=1);

namespace App\Domain\Bar;

/**
 * The counter of the bar: the mugs standing on it and the batch the owl is pouring (docs/spec/protocol.md, «Бар»).
 * The owl pours one batch at a time on the server clock, so every viewer sees the same foam.
 */
final readonly class Bar
{
    /**
     * @param int<0, max> $mugs Full mugs on the counter.
     * @param int|null $since Unix time in milliseconds the owl began pouring; null while she does not pour.
     * @param int|null $until Unix time in milliseconds the poured batch lands on the counter.
     * @param int<0, max>|null $rumor What the owl mutters while pouring: an index into the frontend's catalogue.
     * @param int|null $emptyAt Unix time in milliseconds the owl last said the counter was empty.
     * @param positive-int $batch Mugs in the batch being poured, fixed when the pour begins.
     */
    public function __construct(
        public int $mugs = 0,
        public ?int $since = null,
        public ?int $until = null,
        public ?int $rumor = null,
        public ?int $emptyAt = null,
        public int $batch = 1,
    ) {}

    /**
     * A pour stored before batches had none: it is read as a batch of one, the mug it was then.
     */
    public static function fromArray(mixed $value): self
    {
        if (!\is_array($value)) {
            return new self();
        }

        /** @var mixed $mugs */
        $mugs = $value['mugs'] ?? null;
        /** @var mixed $pouring */
        $pouring = $value['pouring'] ?? null;
        /** @var mixed $since */
        $since = \is_array($pouring) ? $pouring['since'] ?? null : null;
        /** @var mixed $until */
        $until = \is_array($pouring) ? $pouring['until'] ?? null : null;
        /** @var mixed $rumor */
        $rumor = \is_array($pouring) ? $pouring['rumor'] ?? null : null;
        /** @var mixed $batch */
        $batch = \is_array($pouring) ? $pouring['batch'] ?? null : null;
        /** @var mixed $emptyAt */
        $emptyAt = $value['emptyAt'] ?? null;
        $poured = \is_int($since) && \is_int($until);

        return new self(
            \is_int($mugs) && $mugs > 0 ? $mugs : 0,
            $poured ? $since : null,
            $poured ? $until : null,
            $poured && \is_int($rumor) && $rumor >= 0 ? $rumor : null,
            \is_int($emptyAt) ? $emptyAt : null,
            $poured && \is_int($batch) && $batch > 0 ? $batch : 1,
        );
    }

    /**
     * Mugs the owl pours at once for the free places on the counter: one per `$per` places, rounded up — up to `$per`
     * free places one mug, up to twice as many two — and never more than the places.
     *
     * @param int $free Free places on the counter; the owl pours only while there is one.
     * @param positive-int $per
     * @return positive-int
     */
    public static function batch(int $free, int $per): int
    {
        return \max(1, \min($free, \intdiv($free + $per - 1, $per)));
    }

    public function pouring(): bool
    {
        return $this->since !== null;
    }

    /**
     * Whether the poured batch is due on the counter.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function poured(int $now): bool
    {
        return $this->until !== null && $now >= $this->until;
    }

    /**
     * The owl puts the poured batch on the counter, no more than `$max` mugs: players who left mid-pour shrink the
     * room, and a mug already standing is never taken back.
     *
     * @param positive-int $max
     */
    public function placed(int $max): self
    {
        return new self(\max($this->mugs, \min($this->mugs + $this->batch, $max)), emptyAt: $this->emptyAt);
    }

    /**
     * @param int $now Unix time in milliseconds.
     * @param positive-int $duration Milliseconds a batch takes to pour.
     * @param int<0, max>|null $rumor What the owl mutters meanwhile.
     * @param positive-int $batch Mugs in the batch.
     */
    public function pour(int $now, int $duration, ?int $rumor = null, int $batch = 1): self
    {
        return new self($this->mugs, $now, $now + $duration, $rumor, $this->emptyAt, $batch);
    }

    /**
     * A mug leaves the counter; the pour goes on.
     */
    public function taken(): self
    {
        return new self(\max(0, $this->mugs - 1), $this->since, $this->until, $this->rumor, $this->emptyAt, $this->batch);
    }

    /**
     * @param int $now Unix time in milliseconds the owl says the counter is empty.
     */
    public function saidEmpty(int $now): self
    {
        return new self($this->mugs, $this->since, $this->until, $this->rumor, $now, $this->batch);
    }

    /**
     * @return array{since: int, until: int, batch: positive-int, rumor?: int<0, max>}|null The pour as clients see it.
     */
    public function pouringToArray(): ?array
    {
        if ($this->since === null || $this->until === null) {
            return null;
        }

        $pouring = ['since' => $this->since, 'until' => $this->until, 'batch' => $this->batch];
        $this->rumor === null or $pouring['rumor'] = $this->rumor;

        return $pouring;
    }

    /**
     * @return array{mugs: int<0, max>, pouring: array{since: int, until: int, batch: positive-int, rumor?: int<0, max>}|null, emptyAt?: int}
     */
    public function toArray(): array
    {
        $record = ['mugs' => $this->mugs, 'pouring' => $this->pouringToArray()];
        $this->emptyAt === null or $record['emptyAt'] = $this->emptyAt;

        return $record;
    }
}
