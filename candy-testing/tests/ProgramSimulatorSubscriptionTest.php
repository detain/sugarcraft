<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Testing\ProgramSimulator;

/**
 * The runtime delivers every message a subscription produces; the simulator
 * must not silently drop any, nor carry them into a later run().
 */
final class ProgramSimulatorSubscriptionTest extends TestCase
{
    public function testSubscriptionFiresAfterInitAndAfterEverySentMessage(): void
    {
        $sim = ProgramSimulator::for(new RecordingModel(subscribed: true))
            ->send(new KeyMsg(KeyType::Char, rune: 'a'))
            ->send(new KeyMsg(KeyType::Char, rune: 'b'));

        $this->assertSame('*a*b*', $sim->run()->view);
    }

    public function testSubscriptionMessagesAfterSentMessagesAreNotDropped(): void
    {
        $sim = ProgramSimulator::for(new SubscriptionProducingModel(0))
            ->send(new KeyMsg(KeyType::Char, rune: 'a'))
            ->send(new KeyMsg(KeyType::Char, rune: 'b'));

        $this->assertSame(3, $sim->run()->model->count());
    }

    public function testRunIsRepeatable(): void
    {
        $sim = ProgramSimulator::for(new RecordingModel(subscribed: true))
            ->send(new KeyMsg(KeyType::Char, rune: 'a'));

        $first = $sim->run();
        $second = $sim->run();

        $this->assertSame('*a*', $first->view);
        $this->assertSame($first->view, $second->view);
        $this->assertSame($first->output, $second->output);
    }

    public function testSelfFeedingSubscriptionStillTerminates(): void
    {
        // Messages a subscription produces do not fire it again, so a
        // subscription producing on every call leaves run() bounded.
        $sim = ProgramSimulator::for(new RecordingModel(subscribed: true));

        $this->assertSame('*', $sim->run()->view);
    }
}
