<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\ConfigFile;
use SugarCraft\Top\Config\ConfigWriter;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\ConfigLoadedMsg;
use SugarCraft\Top\Msg\ConfigSavedMsg;
use SugarCraft\Top\Msg\PaletteMsg;
use SugarCraft\Top\Msg\QuitRequestMsg;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Msg\UpdateStepMsg;
use SugarCraft\Top\Overlay\MainMenu;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\Overlay\OptionsMenu;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Surface;

/**
 * Phase P-F2 App seams: the options menu's live writes, layout presets,
 * the #1476 proc width keys, config persistence (save_config_on_exit) and
 * the `ctrl+r` reload with its #1849 full repaint.
 */
final class AppOptionsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        T::reset();
        $this->dir = sys_get_temp_dir() . '/candy-top-pf2-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @chmod($f, 0644);
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function file(): ConfigFile
    {
        return ConfigFile::new($this->dir . '/config.conf');
    }

    /** Bundled themes, loaded inside the Cmd as the live loader does. */
    private static function themes(): \Closure
    {
        return static fn (Config $c): Palette => ThemeRegistry::fromDirs(ThemeRegistry::bundledDir())->load($c->colorTheme(), $c->bool('theme_background'), $c->ttyMode());
    }

    /** @param array<string, bool|int|string> $options */
    private static function config(array $options = []): Config
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }

        return $config;
    }

    private static function app(
        ?Config $config = null,
        ?ConfigFile $file = null,
        bool $writeNew = false,
        int $cols = 120,
        int $rows = 40,
        bool $standard = false,
        ?ThemeRegistry $catalog = null,
    ): App {
        $config ??= Config::new();
        $host = Harness::host();
        $app = App::start(
            $config,
            ThemeConfig::new(),
            $host,
            $standard ? Panels::standard($host, $config, true) : Panels::placeholders($host, $config, true),
            static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0),
            ColorProfile::TrueColor,
            self::themes(),
            $file,
            $writeNew,
            $catalog,
        );
        [$app] = $app->update(new WindowSizeMsg($cols, $rows));
        foreach (Cmds::run($app->init()) as $msg) {
            if (!$msg instanceof TickRequest) {
                [$app] = $app->update($msg);
            }
        }

        return $app;
    }

    private static function keyMsg(string $name): KeyMsg
    {
        return match ($name) {
            'escape' => new KeyMsg(KeyType::Escape),
            'enter' => new KeyMsg(KeyType::Enter),
            'down' => new KeyMsg(KeyType::Down),
            'up' => new KeyMsg(KeyType::Up),
            'left' => new KeyMsg(KeyType::Left),
            'right' => new KeyMsg(KeyType::Right),
            'ctrl+r' => new KeyMsg(KeyType::Char, 'r', ctrl: true),
            'ctrl+c' => new KeyMsg(KeyType::Char, 'c', ctrl: true),
            'shift_left' => new KeyMsg(KeyType::Left, shift: true),
            'shift_right' => new KeyMsg(KeyType::Right, shift: true),
            'alt_shift_left' => new KeyMsg(KeyType::Left, shift: true, alt: true),
            'ctrl_shift_left' => new KeyMsg(KeyType::Left, shift: true, ctrl: true),
            'ctrl_shift_right' => new KeyMsg(KeyType::Right, shift: true, ctrl: true),
            'ctrl_shift_down' => new KeyMsg(KeyType::Down, shift: true, ctrl: true),
            'shift_up' => new KeyMsg(KeyType::Up, shift: true),
            default => new KeyMsg(KeyType::Char, $name),
        };
    }

    /** @return array{0: App, 1: ?\Closure} */
    private static function press(App $app, string ...$keys): array
    {
        $cmd = null;
        foreach ($keys as $k) {
            [$app, $cmd] = $app->update(self::keyMsg($k));
        }

        return [$app, $cmd];
    }

    /** Run `$cmd` and feed every non-tick Msg back; returns the App and the Msgs. */
    private static function settle(App $app, ?\Closure $cmd): array
    {
        $msgs = array_values(array_filter(Cmds::run($cmd), static fn (Msg $m): bool => !$m instanceof TickRequest));
        foreach ($msgs as $m) {
            if (!$m instanceof QuitMsg) {
                [$app] = $app->update($m);
            }
        }

        return [$app, $msgs];
    }

    private static function click(int $x, int $y, MouseAction $action = MouseAction::Press): MouseMsg
    {
        return new MouseMsg($x + 1, $y + 1, MouseButton::Left, $action);
    }

    // ---- options menu -------------------------------------------------------

    public function testOptionsMenuWritesApplyAtOnce(): void
    {
        $app = self::app();
        [$app, $cmd] = self::press($app, 'o', ...array_fill(0, 13, 'down'));
        $this->assertInstanceOf(OptionsMenu::class, $app->overlay());
        $generation = $app->generation;
        [$app, $cmd] = self::press($app, 'right');
        $this->assertSame(2100, $app->config->updateMs(), 'applied inside the same update()');
        $this->assertSame($generation + 1, $app->generation, 'through applyConfig: the tick is re-armed');
        $this->assertNotNull($cmd);
        $this->assertTrue($app->writeNew, 'a persisted change is saved on exit');
        $this->assertInstanceOf(OptionsMenu::class, $app->overlay(), 'the menu stays open');
        $this->assertStringContainsString('2100ms', implode("\n", $app->surface()?->plainLines() ?? []), 'the cpu title behind the menu shows it');
    }

    public function testColorThemePreviewsBehindAFrozenMenu(): void
    {
        $catalog = ThemeRegistry::fromDirs(ThemeRegistry::bundledDir());
        $app = self::app(self::config(['background_update' => false]), catalog: $catalog);
        [$app] = self::press($app, 'o');
        $before = $app->surface()?->style(0, 39);
        [$app, $cmd] = self::press($app, 'right');
        $this->assertSame('TTY', $app->config->colorTheme());
        $palettes = Cmds::of(PaletteMsg::class, $cmd);
        $this->assertCount(1, $palettes, 'the theme loads in a Cmd');
        [$app] = $app->update($palettes[0]);
        $this->assertSame('TTY', $app->ink->palette()->name());
        $after = $app->surface()?->style(0, 39);
        $this->assertNotSame($before, $after, 'the frozen backdrop is re-captured in the new theme');
        $this->assertSame(Surface::canonical($app->ink->fg('inactive_fg')), $after);
    }

    public function testFrozenBackdropRefreshesWhenTheTopMenuCloses(): void
    {
        // btop Menu::process: Closed / Switch clear pause_output, so the
        // menu uncovered (or switched to) sits on a fresh frame.
        $app = self::app(self::config(['background_update' => false]));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
        $oldClock = $app->clockText();
        [$app] = self::press($app, 'm');
        $this->assertStringContainsString($oldClock, $app->surface()?->plainLines()[0] ?? '');
        [$app] = $app->update(new ClockTickMsg(Harness::TIME + 3723.0, 3600.0));
        $newClock = $app->clockText();
        $this->assertNotSame($oldClock, $newClock);
        $this->assertStringContainsString($oldClock, $app->surface()?->plainLines()[0] ?? '', 'frozen while the menu is open');
        [$app] = self::press($app, 'down', 'enter');
        $this->assertInstanceOf(\SugarCraft\Top\Overlay\HelpMenu::class, $app->overlay());
        [$app] = $app->update(new ClockTickMsg(Harness::TIME + 7446.0, 3600.0));
        $switchClock = $app->clockText();
        [$app] = self::press($app, 'escape');
        $this->assertInstanceOf(MainMenu::class, $app->overlay());
        $row = $app->surface()?->plainLines()[0] ?? '';
        $this->assertStringContainsString($switchClock, $row, 'the main menu uncovered sits on a fresh frame (Closed)');
        $this->assertStringNotContainsString($newClock, $row);
    }

    // ---- presets ------------------------------------------------------------

    public function testPresetsCycleForwardAndBack(): void
    {
        $app = self::app();
        $this->assertNull($app->preset);
        [$app] = self::press($app, 'p');
        $this->assertSame(0, $app->preset, 'from none, p starts at preset 0');
        [$app] = self::press($app, 'p');
        $this->assertSame(1, $app->preset);
        $this->assertSame(['cpu', 'proc'], $app->config->shownBoxes());
        $this->assertTrue($app->config->bool('cpu_bottom'), 'cpu:1 is the alternate position');
        [$app] = self::press($app, 'p');
        $this->assertSame(['cpu', 'mem', 'net'], $app->config->shownBoxes());
        $this->assertStringContainsString('preset 2', $app->surface()?->plainLines()[0] ?? '', 'btop draws the preset number in the cpu title');
        [$app] = self::press($app, 'p', 'p');
        $this->assertSame(0, $app->preset, 'wraps');
        [$back] = self::press(self::app(), 'P');
        $this->assertSame(3, $back->preset, 'P from none starts at the last');
        $this->assertSame('block', $back->config->string('graph_symbol_cpu'));
        $this->assertSame('tty', $back->config->string('graph_symbol_net'));
        $this->assertTrue($back->writeNew);
    }

    public function testDisablePresetsAndRefusedPresets(): void
    {
        [$all, $cmd] = self::press(self::app(self::config(['disable_presets' => 'All'])), 'p');
        $this->assertNull($all->preset);
        $this->assertNull($cmd);
        [$custom] = self::press(self::app(self::config(['disable_presets' => 'Custom'])), 'p', 'p');
        $this->assertSame(0, $custom->preset, 'Custom pins preset 0');
        [$default] = self::press(self::app(self::config(['disable_presets' => 'Default'])), 'p');
        $this->assertSame(1, $default->preset, 'Default skips preset 0');

        // 70x24 fits "cpu proc" but not preset 0's four boxes.
        $small = self::app(self::config(['shown_boxes' => 'cpu proc']), cols: 70, rows: 24);
        [$small] = self::press($small, 'p');
        $this->assertInstanceOf(MsgBox::class, $small->overlay(), 'btop: the size-error box');
        $this->assertNull($small->preset, 'the old preset stays');
        $this->assertSame(['cpu', 'proc'], $small->config->shownBoxes());
    }

    public function testPresetButtonAndPresetResets(): void
    {
        $app = self::app();
        [$x, $y] = $app->chromeButtons()['p'];
        [$clicked] = $app->update(self::click($x, $y));
        $this->assertSame(0, $clicked->preset, 'the cpu title button is `p`');
        [$clicked] = $clicked->update(self::click($x + 3, $y, MouseAction::Motion));
        $this->assertSame(1, $clicked->preset, 'a drag over it maps too (btop_input.cpp:174)');
        [$toggled] = self::press($clicked, '2');
        $this->assertNull($toggled->preset, 'a box toggle drops it');
        [$edited] = $clicked->update(new SetOptionMsg('presets', 'cpu:0:tty'));
        $this->assertNull($edited->preset, 'a presets edit drops it');
        [$disabled] = $clicked->update(new SetOptionMsg('disable_presets', 'Custom'));
        $this->assertNull($disabled->preset);
        [$kept] = $clicked->update(new SetOptionMsg('proc_tree', true));
        $this->assertSame(1, $kept->preset, 'other options keep it');
    }

    // ---- #1476 proc width keys ----------------------------------------------

    public function testProcWidthKeys(): void
    {
        $app = self::app();
        $width = static fn (App $a): int => $a->layout?->box('proc')?->width ?? 0;
        $this->assertSame(66, $width($app), '55 % of 120 = btop 1.4.7');
        [$wider] = self::press($app, 'shift_left');
        $this->assertSame(56, $wider->config->procBoxWidthPercent());
        $this->assertSame(67, $width($wider));
        $this->assertTrue($wider->writeNew);
        $this->assertSame(54, self::press($app, 'shift_right')[0]->config->procBoxWidthPercent());
        $this->assertSame(65, self::press($app, 'alt_shift_left')[0]->config->procBoxWidthPercent());
        [$max] = self::press($app, 'ctrl_shift_left');
        $this->assertSame(100, $max->config->procBoxWidthPercent());
        $this->assertSame(120 - 36, $width($max), 'the layout clamps to what mem/net need');
        [$min] = self::press($app, 'ctrl_shift_right');
        $this->assertSame(0, $min->config->procBoxWidthPercent());
        $this->assertSame(44, $width($min), 'and to proc\'s own minimum');
        $this->assertSame(55, self::press($max, 'ctrl_shift_down')[0]->config->procBoxWidthPercent(), 'reset to the default');
        // At the bounds the step stops (120 cols: 37 .. 70 %).
        [$capped] = self::press(self::app(self::config(['proc_box_width_percent' => 70])), 'shift_left');
        $this->assertSame(70, $capped->config->procBoxWidthPercent());

        $left = self::app(self::config(['proc_left' => true]));
        $this->assertSame(54, self::press($left, 'shift_left')[0]->config->procBoxWidthPercent(), 'mirrored with proc_left');
        $this->assertSame(0, self::press($left, 'ctrl_shift_left')[0]->config->procBoxWidthPercent());

        [$alone, $cmd] = self::press(self::app(self::config(['shown_boxes' => 'cpu proc'])), 'shift_left');
        $this->assertSame(55, $alone->config->procBoxWidthPercent(), 'only with mem or net shown');
        $this->assertNull($cmd);
        [$preset] = self::press($app, 'p', 'shift_left');
        $this->assertNull($preset->preset, 'a width change drops the preset');
        [$same, $none] = self::press($app, 'shift_up');
        $this->assertSame($app->config->toArray(), $same->config->toArray(), 'btop has no name for shift+up: dropped');
        $this->assertNull($none);
    }

    // ---- persistence --------------------------------------------------------

    public function testQuitSavesOnlyWhatBtopWould(): void
    {
        $file = $this->file();
        [, $cmd] = self::press(self::app(file: $file), 'q');
        $this->assertInstanceOf(QuitMsg::class, $cmd ? $cmd() : null, 'nothing changed: plain quit');
        $this->assertFileDoesNotExist($file->path());

        [$app] = self::app(file: $file)->update(new SetOptionMsg('net_auto', false));
        $this->assertTrue($app->writeNew);
        [, $cmd] = self::press($app, 'q');
        $msgs = Cmds::run($cmd);
        $this->assertInstanceOf(ConfigSavedMsg::class, $msgs[0]);
        $this->assertTrue($msgs[0]->ok);
        $this->assertInstanceOf(QuitMsg::class, $msgs[1], 'save first, then quit (a sequence)');
        $this->assertStringContainsString("net_auto = false\n", (string) file_get_contents($file->path()));
        $this->assertStringStartsWith('#? ' . ConfigWriter::header(), (string) file_get_contents($file->path()));

        unlink($file->path());
        [$off] = self::app(self::config(['save_config_on_exit' => false]), $file)->update(new SetOptionMsg('net_auto', false));
        [, $cmd] = self::press($off, 'q');
        $this->assertInstanceOf(QuitMsg::class, $cmd ? $cmd() : null);
        $this->assertFileDoesNotExist($file->path(), 'save_config_on_exit off: never written');
        $this->assertNull($off->exitSave());

        [, $cmd] = self::press(self::app(file: $file, writeNew: true), 'q');
        $this->assertInstanceOf(ConfigSavedMsg::class, Cmds::run($cmd)[0], 'a missing / outdated file is written on exit even unchanged (btop write_new)');
        $this->assertFileExists($file->path());

        $this->assertNull(self::app()->exitSave(), 'no file, nothing to write');
        [$noFile] = self::app()->update(new SetOptionMsg('net_auto', false));
        $this->assertInstanceOf(QuitMsg::class, (self::press($noFile, 'q')[1])());
    }

    public function testEveryQuitPathSaves(): void
    {
        $file = $this->file();
        [$app] = self::app(file: $file)->update(new SetOptionMsg('proc_tree', true));
        foreach (['ctrl+c', 'q'] as $key) {
            [, $cmd] = self::press($app, $key);
            $this->assertInstanceOf(ConfigSavedMsg::class, Cmds::run($cmd)[0], $key);
        }
        [, $cmd] = $app->update(new QuitRequestMsg());
        $this->assertInstanceOf(ConfigSavedMsg::class, Cmds::run($cmd)[0], 'the main menu Quit entry');
        [$menu] = self::press($app, 'm', 'up', 'enter');
        $this->assertNull($menu->overlay());
        [$small] = $app->update(new WindowSizeMsg(30, 10));
        [, $cmd] = self::press($small, 'q');
        $this->assertInstanceOf(ConfigSavedMsg::class, Cmds::run($cmd)[0], 'behind the size notice too');
        [$saved] = $app->update(new ConfigSavedMsg(true, '', $app->config));
        $this->assertFalse($saved->writeNew);
        $this->assertNull($saved->exitSave());
        [$toggled] = self::press($app, '2');
        $this->assertTrue(self::press(self::app(file: $file), '2')[0]->writeNew, 'a box toggle is a Config::set');
        $this->assertNotNull($toggled);
    }

    public function testSwitchingSaveOnExitOffWritesAtOnce(): void
    {
        $file = $this->file();
        [$app, $cmd] = self::app(file: $file)->update(new SetOptionMsg('save_config_on_exit', false));
        $saved = Cmds::of(ConfigSavedMsg::class, $cmd);
        $this->assertCount(1, $saved, 'btop: toggling it off triggers a save');
        $this->assertStringContainsString("save_config_on_exit = false\n", (string) file_get_contents($file->path()));
        [$app] = $app->update($saved[0]);
        $this->assertFalse($app->writeNew);
    }

    public function testAFailedSaveSaysSo(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root writes read-only files');
        }
        $file = $this->file();
        $file->write(Config::new());
        chmod($file->path(), 0444);
        [$app, $cmd] = self::app(file: $file)->update(new SetOptionMsg('save_config_on_exit', false));
        $msgs = Cmds::of(ConfigSavedMsg::class, $cmd);
        $this->assertFalse($msgs[0]->ok);
        [$app] = $app->update($msgs[0]);
        $this->assertInstanceOf(MsgBox::class, $app->overlay());
        $this->assertSame('warning', $app->overlay()->title);
        $this->assertStringContainsString('read-only', implode("\n", $app->surface()?->plainLines() ?? []));
    }

    // ---- ctrl+r reload ------------------------------------------------------

    public function testCtrlRReloadsTheFileOverTheRuntimeState(): void
    {
        $file = $this->file();
        $file->write(self::config(['update_ms' => 3000, 'color_theme' => 'nord']));
        [$app] = self::app(file: $file)->update(new SetOptionMsg('proc_filter', 'php'));
        [$app, $cmd] = self::press($app, 'ctrl+r');
        $this->assertSame(2000, $app->config->updateMs(), 'nothing read inside update()');
        $loaded = Cmds::of(ConfigLoadedMsg::class, $cmd);
        $this->assertCount(1, $loaded);
        [$app, $cmd] = $app->update($loaded[0]);
        $this->assertSame(3000, $app->config->updateMs());
        $this->assertSame('php', $app->config->string('proc_filter'), 'runtime state survives');
        $this->assertFalse($app->writeNew, 'a reload is not a change to save');
        $palettes = Cmds::of(PaletteMsg::class, $cmd);
        $this->assertCount(1, $palettes);
        [$app] = $app->update($palettes[0]);
        $this->assertSame('nord', $app->ink->palette()->name());

        // An unchanged theme name still reloads (the file may have changed).
        [, $cmd] = $app->update(new ConfigLoadedMsg(null));
        $this->assertCount(1, Cmds::of(PaletteMsg::class, $cmd));
        [, $cmd] = self::press(self::app(), 'ctrl+r');
        $this->assertInstanceOf(ConfigLoadedMsg::class, Cmds::run($cmd)[0], 'no file: the theme still reloads');
        $this->assertNull(Cmds::run($cmd)[0]->result);

        file_put_contents($file->path(), "update_ms = 2500\n");
        [$stale] = self::app(file: $file)->update(Cmds::of(ConfigLoadedMsg::class, self::press(self::app(file: $file), 'ctrl+r')[1])[0]);
        $this->assertTrue($stale->writeNew, 'a header-less (older) file is rewritten on exit, as btop flags write_new');
    }

    public function testReloadRepaintsEveryCachedRender(): void
    {
        // #1849: a theme change through ctrl+r leaves no SGR of the old
        // palette anywhere — battery meter and frozen backdrop included.
        $file = $this->file();
        $app = self::app(self::config(['background_update' => false]), $file, standard: true);
        $old = $app->ink;
        $this->assertNotNull($app->panel('cpu'));
        [$app] = self::press($app, 'm');
        $file->write(self::config(['background_update' => false, 'color_theme' => 'gruvbox_dark']));
        [$app, $cmd] = self::press($app, 'escape', 'ctrl+r');
        [$app, $cmd] = $app->update(Cmds::of(ConfigLoadedMsg::class, $cmd)[0]);
        [$app] = $app->update(Cmds::of(PaletteMsg::class, $cmd)[0]);
        $this->assertSame('gruvbox_dark', $app->config->colorTheme());
        $forbidden = array_diff(self::colors($old), self::colors($app->ink));
        $this->assertNotEmpty($forbidden);
        $frame = $app->surface();
        $this->assertNotNull($frame);
        $this->assertStringContainsString('BAT', implode('', $frame->plainLines()), 'the battery badge is drawn');
        foreach ($frame->lines() as $y => $line) {
            foreach ($forbidden as $color) {
                $this->assertStringNotContainsString($color, $line, 'row ' . $y . ' still carries an old-palette colour');
            }
        }

        // Inside a menu ctrl+r is the menu's key, not a reload.
        $app = self::app(self::config(['background_update' => false]), $file, standard: true);
        [$app] = self::press($app, 'm');
        [$menu, $cmd] = self::press($app, 'ctrl+r');
        $this->assertInstanceOf(MainMenu::class, $menu->overlay());
        $this->assertNull($cmd);
    }

    public function testAReloadLandingUnderAnOpenMenuRecapturesTheFrozenBackdrop(): void
    {
        // The reload Cmd answers after a menu opened: ConfigLoadedMsg and the
        // PaletteMsg land while the MainMenu is up — the frozen frame behind
        // it must come back in the new palette (#1849).
        $file = $this->file();
        $app = self::app(self::config(['background_update' => false]), $file, standard: true);
        $reload = $app->reloadCmd();
        $file->write(self::config(['background_update' => false, 'color_theme' => 'gruvbox_dark']));
        [$app] = self::press($app, 'm');
        $old = $app->ink;
        $before = $app->surface()?->style(0, 39);
        $this->assertSame(Surface::canonical($old->fg('inactive_fg')), $before);
        [$app, $cmd] = $app->update(Cmds::of(ConfigLoadedMsg::class, $reload)[0]);
        [$app] = $app->update(Cmds::of(PaletteMsg::class, $cmd)[0]);
        $this->assertInstanceOf(MainMenu::class, $app->overlay(), 'the menu stays open');
        $this->assertSame('gruvbox_dark', $app->ink->palette()->name());
        $after = $app->surface()?->style(0, 39);
        $this->assertNotSame($before, $after);
        $this->assertSame(Surface::canonical($app->ink->fg('inactive_fg')), $after, 'the frozen backdrop is the new palette\'s dim');
        $forbidden = array_diff(self::colors($old), self::colors($app->ink));
        // The menu's banner and block letters are btop's fixed colours, not
        // the theme's: check the backdrop rows around them.
        $top = MainMenu::top(40);
        foreach ($app->surface()?->lines() ?? [] as $y => $line) {
            if ($y >= $top - 1 && $y <= $top + 15) {
                continue;
            }
            foreach ($forbidden as $color) {
                $this->assertStringNotContainsString($color, $line, 'row ' . $y);
            }
        }
    }

    public function testAReloadNeverRewritesTheFile(): void
    {
        // The user hand-edited save_config_on_exit to false: reloading it is
        // not the options-menu toggle, so nothing is written.
        $file = $this->file();
        $app = self::app(file: $file);
        $text = ConfigWriter::render(self::config(['save_config_on_exit' => false, 'update_ms' => 1500]));
        @mkdir($this->dir, 0700, true);
        file_put_contents($file->path(), $text);
        [$app, $cmd] = $app->update(Cmds::of(ConfigLoadedMsg::class, $app->reloadCmd())[0]);
        $this->assertFalse($app->config->bool('save_config_on_exit'));
        $this->assertSame([], Cmds::of(ConfigSavedMsg::class, $cmd));
        $this->assertSame($text, file_get_contents($file->path()), 'the file is untouched');
        [, $cmd] = self::app(file: $file)->update(new SetOptionMsg('save_config_on_exit', false));
        $this->assertCount(1, Cmds::of(ConfigSavedMsg::class, $cmd), 'the user toggle still writes');
    }

    public function testAReloadShowsTheFirstRejectedValue(): void
    {
        $file = $this->file();
        @mkdir($this->dir, 0700, true);
        file_put_contents($file->path(), "#? x\nupdate_ms = 5\nproc_sorting = \"bogus\"\n");
        $app = self::app(file: $file);
        [$app] = $app->update(Cmds::of(ConfigLoadedMsg::class, $app->reloadCmd())[0]);
        $this->assertInstanceOf(MsgBox::class, $app->overlay());
        $this->assertSame('warning', $app->overlay()->title);
        $this->assertSame(2000, $app->config->updateMs(), 'the rejected value kept its setting');
        $this->assertStringContainsString('update_ms', implode("\n", $app->surface()?->plainLines() ?? []));
    }

    public function testASaveOnlyClearsWhatItWrote(): void
    {
        $file = $this->file();
        [$app] = self::app(file: $file)->update(new SetOptionMsg('net_auto', false));
        $save = $app->saveCmd();
        [$app] = $app->update(new SetOptionMsg('net_sync', false));
        [$app] = $app->update(Cmds::of(ConfigSavedMsg::class, $save)[0]);
        $this->assertTrue($app->writeNew, 'net_sync changed after the snapshot was written');
        [$app] = $app->update(Cmds::of(ConfigSavedMsg::class, $app->saveCmd())[0]);
        $this->assertFalse($app->writeNew);
    }

    public function testTerminalResetUndoesWhatTheProgramTurnedOn(): void
    {
        // bin writes these when a crash escapes Program::run() before its
        // teardown; every mode programOptions() enables must be undone.
        $reset = App::terminalReset();
        foreach ([Ansi::altScreenLeave(), Ansi::cursorShow(), Ansi::mouseCellMotionOff(), Ansi::mouseAllMotionOff(), Ansi::reset(), Ansi::syncEnd()] as $bytes) {
            $this->assertStringContainsString($bytes, $reset);
        }
        $this->assertStringEndsWith(Ansi::altScreenLeave(), $reset, 'the main screen comes back last, for the error that follows');
        $this->assertSame(MouseMode::CellMotion, App::programOptions(Config::new())->mouseMode, 'the mode this undoes');
    }

    public function testMouseReportingFollowsDisableMouse(): void
    {
        $this->assertSame(MouseMode::CellMotion, App::programOptions(Config::new())->mouseMode, 'btop ?1002h + ?1006h');
        $this->assertTrue(App::programOptions(Config::new())->useAltScreen);
        $this->assertSame(MouseMode::Off, App::programOptions(self::config(['disable_mouse' => true]))->mouseMode);
        [$off, $cmd] = self::app()->update(new SetOptionMsg('disable_mouse', true));
        $raw = Cmds::of(RawMsg::class, $cmd);
        $this->assertCount(1, $raw);
        $this->assertSame(Ansi::mouseAllMotionOff() . Ansi::mouseCellMotionOff(), $raw[0]->bytes, 'btop Term::mouse_off');
        [, $cmd] = $off->update(new SetOptionMsg('disable_mouse', false));
        $this->assertSame(Ansi::mouseCellMotionOn(), Cmds::of(RawMsg::class, $cmd)[0]->bytes, 'btop Term::mouse_on');
        [, $cmd] = self::app()->update(new SetOptionMsg('proc_tree', true));
        $this->assertSame([], Cmds::of(RawMsg::class, $cmd));
    }

    /** @return list<string> every truecolor parameter run the ink can emit (themes keys + gradients). */
    private static function colors(Ink $ink): array
    {
        $out = [];
        foreach (array_keys(ThemeConfig::DEFAULT_THEME) as $key) {
            foreach ([$ink->fg($key), $ink->bg($key)] as $sgr) {
                if (preg_match('/[34]8;2;\d+;\d+;\d+/', $sgr, $m) === 1) {
                    $out[] = $m[0];
                }
            }
        }

        return array_values(array_unique($out));
    }

    // ---- mouse --------------------------------------------------------------

    public function testDisableMouseDropsEveryMouseEvent(): void
    {
        $app = self::app(self::config(['disable_mouse' => true]));
        [$x, $y] = $app->chromeButtons()['m'];
        [$next, $cmd] = $app->update(self::click($x, $y));
        $this->assertNull($next->overlay());
        $this->assertNull($cmd);
        $this->assertInstanceOf(MainMenu::class, self::app()->update(self::click($x, $y))[0]->overlay());
    }

    public function testDraggingOverPlusSteps(): void
    {
        $app = self::app();
        [$x, $y] = $app->chromeButtons()['+'];
        [, $cmd] = $app->update(self::click($x, $y, MouseAction::Motion));
        $this->assertCount(1, Cmds::of(UpdateStepMsg::class, $cmd), 'btop maps mouse_drag through mouse_mappings too');
    }
}
