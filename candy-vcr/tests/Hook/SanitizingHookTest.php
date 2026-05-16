<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Hook;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Hook\SanitizingHook;

final class SanitizingHookTest extends TestCase
{
    public function testRemoveKeys(): void
    {
        $hook = new SanitizingHook(['API_KEY', 'SECRET']);

        $event = new Event(
            t: 0.1,
            kind: EventKind::Input,
            payload: ['msg' => ['API_KEY' => 'secret123', 'SECRET' => 'hidden', 'data' => 'ok']],
        );

        $result = $hook->beforeSave($event);

        $this->assertArrayNotHasKey('API_KEY', $result->payload['msg']);
        $this->assertArrayNotHasKey('SECRET', $result->payload['msg']);
        $this->assertSame('ok', $result->payload['msg']['data']);
    }

    public function testReplacePatterns(): void
    {
        $hook = new SanitizingHook([], ['/password:\s*\S+/' => 'password: [REDACTED]']);

        $event = new Event(
            t: 0.1,
            kind: EventKind::Output,
            payload: ['b' => 'password: mysecret123 and more text'],
        );

        $result = $hook->beforeSave($event);

        $this->assertSame('password: [REDACTED] and more text', $result->payload['b']);
    }

    public function testRecursiveReplacement(): void
    {
        $hook = new SanitizingHook([], ['/TOKEN:\s*\w+/' => 'TOKEN: [HIDDEN]']);

        $payload = [
            'nested' => [
                'first' => 'TOKEN: abc123',
                'other' => 'TOKEN: xyz789',
            ],
            'top' => 'TOKEN: topsecret',
        ];
        $event = new Event(t: 0.1, kind: EventKind::Input, payload: $payload);

        $result = $hook->beforeSave($event);

        $this->assertSame('TOKEN: [HIDDEN]', $result->payload['nested']['first']);
        $this->assertSame('TOKEN: [HIDDEN]', $result->payload['nested']['other']);
        $this->assertSame('TOKEN: [HIDDEN]', $result->payload['top']);
    }

    public function testNoChangesReturnsSameEvent(): void
    {
        $hook = new SanitizingHook();

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'hello']);

        $result = $hook->beforeSave($event);

        $this->assertSame($event, $result);
    }

    public function testAfterCaptureIsNoOp(): void
    {
        $hook = new SanitizingHook();

        $event = new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'hello']);

        $hook->afterCapture($event);
        $this->assertTrue(true);
    }
}
