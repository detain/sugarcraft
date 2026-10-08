<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\McpMessage;

/**
 * Decision D12: the id-preserving {@see McpMessage} variant the ACP adapter
 * (sugar-crush) reads and writes with. An integer id stays an integer both
 * ways; MCP's own {@see McpMessage::parse()} and factories are byte-for-byte
 * what they were.
 *
 * Moved from sugar-crush/tests/MCP/ with the lane-A2 fold — the canonical
 * tests live with the canonical class.
 */
final class McpMessageIdPreservingTest extends TestCase
{
    public function testParseStillFoldsAnIntegerIdIntoAString(): void
    {
        $message = McpMessage::parse('{"jsonrpc":"2.0","id":7,"method":"tools/list"}');

        self::assertNotNull($message);
        self::assertSame('7', $message->id);
        self::assertFalse($message->wireIdSet);
        self::assertSame('{"jsonrpc":"2.0","id":"7","method":"tools\/list"}', $message->toJson(), 'MCP behaviour is unchanged');
    }

    public function testTheVariantKeepsTheIdAsSentAndWritesItBackThatWay(): void
    {
        $request = McpMessage::parsePreservingId('{"jsonrpc":"2.0","id":7,"method":"initialize","params":{"protocolVersion":1}}');

        self::assertNotNull($request);
        self::assertSame(7, $request->wireId);
        self::assertSame('7', $request->id, 'the string form is still there for code that matches on it');
        self::assertTrue($request->isRequest());
        self::assertSame(['protocolVersion' => 1], $request->params);
        self::assertSame('{"jsonrpc":"2.0","id":7,"method":"initialize","params":{"protocolVersion":1}}', $request->toJson());

        $string = McpMessage::parsePreservingId('{"jsonrpc":"2.0","id":"a-1","result":{"ok":true}}');
        self::assertNotNull($string);
        self::assertSame('a-1', $string->wireId);
        self::assertTrue($string->isResponse());
    }

    public function testBuildingWithAWireId(): void
    {
        self::assertSame('{"jsonrpc":"2.0","id":3,"result":{"x":1}}', McpMessage::success('', ['x' => 1])->withWireId(3)->toJson());
        self::assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"parse error"}}',
            McpMessage::error('', -32700, 'parse error')->withWireId(null)->toJson(),
            'an error to an unreadable request carries "id": null, as JSON-RPC requires',
        );
        self::assertSame(
            '{"jsonrpc":"2.0","method":"session\/update","params":{"a":1}}',
            McpMessage::notification('session/update', ['a' => 1])->withWireId(9)->toJson(),
            'a notification carries no id, whatever it is given',
        );
        $request = McpMessage::request('5', 'session/request_permission', ['q' => 1])->withWireId(5);
        self::assertSame(5, json_decode($request->toJson(), true)['id']);
        self::assertTrue($request->toArray()['wireIdSet']);
    }

    public function testTheVariantRefusesWhatAnRpcServerMustNotRead(): void
    {
        self::assertNull(McpMessage::parsePreservingId('{"jsonrpc":"2.0","id":1.5,"method":"x"}'), 'a float id');
        self::assertNull(McpMessage::parsePreservingId('{"jsonrpc":"2.0","id":{"a":1},"method":"x"}'), 'an object id');
        self::assertNull(McpMessage::parsePreservingId('[{"jsonrpc":"2.0","id":1,"method":"x"}]'), 'a batch');
        self::assertNull(McpMessage::parsePreservingId('{"jsonrpc":"1.0","id":1,"method":"x"}'));
        self::assertNull(McpMessage::parsePreservingId('{not json'));

        $deep = str_repeat('[', McpMessage::MAX_DEPTH + 1) . str_repeat(']', McpMessage::MAX_DEPTH + 1);
        self::assertNull(McpMessage::parsePreservingId('{"jsonrpc":"2.0","id":1,"method":"x","params":{"d":' . $deep . '}}'), 'nesting past the bound');

        $null = McpMessage::parsePreservingId('{"jsonrpc":"2.0","id":null,"result":null}');
        self::assertNotNull($null);
        self::assertNull($null->wireId);
        self::assertTrue($null->wireIdSet);
    }
}
