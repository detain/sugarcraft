<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media\Sd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\HeaderAwareSdTransport;
use SugarCraft\Crush\Media\Sd\ProgressLoop;
use SugarCraft\Crush\Media\Sd\ProgressState;
use SugarCraft\Crush\Media\Sd\ProgressSummary;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\SdTransportResult;

/**
 * The cadence law under fake time: heartbeat == polls (per-poll, NOT
 * per-change), cancel wins within one tick, the failure bound gives up at
 * exactly 3 (never dialling a 4th), previews ride only when requested, and
 * zero wall-clock is burned — the sleeper seam takes every pause.
 */
final class ProgressLoopTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, scalar>}> */
    private array $calls = [];

    /** @var list<int|float|string> poll numbers where the responder was asked to fail */
    private int $attempts = 0;

    private function clientQueuing(array $frames): Client
    {
        $queue = array_values($frames);
        $transport = new CallableSdTransport(
            function (string $method, string $path, array $json, array $query) use (&$queue): SdTransportResult {
                $this->calls[] = [$method, $path, $json, $query];
                $this->attempts++;
                $next = array_shift($queue);
                if ($next === null) {
                    throw new \RuntimeException('test queue exhausted — the loop polled past its script');
                }
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                $body = json_encode($next);
                $this->assertIsString($body);

                return SdTransportResult::new(200, $body, 'application/json');
            },
        );

        return Client::withBase('http://sd.test:7860', $transport);
    }

    /** @param list<array<string, mixed>> $frames @return array{0: list<float>, 1: int} */
    private function runLoop(Client $c, callable $isCancelled, int &$beats, array &$sleeps = []): ProgressSummary
    {
        $sleeps = [];

        return ProgressLoop::run(
            $c,
            function () use (&$beats): void {
                $beats++;
            },
            $isCancelled,
            null,
            0.25,
            static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );
    }

    public function testConstantProgressStillBeatsEveryPollNotEveryChange(): void
    {
        // Mutation-proof of the per-frame re-arm law: if the loop beat only
        // on progress CHANGE, a constant 0.5 fixture would starve the idle
        // watchdog and this pin would go red.
        $beats = 0;
        $done = 0;
        $sleeps = [];
        $summary = $this->runLoop(
            $this->clientQueuing([['progress' => 0.5], ['progress' => 0.5], ['progress' => 0.5]]),
            static function () use (&$done): bool {
                return ++$done >= 3;
            },
            $beats,
            $sleeps,
        );

        self::assertSame(3, $summary->polls);
        self::assertSame(3, $beats);
        self::assertTrue($summary->beatPerPoll());
        self::assertSame(ProgressSummary::TERMINATION_DONE, $summary->termination);
        self::assertSame([0.25, 0.25], $sleeps, 'sleep fires only between continuing polls');
    }

    public function testEveryPollCarriesTheSkipCurrentImagePolarityForItsMode(): void
    {
        $beats = 0;
        $previews = [];
        $c = $this->clientQueuing([
            ['progress' => 0.2, 'current_image' => 'QUk='],
            ['progress' => 0.4],
            ['progress' => 0.6, 'current_image' => 'Q1Z='],
        ]);
        $done = 0;
        $summary = ProgressLoop::run(
            $c,
            function () use (&$beats): void {
                $beats++;
            },
            static function () use (&$done): bool {
                return ++$done >= 3;
            },
            static function (string $b64) use (&$previews): void {
                $previews[] = $b64;
            },
            0.25,
            static function (float $seconds): void {
            },
        );

        self::assertSame(['QUk=', 'Q1Z='], $previews, 'preview frames forward in order, absent frames are not');
        foreach ($this->calls as $call) {
            self::assertSame(['skip_current_image' => 'false'], $call[3], 'preview mode must ask for current_image frames');
        }
    }

    public function testWithoutAPreviewConsumerThePollStaysCheap(): void
    {
        $beats = 0;
        $this->runLoop($this->clientQueuing([['progress' => 0.1]]), static fn (): bool => true, $beats);

        self::assertSame(['skip_current_image' => 'true'], $this->calls[0][3]);
    }

    public function testCancelMidLoopExitsWithinOneTick(): void
    {
        // Ten canned frames exist; cancel arms at poll 2 — polls 3+ must
        // never be dialled (queue exhaustion throws, so over-poll = red).
        $frames = array_fill(0, 10, ['progress' => 0.3]);
        $beats = 0;
        $seen = 0;
        $summary = $this->runLoop(
            $this->clientQueuing($frames),
            static function () use (&$seen): bool {
                return ++$seen >= 2;
            },
            $beats,
        );

        self::assertSame(2, $summary->polls);
        self::assertSame(2, $this->attempts);
        self::assertSame(ProgressSummary::TERMINATION_DONE, $summary->termination);
    }

    public function testAServerFinishedFrameTerminatesOnItsOwnAdvisorySignal(): void
    {
        $beats = 0;
        $summary = $this->runLoop(
            $this->clientQueuing([['progress' => 0.9], ['progress' => 1.0, 'state' => ['finished' => true]]]),
            static fn (): bool => false,
            $beats,
        );

        self::assertSame(2, $summary->polls);
        self::assertSame(ProgressSummary::TERMINATION_SERVER_FINISHED, $summary->termination);
        self::assertTrue($summary->lastState?->finished());
    }

    public function testThreeConsecutivePollFailuresGiveUpWithAReasonNotAFourthDial(): void
    {
        $beats = 0;
        $c = $this->clientQueuing([
            new \RuntimeException('conn refused'),
            new \RuntimeException('conn refused'),
            new \RuntimeException('conn refused'),
            ['progress' => 0.1], // must NEVER be reached — bound is 3
        ]);

        try {
            $this->runLoop($c, static fn (): bool => false, $beats);
            self::fail('the loop must give up at the third consecutive failure');
        } catch (SdException $e) {
            self::assertStringContainsString('3 consecutive', $e->getMessage());
            self::assertStringContainsString('conn refused', $e->getMessage(), 'the give-up reason names the last error');
        }
        self::assertSame(3, $this->attempts, 'a 4th poll is never dialled once the bound trips');
    }

    public function testASuccessResetsTheFailureStreak(): void
    {
        $beats = 0;
        $c = $this->clientQueuing([
            new \RuntimeException('blip'),
            new \RuntimeException('blip'),
            ['progress' => 0.5],
            new \RuntimeException('blip'),
            new \RuntimeException('blip'),
            ['progress' => 0.7, 'state' => ['finished' => true]],
        ]);

        $summary = $this->runLoop($c, static fn (): bool => false, $beats);

        self::assertSame(2, $summary->polls, 'two streaks of two failures each survive between accepted frames');
        self::assertSame(6, $this->attempts, 'every failure and both accepted frames dialled exactly once');
        self::assertSame(ProgressSummary::TERMINATION_SERVER_FINISHED, $summary->termination);
    }

    public function testFlappingProgressReadoutsStillFeedTheWatchdog(): void
    {
        // A failed readout is NOT a reason to starve the 120 s idle ceiling:
        // the heartbeat rides the failure path too; cancel still wins.
        $beats = 0;
        $c = $this->clientQueuing([
            new \RuntimeException('down'),
            new \RuntimeException('down'),
            ['progress' => 0.1], // never polled — cancel fires first
        ]);
        $seen = 0;
        $summary = $this->runLoop(
            $c,
            static function () use (&$seen): bool {
                return ++$seen >= 2;
            },
            $beats,
        );

        self::assertSame(0, $summary->polls);
        self::assertSame(2, $beats, 'failed polls beat too — a missing readout must not kill the waited-on job');
        self::assertSame(ProgressSummary::TERMINATION_DONE, $summary->termination);
    }

    public function testAGarbageFrameCostsAStreakSlotNotTheLoop(): void
    {
        // ""progress": string payload fails the client's map-decode — one
        // counted failure; the next good frame resets and the run finishes.
        $beats = 0;
        $c = $this->clientQueuing(['a bare string is not a progress map']);
        $this->expectException(SdException::class);
        $this->runLoop($c, static fn (): bool => false, $beats);
    }

    public function testGarbageBetweenGoodFramesSkipsAndSurvives(): void
    {
        $body = json_encode(['progress' => 0.4, 'state' => ['finished' => true]]);
        self::assertIsString($body);
        $beats = 0;
        $transport = new CallableSdTransport(
            function (string $method, string $path, array $json, array $query) use ($body): SdTransportResult {
                $this->attempts++;
                if ($this->attempts === 1) {
                    return SdTransportResult::new(200, '"a bare string"', 'application/json');
                }
                $decoded = json_decode($body, true);
                self::assertIsArray($decoded);

                return SdTransportResult::new(200, $body, 'application/json');
            },
        );
        $c = Client::withBase('http://sd.test:7860', $transport);

        $summary = $this->runLoop($c, static fn (): bool => false, $beats);

        self::assertSame(1, $summary->polls, 'the malformed first answer was skipped, the good one counted');
        self::assertNotNull($summary->lastState);
        self::assertSame(0.4, $summary->lastState?->progress);
    }

    public function testOnFrameFeedsTheMediaJobRingWithRawFrames(): void
    {
        $beats = 0;
        $frames = [];
        $c = $this->clientQueuing([['progress' => 0.25, 'job' => 'extra'], ['progress' => 0.5]]);
        $done = 0;
        ProgressLoop::run(
            $c,
            function () use (&$beats): void {
                $beats++;
            },
            static function () use (&$done): bool {
                return ++$done >= 2;
            },
            null,
            0.25,
            static function (float $seconds): void {
            },
            static function (ProgressState $s) use (&$frames): void {
                $frames[] = $s->raw;
            },
        );

        self::assertCount(2, $frames);
        self::assertSame('extra', $frames[0]['job'], 'raw payload rides the seam untouched for MediaJob::withProgressFrame');
    }

    public function testInterruptPassthroughsReachTheServerWithTwoStageSemantics(): void
    {
        $transport = new class implements HeaderAwareSdTransport {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, scalar>}> */
            public array $calls = [];

            public ?array $seenHeaders = null;

            public function request(string $method, string $path, array $json = [], array $query = [], ?float $totalTimeoutSeconds = null): SdTransportResult
            {
                $this->calls[] = [$method, $path, $json, $query];

                return SdTransportResult::new(200, '{}', 'application/json');
            }

            public function requestWithHeaders(string $method, string $path, array $json = [], array $query = [], array $headers = [], ?float $totalTimeoutSeconds = null): SdTransportResult
            {
                $this->calls[] = [$method, $path, $json, $query];
                $this->seenHeaders = $headers;

                return SdTransportResult::new(200, '{}', 'application/json');
            }
        };
        $c = Client::withBase('http://sd.test:7860', $transport);

        ProgressLoop::requestInterrupt($c, true);
        self::assertSame('POST', $transport->calls[0][0]);
        self::assertSame('/sdapi/v1/interrupt', $transport->calls[0][1]);
        self::assertSame(['interrupt_after_current' => 'true'], $transport->seenHeaders);

        ProgressLoop::requestInterrupt($c);
        self::assertCount(2, $transport->calls);

        ProgressLoop::requestSkip($c);
        self::assertSame('/sdapi/v1/skip', $transport->calls[2][1]);
    }

    public function testANonPositiveIntervalIsRefusedUpFront(): void
    {
        $this->expectException(SdException::class);
        $this->expectExceptionMessage('interval must be positive');
        ProgressLoop::run($this->clientQueuing([]), static function (): void {
        }, static fn (): bool => true, null, 0.0, static function (float $s): void {
        });
    }

    public function testTheLoopFileStaysPureOfReactAndWallClockKillers(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../src/Media/Sd/ProgressLoop.php');
        self::assertIsString($src);
        // Comments legitimately NAME the banned concepts (the E646 docblock
        // quotes them to forbid them) — police only executable code text.
        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        self::assertStringNotContainsString('React\\', $code, 'loop purity: no event-loop coupling');
        self::assertStringNotContainsString('timeout', strtolower($code), 'E646: no timeout knob in code, under any option spelling');
        self::assertStringNotContainsString('deadline', strtolower($code), 'no wall-clock killer may hide under another name');
    }
}
