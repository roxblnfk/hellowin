<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

/**
 * Stage and click counters of a pumpkin. The bonfire keeps the same record: it moves by the same rules, only by its
 * own threshold and stage maximums.
 */
final readonly class Pumpkin
{
    /**
     * @param non-empty-string $id Element id.
     * @param int<0, max> $stage
     * @param int<0, max> $clicks Clicks since the pumpkin reached its current stage.
     * @param int<0, max> $accepted Clicks on the pumpkin in the round, burned ones included.
     * @param int<0, max> $spent Clicks the stages reached in the round took: each transition takes the threshold in
     *        force when it runs, which the moderator may change in between.
     */
    public function __construct(
        public string $id,
        public int $stage = 0,
        public int $clicks = 0,
        public int $accepted = 0,
        public int $spent = 0,
    ) {}
}
