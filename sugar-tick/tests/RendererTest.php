<?php

declare(strict_types=1);

namespace CandyCore\Tick\Tests;

use CandyCore\Tick\Dashboard;
use CandyCore\Tick\Heartbeat;
use CandyCore\Tick\Renderer;
use CandyCore\Tick\Stats;
use CandyCore\Tick\Store;
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
        $this->assertStringContainsString('SugarTick', $out);
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
}
