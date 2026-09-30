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
}
