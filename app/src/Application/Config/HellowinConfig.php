<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Balance\Limit;
use App\Domain\Bar\BarLayout;
use App\Domain\Bar\Perch;
use App\Domain\Element\Element;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Point;
use App\Domain\RateLimit\TokenBucket;
use App\Domain\Walk\Box;
use App\Domain\Walk\Ellipse;
use App\Domain\Walk\Items;
use App\Domain\Walk\Obstacle;
use App\Domain\Walk\PumpkinStand;
use App\Domain\Walk\WalkZone;
use Spiral\Core\InjectableConfig;

/**
 * @see app/config/hellowin.php
 */
final class HellowinConfig extends InjectableConfig
{
    public const CONFIG = 'hellowin';
    public const LIMIT_PUMPKIN = 'pumpkin';
    public const LIMIT_BONFIRE = 'bonfire';
    public const LIMIT_CIRCLE = 'circle';
    public const LIMIT_BAR = 'bar';
    public const LIMIT_DRINKS = 'drinks';
    public const LIMIT_ZOMBIES = 'zombies';
    public const LIMIT_BOSS = 'boss';

    /** The env prefix of every limit: `<prefix>_BASE`, `<prefix>_PER_PLAYER`. */
    private const ENV_OF_LIMIT = [
        self::LIMIT_PUMPKIN => 'HELLOWIN_PUMPKIN',
        self::LIMIT_BONFIRE => 'HELLOWIN_BONFIRE',
        self::LIMIT_CIRCLE => 'HELLOWIN_CIRCLE',
        self::LIMIT_BAR => 'HELLOWIN_BAR',
        self::LIMIT_DRINKS => 'HELLOWIN_CURSED_DRINKS',
        self::LIMIT_ZOMBIES => 'HELLOWIN_CURSED_ZOMBIES',
        self::LIMIT_BOSS => 'HELLOWIN_BOSS_HP',
    ];

    protected array $config = [
        'channel' => 'hellowin',
        'storage' => 'hellowin',
        'centrifugo_url' => ':8000/connection/websocket',
        'grafana_url' => ':3000/',
        'moderator_secret' => '',
        'scene_stage_max' => '0,4,8,11',
        'bonfire_stage_max' => '0,0,4,7',
        'circle_stages' => 4,
        'limits' => [
            self::LIMIT_PUMPKIN => ['base' => 2, 'per_player' => 5],
            self::LIMIT_BONFIRE => ['base' => 8, 'per_player' => 20],
            self::LIMIT_CIRCLE => ['base' => 15, 'per_player' => 40],
            self::LIMIT_BAR => ['base' => 5, 'per_player' => 1.5],
            self::LIMIT_DRINKS => ['base' => 5, 'per_player' => 4],
            self::LIMIT_ZOMBIES => ['base' => 5, 'per_player' => 4],
            self::LIMIT_BOSS => ['base' => 45, 'per_player' => 60],
        ],
        'valkey_dsn' => 'tcp://valkey:6379',
        'bar' => ['mugs' => 60, 'pour_ms' => 2500, 'pour_per' => 10, 'rumor_chance' => 0.33, 'drink_ms' => 1200, 'mug_litres' => 0.5],
        'rapier' => ['beer_handover_ms' => 900],
        'top' => ['board' => 200, 'interval' => 500],
        'dice' => ['idempotency_ttl' => 900, 'cooldown_ms' => 1800],
        'chat' => ['max' => 120, 'history' => 50],
        'emotes' => ['set' => '🎃,👻,💀,😂,😱,😍,👍,👏,🔥,🍺,🤘,🙏,🎉', 'cooldown_ms' => 1000],
        'rpc_rate' => ['click' => '20,40', 'shoot' => '20,40', 'move' => '10,20', 'say' => '1,3', 'other' => '10,30'],
        'elements' => [],
        'bushes' => [],
        'horizon' => 38,
        'items' => [],
        'finale_items' => [],
        'walk' => [
            'area' => ['x1' => 3, 'y1' => 44, 'x2' => 97, 'y2' => 97],
            'patch' => ['cx' => 50, 'cy' => 63, 'rx' => 31, 'ry' => 25],
            'spawn' => ['y1' => 86, 'y2' => 90],
            'speed' => 12,
            'leave_after' => 5000,
            'reach' => 10,
            'clink_gap' => 4,
            'clink_duration' => 3200,
            'pumpkin_stand' => ['out' => 1.18, 'gap' => ['l' => 10, 'm' => 9, 's' => 8]],
        ],
    ];

