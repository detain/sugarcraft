<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\McpMessage;

/**
 * Codec pins lifted from sugar-crush/tests (McpMessageResultTypeTest and
 * friends) plus coercion edges. The null-result sentinel family is the core:
 * it exists because a widened-but-lossy `result` once killed the whole MCP
 * subsystem via TypeError.
 */
final class McpMessageTest extends TestCase
{
    public function testParsesARequestLine(): void
    {
        $message = McpMessage::parse('{"jsonrpc":"2.0","id":"7","method":"tools/list","params":{"a":1}}');

        self::assertNotNull($message);
        self::assertSame('7', $message->id);
        self::assertSame('tools/list', $message->method);
        self::assertSame(['a' => 1], $message->params);
        self::assertTrue($message->isRequest());
        self::assertFalse($message->isResponse());
        self::assertFalse($message->isNotification());
        self::assertFalse($message->resultSet);
    }

    public function testIntegerWireIdsAreCoercedToTheirStringForm(): void
    {
        $message = McpMessage::parse('{"jsonrpc":"2.0","id":7,"method":"ping"}');

        self::assertNotNull($message);
        self::assertSame('7', $message->id);
    }

    public function testMethodWithoutIdIsANotification(): void
    {
        $message = McpMessage::parse('{"jsonrpc":"2.0","method":"notifications/progress"}');

        self::assertNotNull($message);
        self::assertTrue($message->isNotification());
        self::assertFalse($message->isRequest());
        self::assertNull($message->id);
    }

    public function testScalarResultsParseInsteadOfRaising(): void
    {
        foreach (['true', 'false', '"s"', '5', '1.5'] as $literal) {
            $message = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":' . $literal . '}');

            self::assertNotNull($message, "scalar result {$literal} must parse");
            self::assertTrue($message->resultSet);
            self::assertTrue($message->isResponse());
            self::assertFalse($message->isError());
        }
    }

    public function testNullResultIsADistinctRepresentableShape(): void
    {
        $withNull = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":null}');

        self::assertNotNull($withNull, 'result:null is a legal success answer');
        self::assertTrue($withNull->resultSet);
        self::assertNull($withNull->result);

        $withoutResult = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":-1,"message":"x"}}');

        self::assertNotNull($withoutResult);
        self::assertFalse($withoutResult->resultSet, 'absence must stay distinguishable from null');
    }

    public function testBareEnvelopeWithoutAnyDiscriminatorIsRejected(): void
    {
        self::assertNull(McpMessage::parse('{"jsonrpc":"2.0"}'));
        self::assertNull(McpMessage::parse('{"jsonrpc":"2.0","id":"1"}'));
    }

    public function testNonJsonRpcShapesAreRejected(): void
    {
        self::assertNull(McpMessage::parse('not json at all'));
        self::assertNull(McpMessage::parse('[1,2,3]'));
        self::assertNull(McpMessage::parse('"just a string"'));
        self::assertNull(McpMessage::parse('{"id":"1","method":"x"}'), 'missing jsonrpc member');
        self::assertNull(McpMessage::parse('{"jsonrpc":"1.0","id":"1","method":"x"}'), 'wrong version');
    }

    public function testErrorAccessors(): void
    {
        $message = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":-32601,"message":"Method not found"}}');

        self::assertNotNull($message);
        self::assertTrue($message->isError());
        self::assertSame(-32601, $message->errorCode());
        self::assertSame('Method not found', $message->errorMessage());
    }

