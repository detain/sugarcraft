<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\McpCall;

final class McpCallTest extends TestCase
{
    public function testEmptyArrayParamsLeaveAsAnObject(): void
    {
        self::assertSame('{"jsonrpc":"2.0","id":"a1","method":"ping","params":{}}', McpCall::request('a1', 'ping'));
    }

    public function testEmptyStdClassStaysAnObject(): void
    {
        self::assertSame('{"jsonrpc":"2.0","id":"a1","method":"ping","params":{}}', McpCall::request('a1', 'ping', new \stdClass()));
    }

    public function testRelayedNestedEmptyObjectSurvives(): void
    {
        $params = (object) ['arguments' => new \stdClass(), 'name' => 'x'];
        self::assertSame('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"arguments":{},"name":"x"}}', McpCall::request(7, 'tools/call', $params));
    }

    public function testNonEmptyParamsKeepTheirShapeAndFlags(): void
    {
        self::assertSame(
            '{"jsonrpc":"2.0","id":"b","method":"m","params":{"p":"/a","n":1.0}}',
            McpCall::request('b', 'm', ['p' => '/a', 'n' => 1.0], \JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    public function testUnencodableParamsThrow(): void
    {
        $this->expectException(\JsonException::class);
        McpCall::request('a', 'm', ['x' => NAN]);
    }
}
