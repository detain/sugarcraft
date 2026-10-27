<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Socket\ServerInterface;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Observability\AsyncOtlpTransport;
use SugarCraft\Code\Observability\BatchEventSink;
use SugarCraft\Code\Observability\OtelConfig;
use SugarCraft\Code\Observability\OtelSink;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * C-SES-4.b: the non-blocking OTLP leg. Every test here proves a SHAPE of
 * the delivery rather than a byte of the payload (the payload rules are
 * {@see BatchEventSinkTest}'s and {@see OtelSinkTest}'s):
 *
 * - the export leaves `consume()` before the collector answers — the TUI's
 *   update() path waits for nothing (loopback react/socket collector, whose
 *   handler cannot run until the loop runs, so "not yet delivered" is
 *   observable rather than timing-dependent),
 * - every non-`true` outcome (refusal status, transport rejection, a leg
 *   that throws) carries the already-rendered body for the retry tick,
 * - `flush()` re-posts whatever the loop never answered through the
 *   synchronous leg — the `-p`/shutdown shape where no loop runs again,
 *   with the documented duplicate-span trade.
 *
 * Loop hygiene: the suite's bootstrap already pins the shared loop to
 * StreamSelectLoop (arm-time deadline refresh — the LoopPin reason sugar-async
 * documents), so timers armed here fire on schedule and every loop run below
 * is bounded by an explicit deadline timer: no test can hang, only fail.
 */
final class AsyncOtlpTransportTest extends TestCase
{
    /** Bodies the loopback collector handler saw, in arrival order. */
    private array $collectorBodies = [];

    /** Set by the handler each turn: true answers 200, false answers 500. */
    private bool $collectorAccepts = true;

    private ?ServerInterface $collector = null;

