<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Element\Element;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Metrics\Collector;
use Spiral\RoadRunner\Metrics\MetricsInterface;

/**
 * Game metrics exported through the RoadRunner metrics plugin.
 *
 * Failures are logged and swallowed: a metrics outage must not break a click. Catches {@see \Throwable}
 * rather than relying on SuppressExceptionsMetrics, which lets relay (connection) errors through.
 */
final readonly class ClickMetrics
{
    public const CLICKS = 'hellowin_clicks_total';
    public const STAGE = 'hellowin_stage';

    public function __construct(
        private MetricsInterface $metrics,
        private LoggerInterface $logger,
    ) {}

    /**
     * Every worker calls this on boot; a repeated declaration failing on the RoadRunner side is harmless.
     */
    public function declare(): void
    {
        $this->safely(fn() => $this->metrics->declare(
            self::CLICKS,
            Collector::counter()->withHelp('Clicks on page elements.')->withLabels('type', 'element'),
        ));
        $this->safely(fn() => $this->metrics->declare(
            self::STAGE,
            Collector::gauge()->withHelp('Current game stage.'),
        ));
    }

    public function click(Element $element): void
    {
        $this->safely(fn() => $this->metrics->add(self::CLICKS, 1, [$element->type, $element->id]));
    }

    public function stage(int $stage): void
    {
        $this->safely(fn() => $this->metrics->set(self::STAGE, $stage));
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
