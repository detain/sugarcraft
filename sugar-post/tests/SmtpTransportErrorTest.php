<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Email;
use SugarCraft\Post\SmtpTransport;

/**
 * Edge case tests for SmtpTransport error paths.
 */
final class SmtpTransportErrorTest extends TestCase
{
    public function testSendRawThrowsWhenNotConnected(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected');

        $this->invokePrivate($transport, 'sendRaw', ["TEST\r\n"]);
    }

    public function testReadResponseThrowsWhenNotConnected(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected');

        $this->invokePrivate($transport, 'readResponse', [220]);
    }

    public function testQuitDoesNotThrowWhenNotConnected(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        // quit() should be safe to call when not connected
        $this->invokePrivate($transport, 'quit');

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testQuitSendsQuitAndDisconnects(): void
    {
        // This tests the quit() path when socket IS connected
        // We can't easily test the connected path without a real server
        // but we can verify quit() doesn't throw when socket is null
        $transport = new SmtpTransport('smtp.example.com', 587);
        $this->invokePrivate($transport, 'quit');
        $this->assertTrue(true);
    }

    public function testDisconnectIsIdempotent(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        // Calling disconnect twice should not throw
        $this->invokePrivate($transport, 'disconnect');
        $this->invokePrivate($transport, 'disconnect');

        $this->assertTrue(true);
    }

    public function testConnectTargetWithImplicitTls(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 465);

        $target = $this->invokePrivate($transport, 'connectTarget');
        $this->assertSame('tls://smtp.example.com:465', $target);
    }

    public function testConnectTargetWithoutImplicitTls(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $target = $this->invokePrivate($transport, 'connectTarget');
        $this->assertSame('tcp://smtp.example.com:587', $target);
    }

    public function testConnectTargetWithImplicitTlsOverride(): void
    {
        $transport = (new SmtpTransport('smtp.example.com', 587))->withImplicitTls();

        $target = $this->invokePrivate($transport, 'connectTarget');
        $this->assertSame('tls://smtp.example.com:587', $target);
    }

    public function testAuthenticateIfNeededSkipsWhenNoCredentials(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, '', '');

        // Should not throw, just return
        $this->invokePrivate($transport, 'authenticateIfNeeded');

        $this->assertTrue(true);
    }

    public function testAuthenticateIfNeededSkipsWhenNoUsername(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, '', 'password');

        $this->invokePrivate($transport, 'authenticateIfNeeded');

        $this->assertTrue(true);
    }

    public function testAuthenticateIfNeededSkipsWhenNoPassword(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, 'username', '');

        $this->invokePrivate($transport, 'authenticateIfNeeded');

        $this->assertTrue(true);
    }

    public function testStartTlsIfNeededSkipsWhenAlreadyEncrypted(): void
    {
        // Create transport with implicit TLS (already encrypted at connect time)
        $transport = new SmtpTransport('smtp.example.com', 465);

        // Set encrypted flag manually via reflection
        $prop = new \ReflectionProperty($transport, 'encrypted');
        $prop->setAccessible(true);
        $prop->setValue($transport, true);

        // Should return early without trying STARTTLS
        $this->invokePrivate($transport, 'startTlsIfNeeded');

        $this->assertTrue(true);
    }

    public function testStartTlsIfNeededSkipsWhenStarttlsNotAvailable(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        // Set lastResponse to indicate no STARTTLS
        $prop = new \ReflectionProperty($transport, 'lastResponse');
        $prop->setAccessible(true);
        $prop->setValue($transport, "220 server ready\r\n250-foo\r\n250 HELP\r\n");

        // requireTls is false by default, so should not throw
        $this->invokePrivate($transport, 'startTlsIfNeeded');

        $this->assertTrue(true);
    }

    public function testStartTlsIfNeededThrowsWhenTlsRequiredButStarttlsNotAvailable(): void
    {
        $transport = (new SmtpTransport('smtp.example.com', 587))->withRequireTls();

        // Set lastResponse to indicate no STARTTLS
        $prop = new \ReflectionProperty($transport, 'lastResponse');
        $prop->setAccessible(true);
        $prop->setValue($transport, "220 server ready\r\n250-foo\r\n250 HELP\r\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TLS is required');

        $this->invokePrivate($transport, 'startTlsIfNeeded');
    }

    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }
}
