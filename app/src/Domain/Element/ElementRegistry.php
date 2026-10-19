<?php

declare(strict_types=1);

namespace App\Domain\Element;

final readonly class ElementRegistry
{
    /** @var array<non-empty-string, Element> */
    private array $elements;

    /**
     * @param iterable<Element> $elements
     */
    public function __construct(iterable $elements)
    {
        $map = [];
        foreach ($elements as $element) {
            isset($map[$element->id]) and throw new \InvalidArgumentException(
                \sprintf('Duplicate element id "%s".', $element->id),
            );
            $map[$element->id] = $element;
        }

        $this->elements = $map;
    }

    public function find(string $id): ?Element
    {
        return $this->elements[$id] ?? null;
    }

    /**
     * @return list<Element>
     */
    public function all(): array
    {
        return \array_values($this->elements);
    }
}
