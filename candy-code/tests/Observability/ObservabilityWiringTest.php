<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;

/**
 * OBS-1 wiring pins over the live source: the two Chat fold sites exist
 * with their three consume points (settle queue, pump head, coalesced run),
 * the production construction threads the sink, the headless path dials
 * the same seam from its event observer (C-SES-4.c), the build seam is
 * dark by default with a bounded shutdown flush, and the whole feature
 * still owes zero open-telemetry packages.
 *
 * The sink now has THREE wiring paths. `Bootstrap::chat()` threads the
 * built sink into Chat (the TUI and every host that builds a Chat), and
 * `NonInteractive::run()` dials `Bootstrap::observabilitySink()`
 * statically and consumes every `$onEvent` event — pinned structurally
 * here and behaviourally by `tests/Cli/NonInteractiveOtelExportTest.php`.
 * `serve` folds through `Host\SessionHost::fold()` into a per-session
 * sink the workspace's `EventSinkFactory` hands out (owner ruling §6.1,
 * C-SES-4.d) — pinned structurally here and behaviourally, including the
 * cross-session step-collision trap, by
 * `tests/Host/SessionHostOtelFoldTest.php`.
 */
final class ObservabilityWiringTest extends TestCase
{
    private const CHAT_SOURCE = __DIR__ . '/../../src/Chat.php';
    private const BOOTSTRAP_SOURCE = __DIR__ . '/../../src/Cli/Bootstrap.php';
    private const NON_INTERACTIVE_SOURCE = __DIR__ . '/../../src/Cli/NonInteractive.php';
    private const OTEL_CONFIG_SOURCE = __DIR__ . '/../../src/Observability/OtelConfig.php';
    private const MANIFEST = __DIR__ . '/../../composer.json';

    public function testChatFoldsAtExactlyTheTwoSettlingDoorsThreeConsumesInTotal(): void
    {
        $source = self::read(self::CHAT_SOURCE);

        $this->assertSame(3, substr_count($source, '$this->eventSink->consume('), 'settle queue, pump head, coalesced run — one per door, the pump run needs all three deltas folded');

        $settle = self::methodBody($source, 'private function applyBackendToolEvent');
        $pump = self::methodBody($source, 'private function pumpLiveToolEvents');
        $this->assertStringContainsString('$this->eventSink->consume($event);', $settle);
        $this->assertSame(2, substr_count($pump, '$this->eventSink->consume('), 'the pump folds its head and each coalesced run member');
        $this->assertStringContainsString('$generation === $this->generation || $event instanceof \\SugarCraft\\Code\\Events\\PermissionResolved', $pump, 'the head gate mirrors the arms: settlement by askId, everything else by live generation');
    }

    public function testTheProductionConstructionThreadsTheSinkAndDefaultsDark(): void
    {
        $chat = self::read(self::CHAT_SOURCE);
        $this->assertStringContainsString('private readonly EventSink $eventSink = new NullEventSink(),', $chat, 'a Chat nobody configures is dark by the parameter default');

        $bootstrap = self::read(self::BOOTSTRAP_SOURCE);
        $this->assertStringContainsString('eventSink: $eventSink,', $bootstrap, 'chat() threads the built sink — one of the three wiring paths (TUI chat, headless -p, serve host); serve coverage is pinned by tests/Host/SessionHostOtelFoldTest.php');
        $seam = self::methodBody($bootstrap, 'public static function observabilitySink');
        $this->assertStringContainsString('OtelConfig::sinkFromEnvironment(', $seam, 'the production seam runs through the single OtelConfig gate');
        $this->assertSame(0, substr_count($seam, 'exportsEvents'), 'the seam holds no second copy of the dark decision — F2 unification');
        $this->assertStringContainsString('register_shutdown_function', $seam);
        $this->assertStringContainsString('$sink->flush();', $seam, 'bounded shutdown flush — an exit-time HTTP POST the request timeout caps, not a wall-clock kill (E646)');
    }

