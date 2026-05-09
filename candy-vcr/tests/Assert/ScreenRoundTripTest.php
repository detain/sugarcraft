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
        $program = new Program(
            new TickModel(quitAfter: PHP_INT_MAX),
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
        $loop->addTimer(0.020, static fn() => $program->quit());
        $loop->addTimer(2.0, static fn() => $loop->stop());
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
    ) {}

    public function init(): ?\Closure
    {
        return null;
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
}
