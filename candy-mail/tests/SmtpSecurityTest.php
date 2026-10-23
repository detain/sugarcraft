<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\SmtpTransport;

/**
 * Regression tests for the SMTP transport TLS hardening:
 *  - implicit TLS (SMTPS/465) connects encrypted BEFORE any EHLO;
 *  - TLS is required by DEFAULT: a missing/failed STARTTLS upgrade aborts the
 *    send instead of degrading to plaintext;
 *  - AUTH LOGIN is refused on any cleartext channel, even with TLS waived;
 *  - withCaFile() threads a custom CA bundle into the ssl context;
 *  - the SMTP password is redacted from __debugInfo().
 *
 * The dialogue tests fork a tiny loopback fake-SMTP server bound to an
 * ephemeral 127.0.0.1 port with short timeouts, so they cannot hang CI.
 */
final class SmtpSecurityTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fix 1 — implicit TLS on 465 (connect encrypted before EHLO)
    // -------------------------------------------------------------------------

    public function testImplicitTlsPort465SelectsTlsTargetBeforeEhlo(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 465);

        // The connect target uses the tls:// scheme, so stream_socket_client()
        // completes the TLS handshake at connect — no plaintext EHLO can precede
        // crypto on port 465.
        $this->assertSame('tls://smtp.example.com:465', $this->invokePrivate($transport, 'connectTarget'));
        $this->assertTrue($this->readProperty($transport, 'implicitTls'));
    }

    public function testNon465SelectsPlaintextTarget(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $this->assertSame('tcp://smtp.example.com:587', $this->invokePrivate($transport, 'connectTarget'));
        $this->assertFalse($this->readProperty($transport, 'implicitTls'));
    }

    public function testWithImplicitTlsUpgradesTargetOnNonSmtpsPort(): void
    {
        $transport = (new SmtpTransport('smtp.example.com', 2525))->withImplicitTls();

        $this->assertSame('tls://smtp.example.com:2525', $this->invokePrivate($transport, 'connectTarget'));
        $this->assertTrue($this->readProperty($transport, 'implicitTls'));
    }

    public function testImplicitTlsContextVerifiesPeer(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 465);

        $options = $this->invokePrivate($transport, 'streamContextOptions');

        // Peer verification must stay ON — verify_peer is never blanket-disabled.
        $this->assertTrue($options['ssl']['verify_peer']);
        $this->assertTrue($options['ssl']['verify_peer_name']);
        $this->assertSame('smtp.example.com', $options['ssl']['peer_name']);
    }

    public function testPlaintextContextHasNoSslBlock(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $options = $this->invokePrivate($transport, 'streamContextOptions');

        $this->assertArrayNotHasKey('ssl', $options);
    }

    // -------------------------------------------------------------------------
    // Fix 2 — STARTTLS only when advertised; requireTls guards downgrade
    // -------------------------------------------------------------------------

    public function testRequireTlsThrowsWhenStarttlsNotOffered(): void
    {
        $this->requireFork();

        [$port, $pid, $log] = $this->forkFakeServer(advertiseStartTls: false);

        try {
            $transport = (new SmtpTransport('127.0.0.1', $port, '', '', 2))->withRequireTls();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('TLS is required');
            $transport->send($this->sampleEmail());
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testStarttlsSkippedWhenNotAdvertised(): void
    {
        $this->requireFork();

        [$port, $pid, $log] = $this->forkFakeServer(advertiseStartTls: false);

        try {
            // Opt-out (requireTls=false after docs-fix-1023 S-1 made TLS the
            // default) and no credentials: the send must complete in plaintext
            // without ever issuing STARTTLS.
            $transport = (new SmtpTransport('127.0.0.1', $port, '', '', 2))->withRequireTls(false);
            $transport->send($this->sampleEmail());

            $this->reap($pid);
            $dialogue = (string) \file_get_contents($log);

            $this->assertStringContainsString('MAIL FROM', $dialogue);
            $this->assertStringNotContainsString('STARTTLS', $dialogue);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    // -------------------------------------------------------------------------
    // Fix 2b — TLS required by default + AUTH never rides plaintext (S-1)
    // -------------------------------------------------------------------------

    public function testRequireTlsIsTheConstructorDefault(): void
    {
        foreach ([25, 587] as $port) {
            $transport = new SmtpTransport('smtp.example.com', $port);
            $this->assertTrue($this->readProperty($transport, 'requireTls'), "port {$port}");
        }
        $this->assertTrue($this->readProperty(new SmtpTransport('smtp.example.com', 465), 'requireTls'));
    }

    public function testSendRefusesStrippedStarttlsByDefault(): void
    {
        $this->requireFork();

        [$port, $pid, $log] = $this->forkFakeServer(advertiseStartTls: false);

        try {
            // No withRequireTls() call at all: the default must refuse the
            // downgrade that a STARTTLS-stripping middlebox would achieve.
            $transport = new SmtpTransport('127.0.0.1', $port, '', '', 2);

            try {
                $transport->send($this->sampleEmail());
                $this->fail('expected the stripped-STARTTLS downgrade to be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('TLS is required', $e->getMessage());
            }

            $this->reap($pid);
            $dialogue = (string) \file_get_contents($log);

            // The envelope never reached the wire.
            $this->assertStringNotContainsString('MAIL FROM', $dialogue);
            $this->assertStringNotContainsString('DATA', $dialogue);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testAuthLoginRefusedOnCleartextChannel(): void
    {
        $this->requireFork();

        $server = $this->forkFakeServer(advertiseStartTls: false, advertiseAuth: true);
        [$port, $pid, $log] = $server;

        try {
            // Opted out of TLS but supplied credentials: AUTH LOGIN carries
            // base64 secrets and must be refused outright (fail-loud).
            $transport = (new SmtpTransport('127.0.0.1', $port, 'user@example.com', 'topSecretPassw0rd', 2))
                ->withRequireTls(false);

            try {
                $transport->send($this->sampleEmail());
                $this->fail('expected AUTH on a cleartext channel to be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('insecure', $e->getMessage());
            }

            $this->reap($pid);
            $dialogue = (string) \file_get_contents($log);

            $this->assertStringNotContainsString('AUTH LOGIN', $dialogue);
            $this->assertStringNotContainsString(\base64_encode('user@example.com'), $dialogue);
            $this->assertStringNotContainsString(\base64_encode('topSecretPassw0rd'), $dialogue);
            $this->assertStringNotContainsString('MAIL FROM', $dialogue);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testAuthLoginProceedsWhenChannelIsEncrypted(): void
    {
        // Positive arm so the refusal guard cannot rot into an unconditional
        // throw: with encrypted=true (as after a successful upgrade) the AUTH
        // LOGIN dialogue runs unchanged over a socketpair.
        [$client, $server] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsNotBool($client);
        $this->assertIsNotBool($server);

        try {
            $transport = new SmtpTransport('smtp.example.com', 587, 'user@example.com', 's3cret');

            $socketProp = new \ReflectionProperty($transport, 'socket');
            $socketProp->setAccessible(true);
            $socketProp->setValue($transport, $client);

            $encryptedProp = new \ReflectionProperty($transport, 'encrypted');
            $encryptedProp->setAccessible(true);
            $encryptedProp->setValue($transport, true);

            $lastProp = new \ReflectionProperty($transport, 'lastResponse');
            $lastProp->setAccessible(true);
            $lastProp->setValue($transport, "250-AUTH LOGIN PLAIN\r\n250 HELP");

            // Pre-load the server side of the pair with the 334/334/235 prompts.
            \fwrite($server, "334 VXNlcm5hbWU6\r\n334 UGFzc3dvcmQ6\r\n235 ok\r\n");

            $this->invokePrivate($transport, 'authenticateIfNeeded');

            $seen = '';
            for ($i = 0; $i < 3; $i++) {
                $line = \fgets($server);
                if ($line === false) {
                    break;
                }
                $seen .= $line;
            }
            $this->assertStringContainsString("AUTH LOGIN\r\n", $seen);
            $this->assertStringContainsString(\base64_encode('user@example.com') . "\r\n", $seen);
            $this->assertStringContainsString(\base64_encode('s3cret') . "\r\n", $seen);
        } finally {
            @\fclose($client);
            @\fclose($server);
        }
    }

    public function testCaFileIsImmutableAndFeedsTheSslContext(): void
    {
        $base = new SmtpTransport('smtp.example.com', 465);
        $with = $base->withCaFile('/etc/ssl/private/mail-ca.pem');

        $this->assertNotSame($base, $with);
        $this->assertNull($this->readProperty($base, 'caFile'));
        $this->assertSame('/etc/ssl/private/mail-ca.pem', $this->readProperty($with, 'caFile'));

        $options = $this->invokePrivate($with, 'streamContextOptions');
        $this->assertSame('/etc/ssl/private/mail-ca.pem', $options['ssl']['cafile']);
        $this->assertTrue($options['ssl']['verify_peer']);

        // Default context carries no cafile: the system trust store stands.
        $defaultOptions = $this->invokePrivate($base, 'streamContextOptions');
        $this->assertArrayNotHasKey('cafile', $defaultOptions['ssl']);

        // Empty path clears the override again.
        $this->assertNull($this->readProperty($with->withCaFile(''), 'caFile'));
    }

    // -------------------------------------------------------------------------
    // Fix 3 — password redaction
    // -------------------------------------------------------------------------

    public function testDebugInfoMasksPassword(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, 'user@example.com', 'topSecretPassw0rd');

        $info = $transport->__debugInfo();
        $this->assertSame('****', $info['password']);

        // The secret must not appear anywhere in a dump of the object.
        $dump = \var_export($info, true) . \print_r($info, true);
        $this->assertStringNotContainsString('topSecretPassw0rd', $dump);

        // ...but the real value stays usable internally.
        $this->assertSame('topSecretPassw0rd', $this->readProperty($transport, 'password'));
    }

    public function testDebugInfoLeavesEmptyPasswordEmpty(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $this->assertSame('', $transport->__debugInfo()['password']);
    }

    // -------------------------------------------------------------------------
    // Fix 4 — attachment header injection (docs-fix-1023 C-2)
    // -------------------------------------------------------------------------

    public function testAttachmentFilenameCarryingCrlfIsRefused(): void
    {
        $forged = "evil.txt\r\nX-Injected: YES\r\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected in attachment filename header');

        \SugarCraft\Mail\Attachment::fromContent('payload', $forged);
    }

    public function testAttachmentFromPathForgedDisplayNameIsRefused(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-sec-att-');
        \file_put_contents($tmp, 'x');
        try {
            $this->expectException(\InvalidArgumentException::class);
            \SugarCraft\Mail\Attachment::fromPath($tmp, "ok.txt\r\nX-Injected: 1");
        } finally {
            @\unlink($tmp);
        }
    }

    public function testAttachmentMimeTypeCarryingCrlfIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected in attachment MIME type header');

        \SugarCraft\Mail\Attachment::fromContent('x', 'a.txt', "text/plain\r\nX-Injected2: MIME");
    }

    public function testInlineAttachmentForgedFilenameIsRefused(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-sec-inline-');
        \file_put_contents($tmp, "\x89PNG");
        try {
            $this->expectException(\InvalidArgumentException::class);
            \SugarCraft\Mail\Attachment::inline($tmp, 'cid-1', "a.png\r\nX-Foo: bar");
        } finally {
            @\unlink($tmp);
        }
    }

    public function testQuoteAndBackslashInFilenameAreEscapedInPartHeaders(): void
    {
        $email = new Email(
            from:  ['from@example.com'],
            to:    ['to@example.com'],
            body:  'x',
            attachments: [\SugarCraft\Mail\Attachment::fromContent('data', 'a"b\\c.txt', 'text/plain')],
        );
        $mime = (new SmtpTransport('smtp.example.com', 587))->buildMimeMessage($email);

        // RFC 2045 quoted-string: every interior " and \ rides escaped, so the
        // parameter cannot terminate early and forge a header tail.
        $this->assertStringContainsString('name="a\\"b\\\\c.txt"', $mime);
        $this->assertStringContainsString('filename="a\\"b\\\\c.txt"', $mime);
        $this->assertStringNotContainsString('name="a"b', $mime);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sampleEmail(): Email
    {
        return Email::new('from@example.com', 'to@example.com', 'Subject', 'Body');
    }

    private function requireFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl not available');
        }
    }

    /**
     * Fork a minimal fake SMTP server on an ephemeral loopback port.
     *
     * The child records every line the client sends to $log, then hard-exits so
     * it never re-enters PHPUnit's shutdown handlers.
     *
     * @param bool $advertiseStartTls include 250-STARTTLS in the EHLO list
     * @param bool $advertiseAuth     include 250-AUTH LOGIN PLAIN in the list
     *
     * @return array{0:int,1:int,2:string} [port, childPid, dialogueLogPath]
     */
    private function forkFakeServer(bool $advertiseStartTls, bool $advertiseAuth = false): array
    {
        $errno  = 0;
        $errstr = '';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("cannot bind loopback server: {$errstr}");
        }
        $name = \stream_socket_get_name($server, false);
        $port = (int) \substr((string) $name, \strrpos((string) $name, ':') + 1);
        $log  = (string) \tempnam(\sys_get_temp_dir(), 'sp-smtp-dialogue-');

        $pid = \pcntl_fork();
        if ($pid === -1) {
            @\fclose($server);
            @\unlink($log);
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            $captured = '';
            $conn = @\stream_socket_accept($server, 3);
            if ($conn !== false) {
                \stream_set_timeout($conn, 2);
                @\fwrite($conn, "220 fake ESMTP ready\r\n");
                for ($i = 0; $i < 200; $i++) {
                    $line = \fgets($conn);
                    if ($line === false || $line === '') {
                        break;
                    }
                    $captured .= $line;
                    $cmd = \strtoupper(\substr(\ltrim($line), 0, 4));
                    if ($cmd === 'EHLO' || $cmd === 'HELO') {
                        $resp = "250-fake greets you\r\n";
                        if ($advertiseStartTls) {
                            $resp .= "250-STARTTLS\r\n";
                        }
                        if ($advertiseAuth) {
                            $resp .= "250-AUTH LOGIN PLAIN\r\n";
                        }
                        $resp .= "250 HELP\r\n";
                        @\fwrite($conn, $resp);
                    } elseif ($cmd === 'MAIL' || $cmd === 'RCPT') {
                        @\fwrite($conn, "250 OK\r\n");
                    } elseif ($cmd === 'DATA') {
                        @\fwrite($conn, "354 go\r\n");
                        while (($d = \fgets($conn)) !== false && $d !== '') {
                            $captured .= $d;
                            if (\rtrim($d, "\r\n") === '.') {
                                break;
                            }
                        }
                        @\fwrite($conn, "250 queued\r\n");
                    } elseif ($cmd === 'QUIT') {
                        @\fwrite($conn, "221 bye\r\n");
                        break;
                    } else {
                        @\fwrite($conn, "250 OK\r\n");
                    }
                }
                @\fclose($conn);
            }
            @\fclose($server);
            @\file_put_contents($log, $captured);
            // Hard-exit: skip inherited PHPUnit shutdown handlers to avoid
            // duplicated test output from the forked child.
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0);
        }

        @\fclose($server); // parent drives the client only; child owns accept()
        return [$port, $pid, $log];
    }

    private function reap(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        $status = 0;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $res = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($res === $pid || $res === -1) {
                return;
            }
            \usleep(20000);
        }
        if (\function_exists('posix_kill')) {
            @\posix_kill($pid, \SIGKILL);
        }
        \pcntl_waitpid($pid, $status);
    }

    private function invokePrivate(object $obj, string $method): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj);
    }

    private function readProperty(object $obj, string $property): mixed
    {
        $ref = new \ReflectionProperty($obj, $property);
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }
}
