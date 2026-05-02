<?php

declare(strict_types=1);

namespace CandyCore\Wish\Tests\Middleware;

use CandyCore\Wish\Middleware\Auth;
use CandyCore\Wish\Session;
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
        $_SERVER = $this->serverBackup;
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
        $a->handle($this->session('anybody'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsUnknownUser(): void
    {
        [$err, $read] = $this->stderr();
        $a = new Auth(['alice', 'bob'], [], $err);
        $reached = false;
        $a->handle($this->session('eve'), function () use (&$reached): void {
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
        $a->handle($this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsWhenFingerprintRequiredButMissing(): void
    {
        [$err, $read] = $this->stderr();
        $a = new Auth([], ['SHA256:abc'], $err);
        $reached = false;
        $a->handle($this->session('alice'), function () use (&$reached): void {
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
        $a->handle($this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }
}
