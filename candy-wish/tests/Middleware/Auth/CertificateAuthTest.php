<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Auth\CertificateAuth;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class CertificateAuthTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset($_SERVER['SSL_CLIENT_CERT'], $_SERVER['SSH_CLIENT_CERT'], $_SERVER['CERTIFICATE']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    private function stderr(): array
    {
        $r = fopen('php://memory', 'w+');
        $this->assertNotFalse($r);
        return [$r, fn() => $this->readAll($r)];
    }

    private function readAll($r): string
    {
        rewind($r);
        return (string) stream_get_contents($r);
    }

    public function testPassesThroughWhenCertValid(): void
    {
        $_SERVER['SSL_CLIENT_CERT'] = "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----";
        [$err] = $this->stderr();
        $a = new CertificateAuth(fn($cert, $s) => str_contains($cert, 'TEST'), true, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsInvalidCert(): void
    {
        $_SERVER['SSL_CLIENT_CERT'] = "-----BEGIN CERTIFICATE-----\nNOTVALIDPEM\n-----END CERTIFICATE-----";
        [$err, $read] = $this->stderr();
        $a = new CertificateAuth(fn($cert, $s) => str_contains($cert, 'TRUSTEDPEM'), true, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Certificate rejected', $read());
    }

    public function testRejectsWhenNoCertAndRequired(): void
    {
        [$err, $read] = $this->stderr();
        $a = new CertificateAuth(fn($cert, $s) => true, true, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Certificate required but none presented', $read());
    }

    public function testPassesThroughWhenNoCertAndNotRequired(): void
    {
        [$err] = $this->stderr();
        $a = new CertificateAuth(fn($cert, $s) => true, false, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testReadsSshClientCertEnvVar(): void
    {
        $_SERVER['SSH_CLIENT_CERT'] = "-----BEGIN CERTIFICATE-----\nSSH_CERT\n-----END CERTIFICATE-----";
        [$err] = $this->stderr();
        $a = new CertificateAuth(fn($cert, $s) => str_contains($cert, 'SSH_CERT'), true, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testValidatorReceivesSession(): void
    {
        $_SERVER['SSL_CLIENT_CERT'] = "-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----";
        [$err] = $this->stderr();
        $receivedSession = null;
        $a = new CertificateAuth(function ($cert, $s) use (&$receivedSession): bool {
            $receivedSession = $s;
            return true;
        }, true, $err);
        $a->handle(Context::background(), $this->session(), function (): void {});
        $this->assertInstanceOf(Session::class, $receivedSession);
        $this->assertSame('alice', $receivedSession->user);
    }
}
