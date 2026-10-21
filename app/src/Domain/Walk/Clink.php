<?php

declare(strict_types=1);

namespace App\Domain\Walk;

/**
 * A clink the character is walking to: it happens when the character arrives, if the target is still in place
 * (docs/spec/scenario.md, «Чоканье»). Kept in the character's record, so a restarted ticker still finds it.
 */
final readonly class Clink
{
    public const WALKER = 'walker';
    public const PUMPKIN = 'pumpkin';

    /** RoadRunner behind the bar's counter after the dawn: one of him, so the target names nobody. */
    public const ROADRUNNER = 'roadrunner';

    /**
     * @param self::WALKER|self::PUMPKIN|self::ROADRUNNER $kind
     * @param non-empty-string $target Id of the character or the pumpkin; for RoadRunner — {@see ROADRUNNER}.
     * @param int $dueAt Unix time in milliseconds when both stand at the meeting point.
     * @param int|null $since `startedAt` of the target character's walk when the clink was asked for: a target that
     *        has set off since then has left. Null for a pumpkin.
     */
    public function __construct(
        public string $kind,
        public string $target,
        public int $dueAt,
        public ?int $since = null,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (!\is_array($value)) {
            return null;
        }

        /** @var mixed $kind */
        $kind = $value['kind'] ?? null;
        /** @var mixed $target */
        $target = $value['target'] ?? null;
        /** @var mixed $dueAt */
        $dueAt = $value['dueAt'] ?? null;
        /** @var mixed $since */
        $since = $value['since'] ?? null;

        return \in_array($kind, [self::WALKER, self::PUMPKIN, self::ROADRUNNER], true) && \is_string($target) && $target !== ''
            && \is_int($dueAt) && ($since === null || \is_int($since))
            ? new self($kind, $target, $dueAt, $since)
            : null;
    }

    public function due(int $now): bool
    {
        return $now >= $this->dueAt;
    }

    /**
     * The target as the client contract names it: `{walker: {id}}`, `{pumpkin: {id}}` or `{roadrunner: true}`.
     *
     * @return array<self::WALKER|self::PUMPKIN|self::ROADRUNNER, array{id: non-empty-string}|true>
     */
    public function toTarget(): array
    {
        return [$this->kind => $this->kind === self::ROADRUNNER ? true : ['id' => $this->target]];
    }

    /**
     * @return array{kind: self::WALKER|self::PUMPKIN|self::ROADRUNNER, target: non-empty-string, dueAt: int, since: int|null}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'target' => $this->target, 'dueAt' => $this->dueAt, 'since' => $this->since];
    }
}
