<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\Kind;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscription;
use SugarCraft\Core\SubscriptionCapable;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

/**
 * sugar-core-034 (RUN-11, RUN-13): the silent arms in the terminal setup and
 * signal teardown chains. Cursor visibility must be SEEDED from the state the
 * program itself observed on the wire, and a signal predecessor that could not
 * be read must never be fabricated as SIG_DFL over someone else's handler.
 */
final class SeededCursorModel implements Model
{
    use SubscriptionCapable;

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array { return [$this, null]; }

    /** Cursor-less view: with an unseeded flag this emitted a redundant hide. */
    public function view(): string { return 'frame'; }
}

final class ProgramSilentArmsTest extends TestCase
{
    /** @var list<resource> */
    private array $handles = [];

    protected function tearDown(): void
    {
        foreach ($this->handles as $h) {
            if (is_resource($h)) {
                fclose($h);
            }
        }
        $this->handles = [];
        parent::tearDown();
    }
    /** @return array{0:resource, 1:resource, 2:resource} */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    private function outputContents($output): string
    {
        $pos = ftell($output);
        rewind($output);
        $bytes = stream_get_contents($output);
        \fseek($output, (int) $pos);
        return $bytes === false ? '' : $bytes;
    }

    /**
     * RUN-11: hideCursor:true makes setupTerminal emit the hide itself; the
     * visibility mirror must be seeded so the first cursor-less frame does not
     * re-emit a redundant CSI ?25 l (old code counted the hide twice).
     */
    public function testSetupTerminalSeedsCursorVisibilityFromTheObservedHide(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $options = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: true,
            input: $in,
            output: $out,
            loop: $loop,
        );

        $program = new Program(new SeededCursorModel(), $options);
        $loop->addTimer(0.05, static fn () => $program->quit());
        $loop->addTimer(5.0, static fn () => $loop->stop());
        $program->run();

        $bytes = $this->outputContents($out);
        $this->assertSame(
            1,
            substr_count($bytes, "\x1b[?25l"),
            'exactly one cursorHide: the observed setup emission, never a re-emission',
        );

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /** The mirror stays false when the program never hid the cursor. */
    public function testNoHideEmissionLeavesTheMirrorUntouched(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $options = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );

        $program = new Program(new SeededCursorModel(), $options);
        $loop->addTimer(0.05, static fn () => $program->quit());
        $loop->addTimer(5.0, static fn () => $loop->stop());
        $program->run();

        $bytes = $this->outputContents($out);
        $this->assertSame(0, substr_count($bytes, "\x1b[?25l"), 'a visible-cursor program emits no hide');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * RUN-13: when the predecessor is UNREADABLE (recorded null), cancelling
     * the subscription must leave the live handler alone — never restore a
     * fabricated SIG_DFL over a host handler we never installed.
     */
    public function testCancellingWithAnUnknownPredecessorRefusesToClobberTheLiveHandler(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl required');
        }
        $signo = defined('SIGUSR2') ? SIGUSR2 : 12;

        // A host handler that predates (and outlives) our subscription.
        $host = static function (int $s): void {};
        pcntl_signal($signo, $host);

        $program = new Program(new SeededCursorModel(), $this->bareOptions());
        $prev = new \ReflectionProperty(Program::class, 'prevSignalHandlers');
        // Simulate the unobservable-predecessor install (no get_handler build).
        $prev->setValue($program, [$signo => null]);

        $cancel = new \ReflectionMethod(Program::class, 'cancelSignalSubscription');
        $sub = new Subscription('sig', Kind::Signal, ['signo' => $signo], static fn (): Msg => new Msg\QuitMsg());
        $cancel->invoke($program, $sub);

        $current = pcntl_signal_get_handler($signo);
        // PHP >= 8 hands back the bare callable (int only for SIG_DFL/SIG_IGN).
        $this->assertSame($host, $current, 'cancel refused to fabricate SIG_DFL over the host handler');

        pcntl_signal($signo, SIG_DFL);
    }

    /**
     * RUN-13 twin on the Program-own chain: restoreSignalHandlers skips null
     * predecessors rather than overwriting the host handler with SIG_DFL.
     */
    public function testRestoringProgramSignalsSkipsUnknownPredecessors(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('pcntl required');
        }
        $signo = defined('SIGUSR2') ? SIGUSR2 : 12;

        $host = static function (int $s): void {};
        pcntl_signal($signo, $host);

        $program = new Program(new SeededCursorModel(), $this->bareOptions());
        $prev = new \ReflectionProperty(Program::class, 'prevProgramSignalHandlers');
        $prev->setValue($program, [$signo => null]);

        $restore = new \ReflectionMethod(Program::class, 'restoreSignalHandlers');
        $restore->invoke($program);

        $current = pcntl_signal_get_handler($signo);
        $this->assertSame($host, $current, 'an unknown predecessor must not be restored as SIG_DFL');

        pcntl_signal($signo, SIG_DFL);
    }

    /** A KNOWN predecessor still restores byte-true on cancel (regression arm). */
    public function testCancellingWithAKnownPredecessorStillRestoresIt(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('pcntl required');
        }
        $signo = defined('SIGUSR2') ? SIGUSR2 : 12;

        $host = static function (int $s): void {};
        pcntl_signal($signo, $host);

        $program = new Program(new SeededCursorModel(), $this->bareOptions());
        $install = new \ReflectionMethod(Program::class, 'installSignalSubscription');
        $sub = new Subscription('sig', Kind::Signal, ['signo' => $signo], static fn (): Msg => new Msg\QuitMsg());
        $install->invoke($program, $sub);

        // Our handler is now on top of the host one.
        $mine = pcntl_signal_get_handler($signo);
        $this->assertNotSame($host, $mine, 'our subscription handler is on top');

        $cancel = new \ReflectionMethod(Program::class, 'cancelSignalSubscription');
        $cancel->invoke($program, $sub);

        $restored = pcntl_signal_get_handler($signo);
        $this->assertSame($host, $restored, 'the recorded predecessor is handed back verbatim');

        pcntl_signal($signo, SIG_DFL);
    }

    private function bareOptions(): ProgramOptions
    {
        [$in, $out, $writer] = $this->pipes();
        // Keep the resources alive for the life of the test object graph.
        $this->handles = [$in, $out, $writer];
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: new StreamSelectLoop(),
        );
    }
}
