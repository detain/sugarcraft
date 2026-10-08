<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Cpu;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Collect\Battery;
use SugarCraft\Top\Collect\BatterySnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Cpu\BorderBattery;
use SugarCraft\Top\Panel\CpuPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeBattery;
use SugarCraft\Top\Source\Fake\FakeCpu;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Tests\Panel\Gfx\ScriptedSource;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

final class BorderBatteryTest extends TestCase
{
    private static function snap(int $percent, string $status = 'discharging', int $seconds = 10440, float $watts = 12.5): BatterySnapshot
    {
        return new BatterySnapshot('BAT0', $percent, $status, $seconds, $watts);
    }

    /** @param list<BatterySnapshot> $snaps */
    private static function fed(array $snaps, ?Config $config = null): BorderBattery
    {
        $config ??= Config::new();
        $badge = BorderBattery::new(new ScriptedSource($snaps));
        $ctx = new PanelContext($config);
        foreach ($snaps as $_) {
            $source = $badge->source($ctx);
            self::assertNotNull($source);
            [$snapshot, $next] = $source->sample();
            $badge = $badge->withSample($snapshot, $next, $ctx);
        }

        return $badge;
    }

    /** The cpu panel's top (or bottom) border row as plain text and with SGR. */
    private static function border(CpuPanel $panel, Config $config, int $cols, int $rows, bool $sgr = false, bool $tty = false): string
    {
        $host = PanelPaint::host();
        $layout = PanelPaint::layout($cols, $rows, $config, $host);
        $surface = $tty
            ? PanelPaint::surface($panel, $config, $layout, $host, TtyTheme::new(), ColorProfile::Ansi)
            : PanelPaint::surface($panel, $config, $layout, $host);
        $rect = $layout->box('cpu');
        $y = $layout->cpuBottom ? $rect->bottom() - 1 : $rect->y;

        return ($sgr ? $surface->lines() : $surface->plainLines())[$y] . "\n";
    }

    private static function cpu(Config $config, int $cols, int $rows, int $n = 4): CpuPanel
    {
        $layout = PanelPaint::layout($cols, $rows, $config, PanelPaint::host());
        $panel = PanelPaint::feed(CpuPanel::new(FakeCpu::new(8))->withBattery(BorderBattery::standard($config, true)), $config, $layout, $n);
        self::assertInstanceOf(CpuPanel::class, $panel);

        return $panel;
    }

    /** One cpu sample at 120x40 (CpuView paints nothing before one); the badge's scripted source repeats. */
    private static function sampled(CpuPanel $panel): CpuPanel
    {
        $config = Config::new();
        $panel = PanelPaint::feed($panel, $config, PanelPaint::layout(120, 40, $config, PanelPaint::host()), 1);
        self::assertInstanceOf(CpuPanel::class, $panel);

        return $panel;
    }

    // ---- sampling ---------------------------------------------------------

    public function testStandardRosterInstallsTheBadge(): void
    {
        foreach ([true, false] as $fake) {
            $panel = Panels::standard(PanelPaint::host(), Config::new(), $fake)['cpu'];
            $this->assertInstanceOf(CpuPanel::class, $panel);
            $this->assertInstanceOf(BorderBattery::class, $panel->battery());
        }
    }

    public function testSourceFollowsShowBatteryAndSelectedBattery(): void
    {
        $live = BorderBattery::standard(Config::new()->with('selected_battery', 'BAT1'));
        $this->assertNull($live->source(new PanelContext(Config::new()->with('show_battery', false))));
        $source = $live->source(new PanelContext(Config::new()->with('selected_battery', 'CMB0')));
        $this->assertInstanceOf(CollectorSource::class, $source);
        $collector = $source->collector();
        $this->assertInstanceOf(Battery::class, $collector);
        $this->assertSame('CMB0', $collector->selected(), 'read from the CURRENT config');
        $this->assertNull($live->source(new PanelContext(Config::new()))->collector()->selected(), 'Auto');
        $this->assertInstanceOf(FakeBattery::class, BorderBattery::standard(Config::new(), true)->source(new PanelContext(Config::new())));
        $plain = new ScriptedSource([self::snap(50)]);
        $this->assertSame($plain, BorderBattery::new($plain)->source(new PanelContext(Config::new())));
    }