    public function testTheHeadlessPathDialsTheSameSeamAndFoldsItsObserver(): void
    {
        // C-SES-4.c: `-p` carries no Chat, so it dials the static seam
        // itself. The behavioural half (a lit run really posts a
        // chat.turn span) lives in tests/Cli/NonInteractiveOtelExportTest.php;
        // this pins that the fold sits INSIDE the observer the backend is
        // handed, so no event path can bypass it.
        $headless = self::read(self::NON_INTERACTIVE_SOURCE);
        $run = self::methodBody($headless, 'public static function run(');

        $this->assertStringContainsString('Bootstrap::observabilitySink();', $run, 'the one-shot path dials the single production seam, no second OtelConfig call');
        $this->assertSame(1, substr_count($run, 'Bootstrap::observabilitySink();'), 'dialled once per run — the seam hands each run its own fresh batch sink');
        $observerStart = strpos($run, '$onEvent = static function (object $event) use (');
        $this->assertIsInt($observerStart, 'the observer the backend is handed is no longer there to pin');
        // methodBody()'s "\n    }" terminator cannot cut out a closure: the
        // $onEvent body ends at an 8-space `};`. Slice to its own terminator.
        $observerEnd = strpos($run, "\n        };", $observerStart);
        $this->assertIsInt($observerEnd, 'unterminated $onEvent observer');
        $observer = substr($run, $observerStart, $observerEnd - $observerStart);
        $this->assertStringContainsString('$eventSink', $observer, 'the sink rides the observer closure, not a side channel');
        $this->assertStringContainsString('$eventSink->consume($event);', $observer, 'every event the backend emits folds into the sink');

        $complete = strpos($run, '$backend->complete(');
        $this->assertIsInt($complete);
        $this->assertLessThan($complete, strpos($run, '$eventSink->consume($event);'), 'the sink is dialled before the backend call that feeds it');
    }

    public function testTheDarkDecisionLivesOnceInTheConfigGate(): void
    {
        $config = self::read(self::OTEL_CONFIG_SOURCE);

        $gate = self::methodBody($config, 'public function buildSink');
        $this->assertLessThan(
            strpos($gate, '$exporterFactory($this)'),
            strpos($gate, 'if (!$this->exportsEvents())'),
            'the dark branch returns the null sink before the factory can run',
        );

        $shim = self::methodBody($config, 'public static function sinkFromEnvironment');
        $this->assertSame(0, substr_count($shim, 'exportsEvents'), 'the env shim delegates to the gate instead of re-checking it — one path, no double-lock drift');
    }

    public function testNoOpenTelemetryPackageJoinsTheManifest(): void
    {
        $manifest = self::read(self::MANIFEST);

        $this->assertStringNotContainsString('open-telemetry', $manifest);
        $this->assertStringNotContainsString('grpc', strtolower($manifest));
    }

    public function testTheEventLogChannelStaysUntouchedByTheSink(): void
    {
        // The fold sites are Chat-only (the plan's decision): EventLog — the
        // second one-consumer channel — must not reference Observability.
        $eventLog = self::read(__DIR__ . '/../../src/Host/EventLog.php');

        $this->assertStringNotContainsString('Observability', $eventLog);
        $this->assertStringNotContainsString('eventSink', $eventLog);
    }

    public function testTheServePathFoldsThroughAPerSessionSink(): void
    {
        // C-SES-4.d (owner ruling §6.1): serve sessions live on
        // Host\SessionHost, which now feeds a per-session sink handed out
        // by the workspace's EventSinkFactory. Structural half; the
        // behavioural half — two sessions, identical step numbers, two
        // distinct session.id spans — is tests/Host/SessionHostOtelFoldTest.php.
        $host = self::read(__DIR__ . '/../../src/Host/SessionHost.php');

        $fold = self::methodBody($host, 'private function fold(?CancellationToken $turn, object $event): void');
        $this->assertStringContainsString('$this->otelSink()->consume($event);', $fold, 'fold() is the single serve choke and it feeds the sink FIRST, before any early-return arm');

        $release = self::methodBody($host, 'public function release(): void');
        $this->assertStringContainsString('$this->otelSink?->flush();', $release, 'the host flushes its sink beside the transcript — but consults the property, so a host that folded nothing never mints a sink to flush');

        $bootstrap = self::read(self::BOOTSTRAP_SOURCE);
        $workspace = self::methodBody($bootstrap, 'public static function workspace(');
        $this->assertStringContainsString('withService(', $workspace);
        $this->assertStringContainsString('$otelConfig->buildFactory(', $workspace, 'the registration walks the single OtelConfig gate, never a second endpoint read');
        $guard = \strpos($workspace, 'if ($otelConfig->exportsEvents())');
        $this->assertIsInt($guard, 'the lit-only guard around the registration is present — without it a dark workspace would register the factory service anyway');
        $serviceAt = \strpos($workspace, '\\SugarCraft\\Code\\Observability\\EventSinkFactory::class');
        $this->assertIsInt($serviceAt, 'the workspace names the EventSinkFactory service');
        $this->assertGreaterThan(
            $guard,
            $serviceAt,
            'the service registration sits INSIDE the lit branch — a dark workspace carries no EventSinkFactory (behavioural twin: SessionHostOtelFoldTest::testADarkLaunchRegistersNoFactoryServiceOnTheRealWorkspace)',
        );
    }

    private static function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "source file unreadable: {$path}");

        return $source;
    }

    private static function methodBody(string $source, string $declaration): string
    {
        $start = strpos($source, $declaration);
        self::assertIsInt($start, "missing declaration: {$declaration}");

        $next = strpos($source, "\n    }", $start);
        self::assertIsInt($next, "unterminated body for {$declaration}");

        return substr($source, $start, $next - $start);
    }
}
