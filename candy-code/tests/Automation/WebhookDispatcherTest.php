<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Automation;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Promise\Deferred;
use SugarCraft\Code\Automation\OutboundWebhookConfig;
use SugarCraft\Code\Automation\WebhookDispatcher;
use SugarCraft\Code\Automation\WebhookEvent;
use SugarCraft\Code\Automation\WebhookSigner;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;

/**
 * C-AGT-5.e: the delivery leg, measured against a fake `React\Http\Browser`.
 *
 * Every law the dispatcher claims gets a count on the fake, never a read of
 * a comment:
 * - dispatch returns before the loop has run (the never-blocks contract);
 * - the exact signed header pair rides the exact event JSON;
 * - 5xx and transport rejections climb the backoff ladder (pinned against
 *   the suite's StreamSelectLoop clock, armed at millisecond scale by the
 *   injectable ladder), while 4xx and refused-redirect 3xx answers are NOT
 *   retried — the ladder exists for servers that may recover, not for
 *   answers that already arrived;
 * - the queue is bounded, overflow drops speak once per episode through
 *   RuntimeNoticeSink (raw emitters: zero), and persistent endpoint failure
 *   speaks once per consecutive streak;
 * - a browser that explodes synchronously never reaches the caller.
 *
 * Loop hygiene: tests/bootstrap.php already pins the shared loop to
 * StreamSelectLoop (the arm-time deadline refresh LoopPin documents), and
 * every loop run here is bounded by an explicit deadline timer — no test can
 * hang, only fail.
 */
final class WebhookDispatcherTest extends TestCase
{
    private const TS = 1761630000;

    private const SECRET = 'dispatcher-secret-value-0123456789';

    /** @var list<array{method:string,url:string,headers:array<string,string>,body:string,at:float}> */
    private array $posts = [];

    protected function setUp(): void
    {
        $this->posts = [];
        $this->appliedTransforms = [];
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
    }

