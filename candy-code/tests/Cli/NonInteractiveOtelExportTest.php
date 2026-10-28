<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\Server;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Message;
use SugarCraft\Code\Observability\OtelConfig;
use SugarCraft\Code\Usage;

/**
 * C-SES-4.c (GAP-OBS-02, `-p` half): the headless one-shot path exports OTLP
 * spans through the SAME `Bootstrap::observabilitySink()` seam the TUI dials,
 * folded inside the `$onEvent` observer the backend is handed.
 *
 * The structural claim (the fold sits inside the observer) is pinned by
 * {@see \SugarCraft\Code\Tests\Observability\ObservabilityWiringTest::testTheHeadlessPathDialsTheSameSeamAndFoldsItsObserver()};
 * this file pins the behaviour: a lit run really lands one `chat.turn` span
 * carrying the turn's token buckets on the wire, and a dark run — the exact
 * same events, endpoint simply unset — costs zero transport calls. The dark
 * leg is an absence claim and the lit leg is its known-positive pairing:
 * the identical harness, one environment variable apart (rule 15).
 *
 * Batch 2 is deliberate: `StepStarted` opens the span and `UsageUpdated`
 * closes it, and the encoder only ever POSTs CLOSED spans — a batch of two
 * guarantees one export holding the whole `chat.turn`, instead of the
 * opening event exporting (empty, no-op) and closing in a second batch.
 * The async leg hands bodies to the shared loop, so the assertions pump the
 * loop until the loopback collector answers; the bounded pump makes a stuck
 * export fail loud, never hang (same discipline as the transport's own suite).
 */
final class NonInteractiveOtelExportTest extends TestCase
{
    /** @var list<string> OTLP request bodies the collector received, in arrival order */
    private array $wireBodies = [];

    private ?Server $headlessCollector = null;

    /** @var array<string, string|false> env priors keyed by variable name */
    private array $envPriors = [];

    protected function tearDown(): void
    {
        $this->headlessCollector?->close();
        $this->headlessCollector = null;
        $this->wireBodies = [];

        foreach ($this->envPriors as $name => $prior) {
            if (false === $prior) {
                putenv($name);
            } else {
                putenv($name . '=' . $prior);
            }
        }
        $this->envPriors = [];
    }

    public function testALitHeadlessRunPostsOneChatTurnSpanCarryingTheTurnTokens(): void
    {
        $base = $this->startHeadlessCollector();
        $this->dialHeadlessOtelEnv($base . '/v1/traces', '2');

        $code = $this->runExportingHeadlessTurn();
        self::assertSame(NonInteractive::EXIT_OK, $code, 'the export changed the one-shot exit status');

        $this->pumpOtelWireUntil(count: 1, budgetSeconds: 3.0);

        self::assertCount(1, $this->wireBodies, 'a lit `-p` run did not land its batch on the wire');
        $decoded = json_decode($this->wireBodies[0], true, 512, JSON_THROW_ON_ERROR);

        $spans = $decoded['resourceSpans'][0]['scopeSpans'][0]['spans'];
        self::assertCount(1, $spans, 'the `-p` batch closed more than one span — the turn opened twice');
        self::assertSame('chat.turn', $spans[0]['name']);

        $attrs = self::spanAttributes($spans[0]);
        // OTLP/JSON serialises int64 attributes as decimal STRINGS — the cast
        // is reading the wire contract, not hiding one.
        self::assertSame(100, (int) $attrs['gen_ai.usage.input_tokens'], 'the exported span misreports the prompt tokens');
        self::assertSame(40, (int) $attrs['gen_ai.usage.output_tokens'], 'the exported span misreports the completion tokens');
        self::assertSame(140, (int) $attrs['gen_ai.usage.total_tokens'], 'the exported span misreports the turn total');
    }

    public function testADarkHeadlessRunCostsZeroTransportCallsForTheSameEvents(): void
    {
        $base = $this->startHeadlessCollector();
        // Endpoint deliberately UNSET (env only — no putenv of an empty value:
        // the config gate reads non-empty, unset is the production default);
        // the batch size still dials at 2 to prove the dark decision outranks
        // any batching knob.
        $this->putHeadlessOtelVar(OtelConfig::ENDPOINT_ENV, null);
        $this->putHeadlessOtelVar(OtelConfig::CANDY_CODE_OTEL_BATCH_ENV, '2');

        $code = $this->runExportingHeadlessTurn();
        self::assertSame(NonInteractive::EXIT_OK, $code);

        // No pending export exists to wait for; this short pump only proves
        // the loop holds nothing that would arrive late.
        $this->pumpOtelWireUntil(count: 1, budgetSeconds: 0.3);

        self::assertCount(0, $this->wireBodies, 'an unconfigured `-p` run dials the network — the dark path must be pure getenv()');
        // KNOWN-POSITIVE PAIRING (rule 15): the lit leg is this exact harness
        // with the endpoint dialed and the same events folded — it detected a
        // landing body, so the absence here means something.
    }