    /**
     * The bar of scene 0: its floor, tables and counter.
     *
     * @return BarLayout|null Null when the config has no `walk.bar`: characters walk the lawn there too.
     */
    public function getBarLayout(): ?BarLayout
    {
        $bar = $this->walk()['bar'] ?? null;
        if ($bar === null) {
            return null;
        }
        \is_array($bar) or throw new \InvalidArgumentException('Config "hellowin.walk.bar" must be an array.');

        $area = $bar['area'] ?? null;
        $counter = $bar['counter'] ?? null;
        $spawn = $bar['spawn'] ?? null;
        $obstacles = $bar['obstacles'] ?? [];
        \is_array($area) && \is_array($counter) && \is_array($spawn) && \is_array($obstacles) or throw new \InvalidArgumentException(
            'Config "hellowin.walk.bar" must have "area" {x1, y1, x2, y2}, "obstacles" [...], "counter" {x1, x2, y} and "spawn" {y1, y2}.',
        );

        return new BarLayout(
            new WalkZone(
                self::float($area['x1'] ?? null, 'walk.bar.area.x1'),
                self::float($area['y1'] ?? null, 'walk.bar.area.y1'),
                self::float($area['x2'] ?? null, 'walk.bar.area.x2'),
                self::float($area['y2'] ?? null, 'walk.bar.area.y2'),
                ...\array_map(self::obstacle(...), \array_values($obstacles)),
            ),
            [
                self::float($counter['x1'] ?? null, 'walk.bar.counter.x1'),
                self::float($counter['x2'] ?? null, 'walk.bar.counter.x2'),
                self::float($counter['y'] ?? null, 'walk.bar.counter.y'),
            ],
            [self::float($spawn['y1'] ?? null, 'walk.bar.spawn.y1'), self::float($spawn['y2'] ?? null, 'walk.bar.spawn.y2')],
            self::nonNegative($bar['clink_reach'] ?? 10, 'walk.bar.clink_reach'),
            self::perches($bar['perches'] ?? []),
            self::point($bar['door'] ?? null, 'walk.bar.door'),
            self::nonNegative($bar['door_in'] ?? 6, 'walk.bar.door_in'),
            self::point($bar['roadrunner'] ?? null, 'walk.bar.roadrunner'),
        );
    }

    /**
     * @return non-empty-string
     */
    public function getChannel(): string
    {
        return self::nonEmptyString($this->config['channel'] ?? null, 'channel');
    }

    /**
     * Cache storage holding the game state.
     *
     * @return non-empty-string
     */
    public function getStorage(): string
    {
        return self::nonEmptyString($this->config['storage'] ?? null, 'storage');
    }

    /**
     * @return non-empty-string A full URL, a path or `:<port>/path`: see {@see \App\Application\Web\ServiceUrl}.
     */
    public function getCentrifugoUrl(): string
    {
        return self::nonEmptyString($this->config['centrifugo_url'] ?? null, 'centrifugo_url');
    }

    /**
     * @return non-empty-string The Grafana dashboard, in the forms of {@see getCentrifugoUrl()}.
     */
    public function getGrafanaUrl(): string
    {
        return self::nonEmptyString($this->config['grafana_url'] ?? null, 'grafana_url');
    }

    /**
     * A limit of the game (docs/spec/protocol.md, «Лимиты и баланс»): `base` for one figure on the floor and
     * `per_player` for every other one, fractions allowed. Numeric strings are accepted too: env variables arrive as
     * strings.
     *
     * @param self::LIMIT_* $name
     */
    public function getLimit(string $name): Limit
    {
        $limits = $this->config['limits'] ?? [];
        \is_array($limits) or throw new \InvalidArgumentException('Config "hellowin.limits" must be an array.');
        $limit = $limits[$name] ?? null;
        \is_array($limit) or throw new \InvalidArgumentException(\sprintf('Config "hellowin.limits.%s" must be {base, per_player}.', $name));

        $env = self::ENV_OF_LIMIT[$name];
        $base = self::number($limit['base'] ?? null);
        $perPlayer = self::number($limit['per_player'] ?? null);
        $base !== null && $base > 0.0 or throw new \InvalidArgumentException(\sprintf(
            'Config "hellowin.limits.%s.base" (env %s_BASE) must be a number above zero.',
            $name,
            $env,
        ));
        $perPlayer !== null && $perPlayer >= 0.0 or throw new \InvalidArgumentException(\sprintf(
            'Config "hellowin.limits.%s.per_player" (env %s_PER_PLAYER) must be a non-negative number.',
            $name,
            $env,
        ));

        return new Limit($base, $perPlayer);
    }

