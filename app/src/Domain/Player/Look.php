<?php

declare(strict_types=1);

namespace App\Domain\Player;

/**
 * The character a player picked on the join screen: one part id per slot. The server checks only the format and
 * keeps the ids as they are — which parts exist and how they look is up to the frontend, which draws an unknown id
 * as the default part, so a new costume needs no backend change.
 */
final readonly class Look
{
    public const PARTS = ['body', 'cloak', 'face', 'eyes', 'hat'];

    /**
     * @param array{body: non-empty-string, cloak: non-empty-string, face: non-empty-string, eyes: non-empty-string, hat: non-empty-string} $parts
     */
    private function __construct(
        private array $parts,
    ) {}

    /**
     * Exactly the slots of {@see PARTS}, each a slug of `a-z 0-9 -`, 1–24 characters.
     *
     * @return self|null Null when the value is not a look.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (!\is_array($value) || \count($value) !== \count(self::PARTS)) {
            return null;
        }

        $parts = [];
        foreach (self::PARTS as $slot) {
            $part = $value[$slot] ?? null;
            if (!\is_string($part) || \preg_match('/^[a-z0-9-]{1,24}$/', $part) !== 1) {
                return null;
            }
            $parts[$slot] = $part;
        }

        /** @var array{body: non-empty-string, cloak: non-empty-string, face: non-empty-string, eyes: non-empty-string, hat: non-empty-string} $parts */
        return new self($parts);
    }

    /**
     * @return array{body: non-empty-string, cloak: non-empty-string, face: non-empty-string, eyes: non-empty-string, hat: non-empty-string}
     */
    public function toArray(): array
    {
        return $this->parts;
    }
}
