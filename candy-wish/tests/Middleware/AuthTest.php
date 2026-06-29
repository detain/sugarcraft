<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Auth;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['SSH_USER_AUTH'], $_SERVER['KEY_FINGERPRINT']);
    }

    protected function tearDown(): void
    {
        // Restore the exact $_SERVER state from before setUp(), then
        // explicitly ensure the env vars we test are absent.
        $_SERVER = $this->serverBackup;
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['SSH_USER_AUTH'], $_SERVER['KEY_FINGERPRINT']);
    }

    private function session(string $user): Session
    {
        return new Session(
            user: $user, clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
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

    public function testAllowsAnyUserWhenListEmpty(): void
    {
        [$err] = $this->stderr();
        $a = new Auth([], [], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('anybody'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsUnknownUser(): void
    {
        [$err, $read] = $this->stderr();
        $a = new Auth(['alice', 'bob'], [], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('eve'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Unauthorized', $read());
        $this->assertStringContainsString('eve',          $read());
    }

    public function testAllowsKnownUser(): void
    {
        [$err] = $this->stderr();
        $a = new Auth(['alice'], [], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsWhenFingerprintRequiredButMissing(): void
    {
        [$err, $read] = $this->stderr();
        $a = new Auth([], ['SHA256:abc'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Unauthorized', $read());
    }

    public function testAllowsKnownFingerprint(): void
    {
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:goodfinger';
        [$err] = $this->stderr();
        $a = new Auth([], ['SHA256:goodfinger'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testAllowsFingerprintFromSshUserAuthBlob(): void
    {
        // SSH_USER_AUTH is an OpenSSH ExposeAuthInfo blob containing the
        // full auth line; fingerprint token must be extracted from it.
        $_SERVER['SSH_USER_AUTH'] = 'publickey ssh-ed25519 SHA256:abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGHIJKLMNOPQRSTU';
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err] = $this->stderr();
        $a = new Auth([], ['SHA256:abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGHIJKLMNOPQRSTU'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsMismatchedFingerprintInBlob(): void
    {
        $_SERVER['SSH_USER_AUTH'] = 'publickey ssh-ed25519 SHA256:wrongvalue';
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err, $readErr] = $this->stderr();
        $a = new Auth([], ['SHA256:expectedfinger'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Unauthorized', $readErr());
        $this->assertStringContainsString('key not allowed', $readErr());
    }

    public function testSshUserAuthBlobWithMd5Fingerprint(): void
    {
        $_SERVER['SSH_USER_AUTH'] = 'publickey ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ MD5:1a:2b:3c:4d:5e:6f:1a:2b:3c:4d:5e:6f:1a:2b:3c:4d';
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err] = $this->stderr();
        $a = new Auth([], ['MD5:1a:2b:3c:4d:5e:6f:1a:2b:3c:4d:5e:6f:1a:2b:3c:4d'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectionMessageSanitizesUsernameWithAnsi(): void
    {
        [$err, $readErr] = $this->stderr();
        $a = new Auth(['alice'], [], $err);
        $reached = false;
        // Simulate a username containing ANSI color escape sequences
        $maliciousSession = new Session(
            user: "\x1b[31meve\x1b[0m",
            clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
        $a->handle(Context::background(), $maliciousSession, function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $output = $readErr();
        // The ESC bytes must not appear in stderr output
        $this->assertStringNotContainsString("\x1b", $output);
        // The literal 'eve' characters should be present (ESC sequences replaced with '?')
        $this->assertStringContainsString('eve', $output);
    }

    public function testRejectionMessageSanitizesFingerprintWithAnsi(): void
    {
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = "SSH-FP\x1b[31m:bad\x1b[0m";
        [$err, $readErr] = $this->stderr();
        $a = new Auth([], ['SSH-FP:bad'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $output = $readErr();
        $this->assertStringNotContainsString("\x1b", $output);
    }
}
