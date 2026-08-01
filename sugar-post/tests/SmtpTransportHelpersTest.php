<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Email;
use SugarCraft\Post\SmtpTransport;

/**
 * Unit tests for SmtpTransport private helper methods and edge cases.
 */
final class SmtpTransportHelpersTest extends TestCase
{
    private SmtpTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new SmtpTransport('smtp.example.com', 587);
    }

    // -------------------------------------------------------------------------
    // hasExtension()
    // -------------------------------------------------------------------------

    public function testHasExtensionReturnsTrueWhenAdvertised(): void
    {
        $this->setLastResponse("220 fake ESMTP ready\r\n250-fake greets you\r\n250-STARTTLS\r\n250 HELP\r\n");

        $has = $this->invokePrivate($this->transport, 'hasExtension', ['STARTTLS']);
        $this->assertTrue($has);
    }

    public function testHasExtensionReturnsFalseWhenNotAdvertised(): void
    {
        $this->setLastResponse("220 fake ESMTP ready\r\n250-fake greets you\r\n250 HELP\r\n");

        $has = $this->invokePrivate($this->transport, 'hasExtension', ['STARTTLS']);
        $this->assertFalse($has);
    }

    public function testHasExtensionIsCaseInsensitive(): void
    {
        $this->setLastResponse("220 fake ESMTP ready\r\n250-fake greets you\r\n250-starttls\r\n250 AUTH LOGIN\r\n");

        $has = $this->invokePrivate($this->transport, 'hasExtension', ['STARTTLS']);
        $this->assertTrue($has);

        $hasAuth = $this->invokePrivate($this->transport, 'hasExtension', ['auth']);
        $this->assertTrue($hasAuth);
    }

    public function testHasExtensionMatchesExactKeyword(): void
    {
        // "250 SIZE 1000" should not match "SIZE2" extension
        $this->setLastResponse("220 fake ESMTP ready\r\n250-SIZE 1000\r\n250 HELP\r\n");

        $has = $this->invokePrivate($this->transport, 'hasExtension', ['SIZE']);
        $this->assertTrue($has);

        $has2 = $this->invokePrivate($this->transport, 'hasExtension', ['SIZE2']);
        $this->assertFalse($has2);
    }

    public function testHasExtensionWithParams(): void
    {
        // "250-AUTH PLAIN LOGIN" should match "AUTH"
        $this->setLastResponse("220 fake ESMTP ready\r\n250-AUTH PLAIN LOGIN\r\n250 HELP\r\n");

        $has = $this->invokePrivate($this->transport, 'hasExtension', ['AUTH']);
        $this->assertTrue($has);
    }

    // -------------------------------------------------------------------------
    // cteFor()
    // -------------------------------------------------------------------------

    public function testCteForReturns7bitForAsciiContent(): void
    {
        $cte = $this->invokePrivate($this->transport, 'cteFor', ['Hello World']);
        $this->assertSame('7bit', $cte);
    }

    public function testCteForReturns8bitForNonAsciiContent(): void
    {
        $cte = $this->invokePrivate($this->transport, 'cteFor', ['Café résumé']);
        $this->assertSame('8bit', $cte);
    }

    public function testCteForReturns7bitForEmptyString(): void
    {
        $cte = $this->invokePrivate($this->transport, 'cteFor', ['']);
        $this->assertSame('7bit', $cte);
    }

    // -------------------------------------------------------------------------
    // bareAddr()
    // -------------------------------------------------------------------------

    public function testBareAddrReturnsBareAddress(): void
    {
        $bare = $this->invokePrivate($this->transport, 'bareAddr', ['user@example.com']);
        $this->assertSame('user@example.com', $bare);
    }

    public function testBareAddrExtractsFromDisplayNameFormat(): void
    {
        $bare = $this->invokePrivate($this->transport, 'bareAddr', ['John Doe <john@example.com>']);
        $this->assertSame('john@example.com', $bare);
    }

    public function testBareAddrHandlesSpacesInDisplayName(): void
    {
        $bare = $this->invokePrivate($this->transport, 'bareAddr', ['John Michael Doe <john@example.com>']);
        $this->assertSame('john@example.com', $bare);
    }

    // -------------------------------------------------------------------------
    // formatAddressForHeader()
    // -------------------------------------------------------------------------

    public function testFormatAddressForHeaderWithDisplayName(): void
    {
        $formatted = $this->invokePrivate($this->transport, 'formatAddressForHeader', ['John Doe <john@example.com>']);
        $this->assertSame('John Doe <john@example.com>', $formatted);
    }

    public function testFormatAddressForHeaderWithNonAsciiDisplayName(): void
    {
        $formatted = $this->invokePrivate($this->transport, 'formatAddressForHeader', ['José García <jose@example.com>']);
        // Non-ASCII display name should be RFC 2047 encoded
        $this->assertStringContainsString('=?UTF-8?B?', $formatted);
        $this->assertStringContainsString('<jose@example.com>', $formatted);
    }

    public function testFormatAddressForHeaderBareAddress(): void
    {
        $formatted = $this->invokePrivate($this->transport, 'formatAddressForHeader', ['user@example.com']);
        $this->assertSame('user@example.com', $formatted);
    }

    // -------------------------------------------------------------------------
    // encodeHeaderWord()
    // -------------------------------------------------------------------------

    public function testEncodeHeaderWordReturnsAsIsForAscii(): void
    {
        $encoded = $this->invokePrivate($this->transport, 'encodeHeaderWord', ['Hello World']);
        $this->assertSame('Hello World', $encoded);
    }

    public function testEncodeHeaderWordEncodesNonAscii(): void
    {
        $encoded = $this->invokePrivate($this->transport, 'encodeHeaderWord', ['Café']);
        $this->assertStringContainsString('=?UTF-8?B?', $encoded);
        $this->assertSame('Café', \base64_decode(\preg_replace('/=\?UTF-8\?B\?([A-Za-z0-9+=]+)\?=/', '$1', $encoded)));
    }

    public function testEncodeHeaderWordEmptyString(): void
    {
        $encoded = $this->invokePrivate($this->transport, 'encodeHeaderWord', ['']);
        $this->assertSame('', $encoded);
    }

    // -------------------------------------------------------------------------
    // getHeloHost()
    // -------------------------------------------------------------------------

    public function testGetHeloHostReturnsCustomHeloHost(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, '', '', 30, 'custom.helo.host');
        $helo = $this->invokePrivate($transport, 'getHeloHost');
        $this->assertSame('custom.helo.host', $helo);
    }

    public function testGetHeloHostFallsBackToLocalhost(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);
        $helo = $this->invokePrivate($transport, 'getHeloHost');
        // Should return gethostname() or 'localhost'
        $this->assertNotEmpty($helo);
    }

    // -------------------------------------------------------------------------
    // streamContextOptions()
    // -------------------------------------------------------------------------

    public function testStreamContextOptionsWithImplicitTls(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 465);
        $opts = $this->invokePrivate($transport, 'streamContextOptions');

        $this->assertArrayHasKey('ssl', $opts);
        $this->assertTrue($opts['ssl']['verify_peer']);
        $this->assertTrue($opts['ssl']['verify_peer_name']);
        $this->assertSame('smtp.example.com', $opts['ssl']['peer_name']);
        $this->assertTrue($opts['ssl']['SNI_enabled']);
    }

    public function testStreamContextOptionsPlaintextHasNoSsl(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);
        $opts = $this->invokePrivate($transport, 'streamContextOptions');

        $this->assertArrayNotHasKey('ssl', $opts);
        $this->assertArrayHasKey('socket', $opts);
        $this->assertSame(30, $opts['socket']['connect_timeout']);
    }

    public function testStreamContextOptionsWithCustomTimeout(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, '', '', 60);
        $opts = $this->invokePrivate($transport, 'streamContextOptions');

        $this->assertSame(60, $opts['socket']['connect_timeout']);
    }

    // -------------------------------------------------------------------------
    // addrListHeader()
    // -------------------------------------------------------------------------

    public function testAddrListHeaderSingleAddress(): void
    {
        $header = $this->invokePrivate($this->transport, 'addrListHeader', [['user@example.com']]);
        $this->assertSame('user@example.com', $header);
    }

    public function testAddrListHeaderMultipleAddresses(): void
    {
        $header = $this->invokePrivate($this->transport, 'addrListHeader', [
            ['user1@example.com', 'User 2 <user2@example.com>'],
        ]);
        $this->assertSame('user1@example.com, User 2 <user2@example.com>', $header);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function setLastResponse(string $response): void
    {
        $prop = new \ReflectionProperty($this->transport, 'lastResponse');
        $prop->setAccessible(true);
        $prop->setValue($this->transport, $response);
    }

    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }
}
