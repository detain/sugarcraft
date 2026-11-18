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

    public function testZoneScopedIpv6HostKeepsItsZoneId(): void
    {
        // The host's Attach::websocketUrl keeps a bracketed zone-scoped address as printed.
        self::assertSame('ws://[fe80::1%25eth0]:8080/ws', WebsocketUrl::from('http://[fe80::1%25eth0]:8080'));
    }

    public function testEmptyZoneIdIsRefused(): void
    {
        self::assertNull(WebsocketUrl::from('http://[fe80::1%]:8080'));
    }

    public function testPercentEncodedEmptyZoneIdIsRefusedLikeRawEmptyZone(): void
    {
        // The %25 spelling of the same empty zone must be refused too, not re-read as zone `25`.
        self::assertNull(WebsocketUrl::from('http://[fe80::1%25]:8080'));
    }

    public function testZoneIdWithSpaceIsRefused(): void
    {
        self::assertNull(WebsocketUrl::from('http://[fe80::1%25a b]:8080'));
    }

    public function testInvalidIpv6AddressBeforeZoneIdIsRefused(): void
    {
        self::assertNull(WebsocketUrl::from('http://[:::%25eth0]:8080'));
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

    public function testBracketedNameWithoutColonIsRefusedNotDialledAsDnsHost(): void
    {
        // Stripping the brackets would turn `[abc]` into the DNS name `abc`.
        self::assertNull(WebsocketUrl::from('http://[abc]:8080'));
    }

    public function testBracketedDottedNameIsRefusedNotDialledAsDnsHost(): void
    {
        self::assertNull(WebsocketUrl::from('http://[v1.fe]:8080'));
    }

    public function testBracketedBlankHostIsRefused(): void
    {
        self::assertNull(WebsocketUrl::from('http://[ ]:1'));
    }

    public function testHostAcceptedMalformedAddressesAreRefused(): void
    {
        // Inputs candy-code's Attach::websocketUrl turns into a URL today. This class
        // refuses them with null, and the host delegating here changes them on purpose.
        $malformed = [
            'http://[abc]:8080',
            'http://[v1.fe]:8080',
            'http://[ ]:1',
            'ws://[:]:1/ws',
            'http://[fe80::1%]:8080',
            'http://::1',
        ];
        foreach ($malformed as $address) {
            self::assertNull(WebsocketUrl::from($address), $address);
        }
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
