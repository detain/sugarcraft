<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\PtyRelay;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * The PTY relay process itself, launched directly the way
 * {@see BackgroundShellRegistry::startInteractive()} will launch it — argv,
 * staged command file, FIFO, sinks — so every bound the relay carries is
 * proven at the process boundary, not just through the registry.
 *
 * THE HOUSE NEVER-SKIP LAW: on a host that cannot run a pty these pins
 * assert the REFUSAL shape instead (relay exits non-zero, records nothing)
 * — the same both-polarities discipline {@see \SugarCraft\Code\Tests\Tools\PtyRunToolTest}
 * carries. Nothing here skips, so nothing here can silently stop testing
 * the pump on the box that ships the phar.
 *
 * The optional fifth argv (the byte cap) exists ONLY for the flood pin —
 * 64 MiB of pty write is a CI budget nobody should pay, and the seam can
 * only narrow what production would enforce anyway. Production passes
 * exactly four arguments.
 */
final class PtyRelayTest extends TestCase
{
    private string $root;

    /** @var list<array{proc: resource, dir: string, id: string}> */
    private array $relays = [];

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/c6j-relay-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        if (!\mkdir($this->root, 0o700) && !\is_dir($this->root)) {
            self::fail('the relay test root could not be created');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->relays as $relay) {
            if (\is_resource($relay['proc']) && \proc_get_status($relay['proc'])['running']) {
                ProcessContainment::terminate($relay['proc'], 15);
                $budget = \microtime(true) + 2.0;
                while (\proc_get_status($relay['proc'])['running'] && \microtime(true) < $budget) {
                    \usleep(50_000);
                }
                if (\proc_get_status($relay['proc'])['running']) {
                    ProcessContainment::terminate($relay['proc'], 9);
                }
            }
            if (\is_resource($relay['proc'])) {
                @\proc_close($relay['proc']);
            }
        }
        $this->relays = [];

        foreach ((@\scandir($this->root) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->root . '/' . $entry;
            if (\is_dir($path)) {
                foreach ((@\scandir($path) ?: []) as $inner) {
                    if ($inner !== '.' && $inner !== '..') {
                        @\unlink($path . '/' . $inner);
                    }
                }
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($this->root);
    }

    /**
     * The FIFO text comes back in the transcript: the relay reads the
     * master, the shell echoes what the relay feeds it, and the appended
     * bytes land in <id>.out — the full input->shell->output->file loop.
     */
    public function testFifoTextComesBackInTheTranscript(): void
    {
        if (!self::relaySupported()) {
            $this->assertRefusalPolarity();

            return;
        }

        $token = \bin2hex(\random_bytes(6));
        $launched = $this->launchRelay('cat', 90, 30);
        $paths = BackgroundShellRegistry::pathFor($launched['dir'], $launched['id']);

        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => \is_file($paths['ptyPid']), 8.0),
            'the relay never recorded the shell pid',
        );
        self::assertTrue($this->pumpRelayInput($paths['in'], $token . "\x0d"), 'the FIFO refused the bytes');

        self::assertTrue(
            $this->relayWaitFor(static function () use ($paths, $token): bool {
                $seen = @\file_get_contents($paths['stdout']);

                return \is_string($seen) && \str_contains($seen, $token);
            }, 8.0),
            'the typed token never came back through the transcript',
        );
    }

