<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyboardEnhancementsMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Ansi;

/**
 * M-14 (cc-review-1111): the kitty keyboard push escaped unpaired on a
 * throwing exit. An app arms it through the RawMsg side-channel
 * (Cmd::pushKittyKeyboard() rides the init batch), and the consumer's
 * pairing pop line runs only AFTER run() returns normally; the shutdown
 * backstop pops only while the attach latch is up — but run()'s finally
 * always tears down first, lowering that latch. A Throwable escaping
 * run() therefore reached neither pop, leaving the user's shell in CSI-u
 * mode until a manual reset.
 *
 * The fix pairs the push inside the library: run()'s finally distinguishes
 * a normal return (the consumer still owns the pop — the candy-code bin's
 * line keeps the normal path byte-identical) from an unwind, and the
 * unwind tears down with the pop riding BEFORE the alt-screen leave (the
 * byte-safe ordering documented on releaseTerminalForShutdown), gated by a
 * flag-stack mirror armed at the RawMsg choke so a program that never
 * pushed never pops.
 *
 * These pins are behavioral (captured output streams), not source-shape;
 * the companion source pins live in ProgramTerminalRecoveryTest.
 */
final class ProgramKittyTeardownTest extends TestCase
{
    /** The exact bytes an app's init-batch push puts on the wire. */
    private static function push(): string
    {
        return Ansi::pushKittyKeyboard(KeyboardEnhancementsMsg::DISAMBIGUATE);
    }

    /** The pairing pop whose count every pin below asserts. */
    private static function pop(): string
    {
        return Ansi::popKittyKeyboard();
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

    /** Fully-armed terminal options: the alt-screen leave anchors the ordering pin. */
    private function options($in, $out, StreamSelectLoop $loop): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: true,
            catchInterrupts: false,
            hideCursor: true,
            mouseMode: \SugarCraft\Core\MouseMode::CellMotion,
            reportFocus: true,
            bracketedPaste: true,
            input: $in,
            output: $out,
            loop: $loop,
        );
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

