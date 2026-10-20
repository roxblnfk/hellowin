<?php

declare(strict_types=1);

namespace App\Domain\Element;

final readonly class ElementRegistry
{
    /** @var array<non-empty-string, Element> */
    private array $elements;

    /**
     * @param iterable<Element> $elements At most one of them is the bonfire.
     */
    public function __construct(iterable $elements)
    {
        $map = [];
        $bonfire = null;
        foreach ($elements as $element) {
            isset($map[$element->id]) and throw new \InvalidArgumentException(
                \sprintf('Duplicate element id "%s".', $element->id),
            );
            $map[$element->id] = $element;

            if ($element->bonfire()) {
                $bonfire === null or throw new \InvalidArgumentException(
                    \sprintf('Elements "%s" and "%s" are both bonfires; there is one at most.', $bonfire, $element->id),
                );
                $bonfire = $element->id;
            }
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

    /**
     * @return list<non-empty-string>
     */
    public function pumpkinIds(): array
    {
        $ids = [];
        foreach ($this->elements as $element) {
            $element->bonfire() or $ids[] = $element->id;
        }

        return $ids;
    }

    public function bonfire(): ?Element
    {
        foreach ($this->elements as $element) {
            if ($element->bonfire()) {
                return $element;
            }
        }

        return null;
    }
}
