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
 * VCR-S27 (finding 054) — INSTANT pacing is per BATCH, not per event.
 *
 * The old shape armed a 5 ms yield timer for EVERY event, so replay time
 * scaled with the scheduler floor: a 2000-event tape could not finish in
 * under 10 s of virtual pacing while doing no content work. The batched
 * fast path dispatches up to Player::INSTANT_BATCH events inside one
 * callback and arms at most one yield per batch, keeping the render-tick
 * opportunity without paying event-count x timer-overhead.
 *
 * FakeClockLoop records every one-shot arm, so the tests below count
 * scheduler work directly — no wall-clock sleeping, no machine flake.
 */
final class PlayerPacingTest extends TestCase
{
    public function testInstantBatchesArmsOneYieldPerBatchNotPerEvent(): void
    {
        $events = [];
        for ($i = 0; $i < 2000; $i++) {
            $events[] = new Event(t: $i * 0.01, kind: EventKind::Output, payload: ['b' => 'x']);
        }
        $events[] = new Event(t: 20.0, kind: EventKind::Quit, payload: []);

        $loop = new FakeClockLoop();
        $result = (new Player(new Cassette(self::stubHeader(), $events)))
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_INSTANT,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        // 2001 events over 64-event batches = 32 batches; a yield is
        // armed only BETWEEN batches (31 arms), never before the first or
        // after the final one. Old behaviour would show 2000 yields.
        $armed = $loop->oneShotIntervals();
        $yields = array_values(array_filter($armed, static fn (float $s): bool => $s === Player::INSTANT_YIELD_SECONDS));
        $this->assertCount(31, $yields, 'one yield per batch boundary, none per event');

        // Virtual time consumed by pacing: 31 yields of 5 ms. The 200/s
        // floor for a 2001-event tape allows 10 s; batched pacing costs
        // 0.155 s of it.
        $this->assertLessThan(1.0, count($yields) * Player::INSTANT_YIELD_SECONDS);

        // And the replay is COMPLETE, not just cheap.
        $this->assertSame(2000, $result->outputCount);
        $this->assertSame(1, $result->quitCount);
        $this->assertTrue($result->programQuitCleanly);
    }

    public function testInlineFastPathDispatchesWithoutAnyTimerForSmallTapes(): void
    {
        $events = [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
            new Event(t: 5.0, kind: EventKind::Output, payload: ['b' => 'b']),
            new Event(t: 9.0, kind: EventKind::Quit, payload: []),
        ];

        $loop = new FakeClockLoop();
        $result = (new Player(new Cassette(self::stubHeader(), $events)))
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_INSTANT,
                timeoutSeconds: 2.0,
                loop: $loop,
            );

        // Initial step + cap only: every event rode the inline batch.
        $this->assertSame([0.0, 2.0], $loop->oneShotIntervals());
        $this->assertSame(2, $result->outputCount);
        $this->assertTrue($result->programQuitCleanly);
    }

    public function testRealtimeKeepsOneTimerPerEventDelta(): void
    {
        // Control arm: the batching must not leak into REALTIME pacing —
        // three gaps still arm three distinct recorded deltas.
        $events = [
            new Event(t: 1.0, kind: EventKind::Output, payload: ['b' => 'a']),
            new Event(t: 2.0, kind: EventKind::Output, payload: ['b' => 'b']),
            new Event(t: 4.0, kind: EventKind::Quit, payload: []),
        ];

        $loop = new FakeClockLoop();
        $result = (new Player(new Cassette(self::stubHeader(), $events)))
            ->play(
                self::neverSelfQuitFactory(),
                speed: Player::SPEED_REALTIME,
                timeoutSeconds: 30.0,
                loop: $loop,
            );

        $this->assertSame([1.0, 30.0, 1.0, 2.0], $loop->oneShotIntervals());
        $this->assertTrue($result->programQuitCleanly);
    }

    private static function stubHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-01-01T00:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'bash',
        );
    }

    /**
     * @return \Closure(resource, resource, \React\EventLoop\LoopInterface): Program
     */
    private static function neverSelfQuitFactory(): \Closure
    {
        return static fn ($input, $output, $loop): Program => new Program(
            new EchoModel(quitAfter: \PHP_INT_MAX),
            new ProgramOptions(
                input: $input,
                output: $output,
                loop: $loop,
            ),
        );
    }
}
