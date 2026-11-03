<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Middleware\Auth;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware\Auth\CertificateAuth;
use SugarCraft\SshServer\Session;
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

    /**
     * Audit K6: an optional gate with nothing to read used to pass in silence, so a
     * misdeployed deployment looked configured. The pass must now be announced.
     */
    public function testTheOptionalGateSaysOutLoudThatItCheckedNothing(): void
    {
        [$err, $read] = $this->stderr();
        $a = new CertificateAuth(fn($cert, $s) => true, false, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached, 'optional gate still forwards');
        $notice = $read();
        $this->assertStringContainsString('checked nothing', $notice);
        $this->assertStringContainsString('SSL_CLIENT_CERT', $notice, 'the notice names where it looked');
        $this->assertStringContainsString('CERTIFICATE', $notice);
    }

    /** The required path keeps denying, and now says the delivery failed, not just "none". */
    public function testTheRequiredGateNamesTheDeliveryProblemAlongsideTheDenial(): void
    {
        [$err, $read] = $this->stderr();
        $a = new CertificateAuth(fn($cert, $s) => true, true, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session(), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $notice = $read();
        $this->assertStringContainsString('checked nothing', $notice);
        $this->assertStringContainsString('Certificate required but none presented', $notice);
    }

    /**
     * The probe list is public so docs and operators can name the surface, and the
     * class doc has to say plainly that stock sshd fills none of it (audit K6).
     */
    public function testTheProbeListIsPublicAndTheClassSaysSshdSetsNoneOfIt(): void
    {
        $this->assertSame(['SSL_CLIENT_CERT', 'SSH_CLIENT_CERT', 'CERTIFICATE'], CertificateAuth::ENV_KEYS);

        $src = \file_get_contents(\dirname(__DIR__, 3) . '/src/Middleware/Auth/CertificateAuth.php');
        $this->assertNotFalse($src);
        $flat = (string) \preg_replace('/\s+/u', ' ', $src);
        $this->assertStringContainsString('Nothing in stock OpenSSH', $flat);
        $this->assertStringContainsString('SSH_USER_AUTH', $flat, 'the doc points at the real sshd auth blob');
    }
}
