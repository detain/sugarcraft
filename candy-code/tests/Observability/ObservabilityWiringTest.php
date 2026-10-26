<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Observability;

use PHPUnit\Framework\TestCase;

/**
 * OBS-1 wiring pins over the live source: the two Chat fold sites exist
 * with their three consume points (settle queue, pump head, coalesced run),
 * the production construction threads the sink, the build seam is dark by
 * default with a bounded shutdown flush, and the whole feature still owes
 * zero open-telemetry packages.
 *
 * These are CHAT-path pins by design: the sink is wired at the single
 * `Bootstrap::chat()` construction site, and `serve` runs no Chat (its
 * sessions live on `Host\SessionHost`, which carries no sink), so a serve
 * process exports nothing today — the gap is the OBS-02 row of
 * COMPARISON.md and wiring it is an owner-gated product decision. Nothing
 * here asserts serve coverage; if serve ever grows a sink, that wiring
 * needs its own pin, not a rewording of these.
 */
final class ObservabilityWiringTest extends TestCase
{
    private const CHAT_SOURCE = __DIR__ . '/../../src/Chat.php';
    private const BOOTSTRAP_SOURCE = __DIR__ . '/../../src/Cli/Bootstrap.php';
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
        $this->assertStringContainsString('eventSink: $eventSink,', $bootstrap, 'chat() threads the built sink — the ONLY wiring site: serve builds no Chat at all and exports nothing (OBS-02 in COMPARISON.md)');
        $seam = self::methodBody($bootstrap, 'public static function observabilitySink');
        $this->assertStringContainsString('OtelConfig::sinkFromEnvironment(', $seam, 'the production seam runs through the single OtelConfig gate');
        $this->assertSame(0, substr_count($seam, 'exportsEvents'), 'the seam holds no second copy of the dark decision — F2 unification');
        $this->assertStringContainsString('register_shutdown_function', $seam);
        $this->assertStringContainsString('$sink->flush();', $seam, 'bounded shutdown flush — an exit-time HTTP POST the request timeout caps, not a wall-clock kill (E646)');
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