    /**
     * The last pumpkin stage of every scene before the final one, cumulative: `[0, 4, 8, 11]` — the bar (scene 0) has
     * no pumpkins to move, they reach stage 4 in scene 1, 8 in scene 2, 11 in scene 3; scene 4 is the final one. Only
     * the first value may be 0, and only then scene 0 is the bar. A comma-separated string is accepted too: env
     * variables arrive as strings.
     *
     * @return non-empty-list<int<0, max>> Strictly increasing.
     */
    public function getSceneStageMax(): array
    {
        $value = $this->config['scene_stage_max'] ?? null;
        \is_string($value) and $value = \array_map(\trim(...), \explode(',', $value));

        $error = static fn(): \InvalidArgumentException => new \InvalidArgumentException(\sprintf(
            'Config "hellowin.scene_stage_max" (env HELLOWIN_SCENE_STAGE_MAX) must be a strictly increasing list of '
            . 'integers from 0, like "0,4,8,11"; got %s.',
            \is_array($value) ? (string) \json_encode($value, \JSON_UNESCAPED_UNICODE) : \get_debug_type($value),
        ));

        \is_array($value) && $value !== [] or throw $error();

        $list = [];
        foreach ($value as $stage) {
            \is_string($stage) && \preg_match('/^\d+$/', $stage) === 1 and $stage = (int) $stage;
            \is_int($stage) && $stage > ($list === [] ? -1 : $list[\count($list) - 1]) or throw $error();
            $list[] = $stage;
        }

        /** @var non-empty-list<int<0, max>> $list */
        return $list;
    }

    /**
     * The last bonfire stage of every scene, cumulative like {@see getSceneStageMax()}: `[0, 0, 4, 7]` leaves the
     * bonfire out in the bar and scene 1 and takes it to stage 4 in scene 2, to 7 in scene 3. Scenes past the list keep
     * its last value.
     *
     * @return non-empty-list<int<0, max>> Never decreasing.
     */
    public function getBonfireStageMax(): array
    {
        $value = $this->config['bonfire_stage_max'] ?? null;
        \is_string($value) and $value = \array_map(\trim(...), \explode(',', $value));

        $error = static fn(): \InvalidArgumentException => new \InvalidArgumentException(\sprintf(
            'Config "hellowin.bonfire_stage_max" (env HELLOWIN_BONFIRE_STAGE_MAX) must be a non-decreasing list of '
            . 'integers from 0, like "0,0,4,7"; got %s.',
            \is_array($value) ? (string) \json_encode($value, \JSON_UNESCAPED_UNICODE) : \get_debug_type($value),
        ));

        \is_array($value) && $value !== [] or throw $error();

        $list = [];
        foreach ($value as $stage) {
            \is_string($stage) && \preg_match('/^\d+$/', $stage) === 1 and $stage = (int) $stage;
            \is_int($stage) && $stage >= ($list === [] ? 0 : $list[\count($list) - 1]) or throw $error();
            $list[] = $stage;
        }

        /** @var non-empty-list<int<0, max>> $list */
        return $list;
    }

    /**
     * @return positive-int The last stage of the magic circle: it is closed there, and the final scene is ready.
     */
    public function getCircleStages(): int
    {
        return self::positiveInt($this->config['circle_stages'] ?? null, 'circle_stages', 'HELLOWIN_CIRCLE_STAGES');
    }

    /**
     * @return positive-int Full mugs the counter of the bar has room for; the owl pours one per player on the floor up
     *         to it.
     */
    public function getBarMugs(): int
    {
        return self::positiveInt($this->bar()['mugs'] ?? null, 'bar.mugs', 'HELLOWIN_BAR_MUGS');
    }

    /**
     * @return positive-int Milliseconds the owl pours one batch of mugs.
     */
    public function getBarPourMs(): int
    {
        return self::positiveInt($this->bar()['pour_ms'] ?? null, 'bar.pour_ms', 'HELLOWIN_BAR_POUR_MS');
    }

    /**
     * @return positive-int Free places on the counter per mug of a batch: up to this many free places the owl pours
     *         one mug at once, up to twice as many two.
     */
    public function getBarPourPer(): int
    {
        return self::positiveInt($this->bar()['pour_per'] ?? null, 'bar.pour_per', 'HELLOWIN_BAR_POUR_PER');
    }

    /**
     * @return float Probability that the owl mutters a rumour over a pour.
     */
    public function getBarRumorChance(): float
    {
        /** @var mixed $value */
        $value = $this->bar()['rumor_chance'] ?? null;
        \is_string($value) && \is_numeric(\trim($value)) and $value = (float) \trim($value);
        \is_int($value) and $value = (float) $value;

        return \is_float($value) && $value >= 0.0 && $value <= 1.0
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.bar.rumor_chance" (env HELLOWIN_BAR_RUMOR_CHANCE) must be a probability from 0 to 1.');
    }

