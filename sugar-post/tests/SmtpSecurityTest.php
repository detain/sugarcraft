<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Email;
use SugarCraft\Post\SmtpTransport;

/**
 * Regression tests for the SMTP transport TLS hardening:
 *  - implicit TLS (SMTPS/465) connects encrypted BEFORE any EHLO;
 *  - STARTTLS is only issued when advertised, and requireTls closes the
 *    plaintext-downgrade hole;
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
            // Opportunistic (default requireTls=false), no credentials: the send
            // must complete in plaintext without ever issuing STARTTLS.
            $transport = new SmtpTransport('127.0.0.1', $port, '', '', 2);
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
     * @return array{0:int,1:int,2:string} [port, childPid, dialogueLogPath]
     */
    private function forkFakeServer(bool $advertiseStartTls): array
    {
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
