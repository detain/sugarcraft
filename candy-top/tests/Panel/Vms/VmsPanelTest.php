<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Vms;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\VmFleet;
use SugarCraft\Top\Collect\VmFleetSnapshot;
use SugarCraft\Top\Collect\VmGuest;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Vms\VmCard;
use SugarCraft\Top\Panel\Vms\VmsGrid;
use SugarCraft\Top\Panel\Vms\VmsView;
use SugarCraft\Top\Panel\VmsPanel;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeVms;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\BorderFlow;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * The VM dashboard panel: sampling only while shown, keys, mouse, Enter's
 * hand-off to the proc box, and the paint goldens under
 * tests/fixtures/panels/vms/ (regenerate with CANDY_TOP_UPDATE_GOLDENS=1
 * vendor/bin/phpunit tests/Panel/Vms/VmsPanelTest.php, one golden per test).
 */
final class VmsPanelTest extends TestCase
{
    private const BOXES = 'cpu mem net proc vms';

    private const WEB01 = '/machine.slice/machine-qemu\x2d1\x2dweb01.scope';

    public function testHiddenBoxNeverSamples(): void
    {
        $panel = VmsPanel::standard(true);
        $config = Config::new();
        $this->assertNull($panel->collect(new PanelContext($config)));
        $this->assertNotNull($panel->collect(new PanelContext($config->with('shown_boxes', self::BOXES))));
    }

    public function testTheLiveSourceIsRetunedWithAMaxWindow(): void
    {
        $panel = VmsPanel::new(CollectorSource::of(VmFleet::disabled()));
        $cmd = $panel->collect(new PanelContext(Config::new()->with('shown_boxes', 'vms')));
        $this->assertNotNull($cmd);
        $msg = $cmd();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertSame('vms', $msg->box);
        $this->assertInstanceOf(VmFleetSnapshot::class, $msg->snapshot);
        $this->assertSame(0, $msg->snapshot->count(), 'FreeBSD / disabled: empty fleet');
        $bsd = VmsPanel::standard(false, Platform::for('FreeBSD'));
        $this->assertInstanceOf(VmsPanel::class, $bsd);
    }

    public function testSamplesFillHistoriesAndAVanishedGuestDropsThem(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40, 3);
        $this->assertSame(7, $panel->snapshot()?->count());
        $this->assertCount(3, $panel->history()->series(VmCard::key(self::WEB01, 'cpu')));

        $only = new VmFleetSnapshot([new VmGuest('/x', 'x', cpu: 5.0)]);
        $next = $panel->update(new SampledMsg('vms', $only, FakeVms::new()), $ctx)->panel;
        $this->assertInstanceOf(VmsPanel::class, $next);
        $this->assertSame([], $next->history()->series(VmCard::key(self::WEB01, 'cpu')));
        $this->assertSame([5], $next->history()->series(VmCard::key('/x', 'cpu')));