    /**
     * FIX #2: a malformed third-party error object must read as "nothing
     * reported", never as a cast fabrication — `(int)'abc'` is 0 and
     * `(string)['x']` is 'Array' with a Warning. Mirrors the sugar-crush twin
     * pins: only a real int code / real string message survives.
     */
    public function testMalformedErrorMembersReadAsAbsentInsteadOfCast(): void
    {
        // Raw JSON literal text is injected into the envelope rather than
        // round-tripped through json_encode(): encode collapses `-32601.0`
        // to int text on this host, and the whole point of these rows is
        // that a float token on the wire is not a code. (Carried from the
        // sugar-crush original with the lane-A2 fold.)
        foreach (['"abc"', '"-32601"', 'true', 'false', '1.5', '-32601.0', '["x"]', '{"a":1}', 'null'] as $literal) {
            $message = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":' . $literal . ',"message":"m"}}');
            self::assertNotNull($message, $literal);
            self::assertTrue($message->isError(), $literal);
            self::assertNull($message->errorCode(), $literal);
        }

        $absent = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"message":"m"}}');
        self::assertNotNull($absent);
        self::assertNull($absent->errorCode(), 'an absent code is no code');

        $zero = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":0,"message":"m"}}');
        self::assertNotNull($zero);
        self::assertSame(0, $zero->errorCode(), 'a real zero code is still a code');

        // A code that IS valid still parses alongside a malformed message.
        $control = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":-1,"message":"ok"}}');
        self::assertNotNull($control);
        self::assertSame(-1, $control->errorCode());

        foreach (['5', '1.5', 'true', '["x"]', '{"a":1}', 'null'] as $literal) {
            $message = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":-1,"message":' . $literal . '}}');
            self::assertNotNull($message, $literal);
            self::assertNull($message->errorMessage(), $literal);
        }

        $noMessage = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":-1}}');
        self::assertNotNull($noMessage);
        self::assertNull($noMessage->errorMessage(), 'an absent message is no message');
    }

    public function testFactoriesRoundTripThroughTheWire(): void
    {
        $request = McpMessage::request('42', 'tools/call', ['name' => 'ping']);
        $parsed = McpMessage::parse($request->toJson());

        self::assertNotNull($parsed);
        self::assertSame('42', $parsed->id);
        self::assertTrue($parsed->isRequest());

        $notification = McpMessage::notification('initialized');
        $parsedNotification = McpMessage::parse($notification->toJson());

        self::assertNotNull($parsedNotification);
        self::assertTrue($parsedNotification->isNotification());

        $success = McpMessage::success('42', ['ok' => true]);
        self::assertTrue(McpMessage::parse($success->toJson())?->resultSet);

        $error = McpMessage::error('42', -32000, 'boom', ['detail' => 1]);
        $parsedError = McpMessage::parse($error->toJson());

        self::assertNotNull($parsedError);
        self::assertTrue($parsedError->isError());
        self::assertSame(['detail' => 1], $parsedError->error['data'] ?? null);
    }

    /**
     * The error `data` member is `mixed` on the JSON-RPC wire — sugar-crush's
     * ACP error relay forwards a peer's scalar `data` verbatim, so the factory
     * must carry `'data'`, not only `['k' => 'v']`.
     */
    public function testErrorFactoryCarriesAScalarDataPayload(): void
    {
        $message = McpMessage::error('9', -32600, 'Invalid Request', 'data');

        self::assertTrue($message->isError());
        self::assertSame('data', $message->error['data']);
        self::assertSame('data', McpMessage::parse($message->toJson())?->error['data']);
    }

    public function testToJsonPreservesNullResultsAndOmitsForeignKeys(): void
    {
        self::assertStringContainsString('"result":null', McpMessage::success('1', null)->toJson());
        self::assertStringNotContainsString('result', McpMessage::error('1', -1, 'x')->toJson());
        self::assertStringNotContainsString('method', McpMessage::success('1', 5)->toJson());

        $notificationJson = McpMessage::notification('m')->toJson();
        self::assertStringNotContainsString('id', $notificationJson);
        self::assertStringNotContainsString('params', $notificationJson);
    }

    public function testToArrayIsTheUnconditionalInspectionView(): void
    {
        $array = McpMessage::notification('m')->toArray();

        foreach (['jsonrpc', 'id', 'method', 'params', 'result', 'resultSet', 'error', 'isNotification'] as $key) {
            self::assertArrayHasKey($key, $array, "toArray() consumers navigate missing keys as null, not absent");
        }
    }

    /**
     * `(string) false` was the old answer: a BLANK wire line every server
     * ignores, after which callTool() waited, deadline-less, for a reply to a
     * request nobody saw.
     *
     * @return iterable<string, array{0: McpMessage, 1: string}>
     */
    public static function unencodableEnvelopes(): iterable
    {
        yield 'INF argument (a model\'s 1e999)' => [McpMessage::request('7', 'tools/call', ['arguments' => ['n' => INF]]), '"tools/call" (id 7)'];
        yield 'NAN argument' => [McpMessage::request('8', 'tools/call', ['arguments' => ['n' => NAN]]), '"tools/call" (id 8)'];
        yield 'invalid UTF-8' => [McpMessage::request('9', 'resources/read', ['uri' => "\xff\xfe"]), '"resources/read" (id 9)'];
        yield 'notification' => [McpMessage::notification('notifications/progress', ['progress' => INF]), '"notifications/progress"'];
        yield 'success response' => [McpMessage::success('10', INF), 'response (id 10)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unencodableEnvelopes')]
    public function testToJsonRefusesAnUnencodableEnvelopeInsteadOfEmittingABlankLine(McpMessage $message, string $named): void
    {
        $raised = null;
        try {
            $json = $message->toJson();
        } catch (\InvalidArgumentException $failure) {
            $raised = $failure;
        }

        self::assertNotNull($raised, 'toJson() returned ' . var_export($json ?? null, true) . ' instead of throwing');
        self::assertStringContainsString($named, $raised->getMessage());
        self::assertInstanceOf(\JsonException::class, $raised->getPrevious());
    }

    // =========================================================================
    // The result type matrix — moved with the lane-A2 fold from
    // sugar-crush/tests/MCP/McpMessageResultTypeTest.php (the consumer rows
    // that drive a real server child stay there).
    // =========================================================================

    /**
     * ONE FIXTURE PER JSON TYPE `result` CAN HOLD. Each row is the literal that
     * goes into the wire text and the value `->result` must come back as.
     *
     * `assertSame()` on the value, not merely "did not throw": a `parse()` that
     * silently coerced every scalar to `null` — or to `[]` — would satisfy "no
     * exception" while destroying the payload, and the payload is the whole
     * point of the field.
     *
     * ZERO IS A ROW BECAUSE THIS ALPHABET WAS ONCE WRITTEN TO THE SHAPES
     * ALREADY KNOWN. Its first cut held three non-zero numbers and no zero, and
     * a falsy-coalescing bug in a `callTool()`-shaped consumer that erased
     * exactly `0` therefore passed every row.
     *
     * `{}` AND `[]` ARE ONE ROW VALUE-WISE, NOT TWO: `json_decode('{}', true)`
     * and `json_decode('[]', true)` are both PHP `[]`, and the round-trip
     * consumer is handed only the decoded value. Recorded rather than papered
     * over.
     *
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function legalResultTypes(): iterable
    {
        yield 'boolean true' => ['true', true];
        yield 'boolean false' => ['false', false];
        yield 'integer' => ['5', 5];
        yield 'negative integer' => ['-17', -17];
        yield 'zero' => ['0', 0];
        yield 'float' => ['1.5', 1.5];
        yield 'string' => ['"pong"', 'pong'];
        yield 'empty string' => ['""', ''];
        yield 'empty array' => ['[]', []];
        yield 'list' => ['[1,2,3]', [1, 2, 3]];
        yield 'object' => ['{"a":1}', ['a' => 1]];
        yield 'empty object' => ['{}', []];
        yield 'null' => ['null', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legalResultTypes')]
    public function testParseAcceptsEveryJsonTypeAResultMayLegallyHold(string $literal, mixed $expected): void
    {
        $message = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":' . $literal . '}');

        self::assertNotNull(
            $message,
            'parse() rejected a conforming JSON-RPC response whose result was ' . $literal,
        );
        self::assertSame(
            $expected,
            $message->result,
            'result ' . $literal . ' did not survive parse() intact',
        );
        self::assertSame('1', $message->id);
        self::assertTrue($message->isResponse());
        self::assertFalse($message->isError());
    }

    /**
     * `"result": null` PARSES, AND IS TOLD APART FROM AN ABSENT `result` — all
     * four polarities, so neither a blanket-accept nor a blanket-reject passes:
     * a `resultSet` hard-wired to `true` would satisfy "null parses" while
     * quietly making `{"jsonrpc":"2.0"}` parse too.
     */
    public function testANullResultParsesAndIsDistinguishedFromAnAbsentOne(): void
    {
        $nullResult = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":null}');
        self::assertNotNull(
            $nullResult,
            'a conforming JSON-RPC response whose result is null was rejected — the resultSet '
            . 'sentinel is not being consulted',
        );
        self::assertTrue($nullResult->resultSet, 'the result key was present and is not recorded');
        self::assertNull($nullResult->result);
        self::assertTrue($nullResult->isResponse());
        self::assertFalse($nullResult->isError());

        // The neighbouring falsy value, through the SAME call: a guard sloppy
        // enough to sweep `null` up would very likely take `false` with it.
        $falseResult = McpMessage::parse('{"jsonrpc":"2.0","id":"1","result":false}');
        self::assertNotNull($falseResult);
        self::assertTrue($falseResult->resultSet);
        self::assertFalse($falseResult->result);

        // ABSENT, not null: a request carries no `result` key, and must not be
        // reported as one that does.
        $request = McpMessage::parse('{"jsonrpc":"2.0","id":"1","method":"tools/list"}');
        self::assertNotNull($request);
        self::assertFalse(
            $request->resultSet,
            'a message with no result key is reporting one, so the sentinel is hard-wired true '
            . 'and discriminates nothing',
        );

        // AND THE ENVELOPE WITH NOTHING IN IT IS STILL REJECTED — no method, no
        // error, no result key: nothing to match a response against.
        self::assertNull(
            McpMessage::parse('{"jsonrpc":"2.0"}'),
            'an envelope carrying no method, no error and no result key was accepted — parse() '
            . 'now returns objects that readResponse() has nothing to match on',
        );
    }

    /**
     * A NULL RESULT SURVIVES THE ROUND TRIP, which `toJson()` used to break in
     * the mirror of the same bug (`if ($this->result !== null)` dropped the
     * key), AND the other polarity: a message that genuinely has no result must
     * not grow one.
     */
    public function testANullResultSurvivesToJsonAndBack(): void
    {
        $json = McpMessage::success('7', null)->toJson();

        self::assertStringContainsString(
            '"result":null',
            $json,
            'toJson() dropped a null result, so the message it emits is not the one it holds',
        );

        $reparsed = McpMessage::parse($json);
        self::assertNotNull($reparsed, 'a null result did not survive toJson() + parse()');
        self::assertTrue($reparsed->resultSet);
        self::assertNull($reparsed->result);

        self::assertStringNotContainsString(
            '"result"',
            McpMessage::request('7', 'tools/list', [])->toJson(),
            'toJson() put a result key on a REQUEST',
        );
    }

    /**
     * The factory takes the same domain as the parser.
     *
     * @dataProvider legalResultTypes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('legalResultTypes')]
    public function testSuccessRoundTripsEveryLegalResultTypeThroughJson(string $literal, mixed $expected): void
    {
        $reparsed = McpMessage::parse(McpMessage::success('9', $expected)->toJson());

        self::assertNotNull($reparsed, 'success(' . $literal . ') did not survive toJson()+parse()');
        self::assertSame($expected, $reparsed->result);
    }

    /** `toArray()` carries the widened value out too. */
    public function testToArrayCarriesANonArrayResultThrough(): void
    {
        $array = McpMessage::success('3', true)->toArray();

        self::assertTrue($array['result']);
    }
}