    /**
     * Model whose init batch pushes the kitty layer and then arms a timer
     * that throws from inside loop->run() — the exact unguarded-unwind shape
     * M-14 flagged (identical funnel to ProgramTerminalRecoveryTest's
     * loop-callback thrower). With $push false the program never touches
     * the keyboard modes at all. With $explicitPop the batch pops the layer
     * back off before the throw — the disarm half of the mirror.
     */
    private static function throwingModel(bool $push = true, bool $explicitPop = false): Model
    {
        $steps = [Cmd::tick(0.01, static function (): never {
            throw new \LogicException('model exploded mid-run');
        })];
        if ($explicitPop) {
            array_unshift($steps, Cmd::popKittyKeyboard());
        }
        if ($push) {
            array_unshift($steps, Cmd::pushKittyKeyboard(KeyboardEnhancementsMsg::DISAMBIGUATE));
        }

        return new class($steps) implements Model {
            use SubscriptionCapable;

            /** @param array<int, \Closure> $steps */
            public function __construct(private readonly array $steps)
            {
            }

            public function init(): ?\Closure
            {
                return Cmd::batch(...$this->steps);
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
     * Model that pushes the kitty layer and quits in the same init batch —
     * the candy-code App shape (push rides init, exit is cooperative).
     */
    private static function pushingQuittingModel(bool $withExplicitPop = false): Model
    {
        $steps = [Cmd::pushKittyKeyboard(KeyboardEnhancementsMsg::DISAMBIGUATE)];
        if ($withExplicitPop) {
            $steps[] = Cmd::popKittyKeyboard();
        }
        $steps[] = Cmd::quit();

        return new class($steps) implements Model {
            use SubscriptionCapable;

            /** @param array<int, \Closure> $steps */
            public function __construct(private readonly array $steps)
            {
            }

            public function init(): ?\Closure
            {
                return Cmd::batch(...$this->steps);
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
     * THE M-14 keystone (red on the unpatched library): a Throwable
     * escaping run() on a program that pushed must still be answered with
     * exactly one pop, and it must ride before the alt-screen leave so the
     * pairing reaches the screen the user returns to.
     */
    public function testThrowingExitPopsTheKittyLayerOnceBeforeTheAltScreenLeave(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program(self::throwingModel(), $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $thrown = null;
        try {
            $program->run();
        } catch (\LogicException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the Throwable must still propagate out of run()');
        $this->assertSame('model exploded mid-run', $thrown->getMessage());

        $written = $this->drain($out);
        $this->assertSame(1, substr_count($written, self::push()), 'the init push reached the wire');
        $this->assertSame(1, substr_count($written, self::pop()),
            'the throwing exit must pop the layer exactly once (M-14)');
        $leave = strpos($written, Ansi::altScreenLeave());
        $this->assertIsInt($leave);
        $this->assertLessThan($leave, (int) strpos($written, self::pop()),
            'the pop rides before the alt-screen leave, per the byte-safe ordering law');
        $this->assertSame(1, substr_count($written, Ansi::altScreenLeave()));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * Normal return keeps the consumer contract byte-identical: run()'s
     * core stream owes NO pop — the consumer's post-run line (bin/candy-code
     * on the candy-code path) supplies the one and only pairing pop. The
     * second half of this pin emulates that consumer line and asserts the
     * SYSTEM-level count of exactly one on the normal exit path.
     */
    public function testNormalExitLeavesThePairingPopToTheConsumer(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program(self::pushingQuittingModel(), $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $program->run();

        $coreStream = $this->drain($out);
        $this->assertSame(1, substr_count($coreStream, self::push()));
        $this->assertSame(0, substr_count($coreStream, self::pop()),
            'a normal return must add no pop — the consumer owns it (no double-pop on the candy-code path)');

        // Emulate the consumer's post-run pop line (what bin/candy-code does).
        fwrite($out, self::pop());
        $systemStream = $this->drain($out);
        $this->assertSame(1, substr_count($systemStream, self::pop()),
            'end to end the normal exit path contains the pop exactly once');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * The mirror is a real gate, not decoration: a program that never
     * pushed must stay byte-identical on the throwing path — no stray pop
     * onto a terminal that never took a push.
     */
    public function testThrowingExitWithoutAPushStaysPopSilent(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program(self::throwingModel(push: false), $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        try {
            $program->run();
            $this->fail('precondition: the model must throw');
        } catch (\LogicException) {
            // expected
        }

        $written = $this->drain($out);
        $this->assertSame(0, substr_count($written, self::push()));
        $this->assertSame(0, substr_count($written, self::pop()),
            'no push means no pop owed — teardown stays silent');
        $this->assertSame(1, substr_count($written, Ansi::altScreenLeave()));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * After the unwind pop, the shutdown backstop must contribute nothing:
     * the finally lowered the latch, so the hook early-returns — and the
     * consumed mirror keeps even a re-attached teardown honest.
     */
    public function testBackstopAddsNothingAfterTheUnwindPop(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program(self::throwingModel(), $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        try {
            $program->run();
            $this->fail('precondition: the model must throw');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame(1, substr_count($this->drain($out), self::pop()));

        $mark = ftell($out);
        $this->assertIsInt($mark);
        $release = new \ReflectionMethod(Program::class, 'releaseTerminalForShutdown');
        $release->invoke($program, getmypid());
        $this->assertSame($mark, ftell($out),
            'the post-unwind hook must add zero bytes — the pop was already paid');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * The choke mirror round-trips: an app that pops the layer itself mid
     * run disarms the mirror, so the normal-exit teardown (and any later
     * unwind) owes nothing — the explicit pop is the one and only.
     *
     * The second half pins the DISARM itself on the path that consumes it:
     * push, explicit pop, then a throwing exit. There the finally runs
     * teardownTerminal(popKittyKeyboard: true), so only a mirror that the
     * pop actually cleared keeps that teardown silent — an arm-only regex
     * (sets true on '>' but never false on '<') leaves the mirror armed
     * and emits an unbalanced second pop.
     */
    public function testExplicitMidRunPopDisarmsTheMirror(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program(self::pushingQuittingModel(withExplicitPop: true), $this->options($in, $out, $loop));
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $program->run();

        $written = $this->drain($out);
        $this->assertSame(1, substr_count($written, self::push()));
        $this->assertSame(1, substr_count($written, self::pop()),
            'the app-owned pop lands once; core adds nothing on the normal return');

        fclose($writer);
        fclose($in);
        fclose($out);

        // The disarm half: same explicit pop, but the exit THROWS, so the
        // finally asks the teardown for a pop and only the mirror can refuse
        // it. Red exactly under the arm-only mutation (pop count 2 there).
        [$in2, $out2, $writer2] = $this->pipes();
        $loop2 = new StreamSelectLoop();
        $program2 = new Program(self::throwingModel(explicitPop: true), $this->options($in2, $out2, $loop2));
        $loop2->addTimer(2.0, static fn () => $loop2->stop());

        try {
            $program2->run();
            $this->fail('precondition: the model must throw');
        } catch (\LogicException $e) {
            $this->assertSame('model exploded mid-run', $e->getMessage());
        }

        $thrown = $this->drain($out2);
        $this->assertSame(1, substr_count($thrown, self::push()));
        $this->assertSame(1, substr_count($thrown, self::pop()),
            'the explicit mid-run pop disarms the mirror: the unwind teardown adds no second pop');
        $this->assertSame(1, substr_count($thrown, Ansi::altScreenLeave()));

        fclose($writer2);
        fclose($in2);
        fclose($out2);
    }
}
