<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use Spiral\Testing\Attribute\Config;
use Testo\Assert;
use Testo\Test;
use Tests\TestCase;

#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
final class HowItWorksPageTest extends TestCase
{
    public function idempotencyTabControlsAnExistingPanel(): void
    {
        $body = $this->page();

        Assert::string($body)->contains('id="tab-idempotency" href="#idempotency" aria-controls="idempotency"');
        Assert::string($body)->contains('<section class="how__section" id="idempotency" role="tabpanel" aria-labelledby="tab-idempotency">');
    }

    public function everyTabControlsAnExistingPanel(): void
    {
        $body = $this->page();

        \preg_match_all('/<a role="tab" id="([^"]+)"[^>]*\baria-controls="([^"]+)"/', $body, $tabs, \PREG_SET_ORDER);
        Assert::count($tabs, 8);

        foreach ($tabs as [, $tab, $panel]) {
            Assert::string($body)->contains(\sprintf('id="%s" role="tabpanel" aria-labelledby="%s"', $panel, $tab));
        }
    }

    public function describesTheCurrentEngine(): void
    {
        $body = $this->page();

        $terms = [
            'HELLOWIN_CLICKS_PER_STAGE',
            'HELLOWIN_SCENE_STAGE_MAX',
            'SnapshotFactory',
            "rpc('state'",
            'LockstepPolicy',
            'pumpkin:{id}',
            'ResetService',
            'TransitionPumpkin',
            'TransitionScene',
            'IdempotencyInterceptor',
            'RedisAtMostOnceConfig',
            'SET … NX EX 300',
            '{round}:{pumpkin}:{toStage}',
            '{round}:scene:{toScene}',
            'hellowin:amo:',
            'Valkey',
            'hellowin_transition_jobs_pushed_total',
            'hellowin_transitions_total',
            'hellowin_pumpkin_stage',
            'markReady',
            'scene.next',
            'include_connection_meta',
            'HELLOWIN_MODERATOR_SECRET',
            'CentrifugoPresence',
            'HELLOWIN_WALK_LEAVE_AFTER',
            'flyer:{id}',
            'RevealBuilder',
            '{round}:roll:{player}:{attempt}',
            'hellowin:dice',
            'HELLOWIN_DICE_COOLDOWN_MS',
            'HELLOWIN_DICE_SECRET',
        ];
        foreach ($terms as $term) {
            Assert::string($body)->contains($term);
        }
    }

    public function dropsTheOldModel(): void
    {
        $body = $this->page();

        foreach (['GameState', 'ClickMetrics', 'hellowin:state', 'GameBusyException', "type:'emoji'"] as $term) {
            Assert::string($body)->notContains($term);
        }
    }

    private function page(): string
    {
        return (string) $this->fakeHttp()->get('/how-it-works')
            ->assertOk()
            ->getOriginalResponse()
            ->getBody();
    }
}
