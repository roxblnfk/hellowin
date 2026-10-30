<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * How long the owl talks, by the table the frontend keeps in `public/autopilot.json` (docs/spec/protocol.md,
 * «Автопилот», «Таблица речи совы»): the texts are on the frontend, the server knows only their durations, and picks the
 * variant the client says. Also how long each finale step's staging plays. `speechOf` in `public/dev/autopilot.js` is
 * the reference.
 *
 * A line's `lead` is how far into it its last part starts: for an instruction, the part that opens the step's input.
 * An `aside` line is chatter beside the story ({@see OwlClock}).
 *
 * @psalm-type Speech = array{ms: int<0, max>, hold: int<0, max>, delay: int<0, max>, lead: int<0, max>, aside: bool}
 * @psalm-type Variant = array{ms: int<0, max>, hold: int<0, max>, lead: int<0, max>, chars: int<0, max>|null, fill: list<string>}
 * @psalm-type Line = array{
 *     default: Variant,
 *     delay: int<0, max>,
 *     aside: bool,
 *     scenes: array<int, Variant>,
 *     steps: array<int, Variant>,
 *     abilities: array<string, list<Variant>>,
 * }
 */
final readonly class SpeechTable
{
    /**
     * @param array<non-empty-string, Line> $lines Catalogue lines by id.
     * @param array{base: int<0, max>, perChar: int<0, max>, min: int<0, max>, max: int<0, max>} $show The moderator's
     *        own text and a variant with placeholders: milliseconds by its length.
     * @param int<0, max> $holdMs Milliseconds such a text holds the bubble at most.
     * @param array<int, int<0, max>> $steps Milliseconds a finale step's staging plays, by step.
     * @param int<0, max> $someone Characters of the stand-in for a missing player.
     */
    public function __construct(
        private array $lines = [],
        private array $show = ['base' => 0, 'perChar' => 0, 'min' => 0, 'max' => 0],
        private int $holdMs = 0,
        private array $steps = [],
        private int $someone = 0,
    ) {}

    /**
     * @throws \RuntimeException The file is missing or not a JSON object.
     */
    public static function fromFile(string $path): self
    {
        $json = \is_file($path) ? \file_get_contents($path) : false;
        $json === false and throw new \RuntimeException(\sprintf('The speech table "%s" is not readable.', $path));

        try {
            /** @var mixed $value */
            $value = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('The speech table "%s" is not JSON: %s', $path, $e->getMessage()), previous: $e);
        }
        \is_array($value) or throw new \RuntimeException(\sprintf('The speech table "%s" is not an object.', $path));

        return self::fromArray($value);
    }

    /**
     * Anything malformed counts as zero or as missing: a broken entry only lets the autopilot move earlier.
     */
    public static function fromArray(array $value): self
    {
        /** @var mixed $given */
        $given = $value['lines'] ?? null;
        $lines = [];
        /** @var mixed $line */
        foreach (\is_array($given) ? $given : [] as $id => $line) {
            $id = (string) $id;
            if ($id !== '' && \is_array($line)) {
                $lines[$id] = self::lineOf($line);
            }
        }

        /** @var mixed $show */
        $show = $value['showMs'] ?? null;
        \is_array($show) or $show = [];
        /** @var mixed $given */
        $given = $value['steps'] ?? null;
        $steps = [];
        /** @var mixed $ms */
        foreach (\is_array($given) ? $given : [] as $step => $ms) {
            \is_numeric($step) and $steps[(int) $step] = self::ms($ms);
        }

        return new self(
            $lines,
            [
                'base' => self::ms($show['base'] ?? null),
                'perChar' => self::ms($show['perChar'] ?? null),
                'min' => self::ms($show['min'] ?? null),
                'max' => self::ms($show['max'] ?? null),
            ],
            self::ms($value['holdMs'] ?? null),
            $steps,
            self::ms($value['someone'] ?? null),
        );
    }

    public function has(string $line): bool
    {
        return isset($this->lines[$line]);
    }

    /**
     * A catalogue line as the client says it: at finale `step` its `steps` variant, else the scene's, else the
     * ability's phrase `n % length`, else the default; a variant with placeholders by its length with them filled. An
     * unknown id talks as long as any text may.
     *
     * @param array<array-key, mixed> $params The event's params.
     * @param int|null $step The finale step; null outside the final scene.
     * @return Speech
     */
    public function line(string $id, array $params = [], int $scene = 0, ?int $step = null): array
    {
        $line = $this->lines[$id] ?? null;
        if ($line === null) {
            return ['ms' => $this->show['max'], 'hold' => $this->holdMs, 'delay' => 0, 'lead' => 0, 'aside' => false];
        }

        $variant = ($step === null ? null : $line['steps'][$step] ?? null)
            ?? $line['scenes'][$scene] ?? self::ability($line, $params) ?? $line['default'];
        if ($variant['chars'] === null) {
            return ['ms' => $variant['ms'], 'hold' => $variant['hold'], 'delay' => $line['delay'], 'lead' => $variant['lead'], 'aside' => $line['aside']];
        }

        $chars = $variant['chars'];
        foreach ($variant['fill'] as $fill) {
            $chars += $this->fillLength($fill, $params);
        }
        $ms = $this->showOf($chars);

        return ['ms' => $ms, 'hold' => \min($ms, $this->holdMs), 'delay' => $line['delay'], 'lead' => 0, 'aside' => $line['aside']];
    }

    /**
     * The moderator's own text, counted after the trim.
     *
     * @return Speech
     */
    public function text(string $text): array
    {
        $ms = $this->showOf(self::length(self::trim($text)));

        return ['ms' => $ms, 'hold' => \min($ms, $this->holdMs), 'delay' => 0, 'lead' => 0, 'aside' => false];
    }

    /**
     * @return int<0, max> Milliseconds from the step's event the staging plays; 0 for a step the table does not name.
     */
    public function step(int $step): int
    {
        return $this->steps[$step] ?? 0;
    }

    /**
     * @return Line
     */
    private static function lineOf(array $line): array
    {

        $abilities = [];
        /** @var mixed $given */
        $given = $line['abilities'] ?? null;
        /** @var mixed $list */
        foreach (\is_array($given) ? $given : [] as $ability => $list) {
            if (!\is_array($list)) {
                continue;
            }
            $variants = [];
            /** @var mixed $variant */
            foreach ($list as $variant) {
                \is_array($variant) and $variants[] = self::variantOf($variant);
            }
            $variants === [] or $abilities[(string) $ability] = $variants;
        }

        return [
            'default' => self::variantOf($line),
            'delay' => self::ms($line['delay'] ?? null),
            'aside' => ($line['aside'] ?? null) === true,
            'scenes' => self::byKey($line['scenes'] ?? null),
            'steps' => self::byKey($line['steps'] ?? null),
            'abilities' => $abilities,
        ];
    }

    /**
     * @return Variant
     */
    private static function variantOf(array $variant): array
    {
        /** @var mixed $chars */
        $chars = $variant['chars'] ?? null;
        /** @var mixed $given */
        $given = $variant['fill'] ?? null;
        $fill = [];
        /** @var mixed $name */
        foreach (\is_array($given) ? $given : [] as $name) {
            \is_string($name) and $fill[] = $name;
        }

        return [
            'ms' => self::ms($variant['ms'] ?? null),
            'hold' => self::ms($variant['hold'] ?? null),
            'lead' => self::ms($variant['lead'] ?? null),
            'chars' => \is_int($chars) ? \max(0, $chars) : null,
            'fill' => $fill,
        ];
    }

    /**
     * @return array<int, Variant> By scene or by finale step.
     */
    private static function byKey(mixed $given): array
    {
        $variants = [];
        /** @var mixed $variant */
        foreach (\is_array($given) ? $given : [] as $key => $variant) {
            \is_numeric($key) && \is_array($variant) and $variants[(int) $key] = self::variantOf($variant);
        }

        return $variants;
    }

    /**
     * The phrase of an `ability` line: the ability's list at `n` modulo its length, `n` not a whole number ≥ 0 — 0.
     *
     * @param Line $line
     * @return Variant|null
     */
    private static function ability(array $line, array $params): ?array
    {
        /** @var mixed $ability */
        $ability = $params['ability'] ?? null;
        $list = \is_string($ability) || \is_int($ability) ? $line['abilities'][(string) $ability] ?? [] : [];
        if ($list === []) {
            return null;
        }
        $n = self::integer($params['n'] ?? null);

        return $list[($n !== null && $n >= 0 ? $n : 0) % \count($list)];
    }

    /**
     * A whole number as JSON carries it to the client, where `2.0` is an integer too.
     */
    private static function integer(mixed $value): ?int
    {
        return match (true) {
            \is_int($value) => $value,
            \is_float($value) && \is_finite($value) && \floor($value) === $value && \abs($value) < 2 ** 53 => (int) $value,
            default => null,
        };
    }

    /**
     * Like `String.prototype.trim`: Unicode white space and the BOM, not only ASCII.
     */
    private static function trim(string $text): string
    {
        return \preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $text) ?? \trim($text);
    }

    /**
     * Length in UTF-16 code units, as `String.length` counts it on the client: a character past the BMP takes two.
     *
     * @return int<0, max>
     */
    private static function length(string $text): int
    {
        $utf16 = \mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return \max(0, \intdiv(\strlen($utf16 === false ? $text : $utf16), 2));
    }

    /**
     * @return int<0, max>
     */
    private static function ms(mixed $value): int
    {
        return \is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * @return int<0, max> Characters a placeholder takes, as the client fills it: the trimmed name or the stand-in, the
     *         attempt's digits or «?».
     */
    private function fillLength(string $fill, array $params): int
    {
        if ($fill === 'player') {
            /** @var mixed $player */
            $player = $params['player'] ?? null;
            /** @var mixed $name */
            $name = \is_array($player) ? $player['name'] ?? null : null;
            $name = \is_string($name) ? self::trim($name) : '';

            return $name === '' ? $this->someone : self::length($name);
        }
        $attempt = self::integer($params['attempt'] ?? null);

        return $attempt === null ? 1 : \strlen((string) $attempt);
    }

    /**
     * @return int<0, max>
     */
    private function showOf(int $chars): int
    {
        return \max(0, \min($this->show['max'], \max($this->show['min'], $this->show['base'] + $chars * $this->show['perChar'])));
    }
}
