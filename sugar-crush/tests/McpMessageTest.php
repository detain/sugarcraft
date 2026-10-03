<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Crush\McpMessage;
use PHPUnit\Framework\TestCase;

final class McpMessageTest extends TestCase
{
    // --- Parse ---

    public function testParseValidRequest(): void
    {
        $raw = '{"jsonrpc":"2.0","id":"1","method":"tools/call","params":{"name":"test","arguments":{}}}';
        $msg = McpMessage::parse($raw);

        $this->assertNotNull($msg);
        $this->assertSame('1', $msg->id);
        $this->assertSame('tools/call', $msg->method);
        $this->assertSame(['name' => 'test', 'arguments' => []], $msg->params);
        $this->assertFalse($msg->isNotification());
        $this->assertTrue($msg->isRequest());
    }

    public function testParseValidNotification(): void
    {
        $raw = '{"jsonrpc":"2.0","method":"initialized","params":{}}';
        $msg = McpMessage::parse($raw);

        $this->assertNotNull($msg);
        $this->assertNull($msg->id);
        $this->assertSame('initialized', $msg->method);
        $this->assertTrue($msg->isNotification());
        $this->assertFalse($msg->isRequest());
    }

    public function testParseValidSuccessResponse(): void
    {
        $raw = '{"jsonrpc":"2.0","id":"42","result":{"tools":[{"name":"test"}]}}';
        $msg = McpMessage::parse($raw);

        $this->assertNotNull($msg);
        $this->assertSame('42', $msg->id);
        $this->assertNull($msg->method);
        $this->assertSame(['tools' => [['name' => 'test']]], $msg->result);
        $this->assertTrue($msg->isResponse());
        $this->assertFalse($msg->isError());
    }

    public function testParseValidErrorResponse(): void
    {
        $raw = '{"jsonrpc":"2.0","id":"3","error":{"code":-32600,"message":"Invalid Request"}}';
        $msg = McpMessage::parse($raw);

        $this->assertNotNull($msg);
        $this->assertSame('3', $msg->id);
        $this->assertNull($msg->method);
        $this->assertNull($msg->result);
        $this->assertNotNull($msg->error);
        $this->assertTrue($msg->isResponse());
        $this->assertTrue($msg->isError());
        $this->assertSame(-32600, $msg->errorCode());
        $this->assertSame('Invalid Request', $msg->errorMessage());
    }

    public function testParseInvalidJsonReturnsNull(): void
    {
        $this->assertNull(McpMessage::parse('not json'));
        $this->assertNull(McpMessage::parse(''));
        $this->assertNull(McpMessage::parse('{"jsonrpc":"1.0"}')); // wrong version
    }

    public function testParseMissingJsonrpcVersionReturnsNull(): void
    {
        $this->assertNull(McpMessage::parse('{"id":"1","method":"test"}'));
    }

    // --- Factory methods ---

    public function testRequestFactory(): void
    {
        $msg = McpMessage::request('5', 'tools/call', ['name' => 'foo']);

        $this->assertSame('5', $msg->id);
        $this->assertSame('tools/call', $msg->method);
        $this->assertSame(['name' => 'foo'], $msg->params);
        $this->assertTrue($msg->isRequest());
        $this->assertFalse($msg->isNotification());
    }

    public function testNotificationFactory(): void
    {
        $msg = McpMessage::notification('initialized', ['version' => '1.0']);

        $this->assertNull($msg->id);
        $this->assertSame('initialized', $msg->method);
        $this->assertSame(['version' => '1.0'], $msg->params);
        $this->assertTrue($msg->isNotification());
        $this->assertFalse($msg->isRequest());
    }

    public function testSuccessFactory(): void
    {
        $msg = McpMessage::success('7', ['tools' => []]);

        $this->assertSame('7', $msg->id);
        $this->assertNull($msg->method);
        $this->assertSame(['tools' => []], $msg->result);
        $this->assertTrue($msg->isResponse());
        $this->assertFalse($msg->isError());
    }

    public function testErrorFactory(): void
    {
        $msg = McpMessage::error('9', -32600, 'Invalid Request', 'data');

        $this->assertSame('9', $msg->id);
        $this->assertTrue($msg->isError());
        $this->assertSame(-32600, $msg->errorCode());
        $this->assertSame('Invalid Request', $msg->errorMessage());
        $this->assertSame('data', $msg->error['data']);
    }

    // --- Serialization round-trip ---

    public function testToJsonRoundTripRequest(): void
    {
        $original = McpMessage::request('1', 'tools/call', ['name' => 'test', 'arguments' => []]);
        $parsed = McpMessage::parse($original->toJson());

        $this->assertNotNull($parsed);
        $this->assertSame($original->id, $parsed->id);
        $this->assertSame($original->method, $parsed->method);
        $this->assertSame($original->params, $parsed->params);
    }

