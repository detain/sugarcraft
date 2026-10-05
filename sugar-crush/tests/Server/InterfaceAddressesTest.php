<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\InterfaceAddresses;
use SugarCraft\Crush\Server\Server;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * The addresses a wildcard `--allow-remote` bind answers to and names in its
 * sign-in URLs: this machine's own, minus what no remote client can dial.
 */
final class InterfaceAddressesTest extends TestCase
{
    public function testOnlyDialableAddressesSurviveIpv4First(): void
    {
        self::assertSame(
            ['69.10.33.243', '10.0.0.5', '2001:db8::1'],
            InterfaceAddresses::usable([
                '127.0.0.1', '::1', '0.0.0.0', '::', 'fe80::1', '169.254.1.1', 'not-an-ip',
                '2001:DB8:0::1', '69.10.33.243', '[2001:db8::1]', '10.0.0.5', '69.10.33.243',
            ]),
        );
    }

    public function testDetectionAnswersOnlyUsableAddresses(): void
    {
        $detected = InterfaceAddresses::detect();

        self::assertSame(InterfaceAddresses::usable($detected), $detected);
    }

    public function testTheSignInUrlsOfAWildcardBindNameRealAddressesWithOneCode(): void
    {
        $config = ServerConfig::new('/nonexistent')->withHost('0.0.0.0')->withAllowRemote(true)->withPort(7420)->withInterfaceAddresses(['69.10.33.243', '10.0.0.5']);
        $server = Server::new($config, AuthContext::new(TokenStore::new('/nonexistent')->withOverride(\str_repeat('t', 40))));

        $urls = $server->loginUrls();
        self::assertCount(2, $urls);
        self::assertMatchesRegularExpression('#^http://69\.10\.33\.243:7420/\#code=([0-9a-f]+)$#', $urls[0]);
        self::assertSame(\str_replace('69.10.33.243', '10.0.0.5', $urls[0]), $urls[1], 'one code serves every address');
        self::assertStringStartsWith('http://69.10.33.243:7420/#code=', $server->loginUrl());
        self::assertStringNotContainsString('0.0.0.0', \implode(' ', $urls));

        $loopback = Server::new(ServerConfig::new('/nonexistent'), AuthContext::new(TokenStore::new('/nonexistent')->withOverride(\str_repeat('t', 40))));
        self::assertCount(1, $loopback->loginUrls());
        self::assertStringStartsWith('http://127.0.0.1:7420/#code=', $loopback->loginUrl());
    }
}