    /**
     * The headline law (the same shape C-SES-4.b pinned for OTLP): the POST
     * is HANDED OVER, not awaited. Nothing in the loop has run, so nothing
     * has arrived anywhere — yet dispatch() has already returned true and
     * the caller is off the stack. The update() path waits for nothing.
     */
    public function testDispatchReturnsBeforeTheLoopRuns(): void
    {
        $deferred = new Deferred();
        $dispatcher = $this->dispatcher([['defer' => $deferred]], null);

        $admitted = $dispatcher->dispatch($this->event());

        self::assertTrue($admitted);
        self::assertCount(1, $this->posts, 'the request was issued without any loop run');
        self::assertSame(1, $dispatcher->pending());
        self::assertSame(0, $dispatcher->settled());

        $deferred->resolve(new Response(204));
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 1);
        self::assertSame(0, $dispatcher->pending(), 'the accepted 204 settles the delivery');
    }

    public function testTheDeliveryCarriesEventJsonAndItsSignaturePair(): void
    {
        $dispatcher = $this->dispatcher([['status' => 200]], null);
        $event = $this->event();

        $dispatcher->dispatch($event);

        self::assertCount(1, $this->posts);
        $post = $this->posts[0];
        self::assertSame('POST', $post['method']);
        self::assertSame('https://hooks.agent.test/ci', $post['url']);
        self::assertSame($event->json(), $post['body'], 'the bytes signed are the bytes sent');

        $timestamp = $post['headers'][WebhookSigner::TIMESTAMP_HEADER] ?? null;
        self::assertIsString($timestamp);
        self::assertGreaterThanOrEqual(self::TS, (int) $timestamp, 'the header stamps the SEND time, fresh per attempt, not the event time');
        self::assertLessThanOrEqual(\time() + 2, (int) $timestamp);
        self::assertSame(
            WebhookSigner::signature($post['body'], self::SECRET, (int) $timestamp),
            $post['headers'][WebhookSigner::SIGNATURE_HEADER],
            'a receiver recomputing from the sent pair verifies',
        );
        self::assertSame('application/json', $post['headers']['Content-Type']);
    }

    public function testOnlySubscribedEndpointsAreDialed(): void
    {
        $endpoints = OutboundWebhookConfig::parse([
            'turns' => ['url' => 'https://a.test/turns', 'secret' => self::SECRET, 'events' => [WebhookEvent::TYPE_TURN_COMPLETED]],
            'sessions' => ['url' => 'https://b.test/sessions', 'secret' => self::SECRET, 'events' => [WebhookEvent::TYPE_SESSION_ENDED]],
        ]);
        $dispatcher = $this->dispatcher([['status' => 200]], null, $endpoints);

        $dispatcher->dispatch($this->event());

        self::assertCount(1, $this->posts);
        self::assertSame('https://a.test/turns', $this->posts[0]['url']);
    }

    /**
     * The ladder, not just its length: three 5xx answers become four attempts
     * separated by the armed waits (millisecond rescaling of 2/8/30 — the
     * default ladder itself is pinned by constant beside the fake's
     * transforms). A timer fired EARLY would land arrivals inside the lower
     * bounds; no timer at all would collapse all four into the same tick.
     */
    public function testFiveXxClimbsTheBackoffLadderThenGivesUp(): void
    {
        $ladder = [0.05, 0.08, 0.11];
        $dispatcher = $this->dispatcher(
            [['status' => 500], ['status' => 502], ['status' => 503], ['status' => 500]],
            $ladder,
        );
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);

        $dispatcher->dispatch($this->event());
        self::assertCount(1, $this->posts, 'the first attempt is immediate, the ladder starts after it');

        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 1, 5.0);

        self::assertCount(4, $this->posts, 'the initial send plus three ladder rungs');
        $gaps = [
            $this->posts[1]['at'] - $this->posts[0]['at'],
            $this->posts[2]['at'] - $this->posts[1]['at'],
            $this->posts[3]['at'] - $this->posts[2]['at'],
        ];
        self::assertGreaterThanOrEqual(0.04, $gaps[0], 'retry 1 waited its rung');
        self::assertGreaterThanOrEqual(0.07, $gaps[1], 'retry 2 waited its rung');
        self::assertGreaterThanOrEqual(0.10, $gaps[2], 'retry 3 waited its rung');
        self::assertSame(0, $dispatcher->pending());
        self::assertSame(1, $dispatcher->settled());

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows);
        self::assertStringContainsString('failed after 4 attempts', $rows[0]);
        self::assertStringContainsString('"turns"', $rows[0]);
    }

    public function testTheDefaultLadderIsTwoEightThirtyAndTheCapsArePinned(): void
    {
        self::assertSame([2.0, 8.0, 30.0], WebhookDispatcher::BACKOFF_SECONDS);
        self::assertSame(10.0, WebhookDispatcher::POST_TIMEOUT_SECONDS);
        self::assertSame(2.0, WebhookDispatcher::CONNECT_TIMEOUT_SECONDS);
        self::assertSame(128, WebhookDispatcher::DEFAULT_QUEUE_LIMIT);
    }

    public function testFourXxIsFinalTheFirstTime(): void
    {
        $dispatcher = $this->dispatcher([['status' => 404]], [0.01]);

        $dispatcher->dispatch($this->event());
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 1);

        self::assertCount(1, $this->posts, 'the receiver understood us and said no; re-asking is not retry policy');
    }

    public function testRefusedRedirectIsNeitherFollowedNorRetried(): void
    {
        $dispatcher = $this->dispatcher([['status' => 302]], [0.01]);

        $dispatcher->dispatch($this->event());
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 1);

        self::assertCount(1, $this->posts);
        self::assertFalse($this->appliedTransforms['follow'] ?? true, 'the browser arrived with redirects disabled');
        self::assertFalse($this->appliedTransforms['rejectError'] ?? true, 'statuses resolve, they do not reject');
        self::assertSame(WebhookDispatcher::POST_TIMEOUT_SECONDS, $this->appliedTransforms['timeout'] ?? null, 'E646-legal per-POST network cap');
    }

    public function testTransportRejectionRidesTheLadderAndSucceedsQuietly(): void
    {
        $dispatcher = $this->dispatcher([['reject' => 'connection refused'], ['status' => 200]], [0.01]);
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);

        $dispatcher->dispatch($this->event());
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 1);

        self::assertCount(2, $this->posts, 'a refused connect is exactly what the ladder is for');
        self::assertSame([], RuntimeNoticeSink::drain(), 'a recovered delivery never reaches the transcript');
    }

    /**
     * Success ends a failure streak: the notice is once per consecutive
     * streak, not once per endpoint per process — a receiver that dies,
     * heals, and dies again deserves to be announced again.
     */
    public function testPersistentFailureAnnouncesOncePerStreakNotForever(): void
    {
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);

        $outcomes = [
            ['status' => 500], ['status' => 500], ['status' => 500], ['status' => 500],
            ['status' => 500], ['status' => 500], ['status' => 500], ['status' => 500],
            ['status' => 200],
            ['status' => 503], ['status' => 503], ['status' => 503], ['status' => 503],
        ];
        $dispatcher = $this->dispatcher($outcomes, [0.002, 0.002, 0.002]);

        $dispatcher->dispatch($this->event());
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 1);
        self::assertCount(1, RuntimeNoticeSink::drain(), 'streak one announces itself');

        $dispatcher->dispatch($this->event());
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 2);
        self::assertSame([], RuntimeNoticeSink::drain(), 'the same broken streak stays quiet');

        $dispatcher->dispatch($this->event());
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 3);
        self::assertSame([], RuntimeNoticeSink::drain(), 'a recovered delivery never reaches the transcript');

        $dispatcher->dispatch($this->event());
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 4);
        self::assertCount(1, RuntimeNoticeSink::drain(), 'a NEW streak earns a new notice');

        self::assertCount(13, $this->posts);
    }

    public function testBoundedQueueDropsOnOverflowAndAnnouncesOncePerEpisode(): void
    {
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);

        $first = new Deferred();
        $third = new Deferred();
        $dispatcher = $this->dispatcher([['defer' => $first], ['defer' => $third]], null, limit: 1);

        self::assertTrue($dispatcher->dispatch($this->event()), 'the ladder slot was free');
        self::assertFalse($dispatcher->dispatch($this->event()), 'a saturated queue drops instead of growing');
        self::assertFalse($dispatcher->dispatch($this->event()), 'still saturated');
        self::assertCount(1, $this->posts, 'dropped dispatches dial nothing');

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows, 'one notice per episode, not per drop');
        self::assertStringContainsString('queue is full', $rows[0]);

        $first->resolve(new Response(200));
        self::pumpLoopUntil(static fn (): bool => $dispatcher->pending() === 0);

        self::assertTrue($dispatcher->dispatch($this->event()), 'the drain reopened the queue');
        $third->resolve(new Response(200));
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 2);
    }

    public function testABrowserThatThrowsSynchronouslyNeverReachesTheCaller(): void
    {
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);

        $dispatcher = $this->dispatcher([['throw' => true], ['throw' => true], ['throw' => true], ['throw' => true]], [0.002, 0.002, 0.002]);

        self::assertTrue($dispatcher->dispatch($this->event()), 'admitted — its failure is a settled delivery, not an exception');
        self::pumpLoopUntil(static fn (): bool => $dispatcher->settled() === 1, 5.0);

        self::assertCount(4, $this->posts, 'the sync throw rode the same retry path as a rejection');
        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows);
        self::assertStringContainsString('failed after 4 attempts', $rows[0]);
    }

    /**
     * The event vocabulary on the wire: bounded identifiers, no free text,
     * and the exact envelope keys. The 500-character session id is the point
     * — a webhook body must not grow with whatever a caller assembled, and
     * it must never carry the conversation itself.
     */
    public function testEventBodiesAreBoundedAndTextFree(): void
    {
        $long = str_repeat('x', 500);
        $event = WebhookEvent::permissionAsked($long, $long . 'Bash', self::TS);
        $decoded = json_decode($event->json(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['event', 'timestamp', 'data'], array_keys($decoded));
        self::assertSame('permission.asked', $decoded['event']);
        self::assertSame(self::TS, $decoded['timestamp']);
        self::assertSame(['session_id', 'tool'], array_keys($decoded['data']));
        self::assertSame(WebhookEvent::MAX_ID_CHARS, strlen($decoded['data']['session_id']), 'clipped at the bound');

        $schedule = WebhookEvent::scheduleFired('ses_one', 'nightly', str_repeat('y', 200), self::TS);
        $scheduleData = json_decode($schedule->json(), true, 512, \JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['session_id', 'schedule', 'outcome'], array_keys($scheduleData));
        self::assertSame(WebhookEvent::MAX_OUTCOME_CHARS, strlen($scheduleData['outcome']));

        $turn = WebhookEvent::turnCompleted('  ses_pad  ', -3.0, -7, self::TS);
        $turnData = json_decode($turn->json(), true, 512, \JSON_THROW_ON_ERROR)['data'];
        self::assertSame('ses_pad', $turnData['session_id'], 'trimmed');
        self::assertSame(0.0, $turnData['duration_seconds'], 'clamped at zero');
        self::assertSame(0, $turnData['tool_calls']);

        $this->expectException(\InvalidArgumentException::class);
        new WebhookEvent('turn.started', self::TS, []);
    }

    /**
     * C-AGT-5.e round 2 (review MINOR-1): the bounds are a property of the
     * TYPE, not a courtesy of the four factory names. A direct
     * `new WebhookEvent(...)` — the bypass the review named — is clipped,
     * clamped and schema-checked in the constructor itself, off-schema or
     * missing keys refuse fail-closed, and the re-normalization is the
     * identity on factory payloads (byte-for-byte, all four types, so the
     * signer never binds different bytes than before).
     */
    public function testTheConstructorEnforcesTheSchemaAndBoundsOnEveryPath(): void
    {
        $direct = new WebhookEvent(WebhookEvent::TYPE_SCHEDULE_FIRED, self::TS, [
            'session_id' => str_repeat('x', 500),
            'schedule' => '  nightly  ',
            'outcome' => str_repeat('y', 200),
        ]);
        $directData = \json_decode($direct->json(), true, 512, \JSON_THROW_ON_ERROR)['data'];
        self::assertSame(WebhookEvent::MAX_ID_CHARS, \strlen($directData['session_id']), 'ctor clips identifiers');
        self::assertSame(WebhookEvent::MAX_OUTCOME_CHARS, \strlen($directData['outcome']), 'ctor clips the outcome word');
        self::assertSame('nightly', $directData['schedule'], 'ctor trims');

        $negative = new WebhookEvent(WebhookEvent::TYPE_SESSION_ENDED, self::TS, [
            'session_id' => 'ses_one',
            'duration_seconds' => -3.0,
            'turn_count' => -7,
        ]);
        $negativeData = \json_decode($negative->json(), true, 512, \JSON_THROW_ON_ERROR)['data'];
        self::assertSame(0.0, $negativeData['duration_seconds'], 'ctor clamps numbers');
        self::assertSame(0, $negativeData['turn_count']);

        try {
            new WebhookEvent(WebhookEvent::TYPE_TURN_COMPLETED, self::TS, [
                'session_id' => 'ses_one',
                'duration_seconds' => 1.0,
                'tool_calls' => 2,
                'note' => 'a transcript fragment must not ride the envelope',
            ]);
            self::fail('off-schema field accepted through the constructor');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('note', $e->getMessage(), 'refusal names the offending field');
        }

        try {
            new WebhookEvent(WebhookEvent::TYPE_TURN_COMPLETED, self::TS, [
                'session_id' => 'ses_one',
                'duration_seconds' => 1.0,
            ]);
            self::fail('missing schema field accepted through the constructor');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('tool_calls', $e->getMessage());
        }

        foreach ([
            WebhookEvent::turnCompleted('ses_p', 1.234, 3, self::TS),
            WebhookEvent::sessionEnded('ses_p', 9.999, 4, self::TS),
            WebhookEvent::permissionAsked('ses_p', 'Bash', self::TS),
            WebhookEvent::scheduleFired('ses_p', 'nightly', 'ok', self::TS),
        ] as $minted) {
            self::assertSame(
                $minted->json(),
                (new WebhookEvent($minted->type, $minted->timestamp, $minted->data))->json(),
                'ctor normalization is the identity on factory payloads',
            );
        }
    }

    /** @var array<string,mixed> */
    private array $appliedTransforms = [];

    /**
     * Assemble a dispatcher over a fake browser whose scripted outcomes
     * answer each attempt in order (status / reject / throw / defer); an
     * exhausted script answers 200.
     *
     * @param list<array<string,mixed>> $outcomes
     * @param null|list<float> $ladder
     */
    private function dispatcher(array $outcomes, ?array $ladder, ?array $endpoints = null, int $limit = 64): WebhookDispatcher
    {
        $posts = &$this->posts;
        $transforms = &$this->appliedTransforms;

        $browser = new class($posts, $transforms, $outcomes) extends \React\Http\Browser {
            /** @param list<array<string,mixed>> $outcomes */
            public function __construct(private array &$posts, private array &$transforms, private array $outcomes)
            {
                parent::__construct();
            }

            public function request($method, $url, array $headers = [], $body = '')
            {
                $index = \count($this->posts);
                $this->posts[] = [
                    'method' => (string) $method,
                    'url' => (string) $url,
                    'headers' => $headers,
                    'body' => (string) $body,
                    'at' => \microtime(true),
                ];

                $outcome = $this->outcomes[$index] ?? ['status' => 200];
                if (isset($outcome['throw'])) {
                    throw new \RuntimeException('scripted synchronous browser failure');
                }
                if (isset($outcome['reject'])) {
                    return \React\Promise\reject(new \RuntimeException((string) $outcome['reject']));
                }
                if (isset($outcome['defer'])) {
                    return $outcome['defer']->promise();
                }

                return \React\Promise\resolve(new Response((int) $outcome['status']));
            }

            public function withFollowRedirects($followRedirects)
            {
                $this->transforms['follow'] = $followRedirects;

                return $this;
            }

            public function withRejectErrorResponse($bool)
            {
                $this->transforms['rejectError'] = $bool;

                return $this;
            }

            public function withTimeout($timeout)
            {
                $this->transforms['timeout'] = $timeout;

                return $this;
            }
        };

        return new WebhookDispatcher(
            $browser,
            $endpoints ?? OutboundWebhookConfig::parse([
                'turns' => ['url' => 'https://hooks.agent.test/ci', 'secret' => self::SECRET, 'events' => [WebhookEvent::TYPE_TURN_COMPLETED]],
            ]),
            $ladder,
            $limit,
        );
    }

    private function event(): WebhookEvent
    {
        return WebhookEvent::turnCompleted('ses_1234567890abcdef', 1.5, 2, self::TS);
    }

    /**
     * Run the shared loop until `$done` says so, bounded by a deadline
     * timer — byte-copied from tests/Observability/AsyncOtlpTransportTest.php
     * (the house pump; DuplicatedTestHelperDrift's discipline is that
     * shared-shape helpers ride identical, not drifted).
     */
    private static function pumpLoopUntil(callable $done, float $deadlineSeconds = 2.0): void
    {
        if ($done()) {
            return;
        }

        $loop = Loop::get();
        $deadline = $loop->addTimer($deadlineSeconds, static function () use ($loop): void {
            $loop->stop();
        });
        $periodic = $loop->addPeriodicTimer(0.005, function ($timer) use ($loop, $done): void {
            if ($done()) {
                $loop->cancelTimer($timer);
                $loop->stop();
            }
        });

        try {
            $loop->run();
        } finally {
            $loop->cancelTimer($deadline);
            $loop->cancelTimer($periodic);
        }
    }
}
