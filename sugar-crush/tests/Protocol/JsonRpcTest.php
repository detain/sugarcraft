<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\JsonRpc;
use SugarCraft\Crush\Protocol\Request;
use SugarCraft\Crush\Protocol\RpcError;

/**
 * The `sugarcrush.v1` JSON-RPC 2.0 codec: what it accepts, the error it
 * answers everything else with, and the id kept exactly as the client sent it.
 */
final class JsonRpcTest extends TestCase
{
    public function testARequestKeepsItsIdAsSent(): void
    {
        $string = JsonRpc::decode('{"jsonrpc":"2.0","id":"c1-42","method":"session.send","params":{"text":"hi"}}');
        $integer = JsonRpc::decode('{"jsonrpc":"2.0","id":42,"method":"server.health"}');

        self::assertInstanceOf(Request::class, $string);
        self::assertSame('c1-42', $string->id);
        self::assertSame(['text' => 'hi'], $string->params);
        self::assertInstanceOf(Request::class, $integer);
        self::assertSame(42, $integer->id);
        self::assertSame('{"jsonrpc":"2.0","id":42,"result":{"ok":true}}', JsonRpc::result(42, ['ok' => true]));
    }

    public function testANotificationHasNoId(): void
    {
        $request = JsonRpc::decode('{"jsonrpc":"2.0","method":"client.viewing","params":{"sessionIds":[]}}');

        self::assertInstanceOf(Request::class, $request);
        self::assertTrue($request->isNotification());
        self::assertFalse((new Request(null, true, 'x.y', []))->isNotification(), 'an explicit null id is still a request');
    }

    /** @return iterable<string, array{0: string, 1: ErrorCode, 2: string|int|null}> */
    public static function refusals(): iterable
    {
        yield 'not json' => ['{nope', ErrorCode::ParseError, null];
        yield 'too deep' => [\str_repeat('[', 70) . \str_repeat(']', 70), ErrorCode::ParseError, null];
        yield 'a list' => ['[1,2]', ErrorCode::InvalidRequest, null];
        yield 'no version' => ['{"id":1,"method":"a.b"}', ErrorCode::InvalidRequest, null];
        yield 'a float id' => ['{"jsonrpc":"2.0","id":1.5,"method":"a.b"}', ErrorCode::InvalidRequest, null];
        yield 'no method' => ['{"jsonrpc":"2.0","id":3}', ErrorCode::InvalidRequest, 3];
        yield 'positional params' => ['{"jsonrpc":"2.0","id":"x","method":"a.b","params":[1]}', ErrorCode::InvalidParams, 'x'];
    }

    #[DataProvider('refusals')]
    public function testEverythingElseIsAnsweredWithTheRightError(string $text, ErrorCode $code, string|int|null $id): void
    {
        $decoded = JsonRpc::decode($text);

        self::assertIsArray($decoded);
        [$error, $errorId] = $decoded;
        self::assertSame($code, $error->errorCode);
        self::assertSame($code->kind(), $error->kind);
        self::assertSame($id, $errorId);
    }

    public function testAnErrorObjectCarriesItsKindAndRetryability(): void
    {
        $busy = \json_decode(JsonRpc::error('r1', RpcError::of(ErrorCode::Busy, 'later', 'too_many_turns', ['retryAfterMs' => 500])), true);
        $notFound = \json_decode(JsonRpc::error(null, RpcError::notFound('gone')), true);

        self::assertSame(['code' => -32010, 'message' => 'later', 'data' => ['kind' => 'too_many_turns', 'retryAfterMs' => 500, 'retryable' => true]], $busy['error']);
        self::assertNull($notFound['id']);
        self::assertSame(['kind' => 'not_found'], $notFound['error']['data']);
    }

    public function testANotificationIsAnArrayUntilItIsEncoded(): void
    {
        $event = JsonRpc::notification('event', ['type' => 't', 'data' => ['path' => 'a/b']]);

        self::assertSame('{"jsonrpc":"2.0","method":"event","params":{"type":"t","data":{"path":"a/b"}}}', JsonRpc::encode($event));
    }
}
