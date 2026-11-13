<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\SmtpTransport;

/**
 * Real encrypted-channel harness (CM-09, closes 009).
 *
 * The TLS arms were previously provable only through reflection on context
 * option arrays — the handshake itself was never walked. This suite mints an
 * ephemeral self-signed cert with the OpenSSL bindings (no exec, no durable
 * on-disk key material), forks a minimal TLS-terminating fake SMTP server on
 * the loopback, and pins:
 *
 *  (a) STARTTLS completes, AUTH happens ONLY post-upgrade, and the post-TLS
 *      EHLO is re-issued (the capability list the client trusts is the
 *      encrypted one);
 *  (b) implicit-TLS (smtps://) connects encrypted from byte one;
 *  (c) a custom CA file that is NOT the peer's issuer refuses the handshake
 *      — the fail-closed verification proof (flip verify_peer off and this
 *      test goes red — the crown arm);
 *
 * Everything dials 127.0.0.1 only — the house law of never touching external
 * network from tests is held literally.
 */
final class TlsChannelTest extends TestCase
{
    private static string $serverPem = '';
    private static string $alienPem  = '';
    /** @var list<string> */
    private static array $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::harnessAvailable()) {
            return;
        }
        // The peer identity this harness verifies is the loopback literal, so
        // the CN is exactly what SmtpTransport sets peer_name to.
        self::$serverPem = self::writeTemp(self::mintSelfSignedPem('127.0.0.1'));
        self::$alienPem  = self::writeTemp(self::mintSelfSignedPem('alien.invalid'));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$tempFiles as $f) {
            @\unlink($f);
        }
        self::$tempFiles = [];
    }

    private static function harnessAvailable(): bool
    {
        return \extension_loaded('openssl')
            && \function_exists('openssl_pkey_new')
            && \function_exists('pcntl_fork');
    }

    private function requireHarness(): void
    {
        if (!\extension_loaded('openssl') || !\function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('ext-openssl unavailable');
        }
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork unavailable');
        }
    }

    /**
     * Pure-PHP ephemeral self-signed X.509: key + CSR + sign, concatenated
     * into one PEM (the ssl context wants cert and private key together).
     * Assertions are thrown as failures via the caller context; this is
     * static so class setUp can use it.
     */
    private static function mintSelfSignedPem(string $cn): string
    {
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . \openssl_error_string());
        }
        $csr = \openssl_csr_new(['commonName' => $cn], $key);
        if ($csr === false) {
            throw new \RuntimeException('openssl_csr_new failed: ' . \openssl_error_string());
        }
        $crt = \openssl_csr_sign($csr, null, $key, 1);
        if ($crt === false) {
            throw new \RuntimeException('openssl_csr_sign failed: ' . \openssl_error_string());
        }
        if (!\openssl_x509_export($crt, $certPem) || !\openssl_pkey_export($key, $keyPem)) {
            throw new \RuntimeException('PEM export failed: ' . \openssl_error_string());
        }

        return $certPem . "\n" . $keyPem;
    }

    private static function writeTemp(string $contents): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'candy-mail-tls-');
        \file_put_contents($path, $contents);
        self::$tempFiles[] = $path;

        return $path;
    }

    private function email(): Email
    {
        return new Email(
            from:    ['sender@example.com'],
            to:      ['them@example.com'],
            subject: 'TLS arms',
            body:    'Encrypted leg.',
        );
    }

    public function testStartTlsCompletesAndAuthTravelsOnlyPostUpgrade(): void
    {
        $this->requireHarness();
        [$port, $pid, $log] = $this->forkFakeSmtp(starttls: true);

        try {
            $transport = (new SmtpTransport(
                host:     '127.0.0.1',
                port:     $port,
                username: 'relay-user',
                password: 'relay-pass',
            ))->withCaFile(self::$serverPem); // trust our ephemeral issuer

            $transport->send($this->email());

            $transcript = $this->pollText($log, 'QUIT', 8.0);
            $lines      = \array_values(\array_filter(\explode("\n", $transcript)));

            $indexOf = static function (string $needle) use ($lines): int {
                foreach ($lines as $i => $line) {
                    if (\str_starts_with($line, $needle)) {
                        return $i;
                    }
                }

                return -1;
            };

            // The ordering law the whole TLS design rests on:
            $start = $indexOf('EHLO');
            $tls   = $indexOf('STARTTLS');
            $upg   = $indexOf('[TLS-UPGRADED]');
            $auth  = $indexOf('AUTH');
            $this->assertGreaterThanOrEqual(0, $start, 'EHLO seen: ' . $transcript);
            $this->assertGreaterThan($start, $tls, 'STARTTLS after first EHLO');
            $this->assertGreaterThan($tls, $upg, 'handshake completed after STARTTLS');
            $this->assertGreaterThan($upg, $auth, 'AUTH travelled ONLY over the encrypted leg');
            // Re-EHLO after upgrade (post-TLS capability list is the trusted one):
            $reEhlo = 0;
            foreach ($lines as $line) {
                if (\str_starts_with($line, 'EHLO')) {
                    $reEhlo++;
                }
            }
            $this->assertGreaterThanOrEqual(2, $reEhlo, 'client re-EHLOs after STARTTLS');
            $this->assertGreaterThan($auth, $indexOf('MAIL FROM'), 'conversation continued');
            $this->assertGreaterThan(0, $indexOf('QUIT'));
        } finally {
            $this->reap($pid);
        }
    }

    public function testImplicitTlsConnectsEncryptedFromByteOne(): void
    {
        $this->requireHarness();
        [$port, $pid, $log] = $this->forkFakeSmtp(starttls: false);

        try {
            $transport = (new SmtpTransport(
                host:     '127.0.0.1',
                port:     $port,
                username: 'relay-user',
                password: 'relay-pass',
            ))
                ->withImplicitTls(true)
                ->withCaFile(self::$serverPem);

            $transport->send($this->email());

            $transcript = $this->pollText($log, 'QUIT', 8.0);
            // The tls:// server answers in ciphertext from the accept; a
            // reaching QUIT through that socket IS the implicit-TLS proof.
            $this->assertStringNotContainsString('STARTTLS', $transcript, 'STARTTLS is a protocol error on an already-secured channel');
            $this->assertMatchesRegularExpression('/^AUTH LOGIN/m', $transcript);
        } finally {
            $this->reap($pid);
        }
    }

    public function testForeignCaFileRefusesTheSelfSignedPeer(): void
    {
        // The crown arm: an unrelated CA must NOT be able to vouch for this
        // peer. With verify_peer honoured the handshake dies before a single
        // SMTP byte trades — stub verify_peer off and this test goes red.
        $this->requireHarness();
        [$port, $pid, $log] = $this->forkFakeSmtp(starttls: true);

        try {
            $transport = (new SmtpTransport(
                host:     '127.0.0.1',
                port:     $port,
                username: 'relay-user',
                password: 'relay-pass',
            ))->withCaFile(self::$alienPem);

            try {
                $transport->send($this->email());
                $this->fail('a foreign CA must never authenticate the peer');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('STARTTLS negotiation failed', $e->getMessage());
            }

            // The refusal happened BEFORE any credential traded hands:
            $transcript = (string) @\file_get_contents($log);
            $this->assertStringNotContainsString('AUTH', $transcript, 'no credentials after a refused handshake');
        } finally {
            $this->reap($pid);
        }
    }

    /**
     * Fork the fake server. With starttls=true the listener speaks plaintext
     * until STARTTLS; with starttls=false the listener itself is tls:// from
     * the accept (implicit mode). The child logs every command line it reads
     * to a temp file (plus a [TLS-UPGRADED] marker) and SIGKILLs itself after
     * the conversation, so no child ever runs parent teardown hooks.
     *
     * @return array{0:int,1:int,2:string}
     */
    private function forkFakeSmtp(bool $starttls): array
    {
        $ctx = \stream_context_create([
            'ssl' => [
                'local_cert'        => self::$serverPem,
                'capture_peer_cert' => false,
                'allow_self_signed' => true,
            ],
        ]);
        $scheme = $starttls ? 'tcp' : 'tls';
        $server = @\stream_socket_server("$scheme://127.0.0.1:0", $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $ctx);
        $this->assertIsResource($server, "fake server listen failed: $errstr");
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($server, false), ':'), 1);
        $log  = (string) \tempnam(\sys_get_temp_dir(), 'candy-mail-tlslog-');

        $pid = \pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid, 'fork failed');
        if ($pid === 0) {
            $this->serveFakeSmtp($server, $log, $starttls);
            exit(0);
        }
        \fclose($server);

        return [$port, $pid, $log];
    }

    /** @param resource $server */
    private function serveFakeSmtp($server, string $log, bool $starttls): void
    {
        $conn = @\stream_socket_accept($server, 15.0);
        if (!\is_resource($conn)) {
            \file_put_contents($log, "[ACCEPT-FAILED]\n");
            exit(0);
        }
        $enc = false;

        $logline = static function (string $line) use ($log): void {
            \file_put_contents($log, $line . "\n", \FILE_APPEND | \LOCK_EX);
        };
        $send = static function (string $out) use ($conn): void {
            \fwrite($conn, $out);
        };

        $send("220 fake.mail ESMRT candy-mail-harness\r\n");
        $postTls = !$starttls; // implicit mode: the list is always the encrypted one

        while (($line = \fgets($conn)) !== false) {
            $line   = \rtrim($line, "\r\n");
            $verb   = \strtoupper(\strtok($line, ' '));
            $logline($line);

            switch ($verb) {
                case 'EHLO':
                    // One keyword per line immediately after the code-dash —
                    // exactly the grammar SmtpTransport::hasExtension parses.
                    if ($starttls && !$postTls) {
                        $send("250-fake.mail greets\r\n250-STARTTLS\r\n250-AUTH LOGIN PLAIN\r\n250 OK\r\n");
                    } else {
                        $send("250-fake.mail greets\r\n250-8BITMIME\r\n250-AUTH LOGIN PLAIN\r\n250 OK\r\n");
                    }
                    break;
                case 'STARTTLS':
                    $send("220 Ready to start TLS\r\n");
                    // The ssl options live on the SERVER context; the accepted
                    // connection needs them applied explicitly before the
                    // upgrade, or enable_crypto negotiates without a cert.
                    \stream_context_set_option($conn, 'ssl', 'local_cert', self::$serverPem);
                    \stream_context_set_option($conn, 'ssl', 'allow_self_signed', true);
                    $ok = \stream_socket_enable_crypto($conn, true, \STREAM_CRYPTO_METHOD_TLS_SERVER);
                    // Server-side completion may answer bool true rather than
                    // the int 1 the docs promise for the client arm — accept
                    // both here; the CLIENT's strict !== 1 discipline is what
                    // the crown arm proves under (and CM-24 types the pair).
                    if ($ok !== 1 && $ok !== true) {
                        $logline('[TLS-FAILED]');
                        break 2;
                    }
                    $postTls = true;
                    $logline('[TLS-UPGRADED]');
                    break;
                case 'AUTH':
                    if (!$postTls) {
                        $logline('[AUTH-PRE-TLS!]');
                    }
                    if (\str_starts_with($line, 'AUTH LOGIN')) {
                        $send("334 VXNlcm5hbWU6\r\n");
                        $u = \fgets($conn);
                        $logline('B64:' . \trim((string) $u));
                        $send("334 UGFzc3dvcmQ6\r\n");
                        $p = \fgets($conn);
                        $logline('B64:' . \trim((string) $p));
                        $send("235 Authentication successful\r\n");
                    } elseif (\str_starts_with($line, 'AUTH PLAIN ')) {
                        $send("235 Authentication successful\r\n");
                    } else {
                        $send("504 unsupported\r\n");
                    }
                    break;
                case 'MAIL':
                    $send("250 OK\r\n");
                    break;
                case 'RCPT':
                    $send("250 OK\r\n");
                    break;
                case 'DATA':
                    $send("354 End data with <CR><LF>.<CR><LF>\r\n");
                    // Drain to the terminator line so the client's 250-await is honest.
                    while (($d = \fgets($conn)) !== false) {
                        if ($d === ".\r\n" || $d === ".\n") {
                            break;
                        }
                    }
                    $send("250 OK: queued\r\n");
                    break;
                case 'QUIT':
                    $send("221 Bye\r\n");
                    \fclose($conn);
                    if (\function_exists('posix_kill')) {
                        \posix_kill(\getmypid(), \SIGKILL);
                    }
                    break 2;
                default:
                    $send("250 unhandled\r\n");
            }
        }
        if (\function_exists('posix_kill')) {
            \posix_kill(\getmypid(), \SIGKILL);
        }
    }

    private function pollText(string $path, string $needle, float $budget = 5.0): string
    {
        $deadline = \microtime(true) + $budget;
        $content  = '';
        do {
            $content = (string) @\file_get_contents($path);
            if (\str_contains($content, $needle)) {
                return $content;
            }
            \usleep(20000);
        } while (\microtime(true) < $deadline);

        return $content;
    }

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
}
