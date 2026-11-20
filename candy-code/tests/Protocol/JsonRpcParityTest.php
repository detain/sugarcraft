<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Protocol\ErrorCode as CandyErrorCode;
use SugarCraft\Code\Protocol\JsonRpc as CandyJsonRpc;
use SugarCraft\Code\Protocol\Request as CandyRequest;
use SugarCraft\Code\Protocol\RpcError as CandyRpcError;
use SugarCraft\McpClient\Protocol\ErrorCode as LibErrorCode;
use SugarCraft\McpClient\Protocol\JsonRpc as LibJsonRpc;
use SugarCraft\McpClient\Protocol\Request as LibRequest;
use SugarCraft\McpClient\Protocol\RpcError as LibRpcError;
use SugarCraft\Plugins\Host\RefusedCall;

/**
 * Pins candy-code's `Protocol\JsonRpc` and the `sugar-mcp-client` copy to one
 * `candy-code.v1` wire: every fixture runs through both, and the normalized
 * answers must match, so a change to one copy that the other does not follow
 * fails here (plan_plugin.md R9, 6.2).
 *
 * The two copies carry distinct RpcError classes, so an error is compared as
 * the wire frame each copy builds for it; the frame is the contract.
 */
final class JsonRpcParityTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function decodeFixtures(): iterable
    {
        yield 'string id request' => ['{"jsonrpc":"2.0","id":"c1-42","method":"session.send","params":{"text":"hi"}}'];
        yield 'integer id request' => ['{"jsonrpc":"2.0","id":42,"method":"server.health"}'];
        yield 'null id request' => ['{"jsonrpc":"2.0","id":null,"method":"a.b"}'];
        yield 'notification' => ['{"jsonrpc":"2.0","method":"client.viewing","params":{"sessionIds":[]}}'];
        yield 'empty params object' => ['{"jsonrpc":"2.0","id":1,"method":"a.b","params":{}}'];
        yield 'not json' => ['{nope'];
        yield 'too deep' => [\str_repeat('[', 70) . \str_repeat(']', 70)];
        yield 'a list' => ['[1,2]'];
        yield 'an empty object' => ['{}'];
        yield 'no version' => ['{"id":1,"method":"a.b"}'];
        yield 'wrong version' => ['{"jsonrpc":"1.0","id":1,"method":"a.b"}'];
        yield 'a float id' => ['{"jsonrpc":"2.0","id":1.5,"method":"a.b"}'];
        yield 'an object id' => ['{"jsonrpc":"2.0","id":{},"method":"a.b"}'];
        yield 'no method' => ['{"jsonrpc":"2.0","id":3}'];
        yield 'an empty method' => ['{"jsonrpc":"2.0","id":4,"method":""}'];
        yield 'positional params' => ['{"jsonrpc":"2.0","id":"x","method":"a.b","params":[1]}'];
        yield 'scalar params' => ['{"jsonrpc":"2.0","id":"x","method":"a.b","params":"no"}'];
    }

    #[DataProvider('decodeFixtures')]
    public function testDecodeAnswersTheSameRequestOrTheSameErrorFrame(string $text): void
    {
        $candy = CandyJsonRpc::decode($text);
        $lib = LibJsonRpc::decode($text);

        self::assertSame(
            self::shapeOfDecode($candy, static fn (string|int|null $id, CandyRpcError $e): string => CandyJsonRpc::error($id, $e)),
            self::shapeOfDecode($lib, static fn (string|int|null $id, LibRpcError $e): string => LibJsonRpc::error($id, $e)),
        );
    }

    /** @return iterable<string, array{0: mixed, 1: string|int|null}> */
    public static function resultFixtures(): iterable
    {
        yield 'a map' => [['ok' => true], 42];
        yield 'an empty array becomes an object' => [[], 'a'];
        yield 'a null result' => [null, null];
        yield 'invalid UTF-8 is substituted' => [['text' => "ok\xFF"], 1];
        yield 'a float keeps its fraction' => [1.0, 2];
        yield 'a nested list' => [[[1, 2], ['x' => [3]]], 'id-9'];
    }

    #[DataProvider('resultFixtures')]
    public function testResultFramesAgree(mixed $result, string|int|null $id): void
    {
        self::assertSame(CandyJsonRpc::result($id, $result), LibJsonRpc::result($id, $result));
    }

    /** @return iterable<string, array{0: string|int|null, 1: string, 2: string, 3: array<string, mixed>}> */
    public static function errorFixtures(): iterable
    {
        yield 'busy with a retry hint' => ['r1', 'Busy', 'later', ['kind' => 'too_many_turns', 'retryAfterMs' => 500]];
        yield 'not found, no data' => [null, 'NotFound', 'gone', []];
        yield 'invalid params' => [7, 'InvalidParams', 'bad shape', []];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('errorFixtures')]
    public function testErrorFramesAgree(string|int|null $id, string $code, string $message, array $data): void
    {
        $kind = $data['kind'] ?? null;
        unset($data['kind']);
        $candyCode = \constant(CandyErrorCode::class . '::' . $code);
        $libCode = \constant(LibErrorCode::class . '::' . $code);
        $candy = CandyRpcError::of($candyCode, $message, $kind, $data);
        $lib = LibRpcError::of($libCode, $message, $kind, $data);

        self::assertSame(CandyJsonRpc::error($id, $candy), LibJsonRpc::error($id, $lib));
    }

    /**
     * The bundles read a refusal through RefusedCall, not through the frame, so a refusal
     * that loses its kind or data (a busy hint's retryAfterMs, say) must fail here even
     * when both wire frames still agree. The fixture runs the refusal the bundles see.
     *
     * @param array<string, mixed> $data
     */
    #[DataProvider('errorFixtures')]
    public function testARefusalIsARefusedCallWhoseDataIsTheWireData(string|int|null $id, string $code, string $message, array $data): void
    {
        $kind = $data['kind'] ?? null;
        unset($data['kind']);
        $candy = CandyRpcError::of(\constant(CandyErrorCode::class . '::' . $code), $message, $kind, $data);
        $lib = LibRpcError::of(\constant(LibErrorCode::class . '::' . $code), $message, $kind, $data);

        self::assertInstanceOf(RefusedCall::class, $candy);
        $wire = \json_decode(CandyJsonRpc::error($id, $candy), true, 512, \JSON_THROW_ON_ERROR)['error'];
        self::assertSame($wire['data'], $candy->refusalData());
        self::assertSame($candy->refusalKind(), $wire['data']['kind']);
        self::assertSame(
            $wire,
            \json_decode(LibJsonRpc::error($id, $lib), true, 512, \JSON_THROW_ON_ERROR)['error'],
        );
    }

    public function testANamedBusyRefusalKeepsItsRetryHintThroughRefusedCall(): void
    {
        $candy = CandyRpcError::of(CandyErrorCode::Busy, 'later', 'too_many_turns', ['retryAfterMs' => 500]);

        self::assertInstanceOf(RefusedCall::class, $candy);
        self::assertSame('too_many_turns', $candy->refusalKind());
        self::assertSame(['kind' => 'too_many_turns', 'retryAfterMs' => 500, 'retryable' => true], $candy->refusalData());
    }

    public function testNotificationFramesAgree(): void
    {
        $params = ['type' => 't', 'data' => ['path' => 'a/b']];

        self::assertSame(
            CandyJsonRpc::encode(CandyJsonRpc::notification('event', $params)),
            LibJsonRpc::encode(LibJsonRpc::notification('event', $params)),
        );
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function valueFixtures(): iterable
    {
        yield 'a map' => [['text' => 'hi', 'n' => 3]];
        yield 'an empty array' => [[]];
        yield 'invalid UTF-8 is substituted' => [['text' => "ok\xFF"]];
        yield 'a float keeps its fraction' => [1.0];
        yield 'a 511-level nest fits the value budget' => [self::nested(511)];
    }

    #[DataProvider('valueFixtures')]
    public function testEncodeValueAgrees(mixed $value): void
    {
        self::assertSame(CandyJsonRpc::encodeValue($value), LibJsonRpc::encodeValue($value));
    }

    public function testEncodeValueRefusesTheSameDepthWithTheSameWording(): void
    {
        $value = self::nested(511);

        self::assertSame(CandyJsonRpc::MAX_DEPTH_VALUE, LibJsonRpc::MAX_DEPTH_VALUE);
        $candy = self::refusal(static fn () => CandyJsonRpc::encodeValue($value, 510));
        $lib = self::refusal(static fn () => LibJsonRpc::encodeValue($value, 510));

        self::assertSame($candy, $lib);
        self::assertStringStartsWith('cannot encode a wire value', $lib);
    }

    /**
     * The default budget itself, not an explicit depth: one level past it must be
     * refused by both copies, so a default changed on either side goes red here.
     */
    public function testEncodeValueRefusesOneLevelPastTheDefaultBudgetAlike(): void
    {
        $value = self::nested(CandyJsonRpc::MAX_DEPTH_VALUE + 1);

        self::assertSame(CandyJsonRpc::MAX_DEPTH_VALUE, LibJsonRpc::MAX_DEPTH_VALUE);
        $candy = self::refusal(static fn () => CandyJsonRpc::encodeValue($value));
        $lib = self::refusal(static fn () => LibJsonRpc::encodeValue($value));

        self::assertSame($candy, $lib);
        self::assertStringStartsWith('cannot encode a wire value', $lib);
    }

    public function testEncodeRefusesTheSameUnencodableMessage(): void
    {
        $handle = \fopen('php://memory', 'r');
        try {
            $message = ['jsonrpc' => '2.0', 'method' => 'event', 'params' => ['handle' => $handle]];

            self::assertSame(
                self::refusal(static fn () => CandyJsonRpc::encode($message)),
                self::refusal(static fn () => LibJsonRpc::encode($message)),
            );
        } finally {
            \fclose($handle);
        }
    }

    public function testTheVersionAndDecodeDepthAgree(): void
    {
        self::assertSame(CandyJsonRpc::VERSION, LibJsonRpc::VERSION);
        self::assertSame(CandyJsonRpc::MAX_DEPTH, LibJsonRpc::MAX_DEPTH);
    }

    /**
     * A decoded request as its fields, or an error as the frame the copy answers it with.
     *
     * @param CandyRequest|LibRequest|array{0: object, 1: string|int|null} $decoded
     * @param \Closure(string|int|null, object): string $frame
     * @return array<int, mixed>
     */
    private static function shapeOfDecode(CandyRequest|LibRequest|array $decoded, \Closure $frame): array
    {
        if ($decoded instanceof CandyRequest || $decoded instanceof LibRequest) {
            return ['request', $decoded->id, $decoded->hasId, $decoded->method, $decoded->params];
        }

        [$error, $id] = $decoded;

        return ['error', $frame($id, $error)];
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
