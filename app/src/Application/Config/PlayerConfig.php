<?php

declare(strict_types=1);

namespace App\Application\Config;

use Spiral\Core\InjectableConfig;

/**
 * @see app/config/players.php
 */
final class PlayerConfig extends InjectableConfig
{
    use EnvValues;

    public const CONFIG = 'players';

    protected array $config = [
        'leaderboard_interval' => 5000,
        'leaderboard_size' => 10,
    ];

    /**
     * @return positive-int Milliseconds between leaderboard builds.
     */
    public function getLeaderboardInterval(): int
    {
        return self::positiveInt($this->config['leaderboard_interval'] ?? null, 'leaderboard_interval', 'HELLOWIN_LEADERBOARD_INTERVAL');
    }

    /**
     * @return positive-int Players in the leaderboard.
     */
    public function getLeaderboardSize(): int
    {
        return self::positiveInt($this->config['leaderboard_size'] ?? null, 'leaderboard_size', 'HELLOWIN_LEADERBOARD_SIZE');
    }
}
