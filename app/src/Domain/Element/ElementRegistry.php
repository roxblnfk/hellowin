<?php

declare(strict_types=1);

namespace App\Domain\Element;

final readonly class ElementRegistry
{
    /** @var array<non-empty-string, Element> */
    private array $elements;

    /**
     * @param iterable<Element> $elements At most one centrepiece of each type.
     */
    public function __construct(iterable $elements)
    {
        $map = [];
        $centrepieces = [];
        foreach ($elements as $element) {
            isset($map[$element->id]) and throw new \InvalidArgumentException(
                \sprintf('Duplicate element id "%s".', $element->id),
            );
            $map[$element->id] = $element;

            if ($element->centrepiece()) {
                isset($centrepieces[$element->type]) and throw new \InvalidArgumentException(\sprintf(
                    'Elements "%s" and "%s" are both of type "%s"; there is one at most.',
                    $centrepieces[$element->type],
                    $element->id,
                    $element->type,
                ));
                $centrepieces[$element->type] = $element->id;
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
            $element->centrepiece() or $ids[] = $element->id;
        }

        return $ids;
    }

    /**
     * @param Element::TYPE_BONFIRE|Element::TYPE_CIRCLE $type
     */
    public function centrepiece(string $type): ?Element
    {
        foreach ($this->elements as $element) {
            if ($element->type === $type) {
                return $element;
            }
        }

        return null;
    }
}
