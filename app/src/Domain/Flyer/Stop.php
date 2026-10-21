<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * Where a fly lands on its way and for how long.
 */
final readonly class Stop
{
    /**
     * @param positive-int $sit Milliseconds the fly sits there.
     */
    public function __construct(
        public Point $at,
        public int $sit,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        $at = Point::fromArray($value);
        /** @var mixed $sit */
        $sit = \is_array($value) ? $value['sit'] ?? null : null;

        return $at !== null && \is_int($sit) && $sit > 0 ? new self($at, $sit) : null;
    }

    /**
     * @return array{x: float, y: float, sit: positive-int}
     */
    public function toArray(): array
    {
        return [...$this->at->toArray(), 'sit' => $this->sit];
    }
}
