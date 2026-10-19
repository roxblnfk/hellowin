<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Config\HellowinConfig;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Tests\TestCase;

#[Test]
final class HellowinConfigTest extends TestCase
{
    public function shippedElementsAreOnScreen(): void
    {
        $elements = $this->getContainer()->get(HellowinConfig::class)->getElements();

        Assert::int(\count($elements))->greaterThanOrEqual(8);
        foreach ($elements as $element) {
            Assert::numeric($element->x)->between(0, 100);
            Assert::numeric($element->y)->between(0, 100);
            Assert::notBlank($element->emojis);
        }
    }

    #[Env('HELLOWIN_CLICKS_PER_STAGE', '2')]
    public function clicksPerStageComesFromEnv(): void
    {
        $config = $this->getContainer()->get(HellowinConfig::class);

        Assert::same($config->getClicksPerStage(), 2);
    }

    #[Env('HELLOWIN_CLICKS_PER_STAGE', 'five')]
    public function invalidEnvValueIsAConfigError(): never
    {
        $config = $this->getContainer()->get(HellowinConfig::class);

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Config "hellowin.clicks_per_stage" (env HELLOWIN_CLICKS_PER_STAGE) must be a positive integer, got "five".');

        $config->getClicksPerStage();
    }
}
