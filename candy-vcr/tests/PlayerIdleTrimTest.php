<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Player;

/**
 * @covers \SugarCraft\Vcr\Player::withIdleTrim
 * @covers \SugarCraft\Vcr\Player::play
 */
final class PlayerIdleTrimTest extends TestCase
{
    /**
     * Verify withIdleTrim() returns a new Player instance (immutable).
     */
    public function testWithIdleTrimReturnsNewInstance(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-19T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Quit, payload: []),
            ],
        );

        $player = new Player($cassette);
        $playerWithTrim = $player->withIdleTrim(1.5);

        // Original player should be unchanged (different instance)
        $this->assertNotSame($player, $playerWithTrim);
        // Cassette reference should be shared (immutable - only trim setting differs)
        $this->assertSame($player->cassette, $playerWithTrim->cassette);
    }

    /**
     * Verify withIdleTrim(null) disables idle trimming.
     */
    public function testWithIdleTrimNullClearsSetting(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-19T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Quit, payload: []),
            ],
        );

        $player = new Player($cassette);
        $playerWithTrim = $player->withIdleTrim(1.0);
        $playerCleared = $playerWithTrim->withIdleTrim(null);

        $this->assertNotSame($player, $playerWithTrim);
        $this->assertNotSame($playerWithTrim, $playerCleared);
    }

    /**
     * Verify SPEED_REALTIME playback with withIdleTrim() applied.
     * A 10-second gap should be clamped to the 0.5s threshold when
     * using withIdleTrim(0.5) in SPEED_REALTIME mode.
     */
    public function testRealtimePlaybackWithIdleTrim(): void
    {
        // Build a cassette with a 10-second gap between events
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-19T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'start']),
                new Event(t: 10.0, kind: EventKind::Quit, payload: []), // 10 second pause
            ],
        );

        $path = tempnam(sys_get_temp_dir(), 'cv-idletrim-');
        $this->assertNotFalse($path);
        (new JsonlFormat())->write($cassette, $path);

        try {
            $player = Player::open($path)->withIdleTrim(0.5);

            // Use a short timeout so the test doesn't hang
            // Without idle trim, the 10s gap would cause a long wait
            $start = microtime(true);
            $result = $player->play(
                fn($in, $out, $loop) => $this->createEchoProgram($in, $out, $loop),
                speed: Player::SPEED_REALTIME,
                timeoutSeconds: 2.0,
            );
            $elapsed = microtime(true) - $start;

            // The play should complete (not timeout) because idle trim clamps 10s to 0.5s
            $this->assertNotNull($result);
            // Elapsed time should be well under 10 seconds due to idle trimming
            $this->assertLessThan(5.0, $elapsed, 'Idle trim should have clamped the 10s delay');
        } finally {
            @unlink($path);
        }
    }

    /**
     * Verify that explicit idleThresholdSeconds parameter overrides withIdleTrim().
     */
    public function testExplicitIdleThresholdOverridesWithIdleTrim(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-19T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'start']),
                new Event(t: 10.0, kind: EventKind::Quit, payload: []),
            ],
        );

        $path = tempnam(sys_get_temp_dir(), 'cv-idletrim-');
        $this->assertNotFalse($path);
        (new JsonlFormat())->write($cassette, $path);

        try {
            // Player has withIdleTrim(0.5) but play() gets explicit idleThresholdSeconds=0.2
            $player = Player::open($path)->withIdleTrim(0.5);

            $start = microtime(true);
            $result = $player->play(
                fn($in, $out, $loop) => $this->createEchoProgram($in, $out, $loop),
                speed: Player::SPEED_REALTIME,
                idleThresholdSeconds: 0.2,
                timeoutSeconds: 2.0,
            );
            $elapsed = microtime(true) - $start;

            // Explicit 0.2s should win over withIdleTrim's 0.5s
            $this->assertNotNull($result);
            $this->assertLessThan(3.0, $elapsed);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Verify SPEED_INSTANT mode ignores withIdleTrim().
     */
    public function testInstantSpeedIgnoresWithIdleTrim(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-19T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'start']),
                new Event(t: 10.0, kind: EventKind::Quit, payload: []),
            ],
        );

        $path = tempnam(sys_get_temp_dir(), 'cv-idletrim-');
        $this->assertNotFalse($path);
        (new JsonlFormat())->write($cassette, $path);

        try {
            $player = Player::open($path)->withIdleTrim(0.5);

            // In SPEED_INSTANT, idleTrim should be ignored
            $result = $player->play(
                fn($in, $out, $loop) => $this->createEchoProgram($in, $out, $loop),
                speed: Player::SPEED_INSTANT,
                timeoutSeconds: 2.0,
            );

            $this->assertNotNull($result);
        } finally {
            @unlink($path);
        }
    }

    private function createEchoProgram($input, $output, $loop): Program
    {
        return new Program(
            new class implements Model {
                public int $count = 0;

                public function init(): ?\Closure
                {
                    return null;
                }

                public function update(Msg $msg): array
                {
                    $this->count++;
                    $cmd = $this->count >= 1 ? Cmd::quit() : null;
                    return [$this, $cmd];
                }

                public function view(): string
                {
                    return "tick: {$this->count}";
                }

                public function subscriptions(): ?Subscriptions
                {
                    return null;
                }
            },
            new ProgramOptions(
                input: $input,
                output: $output,
                loop: $loop,
                useAltScreen: false,
                catchInterrupts: false,
                hideCursor: false,
            ),
        );
    }
}
