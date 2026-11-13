<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Forms\Spinner\Style as SpinStyle;
use SugarCraft\ScriptUi\Command\SpinCommand;
use SugarCraft\ScriptUi\Process\FakeProcess;
use SugarCraft\ScriptUi\Process\Process;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use Symfony\Component\Console\Tester\CommandTester;

final class SpinCommandTest extends TestCase
{
    /** Global React loop as found on entry — restored in tearDown (see below). */
    private ?LoopInterface $savedLoop = null;

    protected function setUp(): void
    {
        $this->savedLoop = \React\EventLoop\Loop::get();
    }

    protected function tearDown(): void
    {
        // Loop-pollution cure (surfaced by scriptui-02): the timeout tests
        // below swap the GLOBAL loop (Loop::set) for a throwaway
        // StreamSelectLoop carrying an armed 2 s stop-backstop. Restoring
        // the original here means that throwaway — and its pending stop
        // timer — can never cut short the NEXT Loop::get() consumer's run.
        if ($this->savedLoop !== null) {
            \React\EventLoop\Loop::set($this->savedLoop);
            $this->savedLoop = null;
        }
    }

    public function testStyleAliases(): void
    {
        foreach (['line','dot','minidot','points','pulse','globe','meter'] as $name) {
            $this->assertInstanceOf(SpinStyle::class, SpinCommand::pickStyle($name));
        }
    }

    public function testStyleCaseInsensitive(): void
    {
        $this->assertInstanceOf(SpinStyle::class, SpinCommand::pickStyle('DOT'));
    }

