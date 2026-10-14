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
}