    /**
     * The geometry the relay opened the pty with is the geometry the
     * shell's own `stty size` reports — rows then cols, proving the
     * winsize is applied BEFORE the child draws (the memory law from the
     * btop-render audits: a 0x0 ctty is a broken renderer).
     */
    public function testTheGeometryReachesTheShellsOwnSttyQuery(): void
    {
        if (!self::relaySupported()) {
            $this->assertRefusalPolarity();

            return;
        }

        $launched = $this->launchRelay('stty size; exit 0', 100, 30);
        $paths = BackgroundShellRegistry::pathFor($launched['dir'], $launched['id']);

        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => self::relaySettled($launched['proc']), 12.0),
            'the relay did not settle',
        );
        $out = (string) @\file_get_contents($paths['stdout']);
        self::assertMatchesRegularExpression('/\b30 100\b/', $out, 'the shell saw the wrong terminal geometry');
    }

    /**
     * The exit file carries the shell's own code, recorded from the
     * relay's wait — the plain path's trailer-less twin.
     */
    public function testTheExitFileCarriesTheShellsOwnCode(): void
    {
        if (!self::relaySupported()) {
            $this->assertRefusalPolarity();

            return;
        }

        $launched = $this->launchRelay('exit 7', 80, 24);
        $paths = BackgroundShellRegistry::pathFor($launched['dir'], $launched['id']);

        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => self::relaySettled($launched['proc']), 12.0),
            'the relay did not settle after the shell exited',
        );
        self::assertSame('7', @\file_get_contents($paths['exit']), 'the exit file must carry the shells own code');
    }

    /**
     * A SIGTERM to the relay's own process group ends the child: the
     * handler forwards TERM to the shell's session, and the wall-agnostic
     * escalation is only needed for a child that ignores it.
     */
    public function testTermToTheRelayGroupEndsTheChild(): void
    {
        if (!self::relaySupported()) {
            $this->assertRefusalPolarity();

            return;
        }

        $launched = $this->launchRelay('sleep 30', 80, 24);
        $paths = BackgroundShellRegistry::pathFor($launched['dir'], $launched['id']);
        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => \is_file($paths['ptyPid']), 8.0),
            'the relay never recorded the shell pid',
        );
        $ptyPid = (int) \trim((string) @\file_get_contents($paths['ptyPid']));
        self::assertGreaterThan(0, $ptyPid);

        $status = \proc_get_status($launched['proc']);
        self::assertTrue(@\posix_kill(-((int) $status['pid']), 15), 'the group TERM was refused');

        self::assertTrue(
            $this->relayWaitFor(static function () use ($launched, $ptyPid): bool {
                return !self::c6jProcessStanding($ptyPid) && self::relaySettled($launched['proc']);
            }, 12.0),
            'the TERM did not reach the shell through the relay',
        );
        $recorded = (string) @\file_get_contents($paths['exit']);
        self::assertMatchesRegularExpression('/^-?\d+$/', $recorded, 'the settled relay must record a numeric code');
    }

    /**
     * The output cap fells a flood and records the SAME code the plain
     * path's SIGXFSZ death records — so outputLimitNote() narrates an
     * interactive kill exactly as it narrates a piped one. The cap here is
     * the test-only fifth argv; production's 64 MiB would take minutes to
     * pour through a pty for zero extra proof.
     */
    public function testTheOutputCapFellsTheFloodAndRecordsTheSameCode(): void
    {
        if (!self::relaySupported()) {
            $this->assertRefusalPolarity();

            return;
        }

        $launched = $this->launchRelay('yes', 80, 24, 8192);
        $paths = BackgroundShellRegistry::pathFor($launched['dir'], $launched['id']);

        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => self::relaySettled($launched['proc']), 20.0),
            'the cap did not fell the flood',
        );
        self::assertSame((string) BackgroundShellRegistry::OUTPUT_LIMIT_EXIT_CODE, @\file_get_contents($paths['exit']));
        self::assertGreaterThanOrEqual(8192, (int) @\filesize($paths['stdout']));

        $ptyPid = (int) \trim((string) @\file_get_contents($paths['ptyPid']));
        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => !self::c6jProcessStanding($ptyPid), 8.0),
            'the flooded shell outlived the cap',
        );
    }

    /**
     * A SIGKILLed pty holder leaves no shell behind: the master fd lives
     * only in the pump (the forked session-member that opened it), so
     * killing the pump closes it, the kernel hangs up the pty session, and
     * the shell (default SIGHUP behaviour) dies. The .exit file is NEVER
     * written — the pump is the only settle recorder, and the supervising
     * parent records nothing.
     */
    public function testAKilledRelayLeavesNoShellBehind(): void
    {
        if (!self::relaySupported()) {
            $this->assertRefusalPolarity();

            return;
        }

        $launched = $this->launchRelay('cat', 80, 24);
        $paths = BackgroundShellRegistry::pathFor($launched['dir'], $launched['id']);
        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => \is_file($paths['ptyPid']), 8.0),
            'the relay never recorded the shell pid',
        );
        $ptyPid = (int) \trim((string) @\file_get_contents($paths['ptyPid']));
        self::assertTrue(self::c6jProcessStanding($ptyPid), 'the shell was not standing to begin with');

        // The shell's parent IS the pump — /proc stat layout is
        // `pid (comm) STATE PPID ...`, so the field after the state letter.
        $shellStat   = (string) @\file_get_contents('/proc/' . $ptyPid . '/stat');
        $tail        = \trim((string) \substr($shellStat, (int) \strrpos($shellStat, ')') + 2));
        $pumpPid     = (int) (\explode(' ', $tail)[1] ?? 0);
        self::assertGreaterThan(0, $pumpPid, 'the pump pid must be readable from the shell stat line');
        self::assertTrue(@\posix_kill($pumpPid, 9), 'the pump KILL was refused');

        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => !self::c6jProcessStanding($ptyPid), 10.0),
            'the shell survived its relay being SIGKILLed',
        );
        // The trio is pre-created EMPTY (sinkFiles shape), so the proof a
        // murdered pump recorded nothing is that the file is still blank.
        self::assertSame('', (string) @\file_get_contents($paths['exit']), 'a murdered pump must not have recorded an exit');
    }

    /**
     * The staged command file is consumed — read and unlinked — before
     * the shell ever runs, so no process list, core dump trail or later
     * reader can carry the command the model typed.
     */
    public function testTheStagedCommandFileIsGoneOnceTheRelayStarts(): void
    {
        if (!self::relaySupported()) {
            $this->assertRefusalPolarity();

            return;
        }

        $launched = $this->launchRelay('true', 80, 24);
        $paths = BackgroundShellRegistry::pathFor($launched['dir'], $launched['id']);

        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => self::relaySettled($launched['proc']), 12.0),
            'the relay did not settle',
        );
        self::assertFalse(\is_file($paths['cmd']), 'the staged command file must not survive the relay reading it');
        self::assertSame('0', @\file_get_contents($paths['exit']));
    }

    /**
     * Malformed argv is refused BEFORE anything is read or recorded —
     * EXEC on every host, pty-capable or not: this arm proves the guard
     * chain runs without touching the sugar-pty substrate at all.
     */
    public function testBadArgvIsRefusedWithoutAFileTrail(): void
    {
        $dir = $this->root . '/argv-' . \bin2hex(\random_bytes(3));
        self::assertTrue(\mkdir($dir, 0o700));

        $code = \sprintf(
            'require %s; exit(\\%s::main());',
            \var_export((string) BackgroundSupervisor::autoloadPath(), true),
            PtyRelay::class,
        );
        $launched = @\proc_open(
            ProcessContainment::spawnSpec([\PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code, '--', $dir, 'sh-NOTHEX0', '80', '24']),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/relay-stderr.log', 'a']],
            $pipes,
            $dir,
            ProcessContainment::scrubbedEnv(),
        );
        self::assertIsResource($launched);
        $this->relays[] = ['proc' => $launched, 'dir' => $dir, 'id' => 'sh-notthe0'];

        $exitCode = \proc_close($launched);
        self::assertNotSame(0, $exitCode, 'a malformed id must not run a relay');
        self::assertFalse(\is_file($dir . '/sh-notthe0.out'), 'the refusal must not open sinks');
        self::assertFalse(\is_file($dir . '/sh-notthe0.exit'), 'the refusal must not record an exit');

        @\unlink($dir . '/relay-stderr.log');
        @\rmdir($dir);
    }

    private static function relaySupported(): bool
    {
        return ProcessContainment::interactiveAvailable()
            && \function_exists('posix_mkfifo')
            && \function_exists('pcntl_signal');
    }

    /**
     * On a host without the pty substrate the SAME launches must refuse
     * loudly: non-zero exit, and no settled record — never a silent
     * degradation into a half-working pipe.
     */
    private function assertRefusalPolarity(): void
    {
        $launched = $this->launchRelay('true', 80, 24);
        $paths = BackgroundShellRegistry::pathFor($launched['dir'], $launched['id']);

        self::assertTrue(
            $this->relayWaitFor(static fn (): bool => self::relaySettled($launched['proc']), 12.0),
            'even the refusal path must exit promptly',
        );
        self::assertNotSame(0, self::relayStatus($launched['proc']), 'the relay must refuse without the pty substrate');
        // The .exit sink is pre-created EMPTY by stageRelaySinks before the
        // launch (the trio shares the sinkFiles shape, builder correction (e)
        // above), so absence was never the refusal signature — content is.
        self::assertSame('', (string) @\file_get_contents($paths['exit']), 'a refusing relay must record nothing');
    }

    /**
     * @return array{proc: resource, dir: string, id: string}
     */
    private function launchRelay(string $command, int $cols, int $rows, ?int $capBytes = null): array
    {
        $dir = $this->root . '/r-' . \bin2hex(\random_bytes(3));
        self::assertTrue(\mkdir($dir, 0o700));
        $id = 'sh-' . \bin2hex(\random_bytes(4));
        $paths = $this->stageRelaySinks($dir, $id, $command);

        $argv = [\PHP_BINARY, '-d', 'display_errors=stderr', '-r', \sprintf(
            'require %s; exit(\\%s::main());',
            \var_export((string) BackgroundSupervisor::autoloadPath(), true),
            PtyRelay::class,
        ), '--', $dir, $id, (string) $cols, (string) $rows];
        if ($capBytes !== null) {
            $argv[] = (string) $capBytes;
        }

        $proc = @\proc_open(
            ProcessContainment::spawnSpec($argv),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $paths['stderr'], 'a']],
            $pipes,
            $dir,
            ProcessContainment::scrubbedEnv(),
        );
        self::assertIsResource($proc, 'the relay could not be launched for the test');
        $this->relays[] = ['proc' => $proc, 'dir' => $dir, 'id' => $id];

        return ['proc' => $proc, 'dir' => $dir, 'id' => $id];
    }

    /**
     * @return array{stdout: string, stderr: string, exit: string, ptyPid: string, in: string, cmd: string}
     */
    private function stageRelaySinks(string $dir, string $id, string $command): array
    {
        $paths = BackgroundShellRegistry::pathFor($dir, $id);
        foreach ([$paths['stdout'], $paths['stderr'], $paths['exit']] as $sink) {
            $handle = @\fopen($sink, 'xb');
            self::assertIsResource($handle, 'the sink ' . $sink . ' could not be staged');
            @\fclose($handle);
            @\chmod($sink, 0o600);
        }
        if (\function_exists('posix_mkfifo')) {
            self::assertTrue(@\posix_mkfifo($paths['in'], 0o600), 'the input FIFO could not be staged');
        } else {
            @\touch($paths['in']);
        }
        self::assertNotFalse(@\file_put_contents($paths['cmd'], $command));
        @\chmod($paths['cmd'], 0o600);

        return $paths;
    }

    private function relayWaitFor(\Closure $probe, float $budgetSeconds): bool
    {
        $deadline = \microtime(true) + $budgetSeconds;
        while (\microtime(true) < $deadline) {
            if ($probe() === true) {
                return true;
            }
            \usleep(20_000);
        }

        return $probe() === true;
    }

    /**
     * Write the FIFO the way send() does: r+ never blocks on open, and
     * non-blocking mode turns a dead relay into a loud false instead of a
     * test that hangs the suite.
     */
    private function pumpRelayInput(string $fifoPath, string $bytes): bool
    {
        $handle = @\fopen($fifoPath, 'r+');
        if ($handle === false) {
            return false;
        }
        @\stream_set_blocking($handle, false);
        $deadline = \microtime(true) + 2.0;
        do {
            $written = @\fwrite($handle, $bytes);
            if ($written !== false && $written > 0) {
                @\fclose($handle);

                return true;
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);
        @\fclose($handle);

        return false;
    }

    private static function relaySettled($proc): bool
    {
        return \is_resource($proc) && !\proc_get_status($proc)['running'];
    }

    private static function relayStatus($proc): int
    {
        return \is_resource($proc) ? (int) (\proc_get_status($proc)['exitcode'] ?? -1) : -1;
    }

    private static function c6jProcessStanding(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $stat = @\file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return false;
        }
        $at = \strrpos($stat, ')');
        $state = $at === false ? 'X' : \substr($stat, $at + 2, 1);

        return $state !== 'Z' && $state !== 'X';
    }

    /**
     * PHP-C1: {@see PtyRelay::interpretExitStatus()} is the extracted, named,
     * tested replacement for the inline WIFSIGNALED/WTERMSIG/WIFEXITED/
     * WEXITSTATUS calls. Those four are wait(2) C MACROS, not PHP functions
     * (function_exists('WIFSIGNALED') is false on every build), so the previous
     * form was an uncaught Error on the FIRST real reap — not a warning. These
     * pins EXECUTE the repaired path across all four shapes; at base the method
     * does not exist, so any run reaching them discriminates by construction.
     * The integers are the kernel's own (exit_code << 8) | signal composition.
     */
    public function testInterpretExitStatusMirrorsEveryReapedPumpShape(): void
    {
        $interpret = new \ReflectionMethod(PtyRelay::class, 'interpretExitStatus');
        $interpret->setAccessible(true);

        // [status, mirrored exit code] for (a) exited-0, (b) exited-nonzero,
        // (c) killed-by-signal (and with the core-dump bit set), (d) an
        // uninterpretable (stopped) status -> the named sentinel.
        $cases = [
            'exited-0' => [0, 0],
            'exited-3' => [3 << 8, 3],
            'exited-255' => [255 << 8, 255],
            'killed-by-9' => [9, 137],
            'killed-by-9-core' => [9 | 0x80, 137],
            'killed-by-15' => [15, 143],
            'stopped-19-unknown' => [(19 << 8) | 0x7F, 2],
            'stopped-21-unknown' => [(21 << 8) | 0x7F, 2],
        ];
        foreach ($cases as $label => [$status, $expected]) {
            self::assertSame(
                $expected,
                $interpret->invoke(null, $status),
                $label . ': the pump status interpretation must mirror the kernel composition',
            );
        }
    }

    /**
     * The pcntl-free bit decode is pinned DIRECTLY against the same integer
     * facts the pcntl accessors report, so the arithmetic holds even on a host
     * where interpretExitStatus() would have taken the pcntl branch instead.
     */
    public function testDecodeRawStatusMatchesTheKernelCompositionAndPcntl(): void
    {
        $decode = new \ReflectionMethod(PtyRelay::class, 'decodeRawStatus');
        $decode->setAccessible(true);

        $statuses = [0, 3 << 8, 7, 9, 15, 9 | 0x80, (19 << 8) | 0x7F, (2 << 8) | 0x7F];

        if (\function_exists('pcntl_wifsignaled')) {
            foreach ($statuses as $status) {
                $decoded = $decode->invoke(null, $status);
                if (pcntl_wifstopped($status)) {
                    self::assertNull($decoded, 'a stopped status decodes to null (unknown)');

                    continue;
                }
                if (pcntl_wifsignaled($status)) {
                    self::assertSame(128 + pcntl_wtermsig($status), $decoded, 'a signalled status decodes to 128+signal');

                    continue;
                }
                self::assertNotNull($decoded, 'an exited status decodes');
                self::assertSame(pcntl_wexitstatus($status), $decoded, 'an exited status decodes to the exit code');
            }
        }

        // Named spot-check of the arithmetic against the kernel composition,
        // independent of any pcntl answer (runs on every host).
        self::assertSame(0, $decode->invoke(null, 0), 'exit(0)');
        self::assertSame(3, $decode->invoke(null, 3 << 8), 'exit(3)');
        self::assertSame(137, $decode->invoke(null, 9), 'SIGKILL (9) -> 137');
        self::assertNull($decode->invoke(null, (19 << 8) | 0x7F), 'SIGSTOP-echoed stopped status -> null');
    }
}