    public function testPresenceFollowsTheSnapshotAndShowBattery(): void
    {
        $this->assertFalse(BorderBattery::new(FakeBattery::new())->present(Config::new()), 'before a sample');
        $badge = self::fed([self::snap(50)]);
        $this->assertTrue($badge->present(Config::new()));
        $this->assertFalse($badge->present(Config::new()->with('show_battery', false)));
        $gone = self::fed([self::snap(50), BatterySnapshot::absent()]);
        $this->assertFalse($gone->present(Config::new()), 'an absent battery hides the badge (btop has_battery)');
        $this->assertNull($gone->last());
        $kept = $badge->withSample(new \stdClass(), new ScriptedSource([self::snap(1)]), new PanelContext(Config::new()));
        $this->assertSame(50, $kept->last()?->percent, 'a foreign snapshot keeps the reading');
    }

    /** #1008: UNMEASURED time/watts keep the last value while the status holds. */
    public function testUnmeasuredTimeAndWattsHoldWhileStatusUnchanged(): void
    {
        $held = self::fed([self::snap(60, 'discharging', 3600, 9.5), self::snap(59, 'discharging', Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED)])->last();
        $this->assertSame([59, 3600, 9.5], [$held?->percent, $held?->seconds, $held?->watts]);
        $changed = self::fed([self::snap(60, 'discharging', 3600, 9.5), self::snap(60, 'charging', Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED)])->last();
        $this->assertSame([Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED], [$changed?->seconds, $changed?->watts]);
    }

    // ---- paint ------------------------------------------------------------

    public function testBadgeTextPositionAndSymbols(): void
    {
        $config = Config::new();
        $row = self::border(self::cpu($config, 120, 40), $config, 120, 40);
        // FakeBattery after 4 samples: 86 %, 172 min, btop len = 11 + 5 + 3 + 6 + 4 = 29 -> col 120 - 29 - 18.
        $this->assertSame('┐BAT▼ 86% ■■■■■■■■■■ 02:52 ', mb_substr($row, 73, 27));
        $this->assertMatchesRegularExpression('/ \d+\.\d\dW┌- 2000ms \+┌─╮$/u', rtrim($row));

        $narrow = self::border(self::cpu($config, 90, 30), $config, 90, 30);
        $this->assertStringContainsString('┐BAT▼ 86% 02:52 ', $narrow, 'no meter below 100 columns');
        $noWatts = self::border(self::cpu($config->with('show_battery_watts', false), 120, 40), $config->with('show_battery_watts', false), 120, 40);
        $this->assertStringContainsString('02:52┌', $noWatts);
        $off = $config->with('show_battery', false);
        $this->assertStringNotContainsString('BAT', self::border(self::cpu($off, 120, 40), $off, 120, 40));

        foreach (['charging' => '▲', 'full' => '■', 'discharging' => '▼', 'unknown' => '○', 'not charging' => '○'] as $status => $symbol) {
            $panel = self::sampled(CpuPanel::new(FakeCpu::new(8))->withBattery(self::fed([self::snap(100, $status, 0, Sentinel::UNMEASURED)])));
            $row = self::border($panel, $config, 120, 40);
            $this->assertStringContainsString('┐BAT' . $symbol . ' 100% ■■■■■■■■■■┌', $row, $status);
        }
        // Days use sec_to_dhms without seconds: `1d 02:00`.
        $long = self::sampled(CpuPanel::new(FakeCpu::new(8))->withBattery(self::fed([self::snap(99, 'discharging', 93600 + 15, 3.0)])));
        $this->assertStringContainsString(' 1d 02:00 3.00W┌', self::border($long, $config, 120, 40));
    }

    public function testCpuBottomMovesTheBadgeToTheBottomBorder(): void
    {
        $config = Config::new()->with('cpu_bottom', true);
        $row = self::border(self::cpu($config, 120, 40), $config, 120, 40);
        $this->assertStringContainsString('┘BAT▼ 86% ', $row);
        $this->assertStringContainsString('└- 2000ms', $row);
    }

    /** @return iterable<string, array{int, int, array<string, bool|string>}> */
    public static function goldens(): iterable
    {
        yield 'top-120x40' => [120, 40, []];
        yield 'top-90x30' => [90, 30, []];
        yield 'bottom-150x40-500ms' => [150, 40, ['cpu_bottom' => true, 'update_ms' => '500']];
    }

