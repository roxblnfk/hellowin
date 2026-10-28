<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * How long the owl talks, by the table the frontend keeps in `public/autopilot.json` (docs/spec/protocol.md,
 * «Автопилот»): the texts are on the frontend, the server knows only their durations. Also how long each finale
 * step's staging plays.
 *
 * A line's `lead` is how far into it its last part starts: for an instruction, the part that opens the step's input.
 *
 * @psalm-type Speech = array{ms: int<0, max>, hold: int<0, max>, delay: int<0, max>, lead: int<0, max>}
 */
final readonly class SpeechTable
{
    /**
     * @param array<non-empty-string, Speech> $lines Catalogue lines by id.
     * @param array{base: int<0, max>, perChar: int<0, max>, min: int<0, max>, max: int<0, max>} $show The moderator's
     *        own text: milliseconds by its length.
     * @param int<0, max> $holdMs Milliseconds the own text holds the bubble at most.
     * @param array<int, int<0, max>> $steps Milliseconds a finale step's staging plays, by step.
     */
    public function __construct(
        private array $lines = [],
        private array $show = ['base' => 0, 'perChar' => 0, 'min' => 0, 'max' => 0],
        private int $holdMs = 0,
        private array $steps = [],
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
     * Anything malformed counts as zero: a broken entry only lets the autopilot move earlier.
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
                $lines[$id] = [
                    'ms' => self::ms($line['ms'] ?? null),
                    'hold' => self::ms($line['hold'] ?? null),
                    'delay' => self::ms($line['delay'] ?? null),
                    'lead' => self::ms($line['lead'] ?? null),
                ];
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
        );
    }

    public function has(string $line): bool
    {
        return isset($this->lines[$line]);
    }

    /**
     * A catalogue line; an unknown id talks as long as any text may.
     *
     * @return Speech
     */
    public function line(string $id): array
    {
        return $this->lines[$id] ?? ['ms' => $this->show['max'], 'hold' => $this->holdMs, 'delay' => 0, 'lead' => 0];
    }

    /**
     * The moderator's own text, counted in characters after the trim.
     *
     * @return Speech
     */
    public function text(string $text): array
    {
        $ms = \min($this->show['max'], \max($this->show['min'], $this->show['base'] + \mb_strlen(\trim($text)) * $this->show['perChar']));

        return ['ms' => $ms, 'hold' => \min($ms, $this->holdMs), 'delay' => 0, 'lead' => 0];
    }

    /**
     * @return int<0, max> Milliseconds from the step's event the staging plays; 0 for a step the table does not name.
     */
    public function step(int $step): int
    {
        return $this->steps[$step] ?? 0;
    }

    /**
     * @return int<0, max>
     */
    private static function ms(mixed $value): int
    {
        return \is_int($value) && $value > 0 ? $value : 0;
    }
}
