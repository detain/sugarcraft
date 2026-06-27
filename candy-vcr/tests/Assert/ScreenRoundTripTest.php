<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Assert;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Vcr\Assert\ScreenAssertion;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Recorder;

/**
 * Cell-grid round-trip: record a Program session, then replay into a
 * fresh Program and assert the final screen state matches.
 *
 * This is the assertion mode that PR4's `ByteAssertion` couldn't
 * achieve (recording's renderer fires once at model convergence;
 * replay's renderer fires multiple times over the chained-event
 * timeline, producing different bytes but the same final cell grid).
 * `ScreenAssertion` collapses both to grapheme equality and passes.
 */
final class ScreenRoundTripTest extends TestCase
{
    public function testRecordingReplaysWithMatchingScreen(): void
    {
        $path = $this->recordSession();

        $player = Player::open($path);
        $result = $player->play(
            programFactory: $this->programFactory(),
            assertion: new ScreenAssertion(cols: 80, rows: 24),
            speed: Player::SPEED_INSTANT,
        );

        @unlink($path);

        $this->assertTrue($result->ok, "screen mismatch:\n" . $result->diffSummary());
        $this->assertGreaterThan(0, $result->resizeCount);
        $this->assertGreaterThan(0, $result->outputCount);
        $this->assertSame(1, $result->quitCount);
        $this->assertTrue($result->programQuitCleanly);
    }

    public function testDivergentModelProducesScreenDiff(): void
    {
        $path = $this->recordSession();

        $player = Player::open($path);
        $result = $player->play(
            programFactory: $this->programFactory(divergent: true),
            assertion: new ScreenAssertion(cols: 80, rows: 24),
            speed: Player::SPEED_INSTANT,
        );

        @unlink($path);

        $this->assertFalse($result->ok, 'divergent Model should produce screen diff');
        $this->assertStringContainsString('cell-grid mismatch', $result->diff);
        // The divergent Model writes "DIVERGED: <count>" instead of
        // "tick: <count>"; cells (0,0) "t" → "D" and beyond should differ.
        $this->assertStringContainsString("'t'", $result->diff);
        $this->assertStringContainsString("'D'", $result->diff);
    }

    /**
     * Record a session driven by piped key-byte input so the cassette
     * captures a real Msg flow (not just startup/teardown bytes).
     */
    private function recordSession(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-screen-rt-');
        $this->assertNotFalse($path);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);

        $loop = new StreamSelectLoop();
        // The session makes EXACTLY 6 update() calls — the three startup Msgs
        // (WindowSizeMsg, EnvMsg, ColorProfileMsg) plus the three KeyMsgs from
        // the piped 'abc' input — so the model converges on `tick: 6`.
        //
        // The model NEVER self-quits (quitAfter: PHP_INT_MAX); instead the
        // recording ends deterministically on an EVENT — the appearance of
        // the final rendered frame in the output stream — not on a wall
        // clock. The old `addTimer(0.020, quit)` raced the input read +
        // render: under load (CI) the 20 ms quit could fire before the
        // `tick: 6` frame was rendered, so the cassette recorded the `quit`
        // event AHEAD of the final `output` frame. Replay stops accumulating
        // expected output at the quit event, so it then compared a blank
        // recorded screen against the replay's `tick: 6` — a flaky mismatch.
        //
        // Polling the output for the converged frame guarantees the
        // `output: "tick: 6"` frame is recorded BEFORE the `quit` event,
        // regardless of host/CI speed.
        $finalFrame = (new TickModel(quitAfter: \PHP_INT_MAX))->withCount(6)->view();
        $program = new Program(
            new TickModel(quitAfter: \PHP_INT_MAX),
            new ProgramOptions(
                useAltScreen: false,
                catchInterrupts: false,
                hideCursor: false,
                framerate: 1000.0,
                input: $reader,
                output: $output,
                loop: $loop,
            ),
        );
        $program->withRecorder(Recorder::open($path));
        $loop->futureTick(static function () use ($writer): void {
            fwrite($writer, 'abc');
        });
        // Re-arming probe: quit only once the converged frame has actually
        // been rendered (and therefore recorded). Reads the in-memory output
        // stream without disturbing its write pointer.
        $probe = null;
        $probe = static function () use (&$probe, $program, $loop, $output, $finalFrame): void {
            $pos = ftell($output);
            rewind($output);
            $written = stream_get_contents($output);
            if ($pos !== false) {
                fseek($output, $pos);
            }
            if (is_string($written) && str_contains($written, $finalFrame)) {
                $program->quit();
                return;
            }
            $loop->futureTick($probe);
        };
        $loop->futureTick($probe);
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        fclose($writer);
        fclose($reader);
        fclose($output);
        return $path;
    }

    private function programFactory(bool $divergent = false): \Closure
    {
        return static function ($input, $output, LoopInterface $loop) use ($divergent): Program {
            return new Program(
                new TickModel(quitAfter: PHP_INT_MAX, divergent: $divergent),
                new ProgramOptions(
                    useAltScreen: false,
                    catchInterrupts: false,
                    hideCursor: false,
                    framerate: 1000.0,
                    input: $input,
                    output: $output,
                    loop: $loop,
                ),
            );
        };
    }
}

/**
 * Local copy of the test Model used in PlayerTest so this test file
 * is self-contained.
 */
final class TickModel implements Model
{
    public int $count = 0;

    public function __construct(
        public readonly int $quitAfter = 4,
        public readonly bool $divergent = false,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /** Test helper: a copy at a given count (used to derive the final view). */
    public function withCount(int $count): self
    {
        $clone = clone $this;
        $clone->count = $count;
        return $clone;
    }

    public function update(Msg $msg): array
    {
        $next = clone $this;
        $next->count = $this->count + 1;
        $cmd = $next->count >= $this->quitAfter ? Cmd::quit() : null;
        return [$next, $cmd];
    }

    public function view(): string
    {
        return ($this->divergent ? 'DIVERGED: ' : 'tick: ') . $this->count;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
