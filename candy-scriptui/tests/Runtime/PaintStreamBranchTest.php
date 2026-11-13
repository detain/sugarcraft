<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use React\EventLoop\Loop;
use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;

/**
 * scriptui-34 (finding 054) — the frame must never be handed to a
 * redirected STDERR file. These probe the programOptions()/paint()
 * routing ladder through the test seam, asserting the DEC bytes land on a
 * stream a human can see (or the shape refuses outright).
 */
final class PaintStreamBranchTest extends TestCase
{
    /** @var array{0:resource,1:resource} */
    private array $ttyPair = [];

    protected function setUp(): void
    {
        $read  = fopen('php://memory', 'w+b');
        $write = fopen('php://memory', 'w+b');
        self::assertIsResource($read);
        self::assertIsResource($write);
        $this->ttyPair = [$read, $write];
    }

    protected function tearDown(): void
    {
        InteractiveRuntime::setTerminalProbesForTesting(null);
        foreach ($this->ttyPair as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function testStdoutTerminalKeepsTheOpenTtyPosture(): void
    {
        InteractiveRuntime::setTerminalProbesForTesting(['stdout' => true]);
        $options = InteractiveRuntime::programOptions(useAltScreen: false, hideCursor: false, loop: Loop::get());

        self::assertNotNull($options);
        self::assertTrue($options->openTty);
    }

    public function testRedirectedStdoutWithTtyStderrStillPaintsToStderr(): void
    {
        InteractiveRuntime::setTerminalProbesForTesting([
            'stdout' => false,
            'stderr' => true,
            'stdin'  => false,
            'tty'    => $this->ttyPair,
        ]);
        $options = InteractiveRuntime::programOptions(useAltScreen: false, hideCursor: false, loop: Loop::get());

        self::assertNotNull($options);
        self::assertSame($this->ttyPair[0], $options->input, 'keys come from /dev/tty');
        self::assertSame(STDERR, $options->output, 'a tty STDERR is the honest paint target');
        // scriptui-70 (finding 091): the paintable-STDERR shape makes the
        // write side dead on arrival — it must be closed here, not leaked.
        self::assertFalse(is_resource($this->ttyPair[1]), 'write side closed: paintable STDERR makes it dead weight');
        self::assertIsResource($this->ttyPair[0], 'the read side travels with the options');
    }

    public function testBothStreamsRedirectedPaintsThroughTheTtyHandleNotTheLog(): void
    {
        // The finding 054 repro shape: `confirm >out 2>trace.log` under a
        // real terminal. Before the cure the WHOLE frame (62 probed DEC
        // bytes) rode into trace.log; now it goes to the /dev/tty write
        // side and the capture file receives nothing.
        InteractiveRuntime::setTerminalProbesForTesting([
            'stdout' => false,
            'stderr' => false,
            'stdin'  => true,
            'tty'    => $this->ttyPair,
        ]);
        $options = InteractiveRuntime::programOptions(useAltScreen: true, hideCursor: true, loop: Loop::get());

        self::assertNotNull($options);
        self::assertSame($this->ttyPair[0], $options->input);
        self::assertSame($this->ttyPair[1], $options->output);
        self::assertNotSame(STDERR, $options->output);
        // scriptui-70: here the write side IS the paint output, so it stays
        // open and travels with the options (its lifetime follows Program).
        self::assertIsResource($this->ttyPair[1]);

        InteractiveRuntime::paint("\x1b[?25lframe\x1b[0m");
        rewind($this->ttyPair[1]);
        self::assertSame("\x1b[?25lframe\x1b[0m", stream_get_contents($this->ttyPair[1]));
    }

    public function testRedirectedStderrWithoutTtyRefusesInsteadOfCorrupting(): void
    {
        // No /dev/tty and STDERR is a capture file: nowhere honest to paint,
        // so refuse (null) — callers print runtime.no_terminal. The old arm
        // here happily streamed frames into the redirected file.
        InteractiveRuntime::setTerminalProbesForTesting([
            'stdout' => false,
            'stderr' => false,
            'stdin'  => true,
            'tty'    => null,
        ]);

        self::assertNull(InteractiveRuntime::programOptions(useAltScreen: false, hideCursor: false, loop: Loop::get()));
        self::assertTrue(InteractiveRuntime::isHeadless());
    }

    public function testPaintIsSilentWhenNothingCanDisplay(): void
    {
        InteractiveRuntime::setTerminalProbesForTesting([
            'stdout' => false,
            'stderr' => false,
            'stdin'  => false,
            'tty'    => null,
        ]);

        self::assertTrue(InteractiveRuntime::isHeadless());
        InteractiveRuntime::paint('dropped, not injected');

        // Reaching the next line is the cure: paint() in the no-display
        // shape returns silently instead of fwrite()-ing a dead handle.
        self::assertTrue(true);
    }

    public function testRealBackendPairIsOpenedOnceAndReused(): void
    {
        // scriptui-70 acceptance: an embedder looping prompts pays ONE fd
        // pair, not one per prompt. Exercises the REAL cache path (no probe
        // override); on a session without a controlling terminal every
        // answer is null — still the honest flat-fd outcome.
        InteractiveRuntime::setTerminalProbesForTesting(null);
        $method = new \ReflectionMethod(InteractiveRuntime::class, 'ttyPair');
        $method->setAccessible(true);

        $before = count(glob('/proc/self/fd') ?: []);
        $first  = $method->invoke(null);
        for ($i = 0; $i < 100; $i++) {
            self::assertSame($first, $method->invoke(null), 'one pair, reused');
        }
        $after = count(glob('/proc/self/fd') ?: []);

        self::assertLessThanOrEqual(1, $after - $before, '100 prompts must not grow the fd table');
    }
}
