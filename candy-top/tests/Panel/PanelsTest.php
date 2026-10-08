<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Panel\PlaceholderPanel;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Collect\PosixProcessControl;
use SugarCraft\Top\Source\Fake\FakeProcessControl;
use SugarCraft\Top\Source\Fake\FakeCpu;
use SugarCraft\Top\Source\Fake\FakeMemory;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

final class PanelsTest extends TestCase
{
    public function testStandardRosterCoversTheFourBoxesAndTheGpuPanel(): void
    {
        foreach ([true, false] as $fake) {
            $panels = Panels::standard(Harness::host(), Config::new(), $fake);
            // `gpu` draws every gpuN box (btop PR #1730), registered once.
            $this->assertSame(['cpu', 'gpu', 'mem', 'net', 'proc'], array_keys($panels));
            foreach ($panels as $box => $panel) {
                $this->assertSame($box, $panel->box());
            }
        }
    }

    public function testOnlyTheLiveRosterCanSignalRealProcesses(): void
    {
        $fake = Panels::standard(Harness::host(), Config::new(), true)['proc'];
        $this->assertInstanceOf(ProcPanel::class, $fake);
        $this->assertInstanceOf(FakeProcessControl::class, $fake->processControl(), '--fake pids are invented');
        $live = Panels::standard(Harness::host(), Config::new(), false)['proc'];
        $this->assertInstanceOf(ProcPanel::class, $live);
        $this->assertInstanceOf(PosixProcessControl::class, $live->processControl());
        $this->assertInstanceOf(FakeProcessControl::class, ProcPanel::new(FakeMemory::new())->processControl(), 'the default is inert');
    }

    public function testTheProcBoxCarriesItsOwnGpuSource(): void
    {
        // #1552 Gpu%/GMem: --fake joins the demo pids, live samples the host's accelerators.
        $fake = Panels::standard(Harness::host(), Config::new(), true)['proc'];
        $this->assertInstanceOf(ProcPanel::class, $fake);
        $this->assertInstanceOf(\SugarCraft\Top\Source\Fake\FakeGpuProcesses::class, $fake->gpuSource());
        $live = Panels::standard(Harness::host(), Config::new(), false)['proc'];
        $this->assertInstanceOf(ProcPanel::class, $live);
        $this->assertNotNull($live->gpuSource());
        $this->assertNull(ProcPanel::new(FakeMemory::new())->gpuSource(), 'no GPU source unless wired');
    }

    public function testCollectSamplesOffTheUpdatePathAndUpdateStoresIt(): void
    {
        $panel = PlaceholderPanel::new('cpu', FakeCpu::new(2));
        $this->assertNull($panel->snapshot());

        $msg = ($panel->collect(new PanelContext(Config::new())))();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertSame('cpu', $msg->box);

        $result = $panel->update($msg, new PanelContext(Config::new()));
        $next = $result->panel;
        $this->assertNull($result->cmd);
        $this->assertSame([], $result->set, 'a placeholder never writes config');
        $this->assertInstanceOf(CpuSnapshot::class, $next->snapshot());
        $this->assertNull($panel->snapshot(), 'update is immutable');

        // The next collect samples the advanced source.
        $this->assertNotEquals($msg->snapshot, ($next->collect(new PanelContext(Config::new())))()->snapshot);
    }

    public function testForeignSamplesAndOtherMsgsAreIgnored(): void
    {
        $panel = PlaceholderPanel::new('cpu', FakeCpu::new(2));
        [$snap, $src] = FakeMemory::new()->sample();
        $same = $panel->update(new SampledMsg('mem', $snap, $src), new PanelContext(Config::new()))->panel;
        $this->assertSame($panel, $same);
        $same = $panel->update(new FocusGainedMsg(), new PanelContext(Config::new()))->panel;
        $this->assertSame($panel, $same);
    }

    public function testPaintStaysInsideItsBox(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(80, 24, $config, 8);
        $ink = Ink::new(ThemeConfig::new());
        foreach (Panels::standard(Harness::host(), $config, true) as $box => $panel) {
            if ($box === 'gpu') {
                continue; // no gpu box in the default layout: GpuPanelTest covers its clipping
            }
            $panel = $panel->update(($panel->collect(new PanelContext($config, $layout, $layout->box($box))))(), new PanelContext($config, $layout, $layout->box($box)))->panel;
            $rect = $layout->box($box);
            $this->assertNotNull($rect);
            $surface = Surface::new(80, 24);
            $panel->paint($surface->region($rect), new PanelFrame($layout, $rect, $ink, FrameBuilder::border($config), $config, Harness::host()));

            $outside = 0;
            foreach ($surface->plainLines() as $y => $row) {
                foreach (mb_str_split($row) as $x => $ch) {
                    if ($ch !== ' ' && !$rect->contains($x, $y)) {
                        $outside++;
                    }
                }
            }
            $this->assertSame(0, $outside, "$box painted outside its box");
            $this->assertNotSame(str_repeat(' ', 80), $surface->plainLines()[$rect->y + 1], "$box painted something");
        }
    }

    public function testPaintBeforeTheFirstSampleIsANoOp(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(80, 24, $config, 8);
        $surface = Surface::new(80, 24);
        $rect = Rect::new(0, 0, 80, 8);
        PlaceholderPanel::new('cpu', FakeCpu::new())->paint(
            $surface->region($rect),
            new PanelFrame($layout, $rect, Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, Harness::host()),
        );
        $this->assertSame(array_fill(0, 24, str_repeat(' ', 80)), $surface->plainLines());
    }

    public function testPanelContextExposesTheCurrentGeometry(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $proc = $layout->box('proc');
        $this->assertNotNull($proc);

        $ctx = new PanelContext($config, $layout, $proc);
        $this->assertTrue($ctx->visible());
        $this->assertSame($layout->procSelectMax, $ctx->procSelectMax());
        $this->assertGreaterThan(0, $ctx->procSelectMax());
        // Mouse cells are 1-based; the box is 0-based.
        $this->assertTrue($ctx->hit($proc->x + 1, $proc->y + 1));
        $this->assertFalse($ctx->hit($proc->x, $proc->y + 1));
        $this->assertFalse($ctx->hit($proc->right() + 1, $proc->y + 1));

        $hidden = new PanelContext($config);
        $this->assertFalse($hidden->visible());
        $this->assertSame(0, $hidden->procSelectMax());
        $this->assertFalse($hidden->hit(1, 1));
    }

    public function testPanelFrameLocal(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $box = $layout->box('net');
        $this->assertNotNull($box);
        $frame = new PanelFrame($layout, $box, Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, Harness::host());
        $local = $frame->local($layout->netStats ?? Rect::new(0, 0, 0, 0));
        $this->assertSame([26, 2], [$local->x, $local->y]);
        $this->assertFalse($frame->tty());
    }
}
