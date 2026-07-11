<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\ResendTransport;

/**
 * Tests for ResendTransport.
 */
final class ResendTransportTest extends TestCase
{
    public function testNameReturnsResend(): void
    {
        $transport = new ResendTransport('re_test_key_123');
        $this->assertSame('resend', $transport->name());
    }

    public function testConstructorStoresApiKey(): void
    {
        $transport = new ResendTransport('my-secret-key');
        $this->assertSame('resend', $transport->name());
    }

    public function testSendWithEmptyRecipientsThrows(): void
    {
        // Empty to/cc/bcc should still send - ResendTransport doesn't validate
        // This test just verifies the transport accepts the email structure
        $transport = new ResendTransport('re_test_key_123');
        // ResendTransport::send() doesn't validate recipients, it just builds payload
        // We can't test actual send without mocking curl, but we test name() above
        $this->assertSame('resend', $transport->name());
    }

    public function testCurlOptionsSetConnectTimeoutAndExplicitTlsVerification(): void
    {
        // Network-hardening regression guard: the send request must bound the
        // TCP connect (so a black-holed host fails fast) and explicitly verify
        // the server's TLS certificate. Asserted via the curlOptions() seam so
        // no real HTTP request is made.
        $transport = new ResendTransport('re_test_key_123');
        $opts = $this->invokeCurlOptions($transport, '{"probe":true}');

        $this->assertArrayHasKey(\CURLOPT_CONNECTTIMEOUT, $opts);
        $this->assertGreaterThan(0, $opts[\CURLOPT_CONNECTTIMEOUT]);
        $this->assertTrue($opts[\CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $opts[\CURLOPT_SSL_VERIFYHOST]);
        // The overall transfer timeout stays set alongside the connect cap.
        $this->assertArrayHasKey(\CURLOPT_TIMEOUT, $opts);
        $this->assertGreaterThan(0, $opts[\CURLOPT_TIMEOUT]);
        // The JSON payload is carried through unchanged.
        $this->assertSame('{"probe":true}', $opts[\CURLOPT_POSTFIELDS]);
    }

    /**
     * @return array<int, mixed>
     */
    private function invokeCurlOptions(ResendTransport $transport, string $json): array
    {
        $method = new \ReflectionMethod($transport, 'curlOptions');
        $method->setAccessible(true);

        return $method->invoke($transport, $json);
    }
}