    public function testUnknownStyleRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpinCommand::pickStyle('whirl');
    }

    public function testInterruptedExitCodeIs130(): void
    {
        // Conventional SIGINT-exit code: scripts use this to detect a
        // user-cancelled spin run.
        $this->assertSame(130, SpinCommand::EXIT_INTERRUPTED);
    }

    /**
     * A child that never exits under `--timeout 0.1` must be aborted by the
     * deadline: the process is terminated + reaped and the command surfaces
     * the GNU timeout code (124), NOT the interrupted code (130). This drives
     * the whole SpinCommand->Program->TimeoutGuard path headless via a
     * {@see FakeProcess} that reports `exitCode() === null` forever.
     */
    public function testTimeoutAbortsAndTerminatesChild(): void
    {
        $fake    = new FakeProcess(); // exitCode stays null -> never completes
        $command = $this->spinCommandWith($fake);

        // Backstop: if the timeout wiring is broken the FakeProcess would spin
        // the loop forever, so stop it after 2s — a broken run then returns 130
        // and fails the assertions below loudly instead of hanging the suite.
        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $status = (new CommandTester($command))->execute(
            ['argv' => ['sleep', '9999'], '--timeout' => '0.1'],
            ['decorated' => false],
        );

        $this->assertSame(TimeoutGuard::EXIT_TIMEOUT, $status);
        $this->assertSame(124, $status);
        $this->assertTrue($fake->terminated, 'child must be signalled on timeout');
        $this->assertTrue($fake->closed, 'child handle must be reaped on timeout');
        // M28: terminate() must have sent SIGTERM specifically (the old
        // double-deny — ext-posix-less hosts sent NO signal at all — and a
        // bare boolean flag could not see that), and the spawn line must
        // match argv verbatim (the harness factory used to discard it).
        $this->assertSame(\defined('SIGTERM') ? \SIGTERM : 15, $fake->lastSignal);
        $this->assertSame(['sleep', '9999'], $fake->spawnArgv);
        $this->assertFalse($fake->spawnCaptureStdout);
        $this->assertFalse($fake->spawnCaptureStderr);
    }

    /**
     * Cluster 6 (2026-11-03): the timeout path used to close the child and
     * return 124 WITHOUT flushing the captured buffers — with `--show-output`
     * set, everything the child had printed was silently dropped. The rewrite
     * flushes stdout/stderr on every exit path, so a timed-out run still
     * surfaces what the child managed to produce.
     */
    public function testTimeoutStillFlushesShowOutputBuffer(): void
    {
        $fake = new FakeProcess();
        $fake->bufferedStdout = 'TIMEOUT-STDOUT-MARKER'; // never exits -> deadline wins
        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['argv' => ['sleep', '9999'], '--timeout' => '0.1', '--show-output' => true],
            ['decorated' => false],
        );

        $this->assertSame(TimeoutGuard::EXIT_TIMEOUT, $status);
        $this->assertTrue($fake->stdoutRead, 'the timeout path must still read the captured stdout');
        $this->assertStringContainsString('TIMEOUT-STDOUT-MARKER', $tester->getDisplay());
    }

    /** The stderr twin: `--show-error` consumes the buffer on the timeout path too. */
    public function testTimeoutStillReadsShowErrorBuffer(): void
    {
        $fake = new FakeProcess();
        $fake->bufferedStderr = ''; // exercise the branch without polluting the runner's STDERR
        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $status = (new CommandTester($command))->execute(
            ['argv' => ['sleep', '9999'], '--timeout' => '0.1', '--show-error' => true],
            ['decorated' => false],
        );

        $this->assertSame(TimeoutGuard::EXIT_TIMEOUT, $status);
        $this->assertTrue($fake->stderrRead, 'the timeout path must still read the captured stderr');
    }

    /** Unknown `--align` values must be refused, not silently coerced (m-a). */
    public function testUnknownAlignIsRefused(): void
    {
        $fake = new FakeProcess();
        $fake->finish(0);
        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['argv' => ['true'], '--timeout' => '0', '--align' => 'middle'],
            ['decorated' => false],
        );

        $this->assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $status);
        $this->assertSame('', $this->readRendered(), 'no UI may start for a refused --align value');
    }

    /**
     * `--timeout 0` (the default) leaves the run unbounded: a child that
     * completes normally still yields its own exit code and is never
     * terminated. Guards the timeout wiring against clobbering the happy path.
     */
    public function testZeroTimeoutRunsToCompletion(): void
    {
        $fake = new FakeProcess();
        $fake->finish(0); // child already exited cleanly

        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $status = (new CommandTester($command))->execute(
            ['argv' => ['true'], '--timeout' => '0'],
            ['decorated' => false],
        );

        $this->assertSame(0, $status);
        $this->assertFalse($fake->terminated, 'a clean exit must not be terminated');
        $this->assertTrue($fake->closed, 'the process handle must still be reaped');
    }

    /**
     * A child that exits non-zero forwards its own exit code as candy-scriptui's
     * exit code — it is NOT coerced to the interrupted (130) or timeout (124)
     * code. Guards the happy-path exit-code forwarding in SpinCommand::execute().
     */
    public function testNonZeroChildExitCodeIsForwarded(): void
    {
        $fake = new FakeProcess();
        $fake->finish(3); // child failed with code 3

        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $status = (new CommandTester($command))->execute(
            ['argv' => ['false'], '--timeout' => '0'],
            ['decorated' => false],
        );

        $this->assertSame(3, $status, 'the child exit code must pass through verbatim');
        $this->assertFalse($fake->terminated, 'a self-completed child must not be terminated');
        $this->assertTrue($fake->closed, 'the process handle must still be reaped');
    }

    /**
     * `--show-output` (and its `--show-stdout` gum alias) prints the child's
     * captured stdout after the spinner stops. The buffered bytes land on the
     * command's own output, so they are observable via CommandTester's display.
     */
    public function testShowOutputPrintsCapturedStdout(): void
    {
        $fake = new FakeProcess();
        $fake->bufferedStdout = 'BUILD-STDOUT-MARKER';
        $fake->finish(0);

        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['argv' => ['npm', 'run', 'build'], '--timeout' => '0', '--show-output' => true],
            ['decorated' => false],
        );

        $this->assertSame(0, $status);
        $this->assertTrue($fake->stdoutRead, '--show-output must read the child stdout');
        $this->assertTrue($fake->spawnCaptureStdout, '--show-output must be threaded into the spawn capture flags');
        $this->assertStringContainsString('BUILD-STDOUT-MARKER', $tester->getDisplay());
    }

    /** The `--show-stdout` gum alias resolves to the same show-output behaviour. */
    public function testShowStdoutAliasPrintsCapturedStdout(): void
    {
        $fake = new FakeProcess();
        $fake->bufferedStdout = 'ALIAS-STDOUT-MARKER';
        $fake->finish(0);

        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['argv' => ['npm', 'run', 'build'], '--timeout' => '0', '--show-stdout' => true],
            ['decorated' => false],
        );

        $this->assertSame(0, $status);
        $this->assertStringContainsString('ALIAS-STDOUT-MARKER', $tester->getDisplay());
    }

    /**
     * `--show-error` consumes the child's captured stderr. SpinCommand writes it
     * to the real STDERR fd (not the command output), so assert the branch ran
     * via the FakeProcess read flag rather than the display buffer — and keep the
     * buffer empty so nothing pollutes the test runner's stderr.
     */
    public function testShowErrorReadsCapturedStderr(): void
    {
        $fake = new FakeProcess();
        $fake->bufferedStderr = ''; // empty: exercise the branch without STDERR noise
        $fake->finish(0);

        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $status = (new CommandTester($command))->execute(
            ['argv' => ['./flaky.sh'], '--timeout' => '0', '--show-error' => true],
            ['decorated' => false],
        );

        $this->assertSame(0, $status);
        $this->assertTrue($fake->stderrRead, '--show-error must read the child stderr');
        $this->assertFalse($fake->stdoutRead, 'stdout must not be read when only --show-error is set');
        $this->assertTrue($fake->spawnCaptureStderr, '--show-error must be threaded into the spawn capture flags');
        $this->assertFalse($fake->spawnCaptureStdout, 'stdout capture must follow --show-output only');
    }

    /**
     * The `--align`/`--title` options flow through SpinCommand into SpinModel's
     * rendered view: driving a headless Program with a right-aligned title emits
     * that title onto the Program output stream (the align ORDER itself is pinned
     * in SpinModelTest; here we prove the command wires the options through).
     */
    public function testAlignedTitleReachesProgramOutput(): void
    {
        $fake = new FakeProcess();
        $fake->finish(0); // complete immediately so run() returns after the first frame

        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $status = (new CommandTester($command))->execute(
            ['argv' => ['true'], '--timeout' => '0', '--title' => 'DEPLOYING', '--align' => 'right'],
            ['decorated' => false],
        );

        $this->assertSame(0, $status);
        $this->assertStringContainsString('DEPLOYING', $this->readRendered(), 'the spinner title must reach the Program output');
    }

    /**
     * Build a SpinCommand wired to a fake child + a headless Program via its
     * injectable factory closures, so the TEA runtime drives on a socket/memory
     * pair instead of the real TTY. The streams are parked on the test instance
     * so they outlive Program::run(); the timeout timer shares the loop the
     * command pulls from {@see \React\EventLoop\Loop::get()}.
     *
     * @var list<resource> keeps the injected stream resources referenced
     */
    private array $streams = [];

    /** @var resource|null the Program's output stream, kept so tests can read the rendered frames */
    private $lastOutput = null;

    /** Rewind and read everything the headless Program rendered to its output. */
    private function readRendered(): string
    {
        if (!is_resource($this->lastOutput)) {
            return '';
        }
        rewind($this->lastOutput);
        return (string) stream_get_contents($this->lastOutput);
    }

    private function spinCommandWith(FakeProcess $fake): SpinCommand
    {
        // The headless stdin below is a STREAM_PF_UNIX socket pair. The AF_UNIX
        // domain is POSIX-only: on Windows stream_socket_pair() cannot honour it
        // and raises an E_WARNING, which candy-scriptui's failOnWarning="true" turns
        // into a CI failure. Only these two socket-backed tests need it (the rest
        // of the class is Windows-clean), and the timeout wiring they exercise is
        // already covered on Linux, so skip just this scaffolding on Windows —
        // mirroring RealProcessTest's DIRECTORY_SEPARATOR gate.
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('stream_socket_pair(STREAM_PF_UNIX, …) is not supported on Windows.');
        }

        return new SpinCommand(
            processFactory: static function (array $argv, bool $so, bool $se) use ($fake): Process {
                $fake->recordSpawn($argv, $so, $se);
                return $fake;
            },
            programFactory: function (Model $model, LoopInterface $loop): Program {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                \assert($pair !== false);
                [$reader, $writer] = $pair;
                $out = fopen('php://memory', 'w+');
                $this->streams[] = $reader;
                $this->streams[] = $writer;
                $this->streams[] = $out;
                $this->lastOutput = $out;

                return new Program($model, new ProgramOptions(
                    useAltScreen:    false,
                    hideCursor:      false,
                    catchInterrupts: false,
                    input:           $reader,
                    output:          $out,
                    loop:            $loop,
                    windowSize:      ['cols' => 80, 'rows' => 24],
                ));
            },
        );
    }


    /** README spells it `mini-dot`; the internal style name is `minidot`. Both resolve. */
    public function testMiniDotSpellingAliasResolves(): void
    {
        // pickStyle() builds a fresh style object; compare by identity-of-data
        // (SpinStyle::miniDot() is not memoised, so assertEquals is honest).
        $this->assertEquals(SpinStyle::miniDot(), SpinCommand::pickStyle('mini-dot'));
        $this->assertEquals(SpinStyle::miniDot(), SpinCommand::pickStyle('MINI-DOT'));
        $this->assertEquals(SpinStyle::miniDot(), SpinCommand::pickStyle('minidot'));
    }
}