    /** @var list<string> environment keys this test dials, with their prior values */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->collectorBodies = [];
        $this->collectorAccepts = true;
    }

    protected function tearDown(): void
    {
        $this->collector?->close();
        $this->collector = null;

        foreach ($this->savedEnv as $name => $value) {
            if (false === $value) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->savedEnv = [];
    }

    /**
     * The headline law (root cause of C-SES-4.b): before this step every
     * batched export ran a synchronous Guzzle POST inside consume() — on the
     * TUI's update() path. Here the loop is not run at all before the
     * assertions: the collector's handler cannot have executed, yet
     * consume() has already returned with nothing delivered and nothing
     * carried — the caller was never on the request's critical path. Once
     * the loop runs and the 200 lands, the accepted body is simply gone: no
     * carry-over.
     */
    public function testConsumeReturnsBeforeTheCollectorAnswers(): void
    {
        $url = $this->startCollector();
        $syncLegBodies = [];
        $sink = new BatchEventSink(
            OtelSink::withTransports(
                static function (string $body) use (&$syncLegBodies): bool {
                    $syncLegBodies[] = $body;

                    return true;
                },
                fn (string $body): PromiseInterface => $this->collectorTransport($url)->post($body),
            ),
            2,
        );

        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));

        $this->assertSame([], $this->collectorBodies, 'the loop has not run, so the POST has not even reached the handler');
        $this->assertSame([], $syncLegBodies, 'the synchronous leg is not used while an async leg is built and open');
        $this->assertFalse($sink->hasCarryOver(), 'in flight is neither delivered nor refused yet — nothing to retry');

        self::pumpLoopUntil(fn (): bool => [] !== $this->collectorBodies);

        $this->assertCount(1, $this->collectorBodies, 'the loop running is what delivers it');
        $this->assertFalse($sink->hasCarryOver(), 'the acceptance settled the body off the maps');
        $this->assertSame(0, $sink->carryCount());
    }

    /**
     * A refusal STATUS (not a transport error) is a delivered-and-declined
     * outcome: the body must land on the retry queue once its response
     * settles, so the 15-second tick can re-post it — the flip-side proof
     * that the async branch keeps the carry-over law the sync branch has.
     */
    public function testRefusalStatusCarriesTheBodyOnceTheLoopSettlesIt(): void
    {
        $this->collectorAccepts = false;
        $url = $this->startCollector();
        $sink = new BatchEventSink(
            OtelSink::withTransports(
                static fn (string $body): bool => false,
                fn (string $body): PromiseInterface => $this->collectorTransport($url)->post($body),
            ),
            2,
        );

        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $this->assertFalse($sink->hasCarryOver(), 'refusal is not known before the response arrives');

        self::pumpLoopUntil(fn (): bool => $sink->hasCarryOver());
        $this->assertTrue($sink->hasCarryOver(), 'the refused body is queued exactly as the synchronous arm queues it');
        $this->assertSame(1, $sink->carryCount());

        // The endpoint recovers; the next tick — dispatched by the Chat arm —
        // re-posts through the same async leg and the carry queue empties.
        // (Note the window: tick() hands the body straight to in-flight, so
        // hasCarryOver is already false while it flies — the pump watches
        // for the second delivery, not for the queue flag.)
        $this->collectorAccepts = true;
        $sink->tick();
        self::pumpLoopUntil(fn (): bool => \count($this->collectorBodies) >= 2);
        $this->assertFalse($sink->hasCarryOver());
        $this->assertCount(2, $this->collectorBodies, 'the refused batch reached the collector twice: once refused, once accepted');
    }

    /**
     * The flush law the class docblock owns: at shutdown the loop may never
     * run again (`-p` exits between turns; a TUI leaving through exit()), so
     * bodies the async leg accepted for SENDING but never answered are
     * re-posted through the synchronous leg HERE, without any loop running
     * at all. The async leg below hands out promises that never settle — the
     * exact in-flight-at-shutdown state — and flush still delivers.
     */
    public function testFlushWithoutARunningLoopDeliversUnansweredBodiesSynchronously(): void
    {
        $syncLegBodies = [];
        $sink = new BatchEventSink(
            OtelSink::withTransports(
                static function (string $body) use (&$syncLegBodies): bool {
                    $syncLegBodies[] = $body;

                    return true;
                },
                static fn (string $body): PromiseInterface => (new Deferred())->promise(),
            ),
            2,
        );

        $sink->consume(new StepStarted(1, 9));
        $sink->consume(new SpendCapBreached(1, 2.0, 1.0));
        $this->assertSame([], $syncLegBodies, 'the open path went async and the loop never ran');

        $sink->flush();
        $this->assertCount(1, $syncLegBodies, 'the unanswered in-flight body is re-posted at flush, synchronously');
        $this->assertFalse($sink->hasCarryOver(), 'flush reconciled the queue; nothing survives for a tick that will never come');
    }

    /**
     * A transport REJECTION (connect refused, timeout) is the same carry law
     * as a refusal status, reached through a real socket: fromConfig-built
     * transport dialing a dead port. This is also the fromConfig shape test —
     * the endpoint/timeout env reads land a working Browser+Connector pair.
     */
    public function testFromConfigTransportRejectsWhenThePortRefusesTheConnect(): void
    {
        $this->dialEnvironment('http://127.0.0.1:1', '0.5');
        $transport = AsyncOtlpTransport::fromConfig(OtelConfig::fromEnvironment());

        $outcome = 'unsettled';
        $transport->post('{"resourceSpans":[]}')->then(
            static function (bool $accepted) use (&$outcome): void {
                $outcome = $accepted ? 'accepted' : 'refused';
            },
            static function () use (&$outcome): void {
                $outcome = 'rejected';
            },
        );

        self::pumpLoopUntil(fn (): bool => 'unsettled' !== $outcome, 3.0);
        $this->assertSame('rejected', $outcome, 'a dead collector rejects with the transport error, never a silent stall');
    }

    /**
     * The sink-level outcome contract, settled synchronously (react/promise
     * fulfils an already-settled promise inside `then()`), so each arm is a
     * deterministic queue assertion: `true` drops the body, `false` and a
     * rejection both carry it, and a leg that THROWS is folded into a
     * rejected promise rather than escaping postAsync().
     */
    public function testSettledOutcomesFollowTheCarryLawWithoutAnyLoopRun(): void
    {
        foreach ([
            'accepted' => [static fn (string $body): PromiseInterface => resolve(true), false],
            'refused-status' => [static fn (string $body): PromiseInterface => resolve(false), true],
            'rejected' => [static fn (string $body): PromiseInterface => reject(new \RuntimeException('connection refused')), true],
            'thrown' => [static function (string $body): PromiseInterface {
                throw new \RuntimeException('malformed leg');
            }, true],
        ] as $label => [$leg, $expectCarry]) {
            $this->collectorBodies = [];
            $sink = new BatchEventSink(
                OtelSink::withTransports(
                    static fn (string $body): bool => false,
                    $leg,
                ),
                2,
            );

            $sink->consume(new StepStarted(1, 9));
            $sink->consume(new SpendCapBreached(1, 2.0, 1.0));

            $this->assertSame($expectCarry, $sink->hasCarryOver(), $label . ': ' . __METHOD__);
        }
    }

    /**
     * Wiring (mirrors the Guzzle leg's fromConfig): a configured endpoint
     * builds BOTH legs, so a production sink exports async while the
     * shutdown flush still has its bounded synchronous leg to fall back on.
     */
    public function testFromConfigBuildsBothTransportLegs(): void
    {
        $this->dialEnvironment('http://127.0.0.1:4318', '1.5');
        $client = $this->createMock(\GuzzleHttp\ClientInterface::class);

        $sink = OtelSink::fromConfig(OtelConfig::fromEnvironment(), $client);
        $this->assertTrue($sink->hasAsyncTransport(), 'a configured endpoint builds the async leg alongside the bounded sync one');
    }

    /** A bare withTransport (the debugging/test shape) keeps NO async leg. */
    public function testSyncOnlyShapeReportsNoAsyncLeg(): void
    {
        $sink = OtelSink::withTransport(static fn (string $body): bool => true);
        $this->assertFalse($sink->hasAsyncTransport());

        $outcome = 'unsettled';
        $sink->postAsync('x')->then(null, static function () use (&$outcome): void {
            $outcome = 'rejected';
        });
        $this->assertSame('rejected', $outcome, 'postAsync on a legless sink rejects — it never blocks and never pretends');
    }

    /**
     * An already-built transport used directly: status boundary. 200 → true;
     * the handler answers immediately, so a single bounded pump settles it.
     */
    public function testCollectorStatusBoundarySettlesThePromiseBool(): void
    {
        $url = $this->startCollector();
        $transport = $this->collectorTransport($url);

        $accepted = null;
        $transport->post('{"resourceSpans":[]}')->then(static function (bool $ok) use (&$accepted): void {
            $accepted = $ok;
        });
        self::pumpLoopUntil(fn (): bool => null !== $accepted);
        $this->assertTrue($accepted, 'status 200 is an acceptance');

        $this->collectorAccepts = false;
        $refused = null;
        $transport->post('{"resourceSpans":[]}')->then(static function (bool $ok) use (&$refused): void {
            $refused = $ok;
        });
        self::pumpLoopUntil(fn (): bool => null !== $refused);
        $this->assertFalse($refused, 'a 500 settles false — the body carries upstream, the promise never rejects for a status');
        $this->assertCount(2, $this->collectorBodies);
    }

    // ---------------------------------------------------------------- seams

    /**
     * Loopback collector: an HttpServer on 127.0.0.1, ephemeral port, whose
     * handler records bodies and answers per the flags above. Returns the
     * base URL to dial.
     */
    private function startCollector(): string
    {
        $loop = Loop::get();
        $server = new \React\Socket\Server('127.0.0.1:0', $loop);
        $this->collector = $server;

        $http = new HttpServer(function (\Psr\Http\Message\ServerRequestInterface $request): Response {
            $this->collectorBodies[] = (string) $request->getBody();

            return new Response($this->collectorAccepts ? 200 : 500);
        });
        $http->listen($server);

        $address = (string) $server->getAddress();

        return str_replace('tcp://', 'http://', $address);
    }

    private function collectorTransport(string $url): AsyncOtlpTransport
    {
        return new AsyncOtlpTransport(
            new Browser(new Connector([], Loop::get()), Loop::get()),
            $url . '/v1/traces',
        );
    }

    /**
     * Dial the endpoint env (and optionally the timeout env) for a fromConfig
     * read, recording priors so tearDown restores them — the config reads
     * whole values via getenv(), so putenv is the honest lever.
     */
    private function dialEnvironment(string $endpoint, ?string $timeoutSeconds = null): void
    {
        foreach ([OtelConfig::ENDPOINT_ENV => $endpoint, OtelConfig::CANDY_CODE_OTEL_TIMEOUT_ENV => $timeoutSeconds] as $name => $value) {
            if (null === $value) {
                continue;
            }
            $prior = getenv($name);
            $this->savedEnv[$name] = false === $prior ? false : $prior;
            putenv($name . '=' . $value);
        }
    }

    /**
     * Bounded loop pump: run the shared loop until $done() or the deadline
     * (arm-time refresh, StreamSelectLoop pinned by tests/bootstrap.php —
     * the LoopPin contract). BOTH timers are cancelled before returning:
     * the suite shares one loop, and a deadline left armed would stop a
     * LATER test's run() out from under it (the flake this helper owns).
     * No test in this file can hang: the deadline stops the loop and the
     * following assertion fails loud.
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
