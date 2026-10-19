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
 */
final class ObservabilityWiringTest extends TestCase
{
    private const CHAT_SOURCE = __DIR__ . '/../../src/Chat.php';
    private const BOOTSTRAP_SOURCE = __DIR__ . '/../../src/Cli/Bootstrap.php';
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
        $this->assertStringContainsString('eventSink: $eventSink,', $bootstrap, 'chat() threads the built sink into the single funnel serve shares');
        $seam = self::methodBody($bootstrap, 'public static function observabilitySink');
        $this->assertStringContainsString('return new \\SugarCraft\\Code\\Observability\\NullEventSink();', $seam);
        $this->assertStringContainsString('register_shutdown_function', $seam);
        $this->assertStringContainsString('$sink->flush();', $seam, 'bounded shutdown flush — an exit-time HTTP POST the request timeout caps, not a wall-clock kill (E646)');
        $this->assertLessThan(
            strpos($seam, 'register_shutdown_function'),
            strpos($seam, 'if (!$config->exportsEvents())'),
            'the dark branch returns before any client or registration exists',
        );
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
