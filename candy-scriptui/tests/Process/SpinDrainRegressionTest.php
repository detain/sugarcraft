<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Process;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Forms\Spinner\TickMsg;
use SugarCraft\ScriptUi\Command\SpinCommand;
use SugarCraft\ScriptUi\Model\SpinModel;
use SugarCraft\ScriptUi\Process\FakeProcess;
use SugarCraft\ScriptUi\Process\Process;
use SugarCraft\ScriptUi\Process\RealProcess;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * scriptui-02 (candy-scriptui-001, the CRITICAL): `spin` used to poll only
 * the exit verdict while the run loop ran, so a child writing past the
 * ~64KiB OS pipe buffer blocked forever with default `--timeout 0`
 * (reproduced pre-fix as rc=124 on `yes | head -n 200000`).
 *
 * The cure is the pump: `attachPump()` registers the capture pipes as
 * loop read streams, and every spinner tick drains as a backstop.
 *
 * Mutation proof: delete the `$process->attachPump($loop)` line in
 * SpinCommand::execute() (and the tick drain) and the first test reddens
 * through the `--timeout` wall-clock guard — rc=124, truncated capture —
 * WITHOUT hanging CI: the guard timer fires inside the still-running
 * loop, because the BLOCKED party is always the child, never us.
 */
final class SpinDrainRegressionTest extends TestCase
{
    /** Bytes `yes | head -n 40000` produces: 40000 × "y\n" — >64KiB on purpose. */
    private const CAPTURE_BYTES = 80000;

    /** Wall-clock backstop for the e2e run: pump regression trips this, not a hang. */
    private const DEADLINE_SECONDS = '20';

    /** @var list<resource> injected headless streams, referenced for the test lifetime */
    private array $streams = [];

