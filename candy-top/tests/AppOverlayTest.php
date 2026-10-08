<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\OpenOverlayMsg;
use SugarCraft\Top\Msg\PaletteMsg;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Msg\UpdateStepMsg;
use SugarCraft\Top\Overlay\HelpMenu;
use SugarCraft\Top\Overlay\MainMenu;
use SugarCraft\Top\Overlay\Menus;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\Overlay\OptionsMenu;
use SugarCraft\Top\Overlay\Signals;
use SugarCraft\Top\Panel\Net\NetPanel;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Source\Fake\FakeProcessControl;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Tests\Support\ClaimingPanel;
use SugarCraft\Top\Tests\Support\ClickingPanel;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * Phase P-F1 App seams: the overlay stack above the modal tier, the
 * dimmed backdrop, the menu keys, the size-error box, the App-owned click
 * map, and the runtime theme reload.
 */
final class AppOverlayTest extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    private static function host(): HostInfo
    {
        return HostInfo::new('i7-8700K', 8, 'joe', 'box');
    }

    /** @param array<string, bool|int|string> $options */
    private static function config(array $options = []): Config
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }

        return $config->withShownBoxesSettled(0);
    }

    /**
     * @param ?array<string, Panel> $panels
     */
    private static function app(int $cols = 120, int $rows = 40, ?Config $config = null, ?array $panels = null, ?\Closure $themes = null): App
    {
        $config ??= self::config();
        $app = App::start(
            $config,
            ThemeConfig::new(),
            self::host(),
            $panels ?? Panels::placeholders(self::host(), $config, true),
            static fn (): ClockTickMsg => new ClockTickMsg(1_700_000_000.0, 60.0),
            ColorProfile::TrueColor,
            $themes,
        );
        [$app] = $app->update(new WindowSizeMsg($cols, $rows));

        return $app;
    }

    /** Sized and every shown panel sampled once. */
    private static function booted(App $app): App
    {
        foreach (Cmds::run($app->init()) as $msg) {
            if (!$msg instanceof TickRequest) {
                [$app] = $app->update($msg);
            }
        }

        return $app;
    }

    private static function key(App $app, string $name, bool $ctrl = false): App
    {
        return $app->update(self::keyMsg($name, $ctrl))[0];
    }

    private static function keyMsg(string $name, bool $ctrl = false): KeyMsg
    {
        return match ($name) {
            'escape' => new KeyMsg(KeyType::Escape),
            'enter' => new KeyMsg(KeyType::Enter),
            'down' => new KeyMsg(KeyType::Down),
            'f1' => new KeyMsg(KeyType::F1),
            'f2' => new KeyMsg(KeyType::F2),
            default => new KeyMsg(KeyType::Char, $name, ctrl: $ctrl),
        };
    }

    /** A bare left press on 0-based cell ($x, $y). */
    private static function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x + 1, $y + 1, MouseButton::Left, MouseAction::Press);
    }

    public function testMenuKeysOpenTheirMenus(): void
    {
        foreach (['escape' => MainMenu::class, 'm' => MainMenu::class, 'f1' => HelpMenu::class, '?' => HelpMenu::class, 'h' => HelpMenu::class, 'f2' => OptionsMenu::class, 'o' => OptionsMenu::class] as $key => $class) {
            $this->assertInstanceOf($class, self::key(self::app(), $key)->overlay(), $key);
        }
        $vim = self::app(config: self::config(['vim_keys' => true]));
        $this->assertNull(self::key($vim, 'h')->overlay(), 'with vim_keys h is left');
        $this->assertInstanceOf(HelpMenu::class, self::key($vim, 'H')->overlay());
        $this->assertNull(self::key(self::app(), 'H')->overlay());
    }

    public function testAnOpenMenuOwnsInputAboveTheModalTierButCtrlCStillQuits(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['proc'] = new ClaimingPanel('proc', [], modalWhen: 'proc_filtering');
        $app = self::app(panels: $panels);
        [$app] = $app->update(new SetOptionMsg('proc_filtering', true));
        [$app] = $app->update(new OpenOverlayMsg(Menus::help()));
        $seen = count($app->panel('proc')->seen);
        foreach (['x', '1', 'down'] as $k) {
            $app = self::key($app, $k);
        }
        [$app] = $app->update(self::click(10, 30));
        $this->assertCount($seen, $app->panel('proc')->seen, 'the modal panel saw nothing');
        $this->assertNull($app->overlay(), 'the click closed help');

        [$app] = $app->update(new OpenOverlayMsg(Menus::main()));
        [, $quit] = $app->update(self::keyMsg('c', true));
        $this->assertNotNull($quit);
        $this->assertInstanceOf(QuitMsg::class, $quit());
    }

    public function testQIsImmediateWithoutAMenuAndClosesOne(): void
    {
        [, $quit] = self::app()->update(self::keyMsg('q'));
        $this->assertInstanceOf(QuitMsg::class, $quit());
        [$app, $cmd] = self::key(self::app(), 'm')->update(self::keyMsg('q'));
        $this->assertNull($cmd, 'q in a menu closes it (btop)');
        $this->assertNull($app->overlay());
    }

    public function testStackingMainHelpAndBack(): void
    {
        $app = self::key(self::app(), 'm');
        $app = self::key($app, 'down');
        $app = self::key($app, 'enter');
        $this->assertInstanceOf(HelpMenu::class, $app->overlay());
        $this->assertSame(2, $app->overlays->count());
        $app = self::key($app, 'escape');
        $main = $app->overlay();
        $this->assertInstanceOf(MainMenu::class, $main, 'closing help uncovers the main menu');
        $this->assertSame(0, $main->selected);
        $app = self::key($app, 'escape');
        $this->assertNull($app->overlay());
        $this->assertTrue($app->overlays->isEmpty());
    }

    public function testTheFrameIsDimmedUnderTheMenu(): void
    {
        $app = self::booted(self::app());
        $plain = $app->surface();
        $this->assertNotNull($plain);
        $dimmed = self::key($app, 'm')->surface();
        $this->assertNotNull($dimmed);
        $inactive = Surface::canonical($app->ink->fg('inactive_fg'));
        // Row 0 (the cpu title) lies outside the menu: same glyphs, one dim style.
        $this->assertSame($plain->plainLines()[0], $dimmed->plainLines()[0]);
        for ($x = 0; $x < 120; $x++) {
            $this->assertSame($inactive, $dimmed->style($x, 0), "cell {$x}");
        }
        $this->assertNotSame($plain->plainLines()[17], $dimmed->plainLines()[17], 'the menu is drawn over it');
        $this->assertCount(40, $dimmed->lines());
    }

    public function testResizeWhileOpenRecentresAndTheSizeNoticeHidesIt(): void
    {
        $app = self::key(self::app(), 'f1');
        [$app] = $app->update(new WindowSizeMsg(100, 30));
        $this->assertInstanceOf(HelpMenu::class, $app->overlay());
        $s = $app->surface();
        $this->assertNotNull($s);
        $this->assertSame([100, 30], [$s->width, $s->height]);
        [$x, $y] = HelpMenu::geometry(100, 30);
        $this->assertSame('╭', $s->glyph($x - 1, $y + 5), 'the help box re-centred');

        [$tiny] = $app->update(new WindowSizeMsg(40, 10));
        $this->assertInstanceOf(HelpMenu::class, $tiny->overlay(), 'kept behind the size notice');
        $this->assertStringContainsString('Terminal size too small', implode("\n", $tiny->surface()?->plainLines() ?? []));
        [$tiny] = $tiny->update(self::keyMsg('x'));
        $this->assertInstanceOf(HelpMenu::class, $tiny->overlay(), 'behind the notice only q and 1-4 act');
        [$back] = $tiny->update(new WindowSizeMsg(120, 40));
        $this->assertInstanceOf(HelpMenu::class, $back->overlay());
    }

    public function testRefusedToggleOpensTheSizeErrorBox(): void
    {
        $app = self::app(70, 24, self::config(['shown_boxes' => 'cpu proc']));
        $app = self::key($app, '2');
        $this->assertSame(['cpu', 'proc'], $app->config->shownBoxes());
        $box = $app->overlay();
        $this->assertInstanceOf(MsgBox::class, $box);
        $this->assertSame('error', $box->title);
        $this->assertStringContainsString('Terminal size too small to', implode("\n", $app->surface()?->plainLines() ?? []));
        $app = self::key($app, 'enter');
        $this->assertNull($app->overlay());

        $tiny = self::app(40, 10, self::config(['shown_boxes' => 'cpu']));
        $this->assertNull(self::key($tiny, '2')->overlay(), 'behind the size notice btop just ignores the toggle');
    }

    public function testShownBoxesWritesGetTheSameFitCheck(): void
    {
        $app = self::app(70, 24, self::config(['shown_boxes' => 'cpu proc']));
        [$refused, $cmd] = $app->update(new SetOptionMsg('shown_boxes', 'cpu mem net proc'));
        $this->assertSame(['cpu', 'proc'], $refused->config->shownBoxes());
        $this->assertInstanceOf(MsgBox::class, $refused->overlay());
        $this->assertNull($cmd);

        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['net'] = new ClaimingPanel('net', [], sets: ['X' => static fn (): array => ['shown_boxes' => 'cpu mem net proc', 'proc_tree' => true]]);
        $app = self::app(70, 24, self::config(['shown_boxes' => 'cpu proc']), $panels);
        $app = self::key($app, 'X');
        $this->assertSame(['cpu', 'proc'], $app->config->shownBoxes(), 'the panel write is refused');
        $this->assertTrue($app->config->bool('proc_tree'), 'the rest of the set still applies');
        $this->assertInstanceOf(MsgBox::class, $app->overlay());

        [$ok] = self::app()->update(new SetOptionMsg('shown_boxes', 'cpu net'));
        $this->assertSame(['cpu', 'net'], $ok->config->shownBoxes());
        $this->assertNull($ok->overlay());
    }

    public function testARefusedWriteBehindTheSizeNoticeOpensNothing(): void
    {
        $tiny = self::app(40, 10, self::config(['shown_boxes' => 'cpu']));
        [$tiny] = $tiny->update(new SetOptionMsg('shown_boxes', 'cpu mem net proc'));
        $this->assertSame(['cpu'], $tiny->config->shownBoxes());
        $this->assertNull($tiny->overlay(), 'like a refused toggle there');
    }

    public function testBackgroundUpdateOffFreezesTheBackdrop(): void
    {
        $config = self::config(['background_update' => false]);
        $app = self::booted(self::app(config: $config));
        $this->assertTrue($app->freezesBackdrop());
        $menu = self::key($app, 'm');
        $before = $menu->surface()?->plainLines();
        [$later] = $menu->update(new ClockTickMsg(1_700_003_661.0, 60.0));
        $this->assertSame($before, $later->surface()?->plainLines(), 'the clock under the menu does not move');
        [$closed] = self::key($later, 'escape')->update(new ClockTickMsg(1_700_003_661.0, 60.0));
        $this->assertNotSame($before[0] ?? null, $closed->surface()?->plainLines()[0], 'closing the menu repaints live');

        [$resized] = $later->update(new WindowSizeMsg(100, 30));
        $this->assertCount(30, $resized->surface()?->plainLines() ?? [], 'a resize re-captures the backdrop');
        $this->assertStringContainsString(':14:21', $resized->surface()?->plainLines()[0] ?? '', 'with the state at that moment');

        $live = self::key(self::booted(self::app()), 'm');
        $this->assertFalse($live->freezesBackdrop());
        [$moved] = $live->update(new ClockTickMsg(1_700_003_661.0, 60.0));
        $this->assertNotSame($live->surface()?->plainLines()[0], $moved->surface()?->plainLines()[0], 'background_update on: live backdrop');
        $this->assertTrue(self::app(config: self::config(['tty_mode' => true]))->freezesBackdrop(), 'always frozen in tty mode');
    }

    public function testAMenuTooBigForTheTerminalShowsTheSizeError(): void
    {
        $app = self::app(70, 24, self::config(['shown_boxes' => 'cpu proc']));
        $app = self::key($app, 'm');
        $this->assertInstanceOf(MsgBox::class, $app->overlay(), 'btop: main/help/options need 80x24');
        $this->assertSame('error', $app->overlay()->title);
    }

    public function testPanelsRequestOverlaysThroughPanelResult(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['net'] = new ClickingPanel('net', null, [], Menus::help(), 'X');
        $app = self::key(self::app(panels: $panels), 'X');
        $this->assertInstanceOf(HelpMenu::class, $app->overlay());
    }

    public function testAClaimedClickReachesOnlyItsOwner(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['net'] = new ClickingPanel('net', Rect::new(3, 0, 4, 1));
        $panels['proc'] = new ClickingPanel('proc');
        $app = self::app(panels: $panels);
        $net = $app->context('net')->box;
        $this->assertNotNull($net);
        $n = count($app->panel('net')->seen);
        $p = count($app->panel('proc')->seen);
        [$app] = $app->update(self::click($net->x + 4, $net->y));
        $this->assertCount($n + 1, $app->panel('net')->seen);
        $this->assertCount($p, $app->panel('proc')->seen, 'a mapped click is not broadcast');
        [$app] = $app->update(self::click($net->x + 10, $net->y + 2));
        $this->assertCount($n + 2, $app->panel('net')->seen);
        $this->assertCount($p + 1, $app->panel('proc')->seen, 'an unmapped click still is');
        [$app] = $app->update(new MouseMsg($net->x + 5, $net->y + 1, MouseButton::WheelUp, MouseAction::Press));
        $this->assertCount($p + 2, $app->panel('proc')->seen, 'wheel events are never claimed');
    }

    public function testClickingANetButtonKeepsTheProcSelection(): void
    {
        $config = self::config();
        $app = self::booted(self::app(config: $config, panels: Panels::standard(self::host(), $config, true)));
        $app = self::key($app, 'down');
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame(1, $proc->selection()->selected);
        $net = $app->panel('net');
        $this->assertInstanceOf(NetPanel::class, $net);
        $box = $app->context('net')->box;
        $this->assertNotNull($box);
        $hit = null;
        for ($x = $box->x; $x < $box->right() && $hit === null; $x++) {
            if ($net->capturesClick(self::click($x, $box->y), $app->context('net'))) {
                $hit = $x;
            }
        }
        $this->assertNotNull($hit, 'the net box paints buttons');
        [$app] = $app->update(self::click($hit, $box->y));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame(1, $proc->selection()->selected, 'btop: a mapped click never clears the proc selection');
        [$app] = $app->update(self::click($box->x + 3, $box->y + 3));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame(0, $proc->selection()->selected, 'an unmapped click still does');
    }

    public function testCpuTitleButtons(): void
    {
        $app = self::booted(self::app());
        $map = $app->chromeButtons();
        $this->assertSame(['m', 'p', 'x', '-', '+'], array_keys($map));
        $this->assertSame([27, 0, 5, 1], $map['x'], 'btop PR #1873 {button_y, x + 27, 1, 5}');
        [$ctr] = $app->update(self::click(28, 0));
        $this->assertContains('ctr', $ctr->config->shownBoxes(), 'the x ctr button toggles the containers box');
        // btop: m at x + 11 (4 wide), - at x + width - len("2000ms") - 7, + at x + width - 5.
        $this->assertSame([11, 0, 4, 1], $map['m']);
        $this->assertSame([17, 0, 8, 1], $map['p'], 'btop {button_y, x + 17, 1, 8}');
        $this->assertSame([120 - 6 - 7, 0, 2, 1], $map['-']);
        $this->assertSame([115, 0, 2, 1], $map['+']);
        [$menu] = $app->update(self::click(12, 0));
        $this->assertInstanceOf(MainMenu::class, $menu->overlay());
        [$menu] = $menu->update(self::keyMsg('m'));
        $this->assertNull($menu->overlay());
        for ($i = 0; $i < App::HOLD_HISTORY; $i++) {
            [$app] = $app->update(self::click(116, 0));
        }
        [, $cmd] = $app->update(self::click(116, 0));
        $this->assertTrue(Cmds::of(UpdateStepMsg::class, $cmd)[0]->held, 'clicks on + enter the key history as `+` (btop mouse_mappings)');
        [, $cmd] = $app->update(self::click(116, 0));
        $steps = Cmds::of(UpdateStepMsg::class, $cmd);
        $this->assertCount(1, $steps);
        $this->assertSame(1, $steps[0]->direction);
        [, $cmd] = $app->update(self::click(108, 0));
        $this->assertSame(-1, Cmds::of(UpdateStepMsg::class, $cmd)[0]->direction);
        [$closed] = self::key($app, 'm')->update(self::click(12, 0));
        $this->assertNull($closed->overlay(), 'a click outside the main menu closes it instead');
    }

    public function testSignalFlowEndToEnd(): void
    {
        $config = self::config();
        $panels = Panels::standard(self::host(), $config, true);
        $panels['proc'] = ProcPanel::new(FakeProcList::demo(8), FakeProcessControl::new(Signals::EPERM));
        $app = self::booted(self::app(config: $config, panels: $panels));
        $app = self::key($app, 'down');
        $app = self::key($app, 't');
        $box = $app->overlay();
        $this->assertInstanceOf(MsgBox::class, $box);
        $this->assertSame('SIGTERM', $box->title);
        [$app, $cmd] = $app->update(self::keyMsg('y'));
        $this->assertNull($app->overlay());
        $msgs = Cmds::run($cmd);
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(OpenOverlayMsg::class, $msgs[0]);
        [$app] = $app->update($msgs[0]);
        $this->assertStringContainsString('Insufficient permissions to send signal!', implode("\n", $app->surface()?->plainLines() ?? []));
    }

    public function testTtyModeFlipLoadsTheTtyThemeInACmd(): void
    {
        $calls = new \ArrayObject();
        $themes = static function (Config $c) use ($calls): Palette {
            $calls->append($c->ttyMode());

            return $c->ttyMode() ? TtyTheme::new() : ThemeConfig::new();
        };
        $app = self::app(themes: $themes);
        [$tty, $cmd] = $app->update(new SetOptionMsg('tty_mode', true));
        $this->assertSame(ColorProfile::Ansi, $tty->ink->profile());
        $this->assertCount(0, $calls, 'no theme I/O inside update()');
        $palettes = Cmds::of(PaletteMsg::class, $cmd);
        $this->assertCount(1, $palettes);
        $this->assertSame([true], $calls->getArrayCopy());
        [$tty] = $tty->update($palettes[0]);
        $this->assertSame('TTY', $tty->ink->palette()->name());
        $this->assertSame(ColorProfile::Ansi, $tty->ink->profile());

        [$stale] = $app->update($palettes[0]);
        $this->assertSame($app->ink->palette(), $stale->ink->palette(), 'a load for another config is dropped');
        [, $none] = $app->update(new SetOptionMsg('proc_tree', true));
        $this->assertSame([], Cmds::of(PaletteMsg::class, $none), 'unrelated options never reload the theme');
        $this->assertNotSame(App::themeKey(self::config()), App::themeKey(self::config(['color_theme' => 'nord'])));
    }
}
