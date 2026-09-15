<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Input\Event;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\InputDriver;
use SugarCraft\Input\KeyModifier;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

/**
 * E713 (round 82): the idle-poll backoff ladder, the stop() cancel ceiling,
 * and the snap-back rule — all pinned through the injected sleeper seam, so
 * every assertion is timing-free. The scripted driver carries a read budget
 * and throws past it: a neutered cancel path fails LOUD (budget exhausted)
 * instead of hanging the suite.
 */
final class ReadlinePollLoopTest extends TestCase
{
    // =========================================================================
    // Pure ladder — idlePollDelayMicroseconds
    // =========================================================================

    public static function ladderProvider(): array
    {
        return [
            'first empty read is the 1ms floor' => [1, 1_000],
            'second doubles'                    => [2, 2_000],
            'third doubles'                     => [3, 4_000],
            'fourth doubles'                    => [4, 8_000],
            'fifth doubles'                     => [5, 16_000],
            'sixth would be 32ms — capped'      => [6, 20_000],
            'stays capped'                      => [7, 20_000],
            'beyond the shift clamp'            => [17, 20_000],
            'astronomically beyond'             => [64, 20_000],
            'PHP_INT_MAX must not overflow'     => [PHP_INT_MAX, 20_000],
        ];
    }

    #[DataProvider('ladderProvider')]
    public function testTheLadderRunsOneMsFloorDoublingToTheTwentyMsCap(int $emptyReads, int $expected): void
    {
        $this->assertSame($expected, Readline::idlePollDelayMicroseconds($emptyReads));
    }

    public static function nonsenseFloorProvider(): array
    {
        return [[1], [0], [-1], [-100]];
    }

    #[DataProvider('nonsenseFloorProvider')]
    public function testCountsBelowOneCoerceToTheFloor(int $emptyReads): void
    {
        $this->assertSame(1_000, Readline::idlePollDelayMicroseconds($emptyReads));
    }

    public function testTheLadderIsMonotonicAndBoundedForever(): void
    {
        $previous = 0;
        for ($reads = 1; $reads <= 100; $reads++) {
            $delay = Readline::idlePollDelayMicroseconds($reads);
            $this->assertGreaterThanOrEqual($previous, $delay, 'ladder must never shorten with more idle passes');
            $this->assertLessThanOrEqual(Readline::IDLE_POLL_MAX_MICROSECONDS, $delay);
            $previous = $delay;
        }
    }

    // =========================================================================
    // Loop integration — recorded sleep schedule (timing-free)
    // =========================================================================

    public function testIdleSleepsFollowTheLadderAndSnapBackOnKeyActivity(): void
    {
        // null, null, null, 'a', null, null, null — six recorded sleeps, the
        // fourth must be 1000 again (snap-back), not 8000 (blind doubling).
        $driver = $this->scriptedDriver([null, null, null, $this->charEvent('a'), null, null, null]);
        $sleeps = [];
        $readline = $this->recordingReadline($driver, 6, $sleeps);

        $final = $readline->run(TextPrompt::new('> '));

        $this->assertSame([1_000, 2_000, 4_000, 1_000, 2_000, 4_000], $sleeps);
        $this->assertSame('a', $final->value(), 'the dispatched key must still reach the prompt');
        $this->assertTrue($readline->isStopped());
    }

    public function testNonKeyActivityAlsoSnapsTheLadderBackToTheFloor(): void
    {
        $mouse = new MouseEvent(10, 4, MouseEvent::BUTTON_LEFT, MouseEvent::ACTION_PRESS, KeyModifier::none());
        $driver = $this->scriptedDriver([null, null, $mouse, null, null, null]);
        $sleeps = [];
        $readline = $this->recordingReadline($driver, 5, $sleeps);

        $readline->run(TextPrompt::new('> '));

        $this->assertSame([1_000, 2_000, 1_000, 2_000, 4_000], $sleeps, 'mouse events count as activity');
    }

    public function testFlowingEventsCostZeroIdleSleeps(): void
    {
        $driver = $this->scriptedDriver([$this->charEvent('a'), $this->enterEvent()]);
        $sleeps = [];
        $readline = $this->recordingReadline($driver, 1, $sleeps);

        $final = $readline->run(TextPrompt::new('> '));

        $this->assertSame([], $sleeps, 'a prompt that keeps receiving keys must never sleep');
        $this->assertTrue($final->isSubmitted());
        $this->assertSame('a', $final->value());
    }

    // =========================================================================
    // stop() — the cancel ceiling
    // =========================================================================

