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
        $msg = $result->payload['msg'];
        $this->assertIsArray($msg);

        $this->assertArrayNotHasKey('API_KEY', $msg);
        $this->assertArrayNotHasKey('SECRET', $msg);
        $this->assertSame('ok', $msg['data']);
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
        $nested = $result->payload['nested'];
        $this->assertIsArray($nested);

        $this->assertSame('TOKEN: [HIDDEN]', $nested['first']);
        $this->assertSame('TOKEN: [HIDDEN]', $nested['other']);
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
        // afterCapture is a no-op sink for this hook — not throwing is the point.
        $this->addToAssertionCount(1);
    }
}