    /**
     * @return positive-int Milliseconds a sip plays: no mug is taken or drunk meanwhile.
     */
    public function getDrinkMs(): int
    {
        return self::positiveInt($this->bar()['drink_ms'] ?? null, 'bar.drink_ms', 'HELLOWIN_DRINK_MS');
    }

    /**
     * @return float Litres in one mug, for the beer statistics.
     */
    public function getMugLitres(): float
    {
        /** @var mixed $value */
        $value = $this->bar()['mug_litres'] ?? null;
        \is_string($value) && \is_numeric(\trim($value)) and $value = (float) \trim($value);
        \is_int($value) and $value = (float) $value;

        return \is_float($value) && $value > 0
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.bar.mug_litres" (env HELLOWIN_MUG_LITRES) must be a positive number.');
    }

    /**
     * @return positive-int Milliseconds a pumpkin of the cursed world hands its beer over after the character arrives.
     */
    public function getBeerHandoverMs(): int
    {
        return self::positiveInt($this->rapier()['beer_handover_ms'] ?? null, 'rapier.beer_handover_ms', 'HELLOWIN_BEER_HANDOVER_MS');
    }

    /**
     * @return positive-int Rows of the shooters' top an event or the snapshot carries at most.
     */
    public function getTopBoard(): int
    {
        return self::positiveInt($this->top()['board'] ?? null, 'top.board', 'HELLOWIN_TOP_BOARD');
    }

    /**
     * @return positive-int Milliseconds the ticker waits at least between two publications of the shooters' top.
     */
    public function getTopInterval(): int
    {
        return self::positiveInt($this->top()['interval'] ?? null, 'top.interval', 'HELLOWIN_TOP_INTERVAL');
    }

    /**
     * @return positive-int Characters of a chat message at most.
     */
    public function getChatMax(): int
    {
        return self::positiveInt($this->chat()['max'] ?? null, 'chat.max', 'HELLOWIN_CHAT_MAX');
    }

    /**
     * @return positive-int Chat messages the history keeps for the moderator.
     */
    public function getChatHistory(): int
    {
        return self::positiveInt($this->chat()['history'] ?? null, 'chat.history', 'HELLOWIN_CHAT_HISTORY');
    }

    /**
     * @return positive-int Seconds the answer of a dice attempt is kept for a repeat of it.
     */
    public function getDiceIdempotencyTtl(): int
    {
        return self::positiveInt($this->dice()['idempotency_ttl'] ?? null, 'dice.idempotency_ttl', 'HELLOWIN_DICE_IDEMPOTENCY_TTL');
    }

    /**
     * @return int<0, max> Milliseconds between new throws of the dice by one player.
     */
    public function getDiceCooldown(): int
    {
        /** @var mixed $value */
        $value = $this->dice()['cooldown_ms'] ?? null;
        \is_string($value) && \preg_match('/^\s*\d+\s*$/', $value) === 1 and $value = (int) $value;

        return \is_int($value) && $value >= 0
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.dice.cooldown_ms" (env HELLOWIN_DICE_COOLDOWN_MS) must be a non-negative integer.');
    }

    /**
     * The emoji of the emote panel, in the order of its buttons. A comma-separated string is accepted too: env
     * variables arrive as strings.
     *
     * @return non-empty-list<non-empty-string> Distinct.
     */
    public function getEmotes(): array
    {
        /** @var mixed $value */
        $value = $this->emotes()['set'] ?? null;
        \is_string($value) and $value = \array_map(\trim(...), \explode(',', $value));

        $error = static fn(): \InvalidArgumentException => new \InvalidArgumentException(\sprintf(
            'Config "hellowin.emotes.set" (env HELLOWIN_EMOTES) must be a comma-separated list of distinct emoji; got %s.',
            \is_array($value) ? (string) \json_encode($value, \JSON_UNESCAPED_UNICODE) : \get_debug_type($value),
        ));

        \is_array($value) && $value !== [] or throw $error();

        $list = [];
        /** @var mixed $emoji */
        foreach ($value as $emoji) {
            \is_string($emoji) && $emoji !== '' && !\in_array($emoji, $list, true) or throw $error();
            $list[] = $emoji;
        }

        return $list;
    }

