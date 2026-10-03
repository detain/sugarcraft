<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Crush\Tests\Backend\Support\ScaledClockLoop;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Roadmap 1.C-1: the parent's idle ceiling
 * (`EngineBackend::COMPLETE_TIMEOUT_SECONDS`, 120 s) is PAUSED while a question
 * is open and re-armed when it is answered.
 *
 * A child blocked on an ASK writes nothing by design, so without the pause a
 * person who takes two minutes to read a diff would have the whole turn
 * SIGKILLed under them (Appendix O §5.1 parent side, step 2). Driven on
 * {@see ScaledClockLoop} — real fork, real socket, a clock where 120 virtual
 * seconds are 240 ms of wall time — so the ceiling is really crossed rather
 * than read about.
 *
 * Both polarities: the answer comes well PAST the ceiling and the turn lives;
 * and once answered, a silent provider still dies on the same clock, so the
 * pause cannot be a ceiling that was simply switched off.
 */
final class ForkChannelIdleTimerSuspendedTest extends TestCase
{
    /** Virtual seconds the receiver sits on the question — past the 120 s ceiling. */
    private const ANSWER_AFTER = 200.0;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the idle ceiling only exists on completeAsync()\'s forked path.');
        }
    }

    public function testAQuestionLeftOpenPastTheCeilingDoesNotKillTheTurn(): void
    {
        $result = $this->runScaled(InteractiveTurnHarness::provider());

        self::assertFalse($result['realCeiling'], 'the turn hung instead of settling');
        self::assertTrue($result['settled']);
        self::assertNull($result['error'], 'the idle ceiling fired while a question was open: ' . $result['error']?->getMessage());
        self::assertSame('done', $result['value']->content);
        self::assertGreaterThan(
            120.0,
            $result['virtualSeconds'],
            'fixture: the answer came before the ceiling, so this proves nothing',
        );
        self::assertSame(['ran'], $result['finished']);
    }

    public function testTheCeilingIsReArmedOnceTheQuestionIsAnswered(): void
    {
        // After the answer the tool runs and the next provider call goes
        // silent for ~250 virtual seconds (0.5 s of wall time).
        $silent = static function (): CompleteResponse {
            usleep(500_000);

            return new CompleteResponse(content: 'too late');
        };
        $result = $this->runScaled(InteractiveTurnHarness::provider(1, [], [$silent]));

        self::assertFalse($result['realCeiling'], 'the turn hung instead of settling');
        self::assertTrue($result['settled']);
        self::assertInstanceOf(\RuntimeException::class, $result['error'], 'a silent provider after the answer must still hit the ceiling');
        self::assertStringContainsString('without progress', $result['error']->getMessage());
    }

    /**
     * @return array{settled: bool, value: mixed, error: ?\Throwable, virtualSeconds: float, realCeiling: bool, finished: list<string>}
     */
    private function runScaled(ScriptedProvider $provider): array
    {
        $loop = new ScaledClockLoop();
        $previous = Loop::get();
        Loop::set($loop);
        $finished = [];

        try {
            $promise = InteractiveTurnHarness::backend($provider)->completeInteractive(
                [Message::user('go')],
                null,
                null,
                static function (object $event) use ($loop, &$finished): void {
                    if ($event instanceof ToolFinished) {
                        $finished[] = $event->result->content();
                    }
                    if ($event instanceof PermissionAsked) {
                        $ask = $event->ask;
                        $loop->addTimer(self::ANSWER_AFTER, static function () use ($ask): void {
                            $ask->reply(PermissionReply::Once);
                        });
                    }
                },
            );
            // The guard is in VIRTUAL seconds here; the loop's own real
            // ceiling is the backstop.
            $state = InteractiveTurnHarness::settle($promise, $loop, 1.0e9);
        } finally {
            // The SUITE's pinned loop, not a fresh one (tests/bootstrap.php).
            Loop::set($previous);
        }

        return $state + [
            'virtualSeconds' => $loop->highWaterVirtualSeconds(),
            'realCeiling' => $loop->hitRealCeiling(),
            'finished' => $finished,
        ];
    }
}
