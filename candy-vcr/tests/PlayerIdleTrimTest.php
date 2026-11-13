<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Tests\Support\EchoModel;
use SugarCraft\Vcr\Tests\Support\FakeClockLoop;

/**
 * Idle-trim clamping, observed at the scheduler (finding 014).
 *
 * Player arms one loop timer per cassette gap; the clamp is a property
 * of the SCHEDULED interval. With {@see FakeClockLoop} injected, each
 * test asserts the exact interval Player armed — a clamped 10s gap must
 * show 0.5, an unclamped one must show 10.0 — instead of inferring it
 * from wall-clock elapsed time. No sleeping, no machine-speed flake, and
 * the clamp mutation that changes any armed interval turns the right
 * tests red.
 *
 * Also absorbs PlayerIdleTrimmingTest (same feature, assertion-free
 * parameter-acceptance smokes superseded by the explicit-threshold and
 * null-threshold cases below).
 *
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
        $player = new Player(self::quitOnlyCassette());
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
        $player = new Player(self::quitOnlyCassette());
        $playerWithTrim = $player->withIdleTrim(1.0);
        $playerCleared = $playerWithTrim->withIdleTrim(null);

        $this->assertNotSame($player, $playerWithTrim);
        $this->assertNotSame($playerWithTrim, $playerCleared);
    }

    /**
     * A 10-second gap under withIdleTrim(0.5) must arm EXACTLY a 0.5s
     * timer for the second step — and the replay then reaches the
     * cassette Quit before the 2s safety cap fires.
     */
    public function testClampedGapArmsTheThresholdInterval(): void
    {
        $loop = new FakeClockLoop();

        $result = (new Player(self::idleGapCassette()))
            ->withIdleTrim(0.5)
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_REALTIME,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        // Arming order: first event at its recorded t (0.0), the safety
        // cap, then the clamped 10s -> 0.5s gap.
        $this->assertSame([0.0, 2.0, 0.5], $loop->oneShotIntervals());
        $this->assertTrue($result->programQuitCleanly, 'clamped replay must reach the cassette quit');
        $this->assertSame(1, $result->quitCount);
        $this->assertSame(1, $result->outputCount);
    }

    /**
     * Without a threshold the raw 10s interval is armed as-is; the 2s
     * cap timer fires first, so the quit event is never reached. This
     * is the control that keeps the clamped test above honest.
     */
    public function testUnclampedGapArmsTheRawIntervalAndHitsTheCap(): void
    {
        $loop = new FakeClockLoop();

        $result = (new Player(self::idleGapCassette()))
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_REALTIME,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        $this->assertSame([0.0, 2.0, 10.0], $loop->oneShotIntervals());
        $this->assertFalse($result->programQuitCleanly, 'unclamped 10s gap must be cut off by the cap');
        $this->assertSame(0, $result->quitCount);
        $this->assertSame(1, $result->outputCount, 'the first step still dispatched before the cap');
    }

    /**
     * The explicit idleThresholdSeconds play() parameter overrides the
     * fluent setting — observable as the interval actually armed.
     */
    public function testExplicitIdleThresholdOverridesWithIdleTrim(): void
    {
        $loop = new FakeClockLoop();

        $result = (new Player(self::idleGapCassette()))
            ->withIdleTrim(0.5)
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_REALTIME,
                idleThresholdSeconds: 0.2,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        $this->assertSame([0.0, 2.0, 0.2], $loop->oneShotIntervals());
        $this->assertTrue($result->programQuitCleanly);
    }

    /**
     * Parameter-only trimming (no fluent call) must clamp too — the
     * superseded PlayerIdleTrimmingTest "parameter is accepted" smoke
     * had no assertion; this is the same input shape with an oracle.
     */
    public function testIdleThresholdParameterClampsWithoutFluentSetting(): void
    {
        $loop = new FakeClockLoop();

        $result = (new Player(self::idleGapCassette()))
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_REALTIME,
                idleThresholdSeconds: 0.5,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        $this->assertSame([0.0, 2.0, 0.5], $loop->oneShotIntervals());
        $this->assertTrue($result->programQuitCleanly);
    }

    /**
     * INSTANT mode ignores the trim entirely: the interval armed
     * between events is the fixed yield, never the threshold and never
     * the recorded delta.
     */
    public function testInstantSpeedIgnoresWithIdleTrim(): void
    {
        $loop = new FakeClockLoop();

        $result = (new Player(self::idleGapCassette()))
            ->withIdleTrim(0.5)
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_INSTANT,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        $this->assertSame([0.0, 2.0, Player::INSTANT_YIELD_SECONDS], $loop->oneShotIntervals());
        $this->assertTrue($result->programQuitCleanly);
        $this->assertSame(1, $result->quitCount);
    }

    /**
     * Every gap is clamped, not just the first: two 6s+14s gaps under a
     * 0.5 threshold arm two 0.5 timers.
     */
    public function testEveryLongGapIsClamped(): void
    {
        $cassette = new Cassette(
            self::stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'start']),
                new Event(t: 6.0, kind: EventKind::Output, payload: ['b' => 'second']),
                new Event(t: 20.0, kind: EventKind::Quit, payload: []),
            ],
        );
        $loop = new FakeClockLoop();

        $result = (new Player($cassette))
            ->withIdleTrim(0.5)
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_REALTIME,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        $this->assertSame([0.0, 2.0, 0.5, 0.5], $loop->oneShotIntervals());
        $this->assertTrue($result->programQuitCleanly);
        $this->assertSame(2, $result->outputCount);
        $this->assertSame(1, $result->quitCount);
    }

    /**
     * Gaps shorter than the threshold pass through unclamped — the trim
     * is a ceiling, not a floor.
     */
    public function testShortGapPassesThroughUnclamped(): void
    {
        $cassette = new Cassette(
            self::stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'start']),
                new Event(t: 0.3, kind: EventKind::Quit, payload: []),
            ],
        );
        $loop = new FakeClockLoop();

        $result = (new Player($cassette))
            ->withIdleTrim(0.5)
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_REALTIME,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        $this->assertSame([0.0, 2.0, 0.3], $loop->oneShotIntervals());
        $this->assertTrue($result->programQuitCleanly);
    }

    /**
     * A replayed cassette recorded WITH idle-trim carries `tRaw` in the
     * event payload; with useRawTimestamps the scheduler must honour the
     * raw (untrimmed) gap instead of the compressed `t` — the 0.5
     * threshold then clamps the 10s raw gap to 0.5, while the compressed
     * 0.01 gap would arm 0.01 untouched.
     */
    public function testUseRawTimestampsSeesTheRawGap(): void
    {
        $cassette = new Cassette(
            self::stubHeader(),
            [
                new Event(t: 0.05, kind: EventKind::Output, payload: ['b' => 'start', 'tRaw' => 0.0]),
                new Event(t: 0.06, kind: EventKind::Quit, payload: ['tRaw' => 10.0]),
            ],
        );
        $loop = new FakeClockLoop();

        (new Player($cassette))
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_REALTIME,
                idleThresholdSeconds: 0.5,
                timeoutSeconds: 2.0,
                useRawTimestamps: true,
                loop: $loop,
            );

        // firstDelay follows the raw t of event 0 (0.0), and the raw gap
        // of 10.0 is clamped to 0.5.
        $this->assertSame([0.0, 2.0, 0.5], $loop->oneShotIntervals());
    }

    private static function stubHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-19T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
        );
    }

    private static function quitOnlyCassette(): Cassette
    {
        return new Cassette(self::stubHeader(), [
            new Event(t: 0.0, kind: EventKind::Quit, payload: []),
        ]);
    }

    /**
     * One output at t=0, then a 10-second pause before the quit — the
     * canonical trim-able cassette.
     */
    private static function idleGapCassette(): Cassette
    {
        return new Cassette(self::stubHeader(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'start']),
            new Event(t: 10.0, kind: EventKind::Quit, payload: []),
        ]);
    }

    /**
     * Program factory whose model never self-quits: the replay can only
     * end on the cassette's Quit event (clamp works) or on Player's
     * safety cap (clamp broken). The old anonymous twin quit on the
     * first message, which would have ended every REALTIME replay at
     * the t=0 step regardless of trimming.
     */
    private static function neverSelfQuitFactory(): \Closure
    {
        return static fn ($input, $output, $loop): Program => self::echoProgram($input, $output, $loop, \PHP_INT_MAX);
    }

    /**
     * @param resource $input
     * @param resource $output
     */
    private static function echoProgram($input, $output, \React\EventLoop\LoopInterface $loop, int $quitAfter): Program
    {
        return new Program(
            new EchoModel(quitAfter: $quitAfter),
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
