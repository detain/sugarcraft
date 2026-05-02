<?php

declare(strict_types=1);

namespace CandyCore\Wish\Tests;

use CandyCore\Wish\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];
    /** @var array<string,string|false> */
    private array $envBackup = [];

    private const ENV_KEYS = [
        'SSH_CONNECTION', 'SSH_CLIENT', 'SSH_TTY', 'USER', 'LOGNAME',
        'TERM', 'COLUMNS', 'LINES', 'LANG', 'SSH_ORIGINAL_COMMAND',
    ];

    protected function setUp(): void
    {
        // Snapshot $_SERVER and the process env, then clear both so
        // tests are independent of the host PHP process's env.
        $this->serverBackup = $_SERVER;
        foreach (self::ENV_KEYS as $k) {
            $this->envBackup[$k] = getenv($k);
            unset($_SERVER[$k]);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        foreach ($this->envBackup as $k => $v) {
            if ($v === false) {
                putenv($k);
            } else {
                putenv("{$k}={$v}");
            }
        }
    }

    public function testFromEnvironmentParsesSshConnection(): void
    {
        $_SERVER['SSH_CONNECTION'] = '203.0.113.7 54321 198.51.100.4 22';
        $_SERVER['USER']           = 'alice';
        $_SERVER['TERM']           = 'xterm-256color';
        $_SERVER['COLUMNS']        = '120';
        $_SERVER['LINES']          = '40';
        $_SERVER['SSH_TTY']        = '/dev/pts/3';

        $s = Session::fromEnvironment();

        $this->assertSame('alice',         $s->user);
        $this->assertSame('203.0.113.7',   $s->clientHost);
        $this->assertSame(54321,           $s->clientPort);
        $this->assertSame('198.51.100.4',  $s->serverHost);
        $this->assertSame(22,              $s->serverPort);
        $this->assertSame('xterm-256color', $s->term);
        $this->assertSame(120,             $s->cols);
        $this->assertSame(40,              $s->rows);
        $this->assertSame('/dev/pts/3',    $s->tty);
        $this->assertTrue($s->isInteractive());
    }

    public function testFromEnvironmentFallsBackToSshClient(): void
    {
        $_SERVER['SSH_CLIENT'] = '198.51.100.10 11111 22';
        $_SERVER['LOGNAME']    = 'bob';

        $s = Session::fromEnvironment();
        $this->assertSame('bob',           $s->user);
        $this->assertSame('198.51.100.10', $s->clientHost);
        $this->assertSame(11111,           $s->clientPort);
    }

    public function testFromEnvironmentDefaultsArSane(): void
    {
        $s = Session::fromEnvironment();
        $this->assertSame('xterm-256color', $s->term);
        $this->assertSame(80,               $s->cols);
        $this->assertSame(24,               $s->rows);
        $this->assertNull($s->tty);
        $this->assertFalse($s->isInteractive());
    }

    public function testToLogContextHasExpectedShape(): void
    {
        $s = new Session(
            user: 'carol',
            clientHost: '203.0.113.99',
            clientPort: 5555,
            serverHost: '198.51.100.1',
            serverPort: 22,
            term: 'tmux-256color',
            cols: 100,
            rows: 30,
            tty: '/dev/pts/0',
            command: 'wishlist',
            lang: 'en_US.UTF-8',
        );
        $ctx = $s->toLogContext();
        $this->assertSame('carol',                 $ctx['user']);
        $this->assertSame('203.0.113.99:5555',     $ctx['client_addr']);
        $this->assertSame('tmux-256color',         $ctx['term']);
        $this->assertSame('/dev/pts/0',            $ctx['tty']);
        $this->assertSame('100x30',                $ctx['pty']);
        $this->assertSame('wishlist',              $ctx['command']);
    }
}
