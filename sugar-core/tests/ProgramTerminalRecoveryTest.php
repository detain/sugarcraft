<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Ansi;

/**
 * rv-tui-1 / rv-tui-3 (crush-review-1014): a Throwable escaping run() used to
 * skip every teardown line — the process died with alt-screen entered, the
 * cursor hidden and mouse capture on, stranding the user's terminal. run()'s
 * loop section now unwinds through a finally, teardownTerminal() is idempotent
 * via the $terminalAttached latch, and SIGTERM/SIGHUP ride the same clean stop
 * path as SIGINT so `kill <pid>` and a closed terminal recover identically.
 */
final class ProgramTerminalRecoveryTest extends TestCase
{
    /**
     * Model whose update() throws on the first WindowSizeMsg — the exact
     * shape rv-tui-1 flagged (Program's pre-loop dispatch is unguarded).
     */
    private static function throwingModel(): Model
    {
        return new class implements Model {
            use SubscriptionCapable;

            public function init(): ?\Closure
            {
                return null;
            }

            public function update(Msg $msg): array
            {
                if ($msg instanceof WindowSizeMsg) {
                    throw new \RuntimeException('model exploded');
                }
                return [$this, null];
            }

            public function view(): string
            {
                return 'never painted';
            }
        };
    }

    /** Fully-armed terminal options: every recovery byte is observable. */
    private function options($in, $out, StreamSelectLoop $loop, bool $catchInterrupts = false): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: true,
            catchInterrupts: $catchInterrupts,
            hideCursor: true,
            mouseMode: MouseMode::CellMotion,
            reportFocus: true,
            bracketedPaste: true,
            input: $in,
            output: $out,
            loop: $loop,
        );
    }

    /** @return array{0:resource, 1:resource, 2:resource} input, output, inputWriter */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    /** @param resource $out */
    private function drain($out): string
    {
        $pos = ftell($out);
        $this->assertIsInt($pos);
        rewind($out);
        $bytes = stream_get_contents($out);
        $this->assertIsString($bytes);
        fseek($out, $pos);
        return $bytes;
    }

    public function testThrowableFromUpdateStillTearsDownTheTerminal(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program(self::throwingModel(), $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $thrown = null;
        try {
            $program->run();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the Throwable must still propagate out of run()');
        $this->assertSame('model exploded', $thrown->getMessage());

        $written = $this->drain($out);
        // The crash path emits the SAME recovery bytes as a clean quit —
        // each exactly once: the finally ran teardown, and nothing re-ran it.
        $this->assertSame(1, substr_count($written, Ansi::altScreenEnter()));
        $this->assertSame(1, substr_count($written, Ansi::altScreenLeave()));
        $this->assertSame(1, substr_count($written, Ansi::cursorHide()));
        $this->assertSame(1, substr_count($written, Ansi::cursorShow()));
        $this->assertSame(1, substr_count($written, Ansi::mouseCellMotionOff()));
        $this->assertSame(1, substr_count($written, Ansi::focusReportingOff()));
        $this->assertSame(1, substr_count($written, Ansi::bracketedPasteOff()));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testThrowableEscapingTheLoopStillTearsDownTheTerminal(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        // Throws from inside a loop callback (timer → Cmd → dispatch), the
        // other rv-tui-1 shape: loop->run() re-raises and the old tail died.
        $model = new class implements Model {
            use SubscriptionCapable;

            public function init(): ?\Closure
            {
                return Cmd::tick(0.01, static function (): never {
                    throw new \LogicException('loop callback exploded');
                });
            }

            public function update(Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): string
            {
                return 'x';
            }
        };
        $program = new Program($model, $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $thrown = null;
        try {
            $program->run();
        } catch (\LogicException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'a Throwable raised inside loop->run() must propagate');
        $written = $this->drain($out);
        $this->assertSame(1, substr_count($written, Ansi::altScreenLeave()), 'finally teardown ran once');
        $this->assertSame(1, substr_count($written, Ansi::mouseCellMotionOff()));
        $this->assertSame(1, substr_count($written, Ansi::cursorShow()));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testTeardownSurvivesClosedOutputAndTornPipe(): void
    {
        $errors = [];
        set_error_handler(static function (int $no, string $str) use (&$errors): bool {
            if (!(error_reporting() & $no)) {
                return true; // @-silenced, exactly as PHP's own handler treats it
            }
            $errors[] = $str;
            return true;
        });
        try {
            // Closed output resource: every write would TypeError.
            [$in, $out, $writer] = $this->pipes();
            $program = new Program($this->noopModel(), $this->options($in, $out, new StreamSelectLoop()));
            (new \ReflectionMethod(Program::class, 'setupTerminal'))->invoke($program);
            fclose($out);
            (new \ReflectionMethod(Program::class, 'teardownTerminal'))->invoke($program);
            (new \ReflectionMethod(Program::class, 'releaseTerminalForShutdown'))->invoke($program, getmypid());

            // Torn pipe: reader gone, writes fail with EPIPE.
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($sockets);
            [$reader, $torn] = $sockets;
            [$in2, , $writer2] = $this->pipes();
            $program2 = new Program($this->noopModel(), $this->options($in2, $torn, new StreamSelectLoop()));
            (new \ReflectionMethod(Program::class, 'setupTerminal'))->invoke($program2);
            fclose($reader);
            (new \ReflectionMethod(Program::class, 'teardownTerminal'))->invoke($program2);
            fclose($torn);
            fclose($in2);
            fclose($writer2);
            fclose($in);
            fclose($writer);
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $errors, 'teardown must raise no Notice/Warning');
    }

    public function testLaterRestoreWritesStillAttemptedAfterAFailedWrite(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $program = new Program($this->noopModel(), $this->options($in, $out, new StreamSelectLoop()));
        (new \ReflectionMethod(Program::class, 'setupTerminal'))->invoke($program);
        $write = new \ReflectionMethod(Program::class, 'writeOutput');
        $prop = new \ReflectionProperty(Program::class, 'output');
        $dead = fopen('php://memory', 'w');
        fclose($dead);
        $prop->setValue($program, $dead);
        $write->invoke($program, "lost");          // must not throw
        $prop->setValue($program, $out);
        $write->invoke($program, "later");
        $this->assertStringContainsString('later', $this->drain($out));
        fclose($out);
        fclose($in);
        fclose($writer);
    }

    public function testTeardownTerminalIsIdempotentWhileAttached(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->noopModel(), $this->options($in, $out, $loop));

        $setup = new \ReflectionMethod(Program::class, 'setupTerminal');
        $teardown = new \ReflectionMethod(Program::class, 'teardownTerminal');

        $setup->invoke($program);
        $teardown->invoke($program);
        $teardown->invoke($program); // second call must be a byte-safe no-op

        $written = $this->drain($out);
        $this->assertSame(1, substr_count($written, Ansi::altScreenLeave()));
        $this->assertSame(1, substr_count($written, Ansi::cursorShow()));
        $this->assertSame(1, substr_count($written, Ansi::mouseCellMotionOff()));
        $this->assertSame(1, substr_count($written, Ansi::focusReportingOff()));
        $this->assertSame(1, substr_count($written, Ansi::bracketedPasteOff()));

        // Re-arm: setup again, teardown again — a third teardown still adds
        // nothing (the runExec / suspend release→re-acquire pairing relies on
        // the latch tracking attach state, not a one-shot flag).
        $setup->invoke($program);
        $teardown->invoke($program);
        $teardown->invoke($program);
        $written = $this->drain($out);
        $this->assertSame(2, substr_count($written, Ansi::altScreenLeave()));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * M1 (lib-reviews-1102): catchPanics was a byte-for-byte inert knob —
     * documented as the WithoutCatchPanics mirror while setupTerminal()
     * armed the shutdown backstop unconditionally. Now the flag decides;
     * the default (true) keeps every existing run pinned to the hook.
     */
    public function testCatchPanicsGatesTheShutdownBackstopRegistration(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $armed = new \ReflectionProperty(Program::class, 'terminalReleaseHookArmed');
        $setup = new \ReflectionMethod(Program::class, 'setupTerminal');

        $latched = new Program($this->noopModel(), $this->options($in, $out, $loop));
        $setup->invoke($latched);
        $this->assertTrue($armed->getValue($latched), 'default catchPanics:true arms the backstop');

        $unlatched = new Program($this->noopModel(), new ProgramOptions(
            useAltScreen: true,
            catchPanics: false,
            input: $in,
            output: $out,
            loop: $loop,
        ));
        $setup->invoke($unlatched);
        $this->assertFalse($armed->getValue($unlatched), 'catchPanics:false skips the registration');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testTeardownAfterRunIsANoOp(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $model = new class implements Model {
            use SubscriptionCapable;

            public function init(): ?\Closure
            {
                return Cmd::quit();
            }

            public function update(Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): string
            {
                return 'x';
            }
        };
        $program = new Program($model, $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        // run()'s finally already detached the terminal; the public
        // releaseTerminal() on top of it must not re-emit recovery bytes
        // onto the user's (now primary-screen) terminal.
        $before = ftell($out);
        $this->assertIsInt($before);
        $program->releaseTerminal();
        $this->assertSame($before, ftell($out), 'post-run releaseTerminal() emitted bytes again');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testNormalQuitPathEmitsTeardownExactlyOnce(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $model = new class implements Model {
            use SubscriptionCapable;

            public function init(): ?\Closure
            {
                return Cmd::quit();
            }

            public function update(Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): string
            {
                return 'x';
            }
        };
        $program = new Program($model, $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        $written = $this->drain($out);
        $this->assertSame(substr_count($written, Ansi::altScreenEnter()), substr_count($written, Ansi::altScreenLeave()));
        $this->assertSame(1, substr_count($written, Ansi::altScreenLeave()));
        $this->assertSame(1, substr_count($written, Ansi::bracketedPasteOff()));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testSigTermAndSigHangUpArmedAlongsideSigInt(): void
    {
        if (!function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !defined('SIGTERM') || !defined('SIGHUP')
        ) {
            $this->markTestSkipped('pcntl signal introspection not available');
        }

        $prevAsync = function_exists('pcntl_async_signals') ? pcntl_async_signals(false) : null;
        // SIG_DFL (an int), NOT a sentinel closure: a closure sentinel would
        // satisfy any instanceof-Closure check vacuously — the discriminator
        // has to be "only the Program's install can put a handler here".
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGHUP, SIG_DFL);

        try {
            [$in, $out, $writer] = $this->pipes();
            $loop = new StreamSelectLoop();
            $program = new Program($this->noopModel(), $this->options($in, $out, $loop, catchInterrupts: true));

            $install = new \ReflectionMethod(Program::class, 'installSignalHandlers');
            $restore = new \ReflectionMethod(Program::class, 'restoreSignalHandlers');

            $install->invoke($program);
            $this->assertInstanceOf(
                \Closure::class,
                pcntl_signal_get_handler(SIGTERM),
                'SIGTERM must ride the same stop path as SIGINT (rv-tui-1)',
            );
            $this->assertInstanceOf(
                \Closure::class,
                pcntl_signal_get_handler(SIGHUP),
                'SIGHUP (closed terminal) must ride the same stop path as SIGINT',
            );

            // The mirror arm is a real stop: invoke the installed handler and
            // the loop must be told to stop (running=false + loop->stop()).
            // restoreSignalHandlers() then hands each signal back verbatim.
            $restore->invoke($program);
            $this->assertSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM), 'restore must hand SIGTERM back');
            $this->assertSame(SIG_DFL, pcntl_signal_get_handler(SIGHUP), 'restore must hand SIGHUP back');

            fclose($writer);
            fclose($in);
            fclose($out);
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGHUP, SIG_DFL);
            if ($prevAsync !== null) {
                pcntl_async_signals($prevAsync);
            }
        }
    }

    public function testCatchInterruptsOffInstallsNoProgramHandlers(): void
    {
        if (!function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !defined('SIGTERM') || !defined('SIGHUP')
        ) {
            $this->markTestSkipped('pcntl signal introspection not available');
        }

        $sentinelTerm = static function (): void {
        };
        /** @var callable|int $prevTerm */
        $prevTerm = pcntl_signal_get_handler(SIGTERM);
        pcntl_signal(SIGTERM, $sentinelTerm);

        try {
            [$in, $out, $writer] = $this->pipes();
            $loop = new StreamSelectLoop();
            $program = new Program($this->noopModel(), $this->options($in, $out, $loop));

            $install = new \ReflectionMethod(Program::class, 'installSignalHandlers');
            $install->invoke($program);

            $this->assertSame(
                $sentinelTerm,
                pcntl_signal_get_handler(SIGTERM),
                'catchInterrupts=false must not touch the signal table',
            );

            fclose($writer);
            fclose($in);
            fclose($out);
        } finally {
            pcntl_signal(SIGTERM, $prevTerm);
        }
    }

    private function noopModel(): Model
    {
        return new class implements Model {
            use SubscriptionCapable;

            public function init(): ?\Closure
            {
                return null;
            }

            public function update(Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): string
            {
                return '';
            }
        };
    }

    /**
     * Quits immediately when $quits; otherwise a silent passenger. Used by
     * the stopSignal()/hook pins where the run must return through the
     * normal funnel without touching signals.
     */
    private function quietModel(bool $quits = false): Model
    {
        return new class($quits) implements Model {
            use SubscriptionCapable;

            public function __construct(private readonly bool $quits)
            {
            }

            public function init(): ?\Closure
            {
                return $this->quits ? Cmd::quit() : null;
            }

            public function update(Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): string
            {
                return 'x';
            }
        };
    }

    /**
     * A Model that delivers SIGTERM to its own process on the first
     * WindowSizeMsg — the exact mid-run `kill <pid>` a supervisor sends.
     */
    private static function sigTermSendingModel(): Model
    {
        return new class implements Model {
            use SubscriptionCapable;

            public function init(): ?\Closure
            {
                return null;
            }

            public function update(Msg $msg): array
            {
                if ($msg instanceof WindowSizeMsg) {
                    posix_kill(getmypid(), SIGTERM);
                    pcntl_signal_dispatch();
                }
                return [$this, null];
            }

            public function view(): string
            {
                return 'x';
            }
        };
    }

    /**
     * v2:MINOR-6 — a run that a signal stopped must be distinguishable from
     * a cooperative exit: stopSignal() answers SIGTERM end-to-end while the
     * teardown still runs, and the release hook contributes NO bytes to the
     * run itself (it exists only for the exit paths run()'s finally misses).
     */
    public function testSignalStoppedRunRecordsTheStoppingSignal(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')
            || !function_exists('posix_kill') || !defined('SIGTERM')
        ) {
            $this->markTestSkipped('self-signalling requires pcntl and posix');
        }

        $prevAsync = pcntl_async_signals(false);
        pcntl_signal(SIGTERM, SIG_DFL);

        try {
            [$in, $out, $writer] = $this->pipes();
            $loop = new StreamSelectLoop();
            $program = new Program(
                self::sigTermSendingModel(),
                $this->options($in, $out, $loop, catchInterrupts: true),
            );
            $loop->addTimer(2.0, static fn () => $loop->stop());

            $program->run();

            $this->assertSame(SIGTERM, $program->stopSignal());

            $written = $this->drain($out);
            $this->assertSame(1, substr_count($written, Ansi::altScreenLeave()),
                'a signal-stopped run still unwinds through the full teardown');
            $this->assertSame(0, substr_count($written, Ansi::popKittyKeyboard()),
                'the shutdown release contributes no bytes to a run that ended normally');

            fclose($writer);
            fclose($in);
            fclose($out);
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_async_signals($prevAsync);
        }
    }

    /** v2:MINOR-6: every stop the program asked for itself keeps stopSignal() null. */
    public function testCooperativeExitsLeaveStopSignalNull(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->quietModel(quits: true), $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $program->run();

        $this->assertNull($program->stopSignal());

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * v2:MINOR-6: the first signal to ask wins, and run() resets the record
     * so a re-run cannot report the previous run's stop reason.
     */
    public function testFirstSignalWinsAndEachRunResetsTheRecord(): void
    {
        if (!defined('SIGINT') || !defined('SIGTERM')) {
            $this->markTestSkipped('pcntl constants not available');
        }

        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->quietModel(quits: true), $this->options($in, $out, $loop));
        $stop = new \ReflectionMethod(Program::class, 'stopProgram');

        $stop->invoke($program, SIGINT);
        $stop->invoke($program, SIGTERM);
        $this->assertSame(SIGINT, $program->stopSignal(), 'a later signal must not overwrite the first');

        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();
        $this->assertNull($program->stopSignal(), 'run() must reset the record for the new run');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * v2:tui-F-1 fix-2: with the latch UP, this pid, and a live output, the
     * shutdown release is exactly the stranded-terminal byte set — and a
     * second invocation is silent (the teardown latch does the idempotency).
     */
    public function testShutdownReleaseEmitsTheFullSetOnceWhileAttached(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->noopModel(), $this->options($in, $out, $loop));
        $setup = new \ReflectionMethod(Program::class, 'setupTerminal');
        $release = new \ReflectionMethod(Program::class, 'releaseTerminalForShutdown');

        $setup->invoke($program);
        $release->invoke($program, getmypid());

        $written = $this->drain($out);
        $this->assertSame(1, substr_count($written, Ansi::altScreenLeave()));
        $this->assertSame(1, substr_count($written, Ansi::cursorShow()));
        $this->assertSame(1, substr_count($written, Ansi::mouseCellMotionOff()));
        $this->assertSame(1, substr_count($written, Ansi::focusReportingOff()));
        $this->assertSame(1, substr_count($written, Ansi::bracketedPasteOff()));
        $this->assertSame(1, substr_count($written, Ansi::unicodeOff()));
        $this->assertSame(1, substr_count($written, Ansi::popKittyKeyboard()));

        $position = ftell($out);
        $this->assertIsInt($position);
        $release->invoke($program, getmypid());
        $this->assertSame($position, ftell($out), 'the release must be byte-idempotent');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * v2:tui-F-1 fix-2: the gates. A foreign install-pid (a forked child
     * copying the shutdown queue), a program that already detached (every
     * clean run), and a closed output resource must each release nothing.
     * The closed-resource leg is the mutation keystone: without the
     * is_resource() gate PHP 8 raises TypeError inside fwrite() — the test
     * simply errors under that mutation, which is the pin.
     */
    public function testShutdownReleaseStaysSilentBehindEveryGate(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->noopModel(), $this->options($in, $out, $loop));
        $setup = new \ReflectionMethod(Program::class, 'setupTerminal');
        $release = new \ReflectionMethod(Program::class, 'releaseTerminalForShutdown');

        $setup->invoke($program);
        $mark = ftell($out);
        $this->assertIsInt($mark);

        $release->invoke($program, PHP_INT_MAX); // install pid from "another process"
        $this->assertSame($mark, ftell($out), 'a forked child must emit nothing');

        $release->invoke($program, getmypid());  // real release
        $after = ftell($out);
        $this->assertIsInt($after);
        $this->assertGreaterThan($mark, $after);

        $release->invoke($program, getmypid());  // latch is down now
        $this->assertSame($after, ftell($out),
            'an already-detached program must emit nothing');

        $setup->invoke($program);                // re-attach, but the stream dies
        fclose($out);
        $release->invoke($program, getmypid());  // must not throw
        $this->assertTrue(true);

        fclose($writer);
        fclose($in);
    }

    /**
     * v2:tui-F-1 fix-2: registration discipline, pinned on source order —
     * the arm flips its one-shot latch BEFORE capturing (a re-entry between
     * capture and register could double-arm), captures pid and WeakReference
     * BEFORE registering (the queue passes values, not lazily-read state),
     * and the closure null-guards the weakref before dereferencing.
     */
    public function testReleaseHookArmsOncePerProgramWithCaptureBeforeRegister(): void
    {
        $body = $this->methodBody('armTerminalReleaseHook');
        $latchSet = strpos($body, '$this->terminalReleaseHookArmed = true;');
        $weak = strpos($body, 'WeakReference::create($this)');
        $pid = strpos($body, '$installPid = getmypid();');
        $register = strpos($body, 'register_shutdown_function(');
        $get = strpos($body, '$program->get()');
        $fire = strpos($body, '$target->releaseTerminalForShutdown($installPid)');
        $this->assertIsInt($latchSet);
        $this->assertIsInt($weak);
        $this->assertIsInt($pid);
        $this->assertIsInt($register);
        $this->assertIsInt($get);
        $this->assertIsInt($fire);
        $this->assertLessThan($weak, $latchSet);
        $this->assertLessThan($register, $weak);
        $this->assertLessThan($register, $pid);
        $this->assertLessThan($fire, $get);
        $this->assertStringContainsString('$target !== null', $body);
        $this->assertStringContainsString('if ($this->terminalReleaseHookArmed) {', $body);

        $setup = $this->methodBody('setupTerminal');
        $this->assertLessThan(
            strpos($setup, '$this->armTerminalReleaseHook();'),
            strpos($setup, '$this->terminalAttached = true;'),
            'the hook arms as part of attaching, after the latch is set',
        );

        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->noopModel(), $this->options($in, $out, $loop));
        $armed = new \ReflectionProperty(Program::class, 'terminalReleaseHookArmed');

        $this->assertFalse($armed->getValue($program));
        $setupMethod = new \ReflectionMethod(Program::class, 'setupTerminal');
        $setupMethod->invoke($program);
        $this->assertTrue($armed->getValue($program));
        $teardown = new \ReflectionMethod(Program::class, 'teardownTerminal');
        $teardown->invoke($program);
        $this->assertTrue($armed->getValue($program), 'a clean detach must not re-arm a second hook');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** v2:tui-F-1 fix-2: run() — via setupTerminal — arms the hook. */
    public function testRunArmsTheReleaseHook(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->quietModel(quits: true), $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $program->run();

        $this->assertTrue(
            (new \ReflectionProperty(Program::class, 'terminalReleaseHookArmed'))->getValue($program),
        );

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** v2:tui-F-1 fix-2: the release body checks every gate before it writes. */
    public function testReleaseBodyGatesPrecedeTheEmission(): void
    {
        $body = $this->methodBody('releaseTerminalForShutdown');
        $latch = strpos($body, 'if (!$this->terminalAttached) {');
        $pid = strpos($body, 'if ($installPid !== getmypid()) {');
        $resource = strpos($body, 'if (!is_resource($this->output)) {');
        $pop = strpos($body, '$this->writeOutput(Ansi::popKittyKeyboard());');
        $teardown = strpos($body, '$this->teardownTerminal();');
        $this->assertIsInt($latch);
        $this->assertIsInt($pid);
        $this->assertIsInt($resource);
        $this->assertIsInt($pop);
        $this->assertIsInt($teardown);
        $this->assertLessThan($pid, $latch, 'latch first, pid capture second — the order X-CORE1 retired from candy-code and kept here');
        $this->assertLessThan($resource, $pid);
        $this->assertLessThan($pop, $resource);
        $this->assertLessThan($teardown, $pop);
    }

    /**
     * CTSP-F-1 (X-CORE1, reviews-1103): a session that wrote a window title
     * must not let it outlive the process. The teardown emits the OSC
     * empty-title pop before the alt-screen leave, and clears the memo so a
     * re-attach re-issues a title it was given.
     */
    public function testTeardownPopsTheWindowTitleBeforeLeavingTheAltScreen(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->noopModel(), $this->options($in, $out, $loop));
        $setup = new \ReflectionMethod(Program::class, 'setupTerminal');
        $teardown = new \ReflectionMethod(Program::class, 'teardownTerminal');
        $title = new \ReflectionProperty(Program::class, 'lastWindowTitle');

        $setup->invoke($program);
        $title->setValue($program, 'candy — a session');
        $teardown->invoke($program);

        $written = $this->drain($out);
        $pop = Ansi::setWindowTitle('');
        $this->assertSame(1, substr_count($written, $pop), 'the title pop is emitted exactly once');
        $this->assertLessThan(
            strpos($written, Ansi::altScreenLeave()),
            strpos($written, $pop),
            'the pop rides before the alt-screen leave',
        );
        $this->assertNull($title->getValue($program), 'the memo clears with the emission');

        $position = ftell($out);
        $this->assertIsInt($position);
        $teardown->invoke($program);
        $this->assertSame($position, ftell($out), 'the latch keeps the second teardown byte-silent');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** CTSP-F-1 negative half: a program that never titled emits no OSC pop. */
    public function testTeardownStaysTitleSilentWhenNoTitleWasEverSet(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->noopModel(), $this->options($in, $out, $loop));
        $setup = new \ReflectionMethod(Program::class, 'setupTerminal');
        $teardown = new \ReflectionMethod(Program::class, 'teardownTerminal');

        $setup->invoke($program);
        $teardown->invoke($program);

        $this->assertStringNotContainsString(
            Ansi::setWindowTitle(''),
            $this->drain($out),
            'no title was ever claimed, so there is nothing to pop',
        );

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * X-CORE1 / TUIARCH-01 (OD-17): the consumer seam. armTerminalRelease()
     * is public and arms the one-shot hook before run() is ever entered —
     * the wider arming window candy-code's hand-rolled guards existed for —
     * while setupTerminal()'s own catchPanics-gated arming stays a no-op
     * against the first call.
     */
    public function testArmTerminalReleaseArmsTheBackstopBeforeRunIsEntered(): void
    {
        $arm = new \ReflectionMethod(Program::class, 'armTerminalRelease');
        $this->assertTrue($arm->isPublic(), 'the seam is public API');
        $this->assertStringContainsString('$this->armTerminalReleaseHook();', $this->methodBody('armTerminalRelease'));

        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program($this->noopModel(), $this->options($in, $out, $loop));
        $armed = new \ReflectionProperty(Program::class, 'terminalReleaseHookArmed');

        $this->assertFalse($armed->getValue($program));
        $arm->invoke($program);
        $this->assertTrue($armed->getValue($program), 'the pre-run arm lands');

        $setup = new \ReflectionMethod(Program::class, 'setupTerminal');
        $setup->invoke($program);
        $teardown = new \ReflectionMethod(Program::class, 'teardownTerminal');
        $teardown->invoke($program);
        $this->assertTrue($armed->getValue($program), 'arming is once per Program, ever');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * X-CORE1-FIX (reviews-1103 MAJOR-1): the attach latch must arm BEFORE
     * the first terminal write, not at the tail of attach. setupTerminal()
     * runs outside run()'s try, so a hard fatal (or an exception from
     * enableRawMode's TermiosFactory open/makeRaw/apply chain) after the
     * alt-screen is already entered escapes with no finally to lean on —
     * only the shutdown backstop can restore, and it gates on this latch.
     * A tail latch strands the user in the alt screen for every throw
     * inside attach. Pinned on source order (house style): the latch sits
     * ahead of both the first emission and the raw-mode acquisition.
     */
    public function testSetupTerminalArmsTheAttachLatchBeforeItsFirstEmission(): void
    {
        $setup = $this->methodBody('setupTerminal');
        $latch = strpos($setup, '$this->terminalAttached = true;');
        $enter = strpos($setup, '$this->writeOutput(Ansi::altScreenEnter());');
        $raw = strpos($setup, '$this->tty->enableRawMode();');
        $this->assertIsInt($latch);
        $this->assertIsInt($enter);
        $this->assertIsInt($raw);
        $this->assertLessThan($enter, $latch, 'the latch arms before the first write — a fatal mid-attach finds it UP');
        $this->assertLessThan($raw, $latch, 'the raw-mode call sits behind the latch too — its throws land inside the armed window');
    }

    /** Source of one Program method — the shape BinTerminalGuardPidTest scans, per-method. */
    private function methodBody(string $name): string
    {
        $method = new \ReflectionMethod(Program::class, $name);
        $lines = file($method->getFileName());
        $this->assertIsArray($lines);

        return implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
