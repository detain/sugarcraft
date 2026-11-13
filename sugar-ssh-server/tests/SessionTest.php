<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests;

use SugarCraft\SshServer\Session;
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

    public function testTheThreeFieldSshClientShapeKeepsItsFieldsApart(): void
    {
        // SSH_CLIENT has no server-address slot: its third field is the port.
        // Reading index 2 as a host stored '22' in `serverHost` and left
        // `serverPort` at zero - the two audit fields were wrong in exactly the
        // cases where they were populated. (audit MINOR 15)
        $_SERVER['SSH_CLIENT'] = '203.0.113.7 54321 22';
        $_SERVER['USER']       = 'alice';

        $s = Session::fromEnvironment();

        $this->assertSame('203.0.113.7', $s->clientHost);
        $this->assertSame(54321,         $s->clientPort);
        $this->assertSame('',            $s->serverHost);
        $this->assertSame(22,            $s->serverPort);
    }

    public function testAGeometryThatIsNotAPositiveIntegerIsTreatedAsUndeclared(): void
    {
        // A PTY spawned at width 0 confuses every client that asks its own
        // terminal how big it is. (audit MINOR 11, MINOR 16)
        $_SERVER['USER']    = 'alice';
        $_SERVER['COLUMNS'] = '0';
        $_SERVER['LINES']   = 'eighty';

        $s = Session::fromEnvironment();

        $this->assertSame(80, $s->cols);
        $this->assertSame(24, $s->rows);
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

    public function testTheProtocolColumnsArePresentAndDashedBeforeAnyMetadata(): void
    {
        // Audit MAJOR 3: a log line used to be unable to say which
        // authentication decision it belonged to, because the three protocol
        // fields never reached it at all.
        $ctx = $this->shaped()->toLogContext();
        $this->assertSame('-', $ctx['session']);
        $this->assertSame('-', $ctx['auth_method']);
        $this->assertSame('-', $ctx['key_fingerprint']);
    }

    public function testTheProtocolColumnsCarryWhatTheTransportDerived(): void
    {
        $ctx = $this->shaped()
            ->withProtocolMetadata('sess-1', 'publickey', null, null, null)
            ->toLogContext();
        $this->assertSame('sess-1',    $ctx['session']);
        $this->assertSame('publickey', $ctx['auth_method']);
        // Still a dash, because no transport has a fingerprint to hand over
        // (audit K6): the column is honest about the missing delivery.
        $this->assertSame('-', $ctx['key_fingerprint']);
    }

    public function testAPeerSmuggledGeometryAboveTheCeilingIsClamped(): void
    {
        // COLUMNS/LINES reach us through sshd AcceptEnv, so a hostile wrapper
        // can declare COLUMNS=100000 and make row-buffer-hungry child TUIs
        // allocate against a geometry nobody typed. The channel stays
        // disclosed; the amplification vector is capped. (audit MINOR 11, MINOR 16)
        $_SERVER['USER']    = 'alice';
        $_SERVER['COLUMNS'] = '100000';
        $_SERVER['LINES']   = '999999';

        $s = Session::fromEnvironment();

        $this->assertSame(Session::MAX_DECLARED_COLS, $s->cols);
        $this->assertSame(Session::MAX_DECLARED_ROWS, $s->rows);
        $this->assertSame(4096, $s->cols);
    }

    public function testTheDefaultConstructorCarriesThePlaceholderShape(): void
    {
        // Every constructor field is defaulted so mutate()/fromEnvironment()
        // can rebuild from a partial map; the placeholders must match the
        // ones fromEnvironment() fills when sshd delivers nothing. (audit 003)
        $s = new Session();

        $this->assertSame('',     $s->user);
        $this->assertSame('',     $s->clientHost);
        $this->assertSame(0,      $s->clientPort);
        $this->assertSame('xterm-256color', $s->term);
        $this->assertSame(Session::DEFAULT_COLS, $s->cols);
        $this->assertSame(Session::DEFAULT_ROWS, $s->rows);
        $this->assertSame('C.UTF-8', $s->lang);
        $this->assertNull($s->tty);
        $this->assertNull($s->command);
        $this->assertNull($s->sessionId);
    }

    public function testToLogContextDashesAnAbsentCommand(): void
    {
        // Every other absent field dashes; an empty-string command made the
        // 'no command' case grep-indistinguishable from a smuggled empty one.
        // '-' means 'not carried', same as tty/session/auth columns. (audit 042)
        $ctx = (new Session())->toLogContext();

        $this->assertSame('-', $ctx['command']);
    }

    public function testAccumulatedStatusTravelsThroughEveryRebuild(): void
    {
        // The transports hand ONE Session down the stack and every hop
        // rebuilds it; a settled exit code or refusal that a rebuild dropped
        // would turn a denied login back into a clean exit 0. (audit MAJOR 9)
        $s = $this->shaped();
        $s->recordExitStatus(7);
        $s->markRefused();

        $afterSize = $s->withSize(120, 40);
        $this->assertSame(7, $afterSize->exitStatus());
        $this->assertTrue($afterSize->refused());
        $this->assertSame(120, $afterSize->cols);

        $afterMeta = $afterSize->withProtocolMetadata('sess-9', 'password', null, null, null);
        $this->assertSame(7, $afterMeta->exitStatus());
        $this->assertTrue($afterMeta->refused());
        $this->assertSame('sess-9', $afterMeta->sessionId);
        $this->assertSame(120, $afterMeta->cols);
        $this->assertSame('wishlist', $afterMeta->command);
    }

    public function testWithersRouteThroughTheSingleRebuildSite(): void
    {
        // Structural guard for the mutate() rule: `new self(` may appear in
        // exactly two places (mutate() and fromEnvironment()). A wither that
        // hand-carries its own rebuild is the hazard this closes - a field
        // added later gets dropped silently by every such site. (audit 003)
        $src = (string) \file_get_contents(__DIR__ . '/../src/Session.php');

        $this->assertSame(2, \substr_count($src, 'new self('));
        $this->assertGreaterThanOrEqual(2, \substr_count($src, '$this->mutate('));
    }

    private function shaped(): Session
    {
        return new Session(
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
    }
}