    public function testToJsonRoundTripNotification(): void
    {
        $original = McpMessage::notification('initialized');
        $parsed = McpMessage::parse($original->toJson());

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed->isNotification());
        $this->assertNull($parsed->id);
        $this->assertSame('initialized', $parsed->method);
    }

    public function testToJsonRoundTripSuccessResponse(): void
    {
        $original = McpMessage::success('42', ['content' => 'hello']);
        $parsed = McpMessage::parse($original->toJson());

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed->isResponse());
        $this->assertSame($original->result, $parsed->result);
    }

    public function testToJsonRoundTripErrorResponse(): void
    {
        $original = McpMessage::error('7', -32601, 'Method not found');
        $parsed = McpMessage::parse($original->toJson());

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed->isError());
        $this->assertSame(-32601, $parsed->errorCode());
        $this->assertSame('Method not found', $parsed->errorMessage());
    }

    // --- toArray ---

    public function testToArrayContainsAllFields(): void
    {
        $msg = McpMessage::request('1', 'test', ['foo' => 'bar']);
        $arr = $msg->toArray();

        $this->assertArrayHasKey('jsonrpc', $arr);
        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('method', $arr);
        $this->assertArrayHasKey('params', $arr);
        $this->assertArrayHasKey('result', $arr);
        $this->assertArrayHasKey('error', $arr);
        $this->assertArrayHasKey('isNotification', $arr);
    }

    // --- Error helpers ---

    public function testErrorCodeReturnsNullForNonError(): void
    {
        $msg = McpMessage::success('1', null);
        $this->assertNull($msg->errorCode());
    }

    public function testErrorMessageReturnsNullForNonError(): void
    {
        $msg = McpMessage::request('1', 'test', null);
        $this->assertNull($msg->errorMessage());
    }

    /**
     * The error envelope is third-party wire data: a server that sends a
     * non-string `message` must read as "no message", not throw a TypeError
     * out of a `?string` accessor — ClaudeCodeMcpClient::connect() formats an
     * initialize refusal through this call, and a TypeError is not the
     * RuntimeException its callers catch.
     */
    public function testErrorMessageIsNullWhenTheServerSendsANonStringMessage(): void
    {
        foreach (['5', '1.5', 'true', '["x"]', '{"a":1}', 'null'] as $literal) {
            $msg = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":1,"message":' . $literal . '}}');

            $this->assertNotNull($msg, $literal);
            $this->assertTrue($msg->isError(), $literal);
            $this->assertNull($msg->errorMessage(), $literal);
        }

        $msg = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":1,"message":"refused"}}');
        $this->assertNotNull($msg);
        $this->assertSame('refused', $msg->errorMessage());
    }

    /**
     * JSON-RPC 2.0 requires an integer `code`. A server that sends anything
     * else must read as "no code" — the errorMessage() rule — not as a number
     * an `(int)` cast made up: `"abc"` was 0, `true` and `[1]` were 1.
     */
    public function testErrorCodeIsNullWhenTheServerSendsANonIntegerCode(): void
    {
        foreach (['"abc"', '"-32601"', 'true', 'false', '1.5', '-32601.0', '["x"]', '{"a":1}', 'null'] as $literal) {
            $msg = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":' . $literal . ',"message":"m"}}');

            $this->assertNotNull($msg, $literal);
            $this->assertTrue($msg->isError(), $literal);
            $this->assertNull($msg->errorCode(), $literal);
            $this->assertSame('m', $msg->errorMessage(), $literal);
        }

        $msg = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"message":"m"}}');
        $this->assertNotNull($msg);
        $this->assertNull($msg->errorCode(), 'an absent code is no code');

        $msg = McpMessage::parse('{"jsonrpc":"2.0","id":"1","error":{"code":0,"message":"m"}}');
        $this->assertNotNull($msg);
        $this->assertSame(0, $msg->errorCode(), 'a real zero code is still a code');
    }

    // --- Helpers for type guards ---

    public function testIsRequestTrueForRequestWithId(): void
    {
        $msg = McpMessage::request('1', 'test', null);
        $this->assertTrue($msg->isRequest());
        $this->assertFalse($msg->isResponse());
        $this->assertFalse($msg->isNotification());
    }

    public function testIsResponseTrueWhenMethodIsNullAndIdPresent(): void
    {
        $msg = McpMessage::success('1', null);
        $this->assertTrue($msg->isResponse());
        $this->assertFalse($msg->isRequest());
    }
}