    /** @param array<string, bool|string> $options */
    #[DataProvider('goldens')]
    public function testBorderGolden(int $cols, int $rows, array $options): void
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $k === 'update_ms' ? $config->withUpdateMs((int) $v) : $config->with($k, $v);
        }
        PanelPaint::assertGolden($this, 'battery', $this->dataName() . '.txt', self::border(self::cpu($config, $cols, $rows), $config, $cols, $rows));
    }

    public function testSgrGolden(): void
    {
        $config = Config::new();
        PanelPaint::assertGolden($this, 'battery', 'top-120x40.sgr.txt', self::border(self::cpu($config, 120, 40), $config, 120, 40, true));
    }

    public function testTtySgrGolden(): void
    {
        $tty = Config::new()->with('tty_mode', true);
        $sgr = self::border(self::cpu($tty, 120, 40), $tty, 120, 40, true, true);
        $this->assertStringNotContainsString('38;2;', $sgr);
        PanelPaint::assertGolden($this, 'battery', 'top-tty-120x40.sgr.txt', $sgr);
    }

    public function testTinyRegionsNeverThrowOrSpill(): void
    {
        $badge = self::fed([self::snap(42)]);
        $config = Config::new();
        $ink = Ink::new(ThemeConfig::new());
        foreach ([[1, 1], [2, 2], [3, 2], [10, 3], [40, 5], [120, 2]] as [$w, $h]) {
            foreach ([false, true] as $bottom) {
                $rect = Rect::new(0, 0, $w, $h);
                $surface = Surface::new(120, 10);
                $layout = new Layout(120, 10, ['cpu' => $rect], cpuBottom: $bottom);
                $badge->paint($surface->region($rect), new PanelFrame($layout, $rect, $ink, FrameBuilder::border($config), $config, PanelPaint::host()));
                foreach ($surface->plainLines() as $y => $line) {
                    if ($y >= $h) {
                        $this->assertSame(str_repeat(' ', 120), $line, "{$w}x{$h} spilled");
                    } elseif ($w > 1) {
                        $this->assertSame(' ', mb_substr($line, 0, 1), "{$w}x{$h} hit the corner");
                        $this->assertSame(' ', mb_substr($line, $w - 1, 1), "{$w}x{$h} hit the corner");
                    }
                }
            }
        }
    }

    // ---- App clock reserve (ClockReserve) ----------------------------------

    /** @return array{0: App, 1: string} the sampled app and its painted cpu top row */
    private static function app(Config $config, int $cols): array
    {
        $host = Harness::host();
        $panels = Panels::placeholders($host, $config, true);
        $panels['cpu'] = CpuPanel::standard($host, $config, true);
        $app = App::start($config, ThemeConfig::new(), $host, $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg($cols, 40));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
        foreach (Cmds::of(SampledMsg::class, $app->init()) as $msg) {
            [$app] = $app->update($msg);
        }

        return [$app, $app->surface()?->plainLines()[0] ?? ''];
    }

    public function testAppShrinksTheClockForThePresentBattery(): void
    {
        // 54 cells of clock: under budget 140 - 66 = 74 without the reserve, cut to 52 with it.
        $config = Config::new()->with('clock_format', str_repeat('%X ', 6) . '%X');
        $text = str_repeat('17:13:20 ', 6) . '17:13:20';
        $budget = static fn (string $row): int => mb_strlen((string) preg_replace('/^.*┐(17:13:20[^┌]*)┌.*$/u', '$1', $row));

        [$app, $row] = self::app($config, 140);
        $this->assertTrue($app->clockReserved());
        $this->assertSame(FrameBuilder::clockBudget(140, 140, true), $budget($row));
        // btop's 22-cell reserve does not cover a 52-cell clock: the clock
        // (painted last, as btop's per-second update_clock) overlaps the
        // badge's head, exactly as in btop.
        $this->assertStringContainsString(' 87% ■■■■■■■■■■ ', $row);

        [$app, $row] = self::app($config->with('show_battery', false), 140);
        $this->assertFalse($app->clockReserved());
        $this->assertSame(mb_strlen($text), $budget($row));

        // Below 100 columns btop takes no reserve even with a battery.
        [$app, $row] = self::app($config, 99);
        $this->assertTrue($app->clockReserved());
        $this->assertSame(FrameBuilder::clockBudget(99, 99, false), $budget($row));

        // The placeholder roster (no ClockReserve panel) never reserves.
        $this->assertFalse(Harness::running(140, 40, $config)->clockReserved());
    }
}
