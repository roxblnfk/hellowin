<?php

declare(strict_types=1);

namespace App\Domain\Game;

/**
 * Click counts at which the page switches to the next stage.
 */
final readonly class Thresholds
{
    /** @var non-empty-list<positive-int> */
    public array $values;

    /**
     * @param list<int> $values Strictly ascending, positive.
     */
    public function __construct(array $values)
    {
        $values === [] and throw new \InvalidArgumentException('At least one threshold is required.');

        $previous = 0;
        foreach ($values as $value) {
            $value > $previous or throw new \InvalidArgumentException(
                'Thresholds must be positive and strictly ascending.',
            );
            $previous = $value;
        }

        /** @var non-empty-list<positive-int> $values */
        $this->values = $values;
    }

    /**
     * Stage reached with the given number of clicks: the count of thresholds passed.
     *
     * @return int<0, max>
     */
    public function stageFor(int $clicks): int
    {
        $stage = 0;
        foreach ($this->values as $threshold) {
            if ($clicks < $threshold) {
                break;
            }
            ++$stage;
        }

        return $stage;
    }

    /**
     * Next threshold to reach from the stage, or the last one once all are passed.
     *
     * @return positive-int
     */
    public function nextFor(int $stage): int
    {
        return $this->values[$stage] ?? $this->values[\array_key_last($this->values)];
    }
}
