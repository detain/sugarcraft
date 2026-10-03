<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Tests\Support\LoggingModel;

/**
 * crush_libs.md candy-core #3: run() cancelled its render tick and its
 * subscriptions but not the one-shot timers it armed (TickRequest, the
 * lone-ESC flush), and never restored the process-wide signal handlers it
 * replaced. On a shared loop a tick due after QuitMsg fired into the dead
 * Program; the handler closures kept the Program reachable and answered a
 * later Program's signals.
 */
final class ProgramRuntimeTeardownTest extends TestCase
{
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

    private function options($in, $out, StreamSelectLoop $loop, bool $catchInterrupts = false): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: $catchInterrupts,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );
    }

    /** Spin the (shared) loop on its own for `$seconds` after run() returned. */
    private function spin(StreamSelectLoop $loop, float $seconds): void
    {
        $loop->addTimer($seconds, static fn () => $loop->stop());
        $loop->run();
    }

    public function testTickRequestStillArmedAtQuitNeverFiresAfterRun(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $fired = false;
        $model = new LoggingModel(initCmd: Cmd::batch(
            Cmd::tick(0.2, static function () use (&$fired): ?Msg {
                $fired = true;
                return null;
            }),
            Cmd::quit(),
        ));
        $program = new Program($model, $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop()); // safety net
        $program->run();

        $timers = new \ReflectionProperty(Program::class, 'oneShotTimers');
        $this->assertSame([], $timers->getValue($program), 'run() must cancel its one-shot timers');

        $this->spin($loop, 0.4);
        $this->assertFalse($fired, 'a tick armed before quit fired into the torn-down Program');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testFiredTickRequestIsForgottenNotLeaked(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new LoggingModel(initCmd: Cmd::tick(0.01, static fn (): Msg => new \SugarCraft\Core\Msg\QuitMsg()));
        $program = new Program($model, $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        $timers = new \ReflectionProperty(Program::class, 'oneShotTimers');
        $this->assertSame([], $timers->getValue($program));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testPendingEscapeFlushDoesNotFireAfterRun(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        // A lone ESC arms the 50 ms disambiguation timer; the program quits
        // well before it is due.
        fwrite($writer, "\x1b");
        $model = new LoggingModel(initCmd: Cmd::tick(0.02, static fn (): Msg => new \SugarCraft\Core\Msg\QuitMsg()));
        $program = new Program($model, $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        $before = count($program->model()->log);
        $this->spin($loop, 0.2);

        $escapes = array_filter(
            $program->model()->log,
            static fn (Msg $m): bool => $m instanceof KeyMsg && $m->type === KeyType::Escape,
        );
        $this->assertSame([], array_values($escapes), 'the ESC flush fired after run() returned');
        $this->assertCount($before, $program->model()->log);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testRunRestoresTheSignalHandlersAndAsyncModeItReplaced(): void
    {
        if (!function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('pcntl_async_signals')
            || !defined('SIGWINCH') || !defined('SIGTSTP') || !defined('SIGCONT')
        ) {
            $this->markTestSkipped('pcntl signal introspection not available');
        }

        // StreamSelectLoop's constructor turns async delivery ON (it relies on
        // it for addSignal()), so build the loop first, then switch it off:
        // "off" is the pre-run state the Program must put back.
        $loop = new StreamSelectLoop();
        $prevAsync = pcntl_async_signals(false);
        $sentinelInt = static function (): void {
        };
        $sentinelWinch = static function (): void {
        };
        pcntl_signal(SIGINT, $sentinelInt);
        pcntl_signal(SIGWINCH, $sentinelWinch);
        pcntl_signal(SIGTSTP, SIG_IGN);
        pcntl_signal(SIGCONT, SIG_DFL);

        try {
            [$in, $out, $writer] = $this->pipes();
            $program = new Program(
                new LoggingModel(initCmd: Cmd::quit()),
                $this->options($in, $out, $loop, catchInterrupts: true),
            );
            $loop->addTimer(2.0, static fn () => $loop->stop());
            $program->run();

            $this->assertSame($sentinelInt, pcntl_signal_get_handler(SIGINT));
            $this->assertSame($sentinelWinch, pcntl_signal_get_handler(SIGWINCH));
            $this->assertSame(SIG_IGN, pcntl_signal_get_handler(SIGTSTP));
            $this->assertSame(SIG_DFL, pcntl_signal_get_handler(SIGCONT));
            $this->assertFalse(pcntl_async_signals(), 'async signal delivery must be put back');

            fclose($writer);
            fclose($in);
            fclose($out);
        } finally {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGWINCH, SIG_DFL);
            pcntl_signal(SIGTSTP, SIG_DFL);
            pcntl_signal(SIGCONT, SIG_DFL);
            pcntl_async_signals($prevAsync);
        }
    }

    /**
     * A signal subscription that displaced the Program's own SIGWINCH handler
     * must give the signal back to the Program while it runs, and the
     * Program must give it back to the pre-run handler when it ends.
     */
    public function testSignalSubscriptionOnAProgramSignalUnwindsInOrder(): void
    {
        if (!function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !defined('SIGWINCH')
        ) {
            $this->markTestSkipped('pcntl signal introspection not available');
        }

        $prevAsync = function_exists('pcntl_async_signals') ? pcntl_async_signals() : null;
        $sentinel = static function (): void {
        };
        pcntl_signal(SIGWINCH, $sentinel);

        try {
            [$in, $out, $writer] = $this->pipes();
            $loop = new StreamSelectLoop();
            $opts = new ProgramOptions(
                useAltScreen: false,
                catchInterrupts: true,
                hideCursor: false,
                input: $in,
                output: $out,
                loop: $loop,
                subscriptions: static fn () => (new \SugarCraft\Core\Subscriptions())
                    ->withSignal('winch', SIGWINCH, static fn (): ?Msg => null),
            );
            $program = new Program(new LoggingModel(initCmd: Cmd::quit()), $opts);
            $loop->addTimer(2.0, static fn () => $loop->stop());
            $program->run();

            $this->assertSame($sentinel, pcntl_signal_get_handler(SIGWINCH));

            fclose($writer);
            fclose($in);
            fclose($out);
        } finally {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGWINCH, SIG_DFL);
            if (defined('SIGTSTP')) {
                pcntl_signal(SIGTSTP, SIG_DFL);
            }
            if (defined('SIGCONT')) {
                pcntl_signal(SIGCONT, SIG_DFL);
            }
            if ($prevAsync !== null) {
                pcntl_async_signals($prevAsync);
            }
        }
    }
}