    /** Global React loop as found on entry — my tests install their own. */
    private ?LoopInterface $savedLoop = null;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('spins /bin/sh + yes children and uses a STREAM_PF_UNIX pair (Unix only).');
        }
        // SpinCommand::execute runs on the GLOBAL loop (React Loop::get()).
        // Install a pristine one per test so a previous file's swapped-in
        // throwaway (with armed stop-backstops) can never cut this run
        // short; the original goes back in tearDown.
        $this->savedLoop = \React\EventLoop\Loop::get();
        \React\EventLoop\Loop::set(new StreamSelectLoop());
    }

    protected function tearDown(): void
    {
        if ($this->savedLoop !== null) {
            \React\EventLoop\Loop::set($this->savedLoop);
            $this->savedLoop = null;
        }
        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                @\fclose($stream);
            }
        }
        $this->streams = [];
    }

    /**
     * THE crown test: full end-to-end `spin --show-output` on a child
     * emitting ~78KiB (past the 65536-byte pipe buffer) must exit 0 with
     * the COMPLETE capture flushed after the spinner stops.
     */
    public function testSpinCapturesFarPastThePipeBufferWithoutDeadlocking(): void
    {
        $command = new SpinCommand(
            // default processFactory: a REAL RealProcess child
            programFactory: $this->headlessProgramFactory(),
        );
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'argv'          => ['/bin/sh', '-c', 'yes 2>/dev/null | head -n 40000'],
            '--show-output' => true,
            '--timeout'     => self::DEADLINE_SECONDS,
        ]);

        $this->assertSame(
            0,
            $status,
            'child must exit 0; a 124 here means the pump died and `yes` blocked at 64KiB until the deadline guard fired',
        );
        $this->assertSame(
            self::CAPTURE_BYTES,
            \strlen($tester->getDisplay()),
            'the whole 80000-byte capture must arrive — truncation means the pipes were not pumped',
        );
        $this->assertStringStartsWith("y\n", $tester->getDisplay());
    }

    /**
     * Process-level crown: with ONLY the pump attached (no spin runtime),
     * an endless `yes` child keeps moving bytes — capture grows far past
     * the pipe buffer while the child is still alive, and EOF/teardown
     * retire the watcher without wedging the loop.
     */
    public function testAttachedPumpMovesEndlessOutputWhileChildLives(): void
    {
        $loop = new StreamSelectLoop();
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'yes 2>/dev/null'], captureStdout: true);

        $guardFired = false;
        try {
            $proc->attachPump($loop);

            $target = 300_000; // >4x the 64KiB buffer
            // Note: NOTHING in this loop body calls drain() — bytes may
            // only move through the registered read-stream pump. That is
            // the mutation-proof shape: delete attachPump() and the
            // capture stalls at ~64KiB while `yes` blocks, tripping the
            // 15 s guard instead of reaching the target.
            $poll   = $loop->addPeriodicTimer(0.02, function () use ($loop, $proc, $target, &$poll): void {
                if ($proc->exitCode() !== null) {
                    return;
                }
                // exitCode()/exited() probe WITHOUT draining (the old
                // spin poll shape) and a buffer peek without IO — pump
                // delivery is the only reader that can fill it.
                if (\strlen($proc->bufferedPeek()) >= $target) {
                    $loop->cancelTimer($poll);
                    $loop->stop();
                }
            });
            $deadline = $loop->addTimer(15.0, function () use ($loop, $poll, &$guardFired): void {
                $guardFired = true;
                $loop->cancelTimer($poll);
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($deadline);

            $this->assertFalse($guardFired, 'pump must reach the target well inside the 15 s guard');
            $this->assertGreaterThanOrEqual($target, \strlen($proc->stdoutBytes()));
            $this->assertNull($proc->exitCode(), 'the proof needs the child STILL RUNNING — capture came mid-flight');
        } finally {
            $proc->detachPump(); // idempotent with wait()/close() settle
            $proc->terminate();
            $this->assertContains($proc->close(), [-1, 143, 137]);
        }
    }

    /**
     * Wiring pin: SpinCommand must attach the pump before the run and
     * release it after (FakeProcess flags record the calls the command
     * makes — the shape holds even when the real child is absent).
     */
    public function testSpinCommandAttachesAndReleasesThePump(): void
    {
        $fake = new FakeProcess(); // never exits — the timeout guard stops the loop
        $command = new SpinCommand(
            processFactory: static function (array $argv, bool $so, bool $se) use ($fake): Process {
                $fake->recordSpawn($argv, $so, $se);
                return $fake;
            },
            programFactory: $this->headlessProgramFactory(),
        );
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'argv'      => ['sleep', '5'],
            '--timeout' => '0.15',
        ]);

        $this->assertSame(TimeoutGuard::EXIT_TIMEOUT, $status, 'fixture depends on the deadline path firing');
        $this->assertTrue($fake->pumpAttached, 'execute() must attach the pump around the run');
        $this->assertTrue($fake->pumpDetached, 'the pump must be released when the loop returns');
        $this->assertInstanceOf(LoopInterface::class, $fake->pumpLoop);
    }

    /**
     * Tick backstop pin: even without the read-stream pump (pure polling
     * callers), every spinner tick must pump the pipes before the exit
     * probe — the exact site of the old silent poll.
     */
    public function testSpinnerTickDrainsBeforeTheExitProbe(): void
    {
        $fake = new FakeProcess();
        $model = SpinModel::spawn($fake);

        $this->assertSame(0, $fake->drainCount);
        $model->update(new TickMsg($model->spinner->id));
        $this->assertSame(1, $fake->drainCount, 'a tick must drain exactly once');

        $foreign = $model->update(new TickMsg($model->spinner->id + 100));
        $this->assertSame(1, $fake->drainCount, 'a foreign tick id must not pump (still gated on spinner->id)');
        unset($foreign);
    }

    /**
     * Pump hygiene: attach is idempotent per loop, detach is a no-op when
     * unattached, and a plain wait() on an attached handle detaches as it
     * settles — no closed fds may stay registered on a live loop.
     */
    public function testPumpAttachDetachIsIdempotentAndWaitSettlesIt(): void
    {
        $loop = new StreamSelectLoop();

        $proc = RealProcess::spawn(['/bin/sh', '-c', 'printf hi'], captureStdout: true);
        $proc->attachPump($loop);
        $proc->attachPump($loop); // same loop: must not double-register
        $proc->detachPump();
        $proc->detachPump();      // already detached: silent no-op
        $this->assertSame(0, $proc->wait());
        $this->assertSame('hi', $proc->stdoutBytes());

        // Still-attached handle reaped via wait(): settle path detaches.
        $other = RealProcess::spawn(['/bin/sh', '-c', 'sleep 0.2; printf late'], captureStdout: true);
        $other->attachPump($loop);
        $this->assertSame(0, $other->wait());
        $this->assertSame('late', $other->stdoutBytes());
        $this->assertSame(0, $other->close());
    }

    /**
     * Headless Program wiring (mirrors the SpinCommandTest harness): the
     * command runtime drives on a socket pair + memory stream so these
     * tests never touch the real TTY/STDIN.
     */
    private function headlessProgramFactory(): \Closure
    {
        return function (Model $model, LoopInterface $loop): Program {
            $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            $this->assertIsArray($pair);
            [$reader, $writer] = $pair;
            $out = \fopen('php://memory', 'w+');
            $this->assertIsResource($out);
            $this->streams[] = $reader;
            $this->streams[] = $writer;
            $this->streams[] = $out;

            return new Program($model, new ProgramOptions(
                useAltScreen:    false,
                hideCursor:      false,
                catchInterrupts: false,
                input:           $reader,
                output:          $out,
                loop:            $loop,
                windowSize:      ['cols' => 80, 'rows' => 24],
            ));
        };
    }
}
