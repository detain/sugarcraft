<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\Overlay\OptionsCatalog;
use SugarCraft\Top\Overlay\OptionsMenu;
use SugarCraft\Top\Overlay\OverlayContext;
use SugarCraft\Top\Overlay\OverlayResult;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Surface;

/** btop Menu::optionsMenu with #1411 tab digits and #1791b headings. */
final class OptionsMenuTest extends OverlayTestCase
{
    /**
     * @param array<string, bool|int|string> $options
     * @param array<string, list<string>>    $choices
     */
    private static function context(array $options = [], int $cols = 120, int $rows = 40, array $choices = [], bool $gpu = false, ?ThemeRegistry $themes = null): OverlayContext
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }

        return new OverlayContext($config, $cols, $rows, Ink::new(ThemeConfig::new()), $choices, $gpu, $themes);
    }

    /** The menu after `$keys`, asserting it stayed open. */
    private static function after(array $keys, ?OverlayContext $c = null): OptionsMenu
    {
        $r = self::feed(OptionsMenu::new(), $keys, $c ?? self::context());
        self::assertInstanceOf(OptionsMenu::class, $r->overlay);

        return $r->overlay;
    }

    /** Move the selection to `$option` in its tab (by down presses), returning the menu. */
    private static function on(string $option, ?OverlayContext $c = null): OptionsMenu
    {
        $c ??= self::context();
        foreach (OptionsCatalog::categories($c->gpu) as $digit => $cat) {
            if (in_array($option, OptionsCatalog::entries($cat, $c->gpu), true)) {
                $menu = self::after([(string) $digit], $c);
                for ($i = 0; $i < 60 && $menu->selectedOption($c) !== $option; $i++) {
                    $menu = self::step($menu, 'down', $c);
                }
                self::assertSame($option, $menu->selectedOption($c));

                return $menu;
            }
        }
        self::fail('no tab lists ' . $option);
    }

    private static function step(OptionsMenu $menu, string $key, ?OverlayContext $c = null): OptionsMenu
    {
        $r = $menu->update(self::key($key), $c ?? self::context());
        self::assertInstanceOf(OptionsMenu::class, $r->overlay);

        return $r->overlay;
    }

    public function testEveryPersistedOptionIsListedOnceWithADescription(): void
    {
        $seen = [];
        foreach (OptionsCatalog::categories(true) as $cat) {
            foreach (OptionsCatalog::entries($cat, true) as $entry) {
                if (OptionsCatalog::isHeading($entry)) {
                    $this->assertStringNotContainsString('top.options', OptionsCatalog::heading($entry), $entry);
                    continue;
                }
                $seen[] = $entry;
                $lines = OptionsCatalog::description($entry);
                $this->assertStringNotContainsString('top.options', $lines[0], 'missing description ' . $entry);
                foreach ($lines as $line) {
                    $this->assertLessThanOrEqual(45, Width::string($line), $entry . ': "' . $line . '" overruns the description column');
                }
            }
        }
        $this->assertSame(array_values(array_unique($seen)), $seen, 'listed once');
        $missing = array_diff(Schema::persistedNames(), $seen);
        $this->assertSame([], array_values($missing), 'every persisted option has a menu row');
        $this->assertSame([], array_values(array_diff($seen, Schema::persistedNames())), 'only persisted options');
    }

    public function testTabsFollowTheBoxToggleDigits(): void
    {
        // #1411: 0 general, 1 cpu, 2 mem, 3 net, 4 proc, 5 gpu.
        $this->assertSame(['general', 'cpu', 'mem', 'net', 'proc'], OptionsCatalog::categories(false));
        $this->assertSame(['general', 'cpu', 'mem', 'net', 'proc', 'gpu'], OptionsCatalog::categories(true));
        foreach (['0' => 'general', '1' => 'cpu', '2' => 'mem', '3' => 'net', '4' => 'proc'] as $digit => $cat) {
            $this->assertSame($cat, self::after(['3', (string) $digit])->category);
        }
        $this->assertSame('general', self::after(['5'])->category, 'no gpu tab without a GPU');
        $this->assertSame('gpu', self::after(['5'], self::context(gpu: true))->category);
        $this->assertNotContains('graph_symbol_gpu', OptionsCatalog::entries('general', false));
        $this->assertContains('graph_symbol_gpu', OptionsCatalog::entries('general', true));
        $this->assertSame('cpu', self::after(['tab'])->category);
        $this->assertSame('proc', self::after(['shift_tab'])->category, 'wraps back');
        $this->assertSame('general', self::after(['4', 'tab'])->category, 'wraps forward');
        $this->assertSame(0, self::after(['down', 'down', 'tab'])->index, 'a tab change starts at the top');
    }

    public function testArrowsSkipHeadingsAndWrap(): void
    {
        $c = self::context();
        $this->assertSame('color_theme', OptionsMenu::new()->selectedOption($c), 'the opening heading is never selected');
        $this->assertSame('theme_background', self::after(['down'])->selectedOption($c));
        $this->assertSame('force_tty', self::after(['down', 'down', 'down'])->selectedOption($c), 'over the "Terminal and input" heading');
        $this->assertSame('save_config_on_exit', self::after(['up'])->selectedOption($c), 'up from the first wraps to the last');
        $this->assertSame('color_theme', self::after(['up', 'down'])->selectedOption($c));
        $this->assertSame('theme_background', self::after(['mouse_scroll_down'])->selectedOption($c) === 'theme_background' ? 'theme_background' : '', 'wheel moves like down');
        $vim = self::context(['vim_keys' => true]);
        $this->assertSame('theme_background', self::after(['j'], $vim)->selectedOption($vim));
        $this->assertSame('color_theme', self::after(['j', 'k'], $vim)->selectedOption($vim));
        $this->assertSame('color_theme', self::after(['j'])->selectedOption($c), 'j is not down without vim_keys');
    }

    public function testPagesFlipAndClampToTheTerminal(): void
    {
        $c = self::context();
        $g = OptionsMenu::new()->geometry($c);
        $this->assertSame(3, $g['pages']);
        $next = self::after(['page_down']);
        $this->assertSame(1, $next->geometry($c)['page']);
        $this->assertSame(2, self::after(['page_up'])->geometry($c)['page'], 'page_up wraps to the last page');
        $this->assertSame(0, self::after(['page_down', 'page_down', 'page_down'])->geometry($c)['page']);
        // A page starting on a heading lands on the option after it.
        $first = $next->geometry($c);
        $this->assertFalse(OptionsCatalog::isHeading($first['entries'][$first['index']]));
        // 80x24: btop height min(rows - 7, ...) even = 16 → 6 items a page.
        $small = self::context(cols: 80, rows: 24);
        $this->assertSame(6, OptionsMenu::new()->geometry($small)['ih']);
        $this->assertSame([80, 24], OptionsMenu::new()->minSize());
    }

    public function testCloseKeys(): void
    {
        foreach (['escape', 'q', 'o', 'backspace'] as $key) {
            $this->assertNull(self::feed(OptionsMenu::new(), [$key], self::context())->overlay, $key);
        }
        $this->assertNull(OptionsMenu::new()->update(self::click(0, 0), self::context())->overlay, 'a click outside closes');
    }

    public function testBoolsFlipWithTheirBtopCompanions(): void
    {
        $r = self::on('theme_background')->update(self::key('right'), self::context());
        $this->assertSame(['theme_background' => false], $r->set);
        $r = self::on('truecolor')->update(self::key('left'), self::context());
        $this->assertSame(['truecolor' => false, 'lowcolor' => true], $r->set, 'btop flips lowcolor with truecolor');

        $c = self::context();
        $r = self::on('force_tty', $c)->update(self::key('right'), $c);
        $this->assertSame(['force_tty' => true, 'tty_mode' => true], $r->set, 'off a real console force_tty drives tty_mode');
        $console = self::context(['tty_mode' => true, 'tty_console' => true]);
        $r = self::on('force_tty', $console)->update(self::key('right'), $console);
        $this->assertSame(['force_tty' => true], $r->set, 'on a real /dev/tty console tty_mode is left alone');
        // on -> off on a VT: still a console, tty_mode stays on (the old
        // tty_mode && !force_tty guess turned it off here).
        $vt = self::context(['tty_mode' => true, 'force_tty' => true, 'tty_console' => true]);
        $this->assertSame(['force_tty' => false], self::on('force_tty', $vt)->update(self::key('right'), $vt)->set);
        $forced = self::context(['tty_mode' => true, 'force_tty' => true]);
        $this->assertSame(['force_tty' => false, 'tty_mode' => false], self::on('force_tty', $forced)->update(self::key('right'), $forced)->set, 'off a console turning force_tty off leaves tty mode');
        $vim = self::context(['vim_keys' => true]);
        $this->assertSame(['vim_keys' => false], self::on('vim_keys', $vim)->update(self::key('l'), $vim)->set, 'l is right with vim_keys');
    }

    public function testIntsStepAndWarn(): void
    {
        $this->assertSame(['update_ms' => 2100], self::on('update_ms')->update(self::key('right'), self::context())->set, 'update_ms steps by 100');
        $this->assertSame(['net_download' => 99], self::on('net_download')->update(self::key('left'), self::context())->set);
        $low = self::context(['update_ms' => 100]);
        $r = self::on('update_ms', $low)->update(self::key('left'), $low);
        $this->assertSame([], $r->set);
        $this->assertInstanceOf(OptionsMenu::class, $r->overlay);
        $this->assertInstanceOf(MsgBox::class, $r->overlay->warning, 'btop: the rejected value opens a warning inside the menu');
        $this->assertSame('warning', $r->overlay->warning->title);
        $dismissed = $r->overlay->update(self::key('enter'), $low);
        $this->assertInstanceOf(OptionsMenu::class, $dismissed->overlay);
        $this->assertNull($dismissed->overlay->warning, 'Ok dismisses it and the menu stays open');
        $this->assertSame([], $r->overlay->update(self::key('right'), $low)->set, 'keys go to the warning only');
        // #1476: the width percent clamps at 100 instead of warning.
        $full = self::context(['proc_box_width_percent' => 100]);
        $this->assertSame(['proc_box_width_percent' => 100], self::on('proc_box_width_percent', $full)->update(self::key('right'), $full)->set);
        $zero = self::context(['proc_box_width_percent' => 0]);
        $this->assertInstanceOf(MsgBox::class, self::on('proc_box_width_percent', $zero)->update(self::key('left'), $zero)->overlay?->warning, 'below 0 is still btop\'s negative warning');
    }

    public function testListsCycleThroughTheirValues(): void
    {
        $c = self::context();
        $this->assertSame(['graph_symbol' => 'block'], self::on('graph_symbol')->update(self::key('right'), $c)->set);
        $this->assertSame(['graph_symbol' => 'tty'], self::on('graph_symbol')->update(self::key('left'), $c)->set, 'wraps to the last');
        $this->assertSame(['proc_sorting' => 'io read'], self::on('proc_sorting')->update(self::key('right'), $c)->set, 'cpu lazy -> the #1823 io sorts');
        $this->assertSame(['show_core_freq' => 'value'], self::on('show_core_freq')->update(self::key('right'), $c)->set);
        // Runtime lists come from the collectors (btop Cpu::available_fields).
        $fields = self::context(choices: ['cpu_graph_upper' => ['Auto', 'total', 'user']]);
        $this->assertSame(['cpu_graph_upper' => 'total'], self::on('cpu_graph_upper', $fields)->update(self::key('right'), $fields)->set);
        $this->assertSame(['cpu_sensor' => 'Auto'], self::on('cpu_sensor')->update(self::key('right'), $c)->set, 'only Auto until a sensor reports');
        $this->assertSame(['Auto', 'pkg/temp1'], OptionsMenu::choices('cpu_sensor', self::context(choices: ['cpu_sensor' => ['Auto', 'pkg/temp1']])));
        $this->assertNull(OptionsMenu::choices('clock_format', $c), 'free text is edited, not cycled');
    }

    public function testColorThemeCyclesTheThemeList(): void
    {
        $registry = ThemeRegistry::fromDirs(ThemeRegistry::bundledDir());
        $c = self::context(themes: $registry);
        $menu = OptionsMenu::new();
        $this->assertSame(['color_theme' => 'TTY'], $menu->update(self::key('right'), $c)->set);
        $last = $registry->entries()[count($registry->entries()) - 1];
        $this->assertSame(['color_theme' => $registry->configValue($last)], $menu->update(self::key('left'), $c)->set, 'wraps to the last theme file');
        $nord = self::context(['color_theme' => 'nord.theme'], themes: $registry);
        $set = $menu->update(self::key('right'), $nord)->set;
        $this->assertNotSame('nord.theme', $set['color_theme']);
        $this->assertStringEndsWith('.theme', $set['color_theme'], 'btop writes the file name');
        $this->assertSame(['color_theme' => 'TTY'], $menu->update(self::key('right'), self::context())->set, 'no catalog: Default and TTY only');
    }

    public function testEditingFreeTextAndInts(): void
    {
        $c = self::context();
        $menu = self::step(self::on('clock_format', $c), 'enter', $c);
        $this->assertNotNull($menu->edit);
        $this->assertSame('%X', $menu->edit->text, 'the editor starts on the current value');
        foreach (['backspace', 'backspace', '%', 'H'] as $k) {
            $menu = self::step($menu, $k, $c);
        }
        $r = $menu->update(self::key('enter'), $c);
        $this->assertSame(['clock_format' => '%H'], $r->set);
        $this->assertNull($r->overlay instanceof OptionsMenu ? $r->overlay->edit : 'x');

        $cancel = self::step(self::step(self::on('clock_format', $c), 'e', $c), 'escape', $c);
        $this->assertNull($cancel->edit, 'escape cancels');
        $this->assertNull(self::step(self::on('clock_format', $c), 'E', $c)->update(self::click(5, 5), $c)->overlay?->edit, 'a click cancels');

        $int = self::step(self::on('net_download', $c), 'enter', $c);
        $this->assertTrue($int->edit?->numeric, 'ints edit digits only');
        $int = self::step(self::step($int, 'x', $c), '0', $c);
        $this->assertSame('1000', $int->edit?->text);
        $this->assertSame(['net_download' => 1000], $int->update(self::key('enter'), $c)->set);

        $bad = self::step(self::on('cpu_core_map', $c), 'enter', $c);
        foreach (['x', 'y'] as $k) {
            $bad = self::step($bad, $k, $c);
        }
        $r = $bad->update(self::key('enter'), $c);
        $this->assertSame([], $r->set);
        $this->assertInstanceOf(MsgBox::class, $r->overlay instanceof OptionsMenu ? $r->overlay->warning : null, 'btop stringValid failure: warning');

        $width = self::step(self::on('proc_box_width_percent', $c), 'enter', $c);
        $width = self::step(self::step(self::step(self::step($width, 'backspace', $c), 'backspace', $c), '9', $c), '9', $c);
        $width = self::step($width, '9', $c);
        $this->assertSame(['proc_box_width_percent' => 100], $width->update(self::key('enter'), $c)->set, '#1476: an edited width clamps to 100');

        $this->assertNull(self::step(self::on('theme_background', $c), 'enter', $c)->edit, 'bools are not editable');
        $this->assertNull(self::step(self::on('graph_symbol', $c), 'enter', $c)->edit, 'lists are not editable');
    }

    public function testMouseSelectsEditsAndUsesTheArrows(): void
    {
        $c = self::context();
        $g = OptionsMenu::new()->geometry($c);
        [$x, $y] = [$g['x'], $g['y']];
        // Row 2 (theme_background) starts at 1-based line y + 9 + 2*2.
        $click = static fn (int $col, int $line): MouseMsg => new MouseMsg($col, $line, MouseButton::Left, MouseAction::Press);
        $r = OptionsMenu::new()->update($click($x + 5, $y + 13), $c);
        $this->assertSame('theme_background', $r->overlay instanceof OptionsMenu ? $r->overlay->selectedOption($c) : null);
        // Clicking the heading row selects the option after it (#1791b).
        $r = OptionsMenu::new()->update($click($x + 5, $y + 9), $c);
        $this->assertSame('color_theme', $r->overlay instanceof OptionsMenu ? $r->overlay->selectedOption($c) : null);
        // A second click on a selected editable row edits it.
        $clock = self::on('clock_format', $c);
        $cg = $clock->geometry($c);
        $line = $y + 9 + 2 * ($cg['index'] - $cg['page'] * $cg['ih']);
        $r = $clock->update($click($x + 5, $line), $c);
        $this->assertNotNull($r->overlay instanceof OptionsMenu ? $r->overlay->edit : null);
        // The arrows (btop mouse_mappings left/right) change the value.
        $map = self::on('update_ms', $c)->buttons($c);
        [$rx, $ry] = $map['right'];
        $this->assertSame(['update_ms' => 2100], self::on('update_ms', $c)->update(self::click($rx + 1, $ry), $c)->set);
        [$lx, $ly] = $map['left'];
        $this->assertSame(['update_ms' => 1900], self::on('update_ms', $c)->update(self::click($lx + 1, $ly + 1), $c)->set);
        // A tab click (btop select_cat_<n>, fixed 15-wide slots without a GPU).
        [$tx, $ty] = OptionsMenu::new()->buttons($c)['select_cat_2'];
        $this->assertSame('mem', OptionsMenu::new()->update(self::click($tx, $ty + 1), $c)->overlay?->category ?? null);
        $this->assertSame(12, OptionsMenu::new()->buttons(self::context(gpu: true))['select_cat_1'][2], 'gpu build: 12-wide slots');
        $this->assertSame('theme_background', self::after(['mouse_scroll_down'])->selectedOption($c));
        $this->assertSame([], self::step(self::on('clock_format', $c), 'enter', $c)->buttons($c), 'no mappings while editing');
    }

    public function testRestartAndUnusedOptionsSaySo(): void
    {
        $this->assertContains('A restart is required to apply changes.', OptionsCatalog::description('freq_mode'));
        $this->assertContains('Kept for btop.conf; candy-top ignores it.', OptionsCatalog::description('log_level'));
        $this->assertNotContains('A restart is required to apply changes.', OptionsCatalog::description('update_ms'));
    }

    public function testPaintShowsTheSelectionValueAndDescription(): void
    {
        $c = self::context();
        $surface = Surface::new(120, 40);
        self::on('update_ms', $c)->paint($surface, $c);
        $text = implode("\n", $surface->plainLines());
        $this->assertStringContainsString('[general]', $text);
        $this->assertStringContainsString('1cpu', $text, '#1411 digit labels');
        $this->assertStringContainsString('Update ms', $text);
        $this->assertStringContainsString('Update time in milliseconds.', $text);
        $this->assertStringContainsString('Runtime and rendering', $text, '#1791b heading');
        $this->assertStringContainsString('page 2/3', $text);
        $ink = $c->ink;
        $g = self::on('update_ms', $c)->geometry($c);
        $line = $g['y'] + 9 + 2 * ($g['index'] - $g['page'] * $g['ih']);
        $this->assertSame(
            Surface::canonical("\x1b[1m" . $ink->fg('selected_fg') . $ink->bg('selected_bg')),
            $surface->style($g['x'] + 1, $line - 1),
            'the selected name row is selected_bg / selected_fg, bold',
        );
    }

    public function testOnlyKeysAndMouseReachIt(): void
    {
        $menu = OptionsMenu::new();
        $this->assertTrue($menu->capturesInput());
        $r = $menu->update(new KeyMsg(KeyType::Char, 'z'), self::context());
        $this->assertSame([], $r->set);
        $this->assertInstanceOf(OverlayResult::class, $r);
    }
}
