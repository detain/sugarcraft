<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\Protocol\ErrorCode;
use SugarCraft\McpClient\Protocol\JsonRpc;
use SugarCraft\McpClient\Protocol\Request;
use SugarCraft\McpClient\Protocol\RpcError;

/**
 * The serve protocol's JSON-RPC envelope comes from sugar-mcp-client. This pins
 * that the dependency is wired: the codec resolves from this package's
 * autoloader and answers with the candy-code.v1 envelope shape.
 */
final class JsonRpcCodecWiringTest extends TestCase
{
    public function testRequestIdSurvivesDecodeAsSent(): void
    {
        $decoded = JsonRpc::decode('{"jsonrpc":"2.0","id":"c1-7","method":"server.health"}');

        self::assertInstanceOf(Request::class, $decoded);
        self::assertSame('c1-7', $decoded->id);
        self::assertSame('server.health', $decoded->method);
    }

    public function testResultEnvelopeEchoesTheIdAsSent(): void
    {
        self::assertSame('{"jsonrpc":"2.0","id":42,"result":{"ok":true}}', JsonRpc::result(42, ['ok' => true]));
    }

    public function testUndecodableTextIsAnsweredWithAParseError(): void
    {
        $decoded = JsonRpc::decode('{not json');

        self::assertIsArray($decoded);
        self::assertInstanceOf(RpcError::class, $decoded[0]);
        self::assertSame(ErrorCode::ParseError, $decoded[0]->errorCode);
        self::assertNull($decoded[1]);
    }
}
