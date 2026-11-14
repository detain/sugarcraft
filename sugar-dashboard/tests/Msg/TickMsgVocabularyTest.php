<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Msg;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Dashboard\Msg\TickMsg;
use SugarCraft\Dashboard\Modules\Clock\ClockModule;
use SugarCraft\Dashboard\Modules\Generic\GenericModule;
use SugarCraft\Dashboard\Modules\System\SystemModule;
use SugarCraft\Dashboard\Modules\Uptime\UptimeModule;

/**
 * sugar-dashboard-096: the five per-module tick markers collapsed onto
 * one shared vocabulary type; these pins are the acceptance proof that
 * the legacy names still resolve and that routing identity rides on the
 * message instead of on five byte-identical classes.
 */
final class TickMsgVocabularyTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function legacyNameProvider(): array
    {
        return [
            'clock' => ['SugarCraft\Dashboard\Modules\Clock\TickMsg'],
            'uptime' => ['SugarCraft\Dashboard\Modules\Uptime\TickMsg'],
            'weather' => ['SugarCraft\Dashboard\Modules\Weather\TickMsg'],
            'generic' => ['SugarCraft\Dashboard\Modules\Generic\TickMsg'],
            'system refresh' => ['SugarCraft\Dashboard\Modules\System\RefreshMsg'],
        ];
    }

    /**
     * @dataProvider legacyNameProvider
     */
    public function testLegacyMarkerNameResolvesToTheSharedType(string $legacy): void
    {
        self::assertTrue(class_exists($legacy), $legacy . ' must still resolve');
        self::assertSame(TickMsg::class, (new \ReflectionClass($legacy))->getName());

        $instance = new $legacy();
        self::assertInstanceOf(TickMsg::class, $instance);
        self::assertNull($instance->source, 'constructing through a legacy name yields a broadcast tick');
    }

    public function testAddressedTickServesOnlyItsModule(): void
    {
        $clock = new ClockModule();
        $uptime = new UptimeModule();

        $tick = TickMsg::for('clock');
        self::assertTrue($tick->isFor('clock'));
        self::assertFalse($tick->isFor('uptime'));

        [$keptUptime, $cmd] = $uptime->update($tick);
        self::assertNull($cmd, 'a tick for another module must not arm a follow-up');
        self::assertEquals($uptime->state(), $keptUptime->state(), 'a tick for another module must not re-sample');

        [$clockNext, $clockCmd] = $clock->update($tick);
        self::assertNotSame($clock, $clockNext, 'the addressed tick must drive its module');
        self::assertNotNull($clockCmd);
    }

    public function testBroadcastTickServesEveryTickingModule(): void
    {
        $broadcast = new TickMsg();
        self::assertTrue($broadcast->isFor('clock'));
        self::assertTrue($broadcast->isFor('uptime'));

        $clock = new ClockModule();
        $uptime = new UptimeModule();
        self::assertNotSame($clock, $clock->update($broadcast)[0]);
        self::assertNotSame($uptime, $uptime->update($broadcast)[0]);
    }

    public function testEveryModuleProducerMintsItsAddressedTick(): void
    {
        $modules = [
            'clock' => new ClockModule(),
            'system' => new SystemModule(),
            'uptime' => new UptimeModule(),
            'generic' => new GenericModule('true'),
        ];

        foreach ($modules as $name => $module) {
            $producerMethod = new ReflectionMethod($module, 'tickProducer');
            $producerMethod->setAccessible(true);
            $producer = $producerMethod->invoke($module);
            self::assertNotNull($producer, $name . ' must declare a tick producer');

            $msg = $producer();
            self::assertInstanceOf(TickMsg::class, $msg);
            self::assertSame($name, $msg->source, $name . "'s producer must address its own ticks");
            self::assertTrue($msg->isFor($name));
        }
    }
}
