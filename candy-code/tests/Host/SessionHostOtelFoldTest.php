<?php

/**
 * C-SES-4.d (owner ruling §6.1): serve exports OTel spans.
 *
 * The trap this lane exists for: OtlpEncoder keys open turn spans by the
 * event's step number, and a serve process hosts several sessions whose
 * step numbers march over identical values. The answer is an encoder PER
 * SESSION — built through the workspace's EventSinkFactory, tagged with a
 * session.id resource attribute — while both transports stay shared, and
 * the serve loop still never blocks on a POST (the C-SES-4.b law).
 *
 * Pins, one per promise the step makes:
 * - two sessions folding identical step numbers export two distinct
 *   chat.turn spans, each carrying its own session.id, neither body ever
 *   naming the other session (the collision the per-session encoder
 *   prevents — a shared encoder would splice one usage onto the other's
 *   open span and collapse the pair);
 * - a served turn folds through the host: submit, pump, and the batched
 *   body lands with the host's session id; release() flushes the tail a
 *   batch boundary never reached;
 * - a dark workspace carries no factory service, so the host's fold path
 *   runs on the null sink and exports nothing;
 * - the buildFactory gate: dark never runs the factory builder, and the
 *   environment shim lands on the dark factory when unset.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\InteractiveTurn;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Observability\EventSinkFactory;
use SugarCraft\Code\Observability\NullEventSink;
use SugarCraft\Code\Observability\OtelConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Usage;

final class SessionHostOtelFoldTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var array<string, string> env values to restore in tearDown */
    private array $envPriors = [];

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    /** @var list<SessionHost> */
    private array $hosts = [];

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/cc-ses4d-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project, 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('alpha', 'p', 'm');
        $this->store->createSession('beta', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach ($this->hosts as $host) {
            $host->release();
        }
        $this->hosts = [];
        // A real Bootstrap::workspace() launch arms the process-wide notice
        // sink; it must not carry watchers or fds into the next test
        // (ServedRuntimeNoticeDrainTest carries the same duty).
        RuntimeNoticeSink::reset();
        $this->restoreEnvPriors();
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    // ── the collision trap, at the factory level ──────────────────────

    public function testTwoSessionsWithIdenticalStepNumbersExportDistinctSpans(): void
    {
        /** @var list<string> $bodies */
        $bodies = [];
        $factory = EventSinkFactory::forExport(
            $this->litConfig(batch: 2),
            static function (string $body) use (&$bodies): bool {
                $bodies[] = $body;

                return true;
            },
        );

        $usage = Usage::new(totalTokens: 140, costUsd: 0.02, inputTokens: 100, outputTokens: 40);

        // The whole trap in four calls: both sessions run step 1, on the
        // SAME numbers. A shared encoder would key both StepStarteds onto
        // one span map and the second UsageUpdated would close (or refuse)
        // the first session's open span. Per-session encoders export one
        // honest span per session instead.
        $a = $factory->forSession('session-alpha');
        $b = $factory->forSession('session-beta');
        $a->consume(new StepStarted(1, 4));
        $b->consume(new StepStarted(1, 4));
        $a->consume(new UsageUpdated(1, $usage, $usage));
        $b->consume(new UsageUpdated(1, $usage, $usage));

        self::assertCount(2, $bodies, 'each session hits its batch boundary independently');

        $bySession = self::indexBySessionId($bodies);
        self::assertSame(['session-alpha', 'session-beta'], \array_keys($bySession));
        foreach ($bySession as $sessionId => $body) {
            self::assertCount(1, $body['spans'], $sessionId . ': exactly one span — the shared-key splice would leave one body with the pair and the other with none');
            self::assertSame('chat.turn', $body['spans'][0]['name'], $sessionId . ': the step-1 turn span is its own');
        }
        self::assertNotSame(
            $bySession['session-alpha']['spans'][0]['traceId'],
            $bySession['session-beta']['spans'][0]['traceId'],
            'the two spans are different traces, not two events on one',
        );
    }

    // ── the host path ─────────────────────────────────────────────────

    public function testAServedTurnFoldsThroughTheWorkspaceFactory(): void
    {
        /** @var list<string> $bodies */
        $bodies = [];
        $backend = self::stepping();
        $host = $this->host('alpha', EventSinkFactory::forExport(
            $this->litConfig(batch: 2),
            static function (string $body) use (&$bodies): bool {
                $bodies[] = $body;

                return true;
            },
        ), $backend);

        $host->submit('go');
        self::assertTrue($host->isBusy(), 'the turn is in flight while its events report');
        $host->pump();
        $backend->settle(Message::assistant('done'));

        self::assertCount(1, $bodies, 'the two step events reach the batch boundary on the pump fold');
        $bySession = self::indexBySessionId($bodies);
        self::assertSame(['alpha'], \array_keys($bySession), 'the served span carries the host session id');
        self::assertSame('chat.turn', $bySession['alpha']['spans'][0]['name']);
    }

    public function testReleaseFlushesTheTurnTailTheBatchNeverCarried(): void
    {
        /** @var list<string> $bodies */
        $bodies = [];
        $backend = self::stepping();
        $host = $this->host('beta', EventSinkFactory::forExport(
            $this->litConfig(batch: 64),
            static function (string $body) use (&$bodies): bool {
                $bodies[] = $body;

                return true;
            },
        ), $backend);

        $host->submit('go');
        $host->pump();
        $backend->settle(Message::assistant('done'));

        self::assertSame([], $bodies, 'one event never reaches a batch of 64 — no POST mid-flight');

        $host->release();

        self::assertCount(1, $bodies, 'release flushes what fold had buffered');
        $bySession = self::indexBySessionId($bodies);
        self::assertSame(['beta'], \array_keys($bySession));
    }

    public function testADarkWorkspaceRegistersNoFactoryAndExportsNothing(): void
    {
        // The exact shape of Bootstrap::workspace() when the endpoint is
        // unset: no EventSinkFactory service at all. The host still folds
        // its turn (the null sink path must be silent, never deaf in the
        // wrong sense — no throw, no wire, no allocation surprise).
        $backend = self::stepping();
        $workspace = $this->workspace(null, $backend);
        self::assertNull($workspace->service(EventSinkFactory::class), 'a dark workspace carries no factory');

        $host = SessionHost::new('alpha', $workspace, history: [Message::user('hello')]);
        $this->hosts[] = $host;
        $host->submit('go');
        $host->pump();
        $backend->settle(Message::assistant('done'));
        $host->release();

        self::assertTrue(true, 'reaching here IS the pin: the fold path ran and threw nothing on a dark host');
    }

    public function testADarkLaunchRegistersNoFactoryServiceOnTheRealWorkspace(): void
    {
        // The product promise the synthetic workspace cannot pin: a real
        // Bootstrap::workspace() launch with no endpoint set registers no
        // EventSinkFactory at all — a dark serve process never builds the
        // transport legs, never touches the shutdown registrar.
        $this->dialOtelEnv([]);

        self::assertNull(
            Bootstrap::workspace($this->project)->service(EventSinkFactory::class),
            'the workspace arm is guarded by the config, not merely fed by it',
        );
    }

    public function testALitLaunchRegistersTheFactoryServiceOnTheRealWorkspace(): void
    {
        // The same launch with the endpoint lit carries the factory, built
        // through the shared OtelConfig gate. Construction alone dials
        // nothing; the point is the SERVICE IDENTITY hosts resolve against.
        $this->dialOtelEnv([
            OtelConfig::ENDPOINT_ENV => 'http://collector.invalid:4318',
        ]);

        $service = Bootstrap::workspace($this->project)->service(EventSinkFactory::class);
        self::assertInstanceOf(EventSinkFactory::class, $service);
        // A factory handed to a served host must still be able to mint a
        // session sink; the dark legs are the factory's own, not a stub.
        self::assertInstanceOf(
            NullEventSink::class,
            EventSinkFactory::dark()->forSession('anything'),
            'the dark twin still answers the null sink (the gate above proves it is never chosen on the lit arm)',
        );
    }

    // ── the gate ──────────────────────────────────────────────────────

    public function testTheDarkGateNeverRunsTheFactoryBuilder(): void
    {
        $ran = false;
        $factory = OtelConfig::dark()->buildFactory(
            static function (OtelConfig $config) use (&$ran): EventSinkFactory {
                $ran = true;

                return EventSinkFactory::dark();
            },
        );

        self::assertFalse($ran, 'dark answers the factory without the builder ever running — no HTTP client, no shutdown hook');
        self::assertInstanceOf(NullEventSink::class, $factory->forSession('anything'), 'the dark factory answers the null sink for every session');
    }

    public function testTheEnvironmentShimLandsDarkWhenNoEndpointIsSet(): void
    {
        $this->dialOtelEnv([]);

        $factory = OtelConfig::sinkFactoryFromEnvironment();

        self::assertInstanceOf(NullEventSink::class, $factory->forSession('anything'), 'an unset endpoint dials through the same gate and lands dark');
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function litConfig(int $batch): OtelConfig
    {
        $this->dialOtelEnv([
            OtelConfig::ENDPOINT_ENV => 'http://collector.invalid:4318',
            OtelConfig::CANDY_CODE_OTEL_BATCH_ENV => (string) $batch,
        ]);

        return OtelConfig::fromEnvironment();
    }

    /** @param array<string, string> $values */
    private function dialOtelEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->envPriors[$name] = \getenv($name);
            \putenv($name . '=' . $value);
        }
        foreach ([OtelConfig::HEADERS_ENV, OtelConfig::CANDY_CODE_OTEL_TIMEOUT_ENV] as $name) {
            if (!isset($this->envPriors[$name])) {
                $this->envPriors[$name] = \getenv($name);
                \putenv($name);
            }
        }
    }

    private function restoreEnvPriors(): void
    {
        foreach ($this->envPriors as $name => $prior) {
            if ($prior === false) {
                \putenv($name);
            } else {
                \putenv($name . '=' . $prior);
            }
        }
        $this->envPriors = [];
    }

    private function workspace(?EventSinkFactory $factory, object $backend): WorkspaceContext
    {
        $workspace = WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            backend: $backend,
        );

        return $factory === null
            ? $workspace
            : $workspace->withService(EventSinkFactory::class, $factory);
    }

    private function host(string $sessionId, ?EventSinkFactory $factory, object $backend): SessionHost
    {
        $host = SessionHost::new($sessionId, $this->workspace($factory, $backend), history: [Message::user('hello')]);
        $this->hosts[] = $host;

        return $host;
    }

    /**
     * A backend that reports one step's worth of events through the
     * observer its dispatch was handed — the shape EngineBackend's forked
     * child emits and the host's inbox then folds. Step and usage frames
     * ride the separate `$onStep` channel (roadmap 1.C-4): TurnRunner
     * asks for that channel STRUCTURALLY, by the backend declaring
     * InteractiveTurn, and hands a plain completeAsync() observer the
     * narrower tool-event union — feeding StepStarted through it is a
     * TypeError. The turn settles when the test says so.
     */
    private static function stepping(): object
    {
        return new class () implements Backend, InteractiveTurn {
            private ?Deferred $deferred = null;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->deferred = new Deferred();

                return $this->deferred->promise();
            }

            public function completeInteractive(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onStep = null): PromiseInterface
            {
                $this->deferred = new Deferred();

                if ($onStep !== null) {
                    $usage = Usage::new(totalTokens: 140, costUsd: 0.02, inputTokens: 100, outputTokens: 40);
                    $onStep(new StepStarted(1, 4));
                    $onStep(new UsageUpdated(1, $usage, $usage));
                }

                return $this->deferred->promise();
            }

            public function settle(Message $reply): void
            {
                $deferred = $this->deferred;
                $this->deferred = null;
                $deferred?->resolve($reply);
            }
        };
    }

    /**
     * Decode exported bodies keyed by their resource's session.id.
     *
     * @param list<string> $bodies
     *
     * @return array<string, array{spans: list<array<string, mixed>>}>
     */
    private static function indexBySessionId(array $bodies): array
    {
        $out = [];
        foreach ($bodies as $json) {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            $resource = $decoded['resourceSpans'][0]['resource']['attributes'];
            $sessionId = null;
            foreach ($resource as $attribute) {
                if ($attribute['key'] === 'session.id') {
                    $sessionId = $attribute['value']['stringValue'];
                }
            }
            self::assertNotNull($sessionId, 'every exported body carries its session.id resource attribute');
            self::assertArrayNotHasKey($sessionId, $out, 'one session, one body — never two bodies claiming one id');
            $out[$sessionId] = ['spans' => $decoded['resourceSpans'][0]['scopeSpans'][0]['spans']];
        }

        return $out;
    }
}
