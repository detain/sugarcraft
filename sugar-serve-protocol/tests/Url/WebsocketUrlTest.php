<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests\Url;

use PHPUnit\Framework\TestCase;
use SugarCraft\ServeProtocol\Url\WebsocketUrl;

final class WebsocketUrlTest extends TestCase
{
    public function testHttpAddressBecomesWsWithPath(): void
    {
        self::assertSame('ws://127.0.0.1:7420/ws', WebsocketUrl::from('http://127.0.0.1:7420'));
    }

    public function testFragmentIsDroppedAndPathReplaced(): void
    {
        self::assertSame('ws://127.0.0.1:7420/ws', WebsocketUrl::from('http://127.0.0.1:7420/#code=abc'));
    }

    public function testHttpsBecomesWssOnDefaultPort(): void
    {
        self::assertSame('wss://agent.example.com/ws', WebsocketUrl::from('https://agent.example.com/'));
    }

    public function testWsAddressKeepsBracketedIpv6Host(): void
    {
        self::assertSame('ws://[::1]:9/ws', WebsocketUrl::from('ws://[::1]:9/ws'));
    }

    public function testBareIpv6HostIsBracketed(): void
    {
        self::assertSame('ws://[::1]:9/ws', WebsocketUrl::from('http://::1:9'));
    }

    public function testBareIpv6HostWithoutPortIsRefused(): void
    {
        // parse_url() reads `http://::1` as host `:` port 1; that must not become `[:]:1`.
        self::assertNull(WebsocketUrl::from('http://::1'));
    }

    public function testBracketedNonIpv6HostIsRefused(): void
    {
        self::assertNull(WebsocketUrl::from('ws://[:]:1/ws'));
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame('wss://agent.example.com/ws', WebsocketUrl::from("  https://agent.example.com/\n"));
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        self::assertNull(WebsocketUrl::from('ftp://x'));
    }

    public function testStringWithoutSchemeIsRejected(): void
    {
        self::assertNull(WebsocketUrl::from('not a url'));
    }

    public function testMissingHostIsRejected(): void
    {
        self::assertNull(WebsocketUrl::from('http:///ws'));
    }
}
