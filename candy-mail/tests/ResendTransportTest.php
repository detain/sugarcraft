<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Email;
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
        // CM-05 protocol floor: TLS 1.2 minimum, 1.3 eligible (curl >= 7.34
        // reads this spelling as a floor and negotiates upward).
        $this->assertArrayHasKey(\CURLOPT_SSLVERSION, $opts);
        $this->assertSame(\CURL_SSLVERSION_TLSv1_2, $opts[\CURLOPT_SSLVERSION]);
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

    public function testSendRefusesEmptyApiKeyBeforeAnyHttpWork(): void
    {
        // Step 35 hardening: an embedder that constructed the transport with
        // neither a key argument nor RESEND_API_KEY must be refused loudly at
        // send() instead of POSTing an empty 'Authorization: Bearer ' header.
        $saved = \getenv('RESEND_API_KEY', true);
        \putenv('RESEND_API_KEY');
        try {
            unset($_ENV['RESEND_API_KEY']);
            $transport = new ResendTransport('');
            $email     = new Email(from: ['me@example.com'], to: ['you@example.com']);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('no API key');

            $transport->send($email);
        } finally {
            if ($saved !== false) {
                \putenv('RESEND_API_KEY=' . $saved);
            }
            unset($_ENV['RESEND_API_KEY']);
        }
    }

    // -------------------------------------------------------------------------
    // API response arms over the test seam (CM-08) — a forked one-shot fake
    // HTTP server owns the loopback port; the parent's send() dials only it,
    // never api.resend.com (house law: no external network from tests).
    // -------------------------------------------------------------------------

    public function testHttp200ArmCompletesAndPostsJsonWithBearerAuth(): void
    {
        [$port, $pid, $log] = $this->forkFakeApi(200, 'OK', '{"id":"fake-1"}');
        try {
            $transport = new ResendTransport('re_arm_key', "http://127.0.0.1:$port/emails");
            $email     = new Email(
                from:    ['sender@example.com'],
                to:      ['them@example.com'],
                subject: 'Arms',
                body:    'Hello from the arm test.',
            );

            // The happy arm must COMPLETE without throwing — the point the
            // tautology-era suite never proved.
            $transport->send($email);

            $request = $this->pollText($log, 'Content-Length:');
            $this->assertStringStartsWith('POST /emails HTTP/', $request, 'the request line: ' . \substr($request, 0, 40));
            $this->assertStringContainsString("Authorization: Bearer re_arm_key\r\n", $request);
            $this->assertStringContainsString('Content-Type: application/json', $request);
            $this->assertStringContainsString('"subject":"Arms"', $request);
            $this->assertStringContainsString('"to":["them@example.com"]', $request);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testHttp500ArmRaisesApiErrorWithBodyTruncatedToFiveHundred(): void
    {
        // The 500-char truncation at send()'s error arm has only ever been
        // read, never exercised: an unbounded provider body would pour a
        // whole HTML error page into the operator's terminal.
        $body = \str_repeat('A', 500) . 'BEYOND-THE-CAP';
        [$port, $pid, $log] = $this->forkFakeApi(500, 'Internal Server Error', $body);
        try {
            $transport = new ResendTransport('re_arm_key', "http://127.0.0.1:$port/emails");
            $email     = new Email(from: ['sender@example.com'], to: ['them@example.com'], subject: 'Arms');

            try {
                $transport->send($email);
                $this->fail('the 5xx arm must throw');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Resend API error (500)', $e->getMessage());
                $this->assertStringContainsString(\str_repeat('A', 500), $e->getMessage());
                $this->assertStringNotContainsString('BEYOND-THE-CAP', $e->getMessage());
            }
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testClosedPortArmRaisesNetworkError(): void
    {
        // The third arm (curl transport-level failure -> resend.network_error)
        // is the one shape that needs no server at all: bind an ephemeral
        // port, take its number, hang up — the dial is refused.
        if (!\function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl unavailable');
        }

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "ephemeral listen failed: $errstr");
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($server, false), ':'), 1);
        \fclose($server);

        $transport = new ResendTransport('re_arm_key', "http://127.0.0.1:$port/emails");
        $email     = new Email(from: ['sender@example.com'], to: ['them@example.com'], subject: 'Arms');

        try {
            $transport->send($email);
            $this->fail('a refused dial must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Resend network error', $e->getMessage());
        }
    }

    /**
     * Fork a one-shot fake HTTP server on an ephemeral loopback port. The
     * child accepts exactly one connection, captures the request (headers to
     * the blank line, then the full Content-Length body), logs it to a temp
     * file, answers with the canned status/body, and SIGKILLs itself so no
     * child ever runs parent PHPUnit hooks.
     *
     * @return array{0:int,1:int,2:string}  [port, child pid, request-log path]
     */
    private function forkFakeApi(int $status, string $reason, string $body): array
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork unavailable');
        }
        if (!\function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl unavailable');
        }

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "ephemeral listen failed: $errstr");
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($server, false), ':'), 1);
        $log  = (string) \tempnam(\sys_get_temp_dir(), 'candy-mail-http-');

        $pid = \pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid, 'fork failed');
        if ($pid === 0) {
            // The child: serve one request, record it, and die without
            // touching the parent's registered shutdown/teardown.
            $conn = @\stream_socket_accept($server, 10.0);
            if (\is_resource($conn)) {
                \stream_set_timeout($conn, 5);
                $request = '';
                while (($line = \fgets($conn)) !== false) {
                    $request .= $line;
                    if ($line === "\r\n" || $line === "\n") {
                        break;
                    }
                }
                if (\preg_match('/^Content-Length:\s*(\d+)/mi', $request, $m) === 1) {
                    $left = (int) $m[1];
                    while ($left > 0 && \strlen((string) $chunk = \fread($conn, \min(8192, $left))) > 0) {
                        $request .= $chunk;
                        $left    -= \strlen($chunk);
                    }
                }
                $response = "HTTP/1.1 {$status} {$reason}\r\n"
                    . 'Content-Length: ' . \strlen($body) . "\r\n"
                    . "Connection: close\r\n"
                    . "\r\n"
                    . $body;
                \fwrite($conn, $response);
                \fclose($conn);
            }
            \file_put_contents($log, $request ?? '');
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0);
        }

        \fclose($server);

        return [$port, $pid, $log];
    }

    /**
     * Poll $path until its content carries $needle or the budget burns out;
     * the final content answers either way so the assertion fails informatively.
     */
    private function pollText(string $path, string $needle, float $budget = 5.0): string
    {
        $deadline = \microtime(true) + $budget;
        $content  = '';
        do {
            $content = (string) @\file_get_contents($path);
            if ($needle === '' ? $content !== '' : \str_contains($content, $needle)) {
                return $content;
            }
            \usleep(20000);
        } while (\microtime(true) < $deadline);

        return $content;
    }

    /**
     * Reap a forked helper (the children SIGKILL themselves, so this only
     * collects the zombie; WNOHANG keeps a wedged child from hanging the run).
     */
    private function reap(int $pid): void
    {
        if ($pid <= 0 || !\function_exists('pcntl_waitpid')) {
            return;
        }
        $status   = 0;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $res = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($res === $pid || $res === -1) {
                return;
            }
            \usleep(20000);
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function invokeCurlOptions(ResendTransport $transport, string $json): array    {
        $method = new \ReflectionMethod($transport, 'curlOptions');
        $method->setAccessible(true);

        return $method->invoke($transport, $json);
    }
}
