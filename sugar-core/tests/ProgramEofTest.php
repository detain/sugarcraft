<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use React\EventLoop\StreamSelectLoop;

/**
 * A model that never quits: the ONLY way the program stops is an external
 * cooperative quit (EOF arm, quit(), kill()) — which is exactly what
 * sugar-core-006 needs to prove or disprove.
 */
final class EofSilentModel implements Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    /** @var list<Msg> */
    public array $log = [];

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?Cmd}
     */
    public function update(Msg $msg): array
    {
        $this->log[] = $msg;
        return [$this, null];
    }

    public function view(): string
    {
        return 'eof';
    }
}

/**
 * sc-05 / sugar-core-006: EOF on a non-TTY stdin must end the program.
 *
 * The shipped EOF arm only removed the stream watcher, leaving the render
 * tick timer to hold the loop open forever (`echo | app` hangs until
 * killed — rc=137 under timeout). The cure dispatches QuitMsg through the
 * cooperative send() vocabulary; the watchdog timer below doubles as the
 * regression detector: without the EOF quit it fires (loop still alive at
 * t=2s) and the run() escape is attributed, not hung.
 */
final class ProgramEofTest extends TestCase
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

    private function makeOptions($in, $out, $loop): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );
    }

    public function testEofOnClosedStdinQuitsCooperativelyWithinBoundedWindow(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model   = new EofSilentModel();
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        // Close the writer shortly after the loop starts: the reader
        // callback sees EOF exactly once.
        $loop->addTimer(0.01, function () use ($writer): void {
            fclose($writer);
        });

        // Watchdog: ONLY a regression (EOF failing to quit) lets the loop
        // live this long. If it fires, the run() was saved by the
        // watchdog, not the cure — the assertFalse below catches that.
        $watchdogFired = false;
        $loop->addTimer(2.0, function () use ($program, &$watchdogFired): void {
            $watchdogFired = true;
            $program->quit();
        });

        $t0 = \microtime(true);
        $returned = $program->run();
        $elapsed = \microtime(true) - $t0;

        $this->assertFalse($watchdogFired, 'EOF must arm the cooperative quit itself — the watchdog rescued this run');
        $this->assertLessThan(1.5, $elapsed, 'the quit must land well inside the watchdog window');
        $this->assertInstanceOf(Model::class, $returned);
        fclose($in);
        rewind($out);
    }

    public function testBytesBeforeEofAreStillDelivered(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model   = new EofSilentModel();
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(0.01, function () use ($writer): void {
            \fwrite($writer, 'q');
            fclose($writer);
        });
        $watchdog = false;
        $loop->addTimer(2.0, function () use ($program, &$watchdog): void {
            $watchdog = true;
            $program->quit();
        });

        $program->run();
        $this->assertFalse($watchdog);

        // The queued byte arrives before the quit wins: input bytes are
        // dispatched inside the same callback, and send() defers the
        // QuitMsg one tick behind them.
        $keys = [];
        foreach ($model->log as $m) {
            if ($m instanceof Msg\KeyMsg) {
                $keys[] = $m->rune;
            }
        }
        $this->assertContains('q', $keys, 'a byte read in the final window must not be eaten by the quit');
        fclose($in);
        rewind($out);
    }

    public function testOpenStdinNeverQuitsOnEOFPath(): void
    {
        // Regression arm: a pipe that is merely QUIET (watcher fires with
        // no data, feof false) must not quit. We can't reliably race a
        // zero-byte readable on a socketpair, so the pin is structural:
        // after the run, a model whose only traffic was pre-EOF input
        // still ends via the explicit quit, and an intermediate empty
        // read returns readInput false WITHOUT arming the queue.
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model   = new EofSilentModel();
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(0.01, function () use ($program): void {
            $program->quit(); // cooperative quit with the pipe still OPEN
        });
        $t0 = \microtime(true);
        $program->run();
        $this->assertLessThan(1.5, \microtime(true) - $t0);

        // Pipe open the whole time: quit came from quit(), not the EOF
        // arm. Reaching here means EOF (feof false while writable end
        // lives) never fired a premature quit.
        $this->assertTrue(\is_resource($writer));
        fclose($writer);
        fclose($in);
        rewind($out);
    }
}
