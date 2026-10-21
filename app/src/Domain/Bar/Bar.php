<?php

declare(strict_types=1);

namespace App\Domain\Bar;

/**
 * The counter of the bar: the mugs standing on it and the one the owl is pouring (docs/spec/protocol.md, «Бар»). The
 * owl pours one mug at a time on the server clock, so every viewer sees the same foam.
 */
final readonly class Bar
{
    /**
     * @param int<0, max> $mugs Full mugs on the counter.
     * @param int|null $since Unix time in milliseconds the owl began pouring; null while she does not pour.
     * @param int|null $until Unix time in milliseconds the poured mug lands on the counter.
     * @param int<0, max>|null $rumor What the owl mutters while pouring: an index into the frontend's catalogue.
     * @param int|null $emptyAt Unix time in milliseconds the owl last said the counter was empty.
     */
    public function __construct(
        public int $mugs = 0,
        public ?int $since = null,
        public ?int $until = null,
        public ?int $rumor = null,
        public ?int $emptyAt = null,
    ) {}

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
        /** @var mixed $emptyAt */
        $emptyAt = $value['emptyAt'] ?? null;
        $poured = \is_int($since) && \is_int($until);

        return new self(
            \is_int($mugs) && $mugs > 0 ? $mugs : 0,
            $poured ? $since : null,
            $poured ? $until : null,
            $poured && \is_int($rumor) && $rumor >= 0 ? $rumor : null,
            \is_int($emptyAt) ? $emptyAt : null,
        );
    }

    public function pouring(): bool
    {
        return $this->since !== null;
    }

    /**
     * Whether the poured mug is due on the counter.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function poured(int $now): bool
    {
        return $this->until !== null && $now >= $this->until;
    }

    /**
     * The owl puts the poured mug on the counter.
     */
    public function placed(): self
    {
        return new self($this->mugs + 1, emptyAt: $this->emptyAt);
    }

    /**
     * @param int $now Unix time in milliseconds.
     * @param positive-int $duration Milliseconds a mug takes to pour.
     * @param int<0, max>|null $rumor What the owl mutters meanwhile.
     */
    public function pour(int $now, int $duration, ?int $rumor = null): self
    {
        return new self($this->mugs, $now, $now + $duration, $rumor, $this->emptyAt);
    }

    /**
     * A mug leaves the counter; the pour goes on.
     */
    public function taken(): self
    {
        return new self(\max(0, $this->mugs - 1), $this->since, $this->until, $this->rumor, $this->emptyAt);
    }

    /**
     * @param int $now Unix time in milliseconds the owl says the counter is empty.
     */
    public function saidEmpty(int $now): self
    {
        return new self($this->mugs, $this->since, $this->until, $this->rumor, $now);
    }

    /**
     * @return array{since: int, until: int, rumor?: int<0, max>}|null The pour as clients see it.
     */
    public function pouringToArray(): ?array
    {
        if ($this->since === null || $this->until === null) {
            return null;
        }

        $pouring = ['since' => $this->since, 'until' => $this->until];
        $this->rumor === null or $pouring['rumor'] = $this->rumor;

        return $pouring;
    }

    /**
     * @return array{mugs: int<0, max>, pouring: array{since: int, until: int, rumor?: int<0, max>}|null, emptyAt?: int}
     */
    public function toArray(): array
    {
        $record = ['mugs' => $this->mugs, 'pouring' => $this->pouringToArray()];
        $this->emptyAt === null or $record['emptyAt'] = $this->emptyAt;

        return $record;
    }
}