    /**
     * @return int<0, max> Milliseconds between two emotes of one player; 0 — no pause.
     */
    public function getEmoteCooldown(): int
    {
        /** @var mixed $value */
        $value = $this->emotes()['cooldown_ms'] ?? null;
        \is_string($value) && \preg_match('/^\s*\d+\s*$/', $value) === 1 and $value = (int) $value;

        return \is_int($value) && $value >= 0
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.emotes.cooldown_ms" (env HELLOWIN_EMOTE_COOLDOWN_MS) must be a non-negative integer.');
    }

    /**
     * @return non-empty-string
     */
    public function getValkeyDsn(): string
    {
        return self::nonEmptyString($this->config['valkey_dsn'] ?? null, 'valkey_dsn');
    }

    /**
     * RPC limits by group of methods. A value is `"<per second>,<burst>"`; 0 or an empty one turns the group's limit
     * off, and the group is left out.
     *
     * @return array<non-empty-string, TokenBucket>
     */
    public function getRpcRates(): array
    {
        $groups = $this->config['rpc_rate'] ?? [];
        \is_array($groups) or throw new \InvalidArgumentException('Config "hellowin.rpc_rate" must be an array.');

        $buckets = [];
        /** @var mixed $value */
        foreach ($groups as $group => $value) {
            \is_string($group) && $group !== '' or throw new \InvalidArgumentException(
                'Config "hellowin.rpc_rate" must map groups of methods to limits.',
            );
            \is_int($value) and $value = (string) $value;
            \is_string($value) and $value = \trim($value);
            if (\in_array($value, ['', '0', null, false], true)) {
                continue;
            }

            $match = [];
            \is_string($value) && \preg_match('/^(\d+(?:\.\d+)?)\s*,\s*(\d+)$/', $value, $match);
            $rate = (float) ($match[1] ?? 0);
            $burst = (int) ($match[2] ?? 0);
            $rate > 0 && $burst > 0 or throw new \InvalidArgumentException(\sprintf(
                'Config "hellowin.rpc_rate.%1$s" (env HELLOWIN_RPC_RATE_%2$s) must be "<per second>,<burst>" with '
                    . 'both above zero, like "20,40", or 0 to turn the limit off; got %3$s.',
                $group,
                \strtoupper($group),
                \is_string($value) ? \sprintf('"%s"', $value) : \get_debug_type($value),
            ));

            $buckets[$group] = new TokenBucket($rate, $burst);
        }

        return $buckets;
    }