    /**
     * One in-process `-p` run whose stub backend folds the real turn shape:
     * step opened, step billed (100 in / 40 out / 140 total), answer settled.
     */
    private function runExportingHeadlessTurn(): int
    {
        $usage = Usage::new(totalTokens: 140, costUsd: 0.02, inputTokens: 100, outputTokens: 40);
        $backend = $this->exportingBackend(Message::assistant('spanned answer')->withUsage($usage), [
            new StepStarted(1, 30),
            new UsageUpdated(1, $usage, $usage),
        ]);

        ob_start();
        try {
            return NonInteractive::run(
                ArgvParser::parse(['candy-code', '-p', 'go']),
                $backend,
                NonInteractive::FORMAT_TEXT,
            );
        } finally {
            ob_end_clean();
        }
    }

    /**
     * A backend that replays $events into the `$onEvent` observer before
     * settling $answer — the observer is exactly what production EngineBackend
     * hands its step events to, so the fold under test sees the real shapes.
     *
     * @param list<object> $events
     */
    private function exportingBackend(Message $answer, array $events): Backend
    {
        return new class($answer, $events) implements Backend {
            /** @param list<object> $replayed */
            public function __construct(
                private readonly Message $settled,
                private readonly array $replayed,
            ) {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                foreach ($this->replayed as $event) {
                    $onEvent?->__invoke($event);
                }

                return $this->settled;
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };
    }

    /**
     * Loopback OTLP collector answering 200 to everything; returns the base
     * URL to dial (the sink itself appends the traces path per OTLP/HTTP).
     */
    private function startHeadlessCollector(): string
    {
        $socket = new Server('127.0.0.1:0', Loop::get());
        $this->headlessCollector = $socket;

        (new HttpServer(function (\Psr\Http\Message\ServerRequestInterface $request): Response {
            $this->wireBodies[] = (string) $request->getBody();

            return new Response(200);
        }))->listen($socket);

        return str_replace('tcp://', 'http://', (string) $socket->getAddress());
    }

    private function dialHeadlessOtelEnv(string $endpointUrl, string $batchSize): void
    {
        $this->putHeadlessOtelVar(OtelConfig::ENDPOINT_ENV, $endpointUrl);
        $this->putHeadlessOtelVar(OtelConfig::CANDY_CODE_OTEL_BATCH_ENV, $batchSize);
    }

    /**
     * putenv lever with prior capture (null clears the variable — the config
     * gate reads getenv() whole values, so putenv is the honest dial).
     */
    private function putHeadlessOtelVar(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->envPriors)) {
            $this->envPriors[$name] = getenv($name);
        }

        if (null === $value) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    /**
     * Run the shared loop until $count bodies have landed or the budget
     * expires — arriving early is the normal exit, expiring is the loud one
     * (the following assertCount names what never showed up). Both timers are
     * cancelled before returning: the suite shares one loop, and a deadline
     * left armed would stop a later test's pump mid-breath.
     */
    private function pumpOtelWireUntil(int $count, float $budgetSeconds): void
    {
        if (count($this->wireBodies) >= $count) {
            return;
        }

        $loop = Loop::get();
        $expiry = $loop->addTimer($budgetSeconds, static function () use ($loop): void {
            $loop->stop();
        });
        $watch = $loop->addPeriodicTimer(0.01, function () use ($loop, $count, &$watch): void {
            if (count($this->wireBodies) >= $count) {
                $loop->cancelTimer($watch);
                $loop->stop();
            }
        });

        try {
            $loop->run();
        } finally {
            $loop->cancelTimer($expiry);
            $loop->cancelTimer($watch);
        }
    }

    /**
     * @param array<string, mixed> $span
     *
     * @return array<string, mixed> attribute key => its first-set OTLP value
     */
    private static function spanAttributes(array $span): array
    {
        $attrs = [];
        foreach ($span['attributes'] as $entry) {
            $attrs[$entry['key']] = $entry['value']['intValue']
                ?? $entry['value']['doubleValue']
                ?? $entry['value']['stringValue']
                ?? null;
        }

        return $attrs;
    }
}