        $fresh = $next->update(new SampledMsg('vms', new VmFleetSnapshot([new VmGuest('/x', 'x', cpu: 7.0)], baseline: true), FakeVms::new()), $ctx)->panel;
        $this->assertInstanceOf(VmsPanel::class, $fresh);
        $this->assertSame([7], $fresh->history()->series(VmCard::key('/x', 'cpu')), 'a baseline sample restarts every history');
        $this->assertSame($panel, $panel->update(new SampledMsg('proc', $only, FakeVms::new()), $ctx)->panel, 'another box\'s sample');
    }

    public function testArrowsWalkTheGrid(): void
    {
        [$panel, $ctx] = $this->fleet(120, 40, 55);
        $ordered = $panel->ordered($ctx->config);
        $path = static fn (int $i): string => $ordered[$i]->path;
        $press = static function (VmsPanel $p, KeyType $type) use ($ctx): VmsPanel {
            $next = $p->update(new KeyMsg($type), $ctx)->panel;
            self::assertInstanceOf(VmsPanel::class, $next);

            return $next;
        };
        $p = $press($panel, KeyType::Right);
        $this->assertSame($path(0), $p->selected(), 'the first move picks the first visible card');
        $p = $press($p, KeyType::Right);
        $this->assertSame($path(1), $p->selected());
        $p = $press($p, KeyType::Down);
        $this->assertSame($path(4), $p->selected(), 'down = one row (3 columns)');
        $p = $press($p, KeyType::Left);
        $this->assertSame($path(3), $p->selected());
        $p = $press($p, KeyType::Up);
        $this->assertSame($path(0), $p->selected());
        $p = $press($p, KeyType::Up);
        $this->assertSame($path(0), $p->selected(), 'top row stays');
        $p = $press($p, KeyType::PageDown);
        $this->assertSame($path(12), $p->selected(), 'a page = 12 cards (3 columns x 4 rows)');
        $this->assertSame(1, $p->start(), 'scrolled to keep it in view');
        $p = $press($p, KeyType::End);
        $this->assertSame($path(54), $p->selected());
        $this->assertSame(15, $p->start());
        $p = $press($p, KeyType::Home);
        $this->assertSame($path(0), $p->selected());
        $this->assertSame(0, $p->start());
        $p = $press($press($p, KeyType::End), KeyType::Down);
        $this->assertSame($path(54), $p->selected(), 'last row stays');
        $p = $press($press($press($p, KeyType::Home), KeyType::Right), KeyType::Down);
        $this->assertSame($path(4), $p->selected());
        // From row 17 (cards 51-53) down lands on the lone last card.
        $p = $press($press($press($p, KeyType::End), KeyType::Up), KeyType::Down);
        $this->assertSame($path(54), $p->selected());
    }

    public function testSortKeysWriteTheOption(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40, 2);
        $this->assertTrue($panel->capturesKey(new KeyMsg(KeyType::Char, 's'), $ctx));
        $this->assertSame(['vms_sorting' => 'mem'], $panel->update(new KeyMsg(KeyType::Char, 's'), $ctx)->set);
        $this->assertSame(['vms_sorting' => 'name'], $panel->update(new KeyMsg(KeyType::Char, 'S'), $ctx)->set);
        $byName = new PanelContext($ctx->config->with('vms_sorting', 'name'), $ctx->layout, $ctx->box);
        $this->assertSame(['backup', 'build-runner', 'db-primary'], array_map(static fn (VmGuest $g): string => $g->name, \array_slice($panel->ordered($byName->config), 0, 3)));
        $this->assertSame('build-runner', $panel->ordered($ctx->config)[0]->name, 'cpu first by default');
    }

    public function testEnterLeavesForTheProcBoxFilteredToTheGuest(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40, 2);
        $this->assertSame([], $panel->update(new KeyMsg(KeyType::Enter), $ctx)->set, 'nothing selected');
        $selected = $this->select($panel, $ctx, self::WEB01);
        $set = $selected->update(new KeyMsg(KeyType::Enter), $ctx)->set;
        $this->assertSame([
            'shown_boxes' => 'cpu mem net proc ctr',
            GpuPanels::SLOTS_KEY => '',
            Schema::CTR_SELECTED => self::WEB01,
        ], $set);
        // Applied in order, as the App does: the pick survives (ctr is shown).
        $config = $ctx->config;
        foreach ($set as $k => $v) {
            $config = $config->with($k, $v);
        }
        $this->assertSame(self::WEB01, $config->string(Schema::CTR_SELECTED));
        $this->assertSame(['cpu', 'gpu0', 'mem', 'proc', 'ctr'], explode(' ', VmsPanel::openSet(Config::new()->with('shown_boxes', 'cpu gpu0 mem vms'), '/p')['shown_boxes']), 'gpu boxes and their slots kept');
    }

    public function testKeysNeedTheBoxAndClaimOnlyWhatActs(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40, 2);
        $hidden = new PanelContext($ctx->config);
        $this->assertFalse($panel->capturesKey(new KeyMsg(KeyType::Down), $hidden));
        $this->assertSame($panel, $panel->update(new KeyMsg(KeyType::Down), $hidden)->panel);
        $this->assertTrue($panel->capturesKey(new KeyMsg(KeyType::Down), $ctx));
        $this->assertFalse($panel->capturesKey(new KeyMsg(KeyType::Char, 'q'), $ctx));
        $this->assertSame($panel, $panel->update(new KeyMsg(KeyType::Char, 'w'), $ctx)->panel, 'an unbound key changes nothing');
        $empty = VmsPanel::new(FakeVms::new())->update(new SampledMsg('vms', new VmFleetSnapshot([]), FakeVms::new()), $ctx)->panel;
        $this->assertFalse($empty->capturesKey(new KeyMsg(KeyType::Down), $ctx), 'nothing to walk');
    }

    public function testMouse(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40, 2);
        $box = $ctx->box;
        $this->assertNotNull($box);
        $ordered = $panel->ordered($ctx->config);
        $map = VmsView::buttons($box, $ordered, '', 0, 'cpu');
        $this->assertArrayHasKey('vms_card6', $map);
        $this->assertArrayNotHasKey('vms_card7', $map, 'seven guests, seven zones');
        // The sort button: `sort ‹ cpu ›` right-aligned, halves = S / s.
        $this->assertSame($box->y, $map['vms_sort_prev'][1]);
        $this->assertSame($box->x + $box->width - 2, $map['vms_sort_next'][0] + $map['vms_sort_next'][2]);
        $this->assertSame(['vms_sorting' => 'mem'], $panel->update(self::click($map['vms_sort_next'][0], $box->y), $ctx)->set);
        $this->assertSame(['vms_sorting' => 'name'], $panel->update(self::click($map['vms_sort_prev'][0], $box->y), $ctx)->set);

        [$x, $y] = $map['vms_card4'];
        $this->assertTrue($panel->capturesClick(self::click($x + 3, $y + 2), $ctx));
        $clicked = $panel->update(self::click($x + 3, $y + 2), $ctx)->panel;
        $this->assertInstanceOf(VmsPanel::class, $clicked);
        $this->assertSame($ordered[4]->path, $clicked->selected());
        $this->assertSame(Schema::CTR_SELECTED, array_key_last($clicked->update(self::click($x + 3, $y + 2), $ctx)->set), 'a click on the selected card opens it');
        $gap = $map['vms_card0'][0] + $map['vms_card0'][2];
        $this->assertFalse($panel->capturesClick(self::click($gap, $map['vms_card0'][1] + 2), $ctx), 'the gap between cards');
        $this->assertFalse($panel->capturesClick(new MouseMsg($x + 4, $y + 3, MouseButton::Right, MouseAction::Press), $ctx));

        // The wheel moves the selection a row, or scrolls when there is none.
        $wheel = static fn (MouseButton $b): MouseMsg => new MouseMsg($x + 4, $y + 3, $b, MouseAction::Press);
        $up = $clicked->update($wheel(MouseButton::WheelUp), $ctx)->panel;
        $this->assertInstanceOf(VmsPanel::class, $up);
        $this->assertSame($ordered[1]->path, $up->selected());
        [$many, $mctx] = $this->fleet(120, 40, 55);
        $scrolled = $many->update($wheel(MouseButton::WheelDown), $mctx)->panel;
        $this->assertInstanceOf(VmsPanel::class, $scrolled);
        $this->assertSame(1, $scrolled->start());
        $this->assertSame('', $scrolled->selected());
        $this->assertSame($many, $many->update(new MouseMsg(1, 1, MouseButton::WheelDown, MouseAction::Press), $mctx)->panel, 'outside the box');
    }

    public function testAVanishedSelectionIsCleared(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40, 2);
        $selected = $this->select($panel, $ctx, self::WEB01);
        $next = $selected->update(new SampledMsg('vms', new VmFleetSnapshot([new VmGuest('/x', 'x')]), FakeVms::new()), $ctx)->panel;
        $this->assertInstanceOf(VmsPanel::class, $next);
        $this->assertSame('', $next->selected());
        $resized = $selected->update(new WindowSizeMsg(120, 40), $ctx)->panel;
        $this->assertSame(self::WEB01, $resized instanceof VmsPanel ? $resized->selected() : null);
    }

    public function testPaintStaysInsideItsBoxAtEverySize(): void
    {
        [$panel] = $this->fleet(120, 40, 55);
        foreach ([[2, 2], [3, 3], [10, 4], [36, 8], [40, 7], [60, 20], [119, 30], [250, 80]] as [$w, $h]) {
            $ink = Ink::new(ThemeConfig::new());
            $surface = Surface::new($w + 4, $h + 4, $ink->base());
            $rect = Rect::new(2, 2, $w, $h);
            $layout = new Layout($w + 4, $h + 4, ['vms' => $rect]);
            $panel->paint($surface->region($rect), new PanelFrame($layout, $rect, $ink, FrameBuilder::border(Config::new()), Config::new(), PanelPaint::host()));
            $lines = $surface->plainLines();
            foreach ([0, 1, $h + 2, $h + 3] as $row) {
                $this->assertSame(str_repeat(' ', $w + 4), $lines[$row], "{$w}x{$h}: row {$row} untouched");
            }
            $this->assertCount($h + 4, $lines);
        }
    }

    public function testFlowFollowsTheBorderFlowLaw(): void
    {
        $palette = ThemeConfig::fromString("theme[mem_box]=\"#000000\"\ntheme[mem_box_end]=\"#ffffff\"\n");
        $config = Config::new()->with('shown_boxes', self::BOXES);
        $layout = PanelPaint::layout(120, 40, $config, PanelPaint::host());
        [$panel] = $this->sampled(120, 40, 2);
        $rect = $layout->box('vms');
        $this->assertNotNull($rect);
        $surface = PanelPaint::surface($panel, $config, $layout, PanelPaint::host(), $palette);
        // BorderFlow's post-pass knows the `vms` box (family mem): one sweep over the whole dashboard.
        $this->assertSame(FrameBuilder::VMS_FAMILY, BorderFlow::family('vms', $layout));
        BorderFlow::paint($surface, $layout, Ink::new($palette), $config);
        $flow = $palette->boxFlow('mem');
        $this->assertNotNull($flow);
        $card = VmsGrid::for($rect->width, $rect->height, 7)->card(4);
        foreach ([[0, 0], [$rect->width - 1, $rect->height - 1], [$card->x, $card->y], [$card->right() - 1, $card->bottom() - 1]] as [$x, $y]) {
            $t = ($x / ($rect->width - 1) + $y / ($rect->height - 1)) / 2;
            $this->assertSame(Surface::canonical($flow->at($t)->toFg(ColorProfile::TrueColor)), $surface->style($rect->x + $x, $rect->y + $y), "cell {$x},{$y}");
        }
        // 256 colours: BorderFlow is off, the flat mem_box everywhere.
        $flat = PanelPaint::surface($panel, $config, $layout, PanelPaint::host(), $palette, ColorProfile::Ansi256);
        $this->assertFalse(BorderFlow::enabled(Ink::new($palette, ColorProfile::Ansi256), $config));
        BorderFlow::paint($flat, $layout, Ink::new($palette, ColorProfile::Ansi256), $config);
        $this->assertSame($flat->style($rect->x, $rect->y), $flat->style($rect->x + $card->x, $rect->y + $card->y));

        // A selected card keeps its hi_fg outline: the sweep only recolours the flat line colour.
        $ctx = new PanelContext($config, $layout, $rect);
        $chosen = $this->select($panel, $ctx, $panel->ordered($config)[4]->path);
        $lit = PanelPaint::surface($chosen, $config, $layout, PanelPaint::host(), $palette);
        BorderFlow::paint($lit, $layout, Ink::new($palette), $config);
        $this->assertSame(Surface::canonical(Ink::new($palette)->fg('hi_fg')), $lit->style($rect->x + $card->x, $rect->y + $card->y + 1));
    }

    public function testFleetGolden(): void
    {
        PanelPaint::assertGolden($this, 'vms', 'fleet-120x40.txt', $this->grid(120, 40));
    }

    public function testFleetSgrGolden(): void
    {
        PanelPaint::assertGolden($this, 'vms', 'fleet-120x40.sgr.txt', $this->grid(120, 40, sgr: true));
    }

    public function testTallCardsGolden(): void
    {
        PanelPaint::assertGolden($this, 'vms', 'tall-200x60.txt', $this->grid(200, 60));
    }

    public function testSelectedPagedGolden(): void
    {
        [$panel, $ctx] = $this->fleet(120, 40, 55);
        $panel = $this->select($panel, $ctx, $panel->ordered($ctx->config)[20]->path);
        $surface = PanelPaint::surface($panel, $ctx->config, $ctx->layout ?? throw new \LogicException(), PanelPaint::host());
        $grid = PanelPaint::grid($surface, $ctx->box ?? throw new \LogicException());
        $this->assertStringContainsString('10-21/55', $grid, 'card 21 is on row 6: rows 3-6 show');
        PanelPaint::assertGolden($this, 'vms', 'paged-120x40.txt', $grid);
    }

    public function testTtySgrGolden(): void
    {
        $sgr = $this->grid(80, 24, sgr: true, options: ['tty_mode' => true, 'rounded_corners' => false], tty: true);
        $this->assertDoesNotMatchRegularExpression('/\e\[[0-9;]*[34]8;2;/', $sgr, 'no truecolor in tty mode');
        $this->assertStringContainsString('vvms', (string) preg_replace('/\e\[[0-9;]*m/', '', $sgr), 'tty title: plain v');
        PanelPaint::assertGolden($this, 'vms', 'tty-80x24.sgr.txt', $sgr);
    }

    public function testEmptyHostGolden(): void
    {
        $config = Config::new()->with('shown_boxes', 'cpu vms');
        $layout = PanelPaint::layout(100, 30, $config, PanelPaint::host());
        $panel = PanelPaint::feed(VmsPanel::new(self::scripted(new VmFleetSnapshot([], 8 * 1024 ** 3))), $config, $layout, 1);
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, PanelPaint::host()), $layout->box('vms') ?? Rect::new(0, 0, 0, 0));
        $this->assertStringContainsString('No virtual machines found', $grid);
        $this->assertStringContainsString('0 VMs', $grid);
        PanelPaint::assertGolden($this, 'vms', 'empty-100x30.txt', $grid);
    }

    /** @return array{0: VmsPanel, 1: PanelContext} */
    private function sampled(int $cols, int $rows, int $n, string $boxes = self::BOXES): array
    {
        $config = Config::new()->with('shown_boxes', $boxes);
        $layout = PanelPaint::layout($cols, $rows, $config, PanelPaint::host());
        $panel = PanelPaint::feed(VmsPanel::standard(true), $config, $layout, $n);
        $this->assertInstanceOf(VmsPanel::class, $panel);

        return [$panel, new PanelContext($config, $layout, $layout->box('vms'))];
    }

    /**
     * A kvm521-sized fleet: `$n` guests with spread figures.
     *
     * @return array{0: VmsPanel, 1: PanelContext}
     */
    private function fleet(int $cols, int $rows, int $n): array
    {
        $guests = [];
        for ($i = 0; $i < $n; $i++) {
            $guests[] = new VmGuest(
                '/machine.slice/machine-qemu\x2d' . ($i + 1) . '\x2dvps' . (3368300 + $i) . '.scope',
                'vps' . (3368300 + $i),
                $i + 1,
                1 + $i % 4,
                (1 + $i % 4) * 1024 ** 3,
                (float) (($i * 37) % 101),
                (int) ((1 + $i % 4) * 1024 ** 3 * (($i * 13) % 100) / 100),
                (float) ($i * 1000),
                (float) ($i * 500),
                (float) ($i * 2048),
                (float) ($i * 256),
                (float) ($i % 11 === 0 ? 14 : 0),
                0.0,
                0.0,
            );
        }
        $snap = new VmFleetSnapshot($guests, 377 * 1024 ** 3);
        $config = Config::new()->with('shown_boxes', self::BOXES);
        $layout = PanelPaint::layout($cols, $rows, $config, PanelPaint::host());
        $panel = PanelPaint::feed(VmsPanel::new(self::scripted($snap)), $config, $layout, 2);
        $this->assertInstanceOf(VmsPanel::class, $panel);

        return [$panel, new PanelContext($config, $layout, $layout->box('vms'))];
    }

    private function select(VmsPanel $panel, PanelContext $ctx, string $path): VmsPanel
    {
        $ordered = $panel->ordered($ctx->config);
        $target = VmsView::indexOf($ordered, $path);
        $this->assertGreaterThanOrEqual(0, $target);
        $p = $panel->update(new KeyMsg(KeyType::Home), $ctx)->panel;
        $p = $p->update(new KeyMsg(KeyType::Home), $ctx)->panel;
        for ($i = 0; $i < $target; $i++) {
            $p = $p->update(new KeyMsg(KeyType::Right), $ctx)->panel;
        }
        $this->assertInstanceOf(VmsPanel::class, $p);
        $this->assertSame($path, $p->selected());

        return $p;
    }

    /** @param array<string, bool|int|string> $options */
    private function grid(int $cols, int $rows, bool $sgr = false, array $options = [], bool $tty = false): string
    {
        $config = Config::new()->with('shown_boxes', self::BOXES);
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }
        $layout = PanelPaint::layout($cols, $rows, $config, PanelPaint::host());
        $panel = PanelPaint::feed(VmsPanel::standard(true), $config, $layout, 12);
        $surface = PanelPaint::surface($panel, $config, $layout, PanelPaint::host(), $tty ? TtyTheme::new() : null, $tty ? ColorProfile::Ansi : ColorProfile::TrueColor);
        $rect = $layout->box('vms');
        $this->assertNotNull($rect);

        return $sgr ? PanelPaint::sgr($surface, $rect) : PanelPaint::grid($surface, $rect);
    }

    /** A source answering `$snapshot` on every sample. */
    private static function scripted(VmFleetSnapshot $snapshot): Source
    {
        return new class ($snapshot) implements Source {
            public function __construct(private readonly VmFleetSnapshot $snapshot)
            {
            }

            public function sample(): array
            {
                return [$this->snapshot, $this];
            }
        };
    }

    private static function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x + 1, $y + 1, MouseButton::Left, MouseAction::Press);
    }
}
