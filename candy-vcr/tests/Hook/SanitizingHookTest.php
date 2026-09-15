<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Hook;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * E724: a mistyped pattern used to make every preg_replace() return NULL,
     * and the ?? fallback then wrote the ORIGINAL unsanitized value into the
     * cassette — silently defeating the hook's whole purpose. The ctor door
     * probe must reject it loudly, at construction, never at save time.
     */
    #[DataProvider('malformedPatternProvider')]
    public function testMalformedPatternThrowsAtTheDoor(string $pattern): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid PCRE pattern');

        new SanitizingHook([], [$pattern => '[REDACTED]']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPatternProvider(): iterable
    {
        // The usage-docblock example minus its delimiters — the foot-gun.
        yield 'missing-delimiters' => ['password:\s*\S+'];
        yield 'empty-pattern' => [''];
        yield 'unclosed-group' => ['/(password(\s*\S+/'];
        yield 'unknown-modifier' => ['/password/z'];
    }

    /**
     * The numeric-key path: PHP casts the string pattern "0" to an int array
     * key; the probe must cast it back rather than TypeError deep in the
     * recursion, and still refuse it at the door.
     */
    public function testNumericPatternKeyIsRefusedNotTypeErrored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid PCRE pattern');

        new SanitizingHook([], ['0' => '[REDACTED]']);
    }

    /**
     * E724 counterpart: valid-but-tricky pairs must sail through the door
     * (empty replacement, lookbehind, bounded repetition) and still sanitize.
     */
    public function testValidTrickyPatternsPassTheDoorAndSanitize(): void
    {
        $hook = new SanitizingHook([], [
            '~(?<=Bearer\s)[A-Za-z0-9._\-]+~' => '[TOKEN]',
            '/sk-[A-Za-z0-9]{20,}/' => '',
        ]);

        $event = new Event(
            t: 0.1,
            kind: EventKind::Output,
            payload: ['h' => 'Authorization: Bearer abc.9_-X', 'k' => 'key sk-abcdefghijklmnopqrstuv'],
        );

        $result = $hook->beforeSave($event);

        $this->assertSame('Authorization: Bearer [TOKEN]', $result->payload['h']);
        $this->assertSame('key ', $result->payload['k']);
    }

    /**
     * E724 "null is not a path": preg_replace() signals PCRE errors with NULL
     * (never FALSE — that sentinel belongs to preg_match()). Even after the
     * door probe, a COMPILED pattern can still hit the pcre.backtrack_limit on
     * a hostile payload; the value must survive untouched — never a null,
     * never a false, always the original string.
     */
    public function testBacktrackLimitExhaustionKeepsOriginalValueNeverNull(): void
    {
        $previous = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100');
        try {
            $hostile = str_repeat('a', 30) . '!';
            // Valid at the door (compiles, probes '' fine); catastrophic here.
            $hook = new SanitizingHook([], ['/(a+)+$/' => 'b']);

            $result = $hook->beforeSave(new Event(t: 0.1, kind: EventKind::Input, payload: ['b' => $hostile]));

            $this->assertIsString($result->payload['b']);
            $this->assertSame($hostile, $result->payload['b']);
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }
}
