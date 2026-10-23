<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Story\EveningTally;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeValkey;

#[Test]
#[Covers(EveningTally::class)]
final class EveningTallyTest
{
    public function eachCountIsOneIncrAndTheFirstSetsTheTtl(): void
    {
        $valkey = new FakeValkey();
        $tally = new EveningTally($valkey);

        $tally->add(EveningTally::CALLS);
        $valkey->ttls = [];
        $tally->add(EveningTally::CALLS);
        $tally->add(EveningTally::DROPPED);

        Assert::same($valkey->values, ['hellowin:tally:calls' => '2', 'hellowin:tally:dropped' => '1']);
        Assert::same(\array_keys($valkey->ttls), ['hellowin:tally:dropped']);
        Assert::same([$tally->count(EveningTally::CALLS), $tally->count(EveningTally::DROPPED)], [2, 1]);
    }

    public function clearStartsBothCountsOver(): void
    {
        $valkey = new FakeValkey();
        $tally = new EveningTally($valkey);
        $tally->add(EveningTally::CALLS);
        $tally->add(EveningTally::DROPPED);

        $tally->clear();
        $tally->add(EveningTally::CALLS);

        Assert::same([$tally->count(EveningTally::CALLS), $tally->count(EveningTally::DROPPED)], [1, 0]);
        Assert::same(\array_keys($valkey->ttls), ['hellowin:tally:calls']);
    }

    public function valkeyDownCountsNothingAndThrowsNothing(): void
    {
        $valkey = new FakeValkey();
        $tally = new EveningTally($valkey);
        $tally->add(EveningTally::CALLS);
        $valkey->down = true;

        $tally->add(EveningTally::CALLS);
        $tally->clear();

        Assert::same($tally->count(EveningTally::CALLS), 0);
        $valkey->down = false;
        Assert::same($tally->count(EveningTally::CALLS), 1);
    }
}
