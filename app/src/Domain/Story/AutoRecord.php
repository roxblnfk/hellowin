<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * The autopilot's clock of the current scene or step (docs/spec/protocol.md, «Автопилот», «Счёт времени хода»). Times
 * are Unix milliseconds.
 *
 * @psalm-type Acted = array{key: non-empty-string, at: int}
 */
final readonly class AutoRecord
{
    /**
     * @param non-empty-string $ctx {@see AutoScene::ctx()} the record counts for.
     * @param int $since When the autopilot first saw the context.
     * @param int|null $readyAt The first tick that saw the room's task done.
     * @param int|null $dawnUntil The dawn's deadline, chosen once per dawn.
     * @param int|null $owlRollAt The owl's last throw of the dice.
     * @param Acted|null $acted The last move and when it was made: a repeat waits for {@see Autopilot::RETRY_MS}.
     * @param int $resumedAt When the progress pause was last lifted.
     * @param bool $paused The progress pause as the last tick saw it.
     */
    public function __construct(
        public string $ctx,
        public int $since,
        public ?int $readyAt = null,
        public ?int $dawnUntil = null,
        public ?int $owlRollAt = null,
        public ?array $acted = null,
        public int $resumedAt = 0,
        public bool $paused = false,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (!\is_array($value)) {
            return null;
        }
        /** @var mixed $ctx */
        $ctx = $value['ctx'] ?? null;
        /** @var mixed $since */
        $since = $value['since'] ?? null;
        if (!\is_string($ctx) || $ctx === '' || !\is_int($since)) {
            return null;
        }
        /** @var mixed $acted */
        $acted = $value['acted'] ?? null;
        /** @var mixed $key */
        $key = \is_array($acted) ? $acted['key'] ?? null : null;
        /** @var mixed $at */
        $at = \is_array($acted) ? $acted['at'] ?? null : null;

        return new self(
            $ctx,
            $since,
            self::time($value['readyAt'] ?? null),
            self::time($value['dawnUntil'] ?? null),
            self::time($value['owlRollAt'] ?? null),
            \is_string($key) && $key !== '' && \is_int($at) ? ['key' => $key, 'at' => $at] : null,
            self::time($value['resumedAt'] ?? null) ?? 0,
            ($value['paused'] ?? null) === true,
        );
    }

    /**
     * @return array{ctx: non-empty-string, since: int, readyAt: int|null, dawnUntil: int|null, owlRollAt: int|null, acted: Acted|null, resumedAt: int, paused: bool}
     */
    public function toArray(): array
    {
        return [
            'ctx' => $this->ctx,
            'since' => $this->since,
            'readyAt' => $this->readyAt,
            'dawnUntil' => $this->dawnUntil,
            'owlRollAt' => $this->owlRollAt,
            'acted' => $this->acted,
            'resumedAt' => $this->resumedAt,
            'paused' => $this->paused,
        ];
    }

    private static function time(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }
}
