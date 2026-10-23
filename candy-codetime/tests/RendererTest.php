<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use SugarCraft\CodeTime\Dashboard;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Renderer;
use SugarCraft\CodeTime\Stats;
use SugarCraft\CodeTime\Store;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function dashboard(array $beats = [], int $days = 7): Dashboard
    {
        $end = new \DateTimeImmutable('2024-06-07');
        $from = $end->modify("-" . ($days - 1) . " days");
        $stats = Stats::compute($beats, $from, $end);
        return new Dashboard(new Store(sys_get_temp_dir()), $end, $days, $stats);
    }

    public function testRendersHeaderWithTitleAndRange(): void
    {
        $out = Renderer::render($this->dashboard());
        $this->assertStringContainsString('CandyCodetime', $out);
        $this->assertStringContainsString('Jun 1', $out);
        $this->assertStringContainsString('Jun 7', $out);
    }

    public function testEmptyDashboardShowsNoActivityCopy(): void
    {
        $out = Renderer::render($this->dashboard());
        $this->assertStringContainsString('no activity', $out);
    }

    public function testHelpFooterMentionsKeys(): void
    {
        $out = Renderer::render($this->dashboard());
        $this->assertStringContainsString('reload', $out);
        $this->assertStringContainsString('quit', $out);
    }

    public function testRenderShowsProjectAndLanguageRanking(): void
    {
        $beats = [
            new Heartbeat((new \DateTimeImmutable('2024-06-07'))->getTimestamp(), 'demo', 'php', '', 600),
            new Heartbeat((new \DateTimeImmutable('2024-06-07'))->getTimestamp(), 'demo', 'js',  '', 60),
        ];
        $out = Renderer::render($this->dashboard($beats));
        $this->assertStringContainsString('demo', $out);
        $this->assertStringContainsString('php', $out);
        $this->assertStringContainsString('js', $out);
    }

    public function testRenderHasTimelineSection(): void
    {
        $out = Renderer::render($this->dashboard());
        $this->assertStringContainsString('Daily activity', $out);
    }

    public function testControlBytesStrippedFromNames(): void
    {
        // Mirrors Renderer::ranking() sanitization of untrusted heartbeat strings
        // Project name with BEL control char that survives Ansi::strip (not a CSI sequence)
        $beats = [
            new Heartbeat(
                (new \DateTimeImmutable('2024-06-07'))->getTimestamp(),
                "x\x07y",  // BEL control char - not a CSI sequence, survives Ansi::strip
                "clean",
                "file.php",
                600,
            ),
        ];
        $out = Renderer::render($this->dashboard($beats));

        // BEL should be stripped by the regex pass, leaving just "xy"
        $this->assertStringNotContainsString("\x07", $out);
        // Project name should appear as "xy" not "x y" or with embedded control chars
        $this->assertStringContainsString('xy', $out);
    }
    public function testNarrowTerminalDoesNotCrashTheTimeline(): void
    {
        // Pre-fix, width 2 drove the sparkline budget negative and Sparkline
        // threw out of view() — a terminal resize could kill the TUI.
        $beats = [new Heartbeat(
            time: (new \DateTimeImmutable('2024-06-07'))->getTimestamp(),
            project: 'p', language: 'php', file: 'a.php', duration: 600,
        )];
        $end = new \DateTimeImmutable('2024-06-07');
        $stats = Stats::compute($beats, $end->modify('-6 days'), $end);
        $d = new \SugarCraft\CodeTime\Dashboard(new Store(sys_get_temp_dir()), $end, 7, $stats, 2);

        $out = Renderer::render($d);
        $this->assertStringContainsString('Daily activity', $out);
    }

    public function testWideTerminalsStretchTheTimeline(): void
    {
        $d = $this->dashboard([new Heartbeat(
            time: (new \DateTimeImmutable('2024-06-07'))->getTimestamp(),
            project: 'p', language: 'php', file: 'a.php', duration: 600,
        )]);
        $narrow = Renderer::render(new \SugarCraft\CodeTime\Dashboard(
            new Store(sys_get_temp_dir()), new \DateTimeImmutable('2024-06-07'), 7, $d->stats, 60));
        $wide = Renderer::render(new \SugarCraft\CodeTime\Dashboard(
            new Store(sys_get_temp_dir()), new \DateTimeImmutable('2024-06-07'), 7, $d->stats, 120));
        $this->assertNotSame($narrow, $wide, 'timeline must follow the terminal width');
    }

    public function testRankingDisclosesProjectsHiddenByTheSixRowCap(): void
    {
        $end = new \DateTimeImmutable('2024-06-07');
        $beats = [];
        for ($i = 1; $i <= 9; $i++) {
            $beats[] = new Heartbeat(
                time: $end->getTimestamp(), project: "proj{$i}",
                language: 'php', file: "p{$i}.php", duration: 60 * $i,
            );
        }
        $out = Renderer::render($this->dashboard($beats));
        $this->assertStringContainsString('+3 more', $out);
    }

    public function testNoColorDropsEveryHexTint(): void
    {
        $d = $this->dashboard([new Heartbeat(
            time: (new \DateTimeImmutable('2024-06-07'))->getTimestamp(),
            project: 'p', language: 'php', file: 'a.php', duration: 600,
        )]);

        $previous = getenv('NO_COLOR');
        try {
            putenv('NO_COLOR');
            $colored = Renderer::render($d);
            $this->assertMatchesRegularExpression('/\x1b\[38/', $colored, 'tints ship by default');

            putenv('NO_COLOR=1');
            $plain = Renderer::render($d);
            $this->assertDoesNotMatchRegularExpression('/\x1b\[38/', $plain, 'NO_COLOR must strip every foreground tint');
            $this->assertStringContainsString('CandyCodetime', $plain);
        } finally {
            putenv($previous === false ? 'NO_COLOR' : "NO_COLOR={$previous}");
        }
    }
}
