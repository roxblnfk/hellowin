<?php

declare(strict_types=1);

namespace App\Endpoint\Console;

use App\Application\Hellowin\ResetService;
use Spiral\Console\Attribute\AsCommand;
use Spiral\Console\Attribute\Option;
use Spiral\Console\Command;

#[AsCommand(name: 'hellowin:reset', description: 'Start the ritual over in a new round')]
final class ResetCommand extends Command
{
    #[Option(description: 'Round to finish; the current one by default. A round that is already over is left alone.')]
    private ?int $round = null;

    public function __invoke(ResetService $reset): int
    {
        $round = $reset->reset($this->round);

        if ($round === null) {
            $this->writeln(\sprintf('<comment>Round %d is already over, nothing to reset.</comment>', (int) $this->round));
            return self::SUCCESS;
        }

        $this->writeln(\sprintf('<info>Round %d started.</info>', $round));
        return self::SUCCESS;
    }
}
