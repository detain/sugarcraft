<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Middleware\Auth;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware\Auth\PasswordAuth;
use SugarCraft\SshServer\Session;
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

    public function testAnAbsentCredentialIsRefusedWithoutConsultingTheValidator(): void
    {
        // This pin used to assert the opposite: with nothing delivered, the
        // validator was handed '' and a callback written `fn ($u, $p) => $p === ''`
        // authenticated the session. Presence is now its own check.
        // (audit MINOR 6; flip disclosed in the commit message)
        [$err, $read] = $this->stderr();
        unset($_SERVER['SSH_PASSWORD']);
        putenv('SSH_PASSWORD');
        $asked = [];
        $a = new PasswordAuth(function ($u, $p) use (&$asked): bool {
            $asked[] = $p;
            return true;
        }, $err);
        $session = $this->session('alice');
        $reached = false;
        $a->handle(Context::background(), $session, function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertSame([], $asked, 'the validator must not judge a credential that never arrived');
        $this->assertTrue($session->refused());
        $this->assertStringContainsString('Permission denied', $read());
    }

    public function testAnExplicitlyEmptyCredentialStillReachesTheValidator(): void
    {
        [$err, $read] = $this->stderr();
        $_SERVER['SSH_PASSWORD'] = '';
        putenv('SSH_PASSWORD');
        $seen = null;
        $a = new PasswordAuth(function ($u, $p) use (&$seen): bool {
            $seen = $p;
            return $p === '';
        }, $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertSame('', $seen);
        $this->assertTrue($reached);
    }

    public function testClearsSshPasswordAfterRead(): void
    {
        [$err] = $this->stderr();
        $_SERVER['SSH_PASSWORD'] = 'secret123';
        putenv('SSH_PASSWORD=secret123');
        try {
            $a = new PasswordAuth(fn($u, $p) => true, $err);
            $reached = false;
            $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
                $reached = true;
            });

            $this->assertTrue($reached);
            $this->assertArrayNotHasKey('SSH_PASSWORD', $_SERVER);
            $this->assertFalse(getenv('SSH_PASSWORD'));
        } finally {
            putenv('SSH_PASSWORD');
        }
    }
}
