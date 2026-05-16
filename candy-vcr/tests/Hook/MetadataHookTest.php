<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Hook;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Hook\MetadataHook;

/**
 * @covers \SugarCraft\Vcr\Hook\MetadataHook
 */
final class MetadataHookTest extends TestCase
{
    public function testInjectsMetadataOnFirstOutputEvent(): void
    {
        $hook = new MetadataHook(['CI_RUN_ID' => '123', 'test' => 'MyTest']);

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'hello']);

        $result = $hook->beforeSave($event);

        $this->assertArrayHasKey('__meta', $result->payload);
        $this->assertSame('123', $result->payload['__meta']['CI_RUN_ID']);
        $this->assertSame('MyTest', $result->payload['__meta']['test']);
    }

    public function testOnlyInjectsOnFirstOutputEvent(): void
    {
        $hook = new MetadataHook(['key' => 'value']);

        $resizeEvent = new Event(t: 0.1, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]);
        $outputEvent1 = new Event(t: 0.2, kind: EventKind::Output, payload: ['b' => 'first']);
        $outputEvent2 = new Event(t: 0.3, kind: EventKind::Output, payload: ['b' => 'second']);

        $result1 = $hook->beforeSave($resizeEvent);
        $this->assertArrayNotHasKey('__meta', $result1->payload);

        $result2 = $hook->beforeSave($outputEvent1);
        $this->assertArrayHasKey('__meta', $result2->payload);

        $result3 = $hook->beforeSave($outputEvent2);
        $this->assertArrayNotHasKey('__meta', $result3->payload);
    }

    public function testNonOutputEventsArePassedThrough(): void
    {
        $hook = new MetadataHook(['key' => 'value']);

        $inputEvent = new Event(t: 0.1, kind: EventKind::Input, payload: ['b' => 'a']);
        $resizeEvent = new Event(t: 0.2, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]);
        $quitEvent = new Event(t: 0.3, kind: EventKind::Quit, payload: []);

        $this->assertSame($inputEvent, $hook->beforeSave($inputEvent));
        $this->assertSame($resizeEvent, $hook->beforeSave($resizeEvent));
        $this->assertSame($quitEvent, $hook->beforeSave($quitEvent));
    }

    public function testAfterCaptureIsNoOp(): void
    {
        $hook = new MetadataHook();

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'hello']);

        $hook->afterCapture($event);
        $this->assertTrue(true);
    }
}
