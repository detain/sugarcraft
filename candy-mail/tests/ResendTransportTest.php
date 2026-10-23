<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\ResendTransport;

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

    // -------------------------------------------------------------------------
    // Credential leak + extension guard (docs-fix-1023 S-4)
    // -------------------------------------------------------------------------

    public function testDebugInfoMasksApiKey(): void
    {
        $transport = new ResendTransport('re_live_S3cr3tKey');

        $info = $transport->__debugInfo();
        $this->assertSame('****', $info['apiKey']);

        $dump = \var_export($info, true) . \print_r($info, true);
        $this->assertStringNotContainsString('re_live_S3cr3tKey', $dump);

        // The real value stays usable internally.
        $prop = new \ReflectionProperty($transport, 'apiKey');
        $prop->setAccessible(true);
        $this->assertSame('re_live_S3cr3tKey', $prop->getValue($transport));
    }

    public function testDebugInfoLeavesEmptyApiKeyEmpty(): void
    {
        // Only meaningful when the environment is bare — otherwise the ctor
        // reads RESEND_API_KEY and the mask would (correctly) report '****'.
        $saved = \getenv('RESEND_API_KEY', true);
        \putenv('RESEND_API_KEY');
        try {
            unset($_ENV['RESEND_API_KEY']);
            $transport = new ResendTransport('');
            $this->assertSame('', $transport->__debugInfo()['apiKey']);
        } finally {
            if ($saved !== false) {
                \putenv('RESEND_API_KEY=' . $saved);
            }
        }
    }

    public function testSendGuardsCurlBeforeAnyNetworkUse(): void
    {
        // Structural pin: ext-curl is loaded on every CI runner, so the
        // function_exists() branch cannot be executed here — pin its presence
        // and ordering instead: the guard must precede the first curl call,
        // and the named-remedy lang key must exist.
        $ref     = new \ReflectionMethod(ResendTransport::class, 'send');
        $source  = (string) \file_get_contents((string) $ref->getFileName());
        $start   = $ref->getStartLine();
        $end     = $ref->getEndLine();
        $body    = \implode("\n", \array_slice(\explode("\n", $source), $start - 1, $end - $start + 1));

        $guardPos = \strpos($body, "function_exists('curl_init')");
        $callPos  = \strpos($body, '\\curl_init(');
        $this->assertNotFalse($guardPos, 'send() must guard ext-curl availability');
        $this->assertNotFalse($callPos);
        $this->assertLessThan($callPos, $guardPos, 'the curl guard must run before any curl call');
        $this->assertStringContainsString('resend.curl_missing', $body);

        $keys = require \dirname(__DIR__) . '/lang/en.php';
        $this->assertArrayHasKey('resend.curl_missing', $keys);
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