    /**
     * The secret that makes a connection the moderator's.
     *
     * @return non-empty-string|null Null turns the console off: nobody is the moderator.
     */
    public function getModeratorSecret(): ?string
    {
        /** @var mixed $value */
        $value = $this->config['moderator_secret'] ?? null;
        $value === null || \is_string($value) or throw new \InvalidArgumentException(
            'Config "hellowin.moderator_secret" (env HELLOWIN_MODERATOR_SECRET) must be a string.',
        );
        $value = \trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return float The horizon line in percents of the screen from the top.
     */
    public function getHorizon(): float
    {
        return self::float($this->config['horizon'] ?? null, 'horizon');
    }

    /**
     * @return list<Element>
     */
    public function getElements(): array
    {
        $elements = $this->config['elements'] ?? [];
        \is_array($elements) or throw new \InvalidArgumentException('Config "hellowin.elements" must be a list.');

        $zone = $this->getWalkZone();
        $reach = $this->getWalkReach();

        return \array_values(\array_map(static function (mixed $spec) use ($zone, $reach): Element {
            $element = self::element($spec);

            return $element->centrepiece() || $zone->distance(new Point($element->x, $element->y)) > $reach
                ? $element
                : new Element($element->id, $element->type, $element->x, $element->y, $element->emojis, $element->size, true);
        }, $elements));
    }

    /**
     * A bush without `depth` launches the nearest flyers.
     *
     * @param positive-int $depths Number of flyer depths: the length of `HELLOWIN_FLYER_DURATION`.
     *
     * @return list<Bush>
     */
    public function getBushes(int $depths): array
    {
        $bushes = $this->config['bushes'] ?? [];
        \is_array($bushes) or throw new \InvalidArgumentException('Config "hellowin.bushes" must be a list.');

        return \array_values(\array_map(static function (mixed $spec) use ($depths): Bush {
            \is_array($spec) or throw new \InvalidArgumentException('Each bush of "hellowin.bushes" must be an array.');
            $id = self::nonEmptyString($spec['id'] ?? null, 'bushes.*.id');
            /** @var mixed $depth */
            $depth = $spec['depth'] ?? $depths - 1;

            \is_int($depth) && $depth >= 0 && $depth < $depths or throw new \InvalidArgumentException(\sprintf(
                'Config "hellowin.bushes" bush "%s" has depth %s, but flyers have %d depths: use an integer from 0 to %d '
                . 'or list more depths in env HELLOWIN_FLYER_DURATION.',
                $id,
                \is_int($depth) ? (string) $depth : \get_debug_type($depth),
                $depths,
                $depths - 1,
            ));

            return new Bush(
                $id,
                new Point(self::float($spec['x'] ?? null, 'bushes.*.x'), self::float($spec['y'] ?? null, 'bushes.*.y')),
                $depth,
            );
        }, $bushes));
    }

    public function getWalkZone(): WalkZone
    {
        $walk = $this->walk();
        $area = $walk['area'] ?? null;
        $patch = $walk['patch'] ?? null;
        \is_array($area) && \is_array($patch) or throw new \InvalidArgumentException(
            'Config "hellowin.walk" must have "area" {x1, y1, x2, y2} and "patch" {cx, cy, rx, ry}.',
        );

        return WalkZone::lawn(
            self::float($area['x1'] ?? null, 'walk.area.x1'),
            self::float($area['y1'] ?? null, 'walk.area.y1'),
            self::float($area['x2'] ?? null, 'walk.area.x2'),
            self::float($area['y2'] ?? null, 'walk.area.y2'),
            self::float($patch['cx'] ?? null, 'walk.patch.cx'),
            self::float($patch['cy'] ?? null, 'walk.patch.cy'),
            self::float($patch['rx'] ?? null, 'walk.patch.rx'),
            self::float($patch['ry'] ?? null, 'walk.patch.ry'),
        );
    }

    public function getItems(): Items
    {
        $items = $this->config['items'] ?? [];
        \is_array($items) or throw new \InvalidArgumentException('Config "hellowin.items" must be a list.');

        $finale = $this->config['finale_items'] ?? [];
        \is_array($finale) or throw new \InvalidArgumentException('Config "hellowin.finale_items" must be an array.');

        $byStep = [];
        /** @var mixed $item */
        foreach ($finale as $step => $item) {
            \is_int($step) && $step >= 0 && self::isItem($item) or throw new \InvalidArgumentException(
                'Config "hellowin.finale_items" must map finale steps to item ids (slugs `a-z 0-9 -`) or null.',
            );
            /** @var non-empty-string|null $item */
            $byStep[$step] = $item;
        }

        return new Items(\array_values(\array_map(static function (mixed $item): ?string {
            self::isItem($item) or throw new \InvalidArgumentException(
                'Config "hellowin.items" must list item ids (slugs `a-z 0-9 -`) or null by scene.',
            );

            /** @var non-empty-string|null $item */
            return $item;
        }, $items)), $byStep);
    }

    /**
     * @return array{float, float} Top and bottom of the band along the bottom of the lawn new characters appear in.
     */
    public function getWalkSpawn(): array
    {
        $spawn = $this->walk()['spawn'] ?? null;
        \is_array($spawn) or throw new \InvalidArgumentException('Config "hellowin.walk.spawn" must be {y1, y2}.');
        $y1 = self::float($spawn['y1'] ?? null, 'walk.spawn.y1');
        $y2 = self::float($spawn['y2'] ?? null, 'walk.spawn.y2');
        $y1 <= $y2 or throw new \InvalidArgumentException('Config "hellowin.walk.spawn" needs y1 <= y2.');

        return [$y1, $y2];
    }

    /**
     * @return float Percents of the screen per second along the path.
     */
    public function getWalkSpeed(): float
    {
        /** @var mixed $value */
        $value = $this->walk()['speed'] ?? null;
        \is_string($value) && \is_numeric(\trim($value)) and $value = (float) \trim($value);
        \is_int($value) and $value = (float) $value;

        return \is_float($value) && $value > 0
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.walk.speed" (env HELLOWIN_WALK_SPEED) must be a positive number.');
    }

    /**
     * @return positive-int Milliseconds without connections before a character leaves the lawn.
     */
    public function getWalkLeaveAfter(): int
    {
        return self::positiveInt($this->walk()['leave_after'] ?? null, 'walk.leave_after', 'HELLOWIN_WALK_LEAVE_AFTER');
    }

    /**
     * @return float Percents of the screen: a pumpkin at most this far from the lawn is reachable for a clink.
     */
    public function getWalkReach(): float
    {
        return self::nonNegative($this->walk()['reach'] ?? 10, 'walk.reach');
    }

    /**
     * @return float Percents of the screen between two characters that clink.
     */
    public function getClinkGap(): float
    {
        return self::nonNegative($this->walk()['clink_gap'] ?? 4, 'walk.clink_gap');
    }

    public function getPumpkinStand(): PumpkinStand
    {
        $stand = $this->walk()['pumpkin_stand'] ?? null;
        $gap = \is_array($stand) ? $stand['gap'] ?? null : null;
        \is_array($stand) && \is_array($gap) or throw new \InvalidArgumentException(
            'Config "hellowin.walk.pumpkin_stand" must be {out, gap: {l, m, s}}.',
        );

        $out = self::float($stand['out'] ?? null, 'walk.pumpkin_stand.out');
        $out > 0.0 or throw new \InvalidArgumentException('Config "hellowin.walk.pumpkin_stand.out" must be positive.');

        return new PumpkinStand($out, [
            's' => self::nonNegative($gap['s'] ?? null, 'walk.pumpkin_stand.gap.s'),
            'm' => self::nonNegative($gap['m'] ?? null, 'walk.pumpkin_stand.gap.m'),
            'l' => self::nonNegative($gap['l'] ?? null, 'walk.pumpkin_stand.gap.l'),
        ]);
    }

    /**
     * @return positive-int Milliseconds a clink plays: a new clink of the same player is refused meanwhile.
     */
    public function getClinkDuration(): int
    {
        /** @var mixed $value */
        $value = $this->walk()['clink_duration'] ?? 3200;

        return \is_int($value) && $value > 0
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.walk.clink_duration" must be a positive integer.');
    }

    /**
     * @return float|null Null for anything but a number or a numeric string.
     */
    private static function number(mixed $value): ?float
    {
        \is_string($value) && \is_numeric(\trim($value)) and $value = (float) \trim($value);

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    private static function nonNegative(mixed $value, string $key): float
    {
        $value = self::float($value, $key);

        return $value >= 0.0
            ? $value
            : throw new \InvalidArgumentException(\sprintf('Config "hellowin.%s" must not be negative.', $key));
    }

    /**
     * A table `{cx, cy, rx, ry}` or the counter `{x1, y1, x2, y2}`.
     */
    private static function obstacle(mixed $spec): Obstacle
    {
        \is_array($spec) or throw new \InvalidArgumentException('Each obstacle of "hellowin.walk.bar.obstacles" must be an array.');

        try {
            return isset($spec['cx'])
                ? new Ellipse(
                    self::float($spec['cx'], 'walk.bar.obstacles.*.cx'),
                    self::float($spec['cy'] ?? null, 'walk.bar.obstacles.*.cy'),
                    self::float($spec['rx'] ?? null, 'walk.bar.obstacles.*.rx'),
                    self::float($spec['ry'] ?? null, 'walk.bar.obstacles.*.ry'),
                )
                : new Box(
                    self::float($spec['x1'] ?? null, 'walk.bar.obstacles.*.x1'),
                    self::float($spec['y1'] ?? null, 'walk.bar.obstacles.*.y1'),
                    self::float($spec['x2'] ?? null, 'walk.bar.obstacles.*.x2'),
                    self::float($spec['y2'] ?? null, 'walk.bar.obstacles.*.y2'),
                );
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                'Config "hellowin.walk.bar.obstacles" lists ellipses {cx, cy, rx, ry} and rectangles {x1, y1, x2, y2}: '
                . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * A point of the bar `{x, y}` — the threshold of its door, RoadRunner's place — or none.
     */
    private static function point(mixed $point, string $key): ?Point
    {
        if ($point === null) {
            return null;
        }
        \is_array($point) or throw new \InvalidArgumentException(\sprintf('Config "hellowin.%s" must be {x, y}.', $key));

        return new Point(self::float($point['x'] ?? null, $key . '.x'), self::float($point['y'] ?? null, $key . '.y'));
    }

    /**
     * Where flies land besides the tables and the counter: `[{x1, x2, y, name}]`.
     *
     * @return list<Perch>
     */
    private static function perches(mixed $perches): array
    {
        \is_array($perches) or throw new \InvalidArgumentException('Config "hellowin.walk.bar.perches" must be a list.');

        try {
            $list = \array_values(\array_map(static function (mixed $spec): Perch {
                \is_array($spec) or throw new \InvalidArgumentException('Each perch must be {x1, x2, y, name}.');

                return new Perch(
                    self::float($spec['x1'] ?? null, 'walk.bar.perches.*.x1'),
                    self::float($spec['x2'] ?? null, 'walk.bar.perches.*.x2'),
                    self::float($spec['y'] ?? null, 'walk.bar.perches.*.y'),
                    self::nonEmptyString($spec['name'] ?? null, 'walk.bar.perches.*.name'),
                );
            }, $perches));
            $names = \array_map(static fn(Perch $perch): string => $perch->name, $list);
            \count(\array_unique($names)) === \count($names) or throw new \InvalidArgumentException('Each perch needs a name of its own.');

            return $list;
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Config "hellowin.walk.bar.perches": ' . $e->getMessage(), previous: $e);
        }
    }

    private static function isItem(mixed $item): bool
    {
        return $item === null || \is_string($item) && \preg_match('/^[a-z0-9-]{1,24}$/', $item) === 1;
    }

    private static function element(mixed $spec): Element
    {
        \is_array($spec) or throw new \InvalidArgumentException('Each element of "hellowin.elements" must be an array.');

        $emojis = $spec['emojis'] ?? null;
        \is_array($emojis) && $emojis !== [] or throw new \InvalidArgumentException('Element emojis must be a non-empty list.');

        $type = $spec['type'] ?? null;
        \in_array($type, [Element::TYPE_PUMPKIN, ...Element::CENTREPIECES], true) or throw new \InvalidArgumentException(
            'Config "hellowin.elements.*.type" must be "pumpkin", "bonfire" or "circle".',
        );

        return new Element(
            id: self::nonEmptyString($spec['id'] ?? null, 'elements.*.id'),
            type: $type,
            x: self::float($spec['x'] ?? null, 'elements.*.x'),
            y: self::float($spec['y'] ?? null, 'elements.*.y'),
            size: $type === Element::TYPE_PUMPKIN ? self::size($spec['size'] ?? null) : null,
            emojis: \array_values(\array_map(
                static fn(mixed $emoji): string => self::nonEmptyString($emoji, 'elements.*.emojis'),
                $emojis,
            )),
        );
    }

    /**
     * @return non-empty-string
     */
    private static function nonEmptyString(mixed $value, string $key): string
    {
        return \is_string($value) && $value !== ''
            ? $value
            : throw new \InvalidArgumentException(\sprintf('Config "hellowin.%s" must be a non-empty string.', $key));
    }

    /**
     * @return positive-int
     */
    private static function positiveInt(mixed $value, string $key, string $env): int
    {
        \is_string($value) && \preg_match('/^\s*\d+\s*$/', $value) === 1 and $value = (int) $value;

        return \is_int($value) && $value > 0
            ? $value
            : throw new \InvalidArgumentException(\sprintf(
                'Config "hellowin.%s" (env %s) must be a positive integer, got %s.',
                $key,
                $env,
                \is_string($value) ? \sprintf('"%s"', $value) : \get_debug_type($value),
            ));
    }

    /**
     * @return 's'|'m'|'l'
     */
    private static function size(mixed $value): string
    {
        return \in_array($value, ['s', 'm', 'l'], true)
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.elements.*.size" must be one of "s", "m", "l".');
    }

    private static function float(mixed $value, string $key): float
    {
        return \is_int($value) || \is_float($value)
            ? (float) $value
            : throw new \InvalidArgumentException(\sprintf('Config "hellowin.%s" must be a number.', $key));
    }

    private function bar(): array
    {
        $bar = $this->config['bar'] ?? [];
        \is_array($bar) or throw new \InvalidArgumentException('Config "hellowin.bar" must be an array.');

        return $bar;
    }

    private function rapier(): array
    {
        $rapier = $this->config['rapier'] ?? [];
        \is_array($rapier) or throw new \InvalidArgumentException('Config "hellowin.rapier" must be an array.');

        return $rapier;
    }

    private function top(): array
    {
        $top = $this->config['top'] ?? [];
        \is_array($top) or throw new \InvalidArgumentException('Config "hellowin.top" must be an array.');

        return $top;
    }

    private function chat(): array
    {
        $chat = $this->config['chat'] ?? [];
        \is_array($chat) or throw new \InvalidArgumentException('Config "hellowin.chat" must be an array.');

        return $chat;
    }

    private function emotes(): array
    {
        $emotes = $this->config['emotes'] ?? [];
        \is_array($emotes) or throw new \InvalidArgumentException('Config "hellowin.emotes" must be an array.');

        return $emotes;
    }

    private function dice(): array
    {
        $dice = $this->config['dice'] ?? [];
        \is_array($dice) or throw new \InvalidArgumentException('Config "hellowin.dice" must be an array.');

        return $dice;
    }

    private function walk(): array
    {
        $walk = $this->config['walk'] ?? [];
        \is_array($walk) or throw new \InvalidArgumentException('Config "hellowin.walk" must be an array.');

        return $walk;
    }
}
