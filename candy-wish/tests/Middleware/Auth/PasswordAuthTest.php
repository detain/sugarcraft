<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Auth\PasswordAuth;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class PasswordAuthTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset($_SERVER['SSH_PASSWORD']);
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

    public function testCallsNextWhenCallbackReturnsTrue(): void
    {
        [$err] = $this->stderr();
        $_SERVER['SSH_PASSWORD'] = 'secret123';
        $a = new PasswordAuth(fn($u, $p) => $u === 'alice' && $p === 'secret123', $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsWrongPassword(): void
    {
        [$err, $read] = $this->stderr();
        $_SERVER['SSH_PASSWORD'] = 'wrong';
        $a = new PasswordAuth(fn($u, $p) => $p === 'correct', $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Permission denied', $read());
    }

    public function testRejectsWhenCallbackReturnsFalse(): void
    {
        [$err, $read] = $this->stderr();
        $_SERVER['SSH_PASSWORD'] = 'any';
        $a = new PasswordAuth(fn() => false, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Permission denied', $read());
    }

    public function testUsesEnvVarWhenServerVarNotSet(): void
    {
        [$err] = $this->stderr();
        putenv('SSH_PASSWORD=envpass');
        try {
            $a = new PasswordAuth(fn($u, $p) => $p === 'envpass', $err);
            $reached = false;
            $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
                $reached = true;
            });
            $this->assertTrue($reached);
        } finally {
            putenv('SSH_PASSWORD');
        }
    }

    public function testHandlesEmptyPassword(): void
    {
        [$err] = $this->stderr();
        unset($_SERVER['SSH_PASSWORD']);
        putenv('SSH_PASSWORD');
        $a = new PasswordAuth(fn($u, $p) => $p === '', $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }
}