    public function testStopBeforeRunExitsWithoutTouchingTheDriver(): void
    {
        $driver = $this->scriptedDriver([], readBudget: 0);
        $readline = new Readline($driver, static function (int $us): void {
            self::fail('the idle sleeper must never run once the loop is pre-cancelled');
        });
        $readline->stop();

        $final = $readline->run(TextPrompt::new('> '));

        $this->assertSame(0, $driver->reads);
        $this->assertSame('', $final->value());
        $this->assertFalse($final->isSubmitted());
        $this->assertFalse($final->isAborted());
    }

    public function testStopFromAKeyHandlerReturnsTheLastPromptStateUntouched(): void
    {
        // 'b' arrives, its registered handler cancels the pump; the prompt
        // keeps BOTH characters and stays neither submitted nor aborted.
        $driver = $this->scriptedDriver([$this->charEvent('a'), $this->charEvent('b')]);
        $holder = new \stdClass();
        $readline = (new Readline($driver))
            ->onKey('b', static function (KeyEvent $event) use ($holder): void {
                $holder->rl->stop();
            });
        $holder->rl = $readline;

        $final = $readline->run(TextPrompt::new('> '));

        $this->assertSame('ab', $final->value());
        $this->assertFalse($final->isSubmitted());
        $this->assertFalse($final->isAborted());
        $this->assertTrue($readline->isStopped());
    }

    public function testCancelledPumpBoundsItsOwnLifespanToTheReadBudget(): void
    {
        // Driver answers ONLY null forever (EOF-shaped live stream). The
        // sleeper cancels on the third pass; the budget of 10 reads turns a
        // missing stop-check into a loud RuntimeException instead of a hang.
        $driver = $this->scriptedDriver([], readBudget: 10);
        $sleeps = [];
        $readline = $this->recordingReadline($driver, 3, $sleeps);

        $final = $readline->run(TextPrompt::new('> '));

        $this->assertCount(3, $sleeps);
        $this->assertSame([1_000, 2_000, 4_000], $sleeps);
        $this->assertSame('', $final->value());
    }

    public function testIsStoppedTracksTheCancelFlag(): void
    {
        $readline = new Readline($this->scriptedDriver([]));
        $this->assertFalse($readline->isStopped());
        $readline->stop();
        $this->assertTrue($readline->isStopped());
    }

    // =========================================================================
    // Natural exits (regression: cancel did not replace them)
    // =========================================================================

    public function testCtrlCAbortsThroughTheLoopAsBefore(): void
    {
        $driver = $this->scriptedDriver([
            $this->charEvent('a'),
            new KeyEvent('c', KeyModifier::ctrl(), "\x03"),
        ]);

        $final = (new Readline($driver))->run(TextPrompt::new('> '));

        $this->assertTrue($final->isAborted());
        $this->assertFalse($final->isSubmitted());
    }

    public function testDefaultSleeperPathSleepsUsleepWhenNoSeamIsInjected(): void
    {
        // No sleeper: one real usleep on the production path — the ladder
        // floor keeps that at 1ms, so the suite pays nothing measurable.
        $driver = $this->scriptedDriver([null, null, $this->enterEvent()]);

        $final = (new Readline($driver))->run(TextPrompt::new('> ')->handleChar('x'));

        $this->assertTrue($final->isSubmitted());
        $this->assertSame('x', $final->value());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<Event|null> $events queue served in order, then null forever
     */
    private function scriptedDriver(array $events, int $readBudget = 24): InputDriver
    {
        return new class ($events, $readBudget) implements InputDriver {
            public int $reads = 0;

            /** @param array<Event|null> $events */
            public function __construct(
                private readonly array $events,
                private readonly int $budget,
            ) {}

            public function read(): Event|null
            {
                $this->reads++;
                if ($this->reads > $this->budget) {
                    throw new RuntimeException(
                        'poll budget of ' . $this->budget . ' reads exhausted — the loop kept polling past its bound'
                    );
                }
                return $this->events[$this->reads - 1] ?? null;
            }
        };
    }

    /**
     * Readline with a recording, cancel-after-N sleeper injected. The run()
     * call must happen on the returned instance — the cancel flag lives on
     * whichever object the loop is executing.
     *
     * @param list<int> $sleeps out-param: every idle delay the loop computed
     */
    private function recordingReadline(InputDriver $driver, int $stopAfterSleeps, array &$sleeps): Readline
    {
        $sleeps = [];
        $holder = new \stdClass();
        $readline = new Readline($driver, static function (int $us) use (&$sleeps, $stopAfterSleeps, $holder): void {
            $sleeps[] = $us;
            if (count($sleeps) >= $stopAfterSleeps) {
                $holder->rl->stop();
            }
        });
        $holder->rl = $readline;

        return $readline;
    }

    private function charEvent(string $char): KeyEvent
    {
        return KeyEvent::plain($char);
    }

    private function enterEvent(): KeyEvent
    {
        return new KeyEvent('Enter', KeyModifier::none(), "\r");
    }
}
