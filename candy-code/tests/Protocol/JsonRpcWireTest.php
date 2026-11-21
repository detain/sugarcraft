<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Request;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\McpClient\Protocol\ErrorCode as LibErrorCode;
use SugarCraft\McpClient\Protocol\JsonRpc;
use SugarCraft\McpClient\Protocol\Request as LibRequest;
use SugarCraft\Plugins\Host\RefusedCall;

/**
 * Pins the `candy-code.v1` wire through the library codec, the one codec candy-code speaks
 * (plan_plugin.md 4.12). Each fixture is a frame the wire has to answer, and the expected
 * answer is written out literally, so a change to the codec that moves a byte goes red here.
 *
 * This replaces JsonRpcParityTest, which compared candy-code's own codec with the library
 * copy. With that copy deleted there is one codec, and the frames are the contract.
 */
final class JsonRpcWireTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: array<int, mixed>}> */
    public static function decodeFixtures(): iterable
    {
        yield 'string id request' => ['{"jsonrpc":"2.0","id":"c1-42","method":"session.send","params":{"text":"hi"}}', ['request', 'c1-42', true, 'session.send', ['text' => 'hi']]];
        yield 'integer id request' => ['{"jsonrpc":"2.0","id":42,"method":"server.health"}', ['request', 42, true, 'server.health', []]];
        yield 'null id request' => ['{"jsonrpc":"2.0","id":null,"method":"a.b"}', ['request', null, true, 'a.b', []]];
        yield 'notification' => ['{"jsonrpc":"2.0","method":"client.viewing","params":{"sessionIds":[]}}', ['request', null, false, 'client.viewing', ['sessionIds' => []]]];
        yield 'empty params object' => ['{"jsonrpc":"2.0","id":1,"method":"a.b","params":{}}', ['request', 1, true, 'a.b', []]];
        yield 'not json' => ['{nope', ['error', null, '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"parse error","data":{"kind":"parse_error"}}}']];
        yield 'too deep' => [\str_repeat('[', 70) . \str_repeat(']', 70), ['error', null, '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"parse error","data":{"kind":"parse_error"}}}']];
        yield 'a list' => ['[1,2]', ['error', null, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"invalid request: expected a JSON-RPC 2.0 object","data":{"kind":"invalid_request"}}}']];
        yield 'an empty object' => ['{}', ['error', null, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"invalid request: expected a JSON-RPC 2.0 object","data":{"kind":"invalid_request"}}}']];
        yield 'no version' => ['{"id":1,"method":"a.b"}', ['error', null, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"invalid request: expected a JSON-RPC 2.0 object","data":{"kind":"invalid_request"}}}']];
        yield 'wrong version' => ['{"jsonrpc":"1.0","id":1,"method":"a.b"}', ['error', null, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"invalid request: expected a JSON-RPC 2.0 object","data":{"kind":"invalid_request"}}}']];
        yield 'a float id' => ['{"jsonrpc":"2.0","id":1.5,"method":"a.b"}', ['error', null, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"invalid request: id must be a string, an integer or null","data":{"kind":"invalid_request"}}}']];
        yield 'an object id' => ['{"jsonrpc":"2.0","id":{},"method":"a.b"}', ['error', null, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"invalid request: id must be a string, an integer or null","data":{"kind":"invalid_request"}}}']];
        yield 'no method' => ['{"jsonrpc":"2.0","id":3}', ['error', 3, '{"jsonrpc":"2.0","id":3,"error":{"code":-32600,"message":"invalid request: method must be a non-empty string","data":{"kind":"invalid_request"}}}']];
        yield 'an empty method' => ['{"jsonrpc":"2.0","id":4,"method":""}', ['error', 4, '{"jsonrpc":"2.0","id":4,"error":{"code":-32600,"message":"invalid request: method must be a non-empty string","data":{"kind":"invalid_request"}}}']];
        yield 'positional params' => ['{"jsonrpc":"2.0","id":"x","method":"a.b","params":[1]}', ['error', 'x', '{"jsonrpc":"2.0","id":"x","error":{"code":-32602,"message":"invalid params: params must be an object","data":{"kind":"invalid_params"}}}']];
        yield 'scalar params' => ['{"jsonrpc":"2.0","id":"x","method":"a.b","params":"no"}', ['error', 'x', '{"jsonrpc":"2.0","id":"x","error":{"code":-32602,"message":"invalid params: params must be an object","data":{"kind":"invalid_params"}}}']];
    }

    /** @param array<int, mixed> $expected */
    #[DataProvider('decodeFixtures')]
    public function testDecodeAnswersTheRequestOrTheErrorFrame(string $text, array $expected): void
    {
        self::assertSame($expected, self::shapeOfDecode(JsonRpc::decode($text)));
    }

    public function testTheDecodedRequestCrossesIntoTheCandyTypeWithItsFields(): void
    {
        $decoded = JsonRpc::decode('{"jsonrpc":"2.0","id":"c1-42","method":"session.send","params":{"text":"hi"}}');
        self::assertInstanceOf(LibRequest::class, $decoded);

        $request = Request::fromLibrary($decoded);

        self::assertInstanceOf(Request::class, $request);
        self::assertSame('c1-42', $request->id);
        self::assertTrue($request->hasId);
        self::assertSame('session.send', $request->method);
        self::assertSame(['text' => 'hi'], $request->params);
    }

    /** @return iterable<string, array{0: mixed, 1: string|int|null, 2: string}> */
    public static function resultFixtures(): iterable
    {
        yield 'a map' => [['ok' => true], 42, '{"jsonrpc":"2.0","id":42,"result":{"ok":true}}'];
        yield 'an empty array becomes an object' => [[], 'a', '{"jsonrpc":"2.0","id":"a","result":{}}'];
        yield 'a null result' => [null, null, '{"jsonrpc":"2.0","id":null,"result":null}'];
        yield 'invalid UTF-8 is substituted' => [['text' => "ok\xFF"], 1, '{"jsonrpc":"2.0","id":1,"result":{"text":"ok' . "\u{FFFD}" . '"}}'];
        yield 'a float keeps its fraction' => [1.0, 2, '{"jsonrpc":"2.0","id":2,"result":1.0}'];
        yield 'a nested list' => [[[1, 2], ['x' => [3]]], 'id-9', '{"jsonrpc":"2.0","id":"id-9","result":[[1,2],{"x":[3]}]}'];
    }

    #[DataProvider('resultFixtures')]
    public function testResultFramesArePinned(mixed $result, string|int|null $id, string $frame): void
    {
        self::assertSame($frame, JsonRpc::result($id, $result));
    }

    /** @return iterable<string, array{0: string|int|null, 1: ErrorCode, 2: string, 3: ?string, 4: array<string, mixed>, 5: string}> */
    public static function errorFixtures(): iterable
    {
        yield 'busy with a retry hint' => ['r1', ErrorCode::Busy, 'later', 'too_many_turns', ['retryAfterMs' => 500], '{"jsonrpc":"2.0","id":"r1","error":{"code":-32010,"message":"later","data":{"kind":"too_many_turns","retryAfterMs":500,"retryable":true}}}'];
        yield 'not found, no data' => [null, ErrorCode::NotFound, 'gone', null, [], '{"jsonrpc":"2.0","id":null,"error":{"code":-32004,"message":"gone","data":{"kind":"not_found"}}}'];
        yield 'invalid params' => [7, ErrorCode::InvalidParams, 'bad shape', null, [], '{"jsonrpc":"2.0","id":7,"error":{"code":-32602,"message":"bad shape","data":{"kind":"invalid_params"}}}'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('errorFixtures')]
    public function testErrorFramesArePinned(string|int|null $id, ErrorCode $code, string $message, ?string $kind, array $data, string $frame): void
    {
        $refusal = RpcError::of($code, $message, $kind, $data);

        self::assertSame($frame, JsonRpc::error($id, $refusal->toLibrary()));
    }

    /**
     * The bundles read a refusal through RefusedCall, not through the frame, so a refusal
     * that loses its kind or data (a busy hint's retryAfterMs, say) must fail here even
     * when the wire frame still agrees.
     *
     * @param array<string, mixed> $data
     */
    #[DataProvider('errorFixtures')]
    public function testARefusalIsARefusedCallWhoseDataIsTheWireData(string|int|null $id, ErrorCode $code, string $message, ?string $kind, array $data, string $frame): void
    {
        $refusal = RpcError::of($code, $message, $kind, $data);

        self::assertInstanceOf(RefusedCall::class, $refusal);
        $wire = \json_decode(JsonRpc::error($id, $refusal->toLibrary()), true, 512, \JSON_THROW_ON_ERROR)['error'];
        self::assertSame($wire['data'], $refusal->refusalData());
        self::assertSame($refusal->refusalKind(), $wire['data']['kind']);
    }

    public function testANamedBusyRefusalKeepsItsRetryHintThroughRefusedCall(): void
    {
        $refusal = RpcError::of(ErrorCode::Busy, 'later', 'too_many_turns', ['retryAfterMs' => 500]);

        self::assertInstanceOf(RefusedCall::class, $refusal);
        self::assertSame('too_many_turns', $refusal->refusalKind());
        self::assertSame(['kind' => 'too_many_turns', 'retryAfterMs' => 500, 'retryable' => true], $refusal->refusalData());
    }

    public function testEveryCandyErrorCodeHasTheSameLibraryCaseAndValue(): void
    {
        $candy = [];
        foreach (ErrorCode::cases() as $case) {
            $candy[$case->name] = $case->value;
        }
        $library = [];
        foreach (LibErrorCode::cases() as $case) {
            $library[$case->name] = $case->value;
        }

        self::assertSame($library, $candy, 'candy-code ErrorCode and sugar-mcp-client ErrorCode must carry the same cases and values');
    }

    public function testEveryCandyErrorCodeConvertsToTheLibraryCodecWithoutAValueError(): void
    {
        foreach (ErrorCode::cases() as $case) {
            $library = RpcError::of($case, 'refused')->toLibrary();

            self::assertSame($case->value, $library->errorCode->value, $case->name);
        }
    }

    public function testNotificationFrameIsPinned(): void
    {
        $event = JsonRpc::notification('event', ['type' => 't', 'data' => ['path' => 'a/b']]);

        self::assertSame(
            '{"jsonrpc":"2.0","method":"event","params":{"type":"t","data":{"path":"a/b"}}}',
            JsonRpc::encode($event),
        );
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function valueFixtures(): iterable
    {
        yield 'a map' => [['text' => 'hi', 'n' => 3], '{"text":"hi","n":3}'];
        yield 'an empty array' => [[], '[]'];
        yield 'invalid UTF-8 is substituted' => [['text' => "ok\xFF"], '{"text":"ok' . "\u{FFFD}" . '"}'];
        yield 'a float keeps its fraction' => [1.0, '1.0'];
        yield 'a 511-level nest fits the value budget' => [self::nested(511), \str_repeat('[', 510) . '[]' . \str_repeat(']', 510)];
    }

    #[DataProvider('valueFixtures')]
    public function testEncodeValueIsPinned(mixed $value, string $expected): void
    {
        self::assertSame($expected, JsonRpc::encodeValue($value));
    }

    public function testEncodeValueRefusesPastTheBudgetWithTheValueWording(): void
    {
        self::assertSame(511, JsonRpc::MAX_DEPTH_VALUE);

        $tightened = self::refusal(static fn () => JsonRpc::encodeValue(self::nested(511), 510));
        $default = self::refusal(static fn () => JsonRpc::encodeValue(self::nested(512)));

        self::assertSame('cannot encode a wire value: Maximum stack depth exceeded', $tightened);
        self::assertSame($tightened, $default);
    }

    public function testEncodeRefusesAnUnencodableValueAndMessageWithTheirWording(): void
    {
        $handle = \fopen('php://memory', 'r');
        try {
            $value = ['handle' => $handle];
            $message = ['jsonrpc' => JsonRpc::VERSION, 'method' => 'event', 'params' => ['handle' => $handle]];

            self::assertSame('cannot encode a wire value: Type is not supported', self::refusal(static fn () => JsonRpc::encodeValue($value)));
            self::assertSame('cannot encode a wire message: Type is not supported', self::refusal(static fn () => JsonRpc::encode($message)));
        } finally {
            \fclose($handle);
        }
    }

    public function testTheVersionAndDepthsArePinned(): void
    {
        self::assertSame('2.0', JsonRpc::VERSION);
        self::assertSame(64, JsonRpc::MAX_DEPTH);
        self::assertSame(511, JsonRpc::MAX_DEPTH_VALUE);
    }

    /**
     * A decoded request as its fields, or an error as the frame the codec answers it with.
     *
     * @return array<int, mixed>
     */
    private static function shapeOfDecode(LibRequest|array $decoded): array
    {
        if ($decoded instanceof LibRequest) {
            return ['request', $decoded->id, $decoded->hasId, $decoded->method, $decoded->params];
        }

        [$error, $id] = $decoded;

        return ['error', $id, JsonRpc::error($id, $error)];
    }

    /** @return array<int, mixed> */
    private static function nested(int $levels): array
    {
        $value = [];
        for ($level = 1; $level < $levels; $level++) {
            $value = [$value];
        }

        return $value;
    }

    private static function refusal(\Closure $call): string
    {
        try {
            $call();
        } catch (\JsonException $e) {
            return $e->getMessage();
        }

        self::fail('the encoder accepted a value it must refuse');
    }
}
