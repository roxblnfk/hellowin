<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Element\Element;
use App\Domain\Ritual\Pumpkin;
use App\Domain\Ritual\RitualSnapshot;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Metrics\Collector;
use Spiral\RoadRunner\Metrics\MetricsInterface;

/**
 * Ritual metrics exported through the RoadRunner metrics plugin.
 *
 * Failures are logged and swallowed: a metrics outage must not break a click or a transition. Catches
 * {@see \Throwable} rather than relying on SuppressExceptionsMetrics, which lets relay (connection) errors through.
 */
final readonly class RitualMetrics
{
    public const CLICKS = 'hellowin_clicks_total';
    public const JOBS_PUSHED = 'hellowin_transition_jobs_pushed_total';
    public const TRANSITIONS = 'hellowin_transitions_total';
    public const SCENE = 'hellowin_scene_stage';
    public const ROUND = 'hellowin_round';
    public const PUMPKIN_STAGE = 'hellowin_pumpkin_stage';
    public const BONFIRE_STAGE = 'hellowin_bonfire_stage';
    public const CIRCLE_STAGE = 'hellowin_circle_stage';

    /** Element kinds of the job and transition counters are the element types. */
    public const KIND_PUMPKIN = Element::TYPE_PUMPKIN;

    public const KIND_BONFIRE = Element::TYPE_BONFIRE;
    public const KIND_CIRCLE = Element::TYPE_CIRCLE;
    public const KIND_SCENE = 'scene';
    public const KIND_FINALE = 'finale';

    /** Gauges of the centrepieces: each its own, to keep the pumpkin stage panel on one scale. */
    private const CENTREPIECE_STAGE = [
        Element::TYPE_BONFIRE => self::BONFIRE_STAGE,
        Element::TYPE_CIRCLE => self::CIRCLE_STAGE,
    ];

    public function __construct(
        private MetricsInterface $metrics,
        private LoggerInterface $logger,
    ) {}

    /**
     * Every worker calls this on boot; a repeated declaration failing on the RoadRunner side is harmless.
     */
    public function declare(): void
    {
        $collectors = [
            self::CLICKS => Collector::counter()->withHelp('Clicks on page elements.')->withLabels('type', 'element'),
            self::JOBS_PUSHED => Collector::counter()->withHelp('Transition jobs pushed to the queue, duplicates included.')->withLabels('kind'),
            self::TRANSITIONS => Collector::counter()->withHelp('Transitions actually applied.')->withLabels('kind'),
            self::SCENE => Collector::gauge()->withHelp('Current scene of the ritual.'),
            self::ROUND => Collector::gauge()->withHelp('Current round of the ritual.'),
            self::PUMPKIN_STAGE => Collector::gauge()->withHelp('Current stage of each pumpkin.')->withLabels('element'),
            self::BONFIRE_STAGE => Collector::gauge()->withHelp('Current stage of the bonfire.'),
            self::CIRCLE_STAGE => Collector::gauge()->withHelp('Current stage of the magic circle.'),
        ];

        foreach ($collectors as $name => $collector) {
            $this->safely(fn() => $this->metrics->declare($name, $collector));
        }
    }

    public function click(Element $element): void
    {
        $this->safely(fn() => $this->metrics->add(self::CLICKS, 1, [$element->type, $element->id]));
    }

    /**
     * @param self::KIND_* $kind
     */
    public function jobPushed(string $kind): void
    {
        $this->safely(fn() => $this->metrics->add(self::JOBS_PUSHED, 1, [$kind]));
    }

    /**
     * @param Element::TYPE_* $type
     */
    public function moved(string $type, Pumpkin $element): void
    {
        $this->safely(fn() => $this->metrics->add(self::TRANSITIONS, 1, [$type]));
        $this->stage($type, $element);
    }

    public function finaleMoved(): void
    {
        $this->safely(fn() => $this->metrics->add(self::TRANSITIONS, 1, [self::KIND_FINALE]));
    }

    public function sceneMoved(int $scene): void
    {
        $this->safely(fn() => $this->metrics->add(self::TRANSITIONS, 1, [self::KIND_SCENE]));
        $this->safely(fn() => $this->metrics->set(self::SCENE, $scene));
    }

    /**
     * Sets every gauge from the stored state: on boot, since the state outlives the RoadRunner process, and after a reset.
     */
    public function state(RitualSnapshot $snapshot): void
    {
        $this->safely(fn() => $this->metrics->set(self::ROUND, $snapshot->round));
        $this->safely(fn() => $this->metrics->set(self::SCENE, $snapshot->scene));
        foreach ($snapshot->pumpkins as $pumpkin) {
            $this->stage(Element::TYPE_PUMPKIN, $pumpkin);
        }
        foreach (Element::CENTREPIECES as $type) {
            $centrepiece = $snapshot->centrepiece($type);
            $centrepiece === null or $this->stage($type, $centrepiece);
        }
    }

    /**
     * @param Element::TYPE_* $type
     */
    private function stage(string $type, Pumpkin $element): void
    {
        $this->safely(fn() => $type === Element::TYPE_PUMPKIN
            ? $this->metrics->set(self::PUMPKIN_STAGE, $element->stage, [$element->id])
            : $this->metrics->set(self::CENTREPIECE_STAGE[$type], $element->stage));
    }

    /**
     * @param \Closure(): void $operation
     */
    private function safely(\Closure $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->logger->warning('[Metrics] {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
