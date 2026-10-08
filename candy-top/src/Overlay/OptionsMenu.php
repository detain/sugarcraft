<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Bits\Input\TextEdit;
use SugarCraft\Bits\Menu\OptionRow;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Config\Option;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Input\TextKeys;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\View\Banner;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * btop's options menu: the banner over a 78-wide box — category tabs on
 * top, the two-row option list (sugar-bits {@see OptionRow}) on the left,
 * the selected option's description on the right.
 *
 * Keys (btop optionsMenu, with #1411 / #1791b applied):
 *  - escape / q / o / backspace, or a click outside the box, close it;
 *  - up / down (k / j with vim_keys, the wheel) move over the options,
 *    skipping section headings and wrapping through the pages;
 *    page_up / page_down flip pages; tab / shift_tab cycle the tabs, and
 *    a digit picks one: 0 general, 1 cpu, 2 mem, 3 net, 4 proc, 5 gpu —
 *    the box-toggle digits (#1411); a click on a tab picks it too;
 *  - left / right (h / l with vim_keys, or a click on the `←` / `→`)
 *    flip a bool, step an int (update_ms by 100) or cycle an enumerated
 *    string (color_theme through the theme list, cpu_graph_* / cpu_sensor
 *    / selected_battery through what the collectors reported);
 *  - enter / e / E (or a second click on the selected row) edit an int or
 *    free-text option in a sugar-bits {@see TextEdit}: enter applies,
 *    escape or a click cancels. A value btop would reject opens btop's
 *    `warning` box inside the menu instead of being applied.
 *
 * Every change is returned in {@see OverlayResult::$set} and applied by
 * the App synchronously ({@see \SugarCraft\Top\App::applyOptions()}), so
 * the frame behind the menu — dimmed, and re-captured on each change when
 * it is frozen — previews it at once: relayout, theme reload, graph
 * symbols. Writing the file is the App's (save_config_on_exit).
 *
 * Mirrors aristocratos/btop Menu::optionsMenu (src/btop_menu.cpp:1298-1740).
 */
final class OptionsMenu implements Overlay
{
    /** btop's box width. */
    public const WIDTH = 78;

    /** Options whose `←`/`→` cycle a collector-reported list (btop optionsList). */
    private const RUNTIME_LISTS = ['cpu_graph_upper', 'cpu_graph_lower', 'cpu_sensor', 'selected_battery'];

    private function __construct(
        public readonly string $category = 'general',
        public readonly int $index = 0,
        public readonly ?TextEdit $edit = null,
        public readonly ?MsgBox $warning = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public function capturesInput(): bool
    {
        return true;
    }

    public function minSize(): array
    {
        return [80, 24];
    }

    /** The option the selection sits on under `$c` (headings skipped). */
    public function selectedOption(OverlayContext $c): string
    {
        $g = $this->geometry($c);

        return $g['entries'][$g['index']];
    }

    /**
     * btop's layout (1-based), recomputed per call: the box origin, height,
     * items per page, pages, this tab's entries and the selection normalised
     * the way btop's redraw does (page clamped, row clamped to the page,
     * then moved forward off a heading — #1791b).
     *
     * @return array{x: int, y: int, height: int, ih: int, pages: int, entries: list<string>, index: int, page: int, category: string, categories: list<string>}
     */
    public function geometry(OverlayContext $c): array
    {
        $categories = OptionsCatalog::categories($c->gpu);
        $category = in_array($this->category, $categories, true) ? $this->category : 'general';
        $max = OptionsCatalog::maxItems($c->gpu);
        $y = max(1, intdiv($c->rows, 2) - 3 - $max);
        $x = intdiv($c->cols, 2) - 39;
        $height = min($c->rows - 7, $max * 2 + 4);
        if ($height % 2 !== 0) {
            $height--;
        }
        $entries = OptionsCatalog::entries($category, $c->gpu);
        $n = count($entries);
        $ih = max(1, min($n, intdiv($height - 4, 2)));
        $pages = max(1, (int) ceil($n / $ih));
        $page = min(intdiv(max(0, $this->index), $ih), $pages - 1);
        $selectMax = min($ih - 1, $n - 1 - $ih * $page);
        $index = $ih * $page + min(max(0, $this->index) - $ih * $page, $selectMax);
        if ($index < $ih * $page) {
            $index = $ih * $page;
        }
        for ($i = 0; $i < $n && OptionsCatalog::isHeading($entries[$index]); $i++) {
            $index = ($index + 1) % $n;
        }

        return [
            'x' => $x, 'y' => $y, 'height' => $height, 'ih' => $ih, 'pages' => $pages,
            'entries' => $entries, 'index' => $index, 'page' => intdiv($index, $ih),
            'category' => $category, 'categories' => $categories,
        ];
    }

    /**
     * btop's Menu::mouse_mappings (0-based [x, y, w, h]): `select_cat_<n>`
     * per tab (not while editing) and the selected row's `left` / `right`
     * arrows (bool, int and list options, not while editing).
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public function buttons(OverlayContext $c): array
    {
        if ($this->edit !== null || $this->warning !== null) {
            return [];
        }
        $g = $this->geometry($c);
        $map = [];
        $tab = $c->gpu ? 12 : 15;
        foreach ($g['categories'] as $i => $_) {
            $map['select_cat_' . $i] = [$g['x'] + 1 + $tab * $i, $g['y'] + 5, $tab, 3];
        }
        $option = $g['entries'][$g['index']];
        if (self::isTwoD($option, $c)) {
            $line = $g['y'] + 9 + 2 * ($g['index'] - $g['page'] * $g['ih']);
            $map['left'] = [$g['x'] - 1, $line - 1, 5, 2];
            $map['right'] = [$g['x'] + 24, $line - 1, 5, 2];
        }

        return $map;
    }

    public function update(Msg $msg, OverlayContext $c): OverlayResult
    {
        if ($this->warning !== null) {
            // btop: while a warning shows, keys go to its msgBox only.
            $r = $this->warning->update($msg, $c);

            return OverlayResult::keep($this->mutate(warning: $r->overlay instanceof MsgBox ? $r->overlay : null, warningSet: true));
        }
        $g = $this->geometry($c);
        $self = $this->mutate(category: $g['category'], index: $g['index']);
        $option = $g['entries'][$g['index']];

        if ($this->edit !== null) {
            return $self->editKey($msg, $c, $option);
        }

        $vim = $c->config->bool('vim_keys');
        $key = KeyName::mapped($msg, $self->buttons($c));
        if ($key === 'mouse_click' && $msg instanceof MouseMsg) {
            return $self->click($msg, $c, $g, $option);
        }
        if (in_array($key, ['enter', 'e', 'E'], true) && self::isEditable($option, $c)) {
            return OverlayResult::keep($self->editing($option, $c->config));
        }
        if (in_array($key, ['escape', 'q', 'o', 'backspace'], true)) {
            return OverlayResult::close();
        }
        if (in_array($key, ['down', 'mouse_scroll_down'], true) || ($vim && $key === 'j')) {
            return OverlayResult::keep($self->stepped($g, 1));
        }
        if (in_array($key, ['up', 'mouse_scroll_up'], true) || ($vim && $key === 'k')) {
            return OverlayResult::keep($self->stepped($g, -1));
        }
        if ($g['pages'] > 1 && in_array($key, ['page_down', 'page_up'], true)) {
            $page = $g['page'] + ($key === 'page_down' ? 1 : -1);
            $page = $page >= $g['pages'] ? 0 : ($page < 0 ? $g['pages'] - 1 : $page);

            return OverlayResult::keep($self->mutate(index: $page * $g['ih']));
        }
        $cats = $g['categories'];
        $at = (int) array_search($g['category'], $cats, true);
        if ($key === 'tab' || $key === 'shift_tab') {
            $at = $key === 'tab' ? ($at + 1 >= count($cats) ? 0 : $at + 1) : ($at - 1 < 0 ? count($cats) - 1 : $at - 1);

            return OverlayResult::keep($self->mutate(category: $cats[$at], index: 0));
        }
        // #1411: the digit is the box toggle's — 0 general ... 5 gpu.
        $digit = str_starts_with($key, 'select_cat_') ? substr($key, 11) : $key;
        if (strlen($digit) === 1 && ctype_digit($digit) && isset($cats[(int) $digit])) {
            return OverlayResult::keep($self->mutate(category: $cats[(int) $digit], index: 0));
        }
        if (in_array($key, ['left', 'right'], true) || ($vim && in_array($key, ['h', 'l'], true))) {
            return $self->changed($option, $key === 'right' || $key === 'l', $c);
        }

        return OverlayResult::keep($self);
    }

    /** btop's mouse_click branch: outside closes, a row selects (twice: edits). */
    private function click(MouseMsg $msg, OverlayContext $c, array $g, string $option): OverlayResult
    {
        // btop compares the 1-based mouse cell against its 1-based origin.
        [$mx, $my, $x, $y] = [$msg->x, $msg->y, $g['x'], $g['y']];
        if ($mx < $x || $mx > $x + 80 || $my < $y + 6 || $my > $y + 6 + $g['height']) {
            return OverlayResult::close();
        }
        if ($mx < $x + 30 && $my > $y + 8) {
            $row = (int) ceil(($my - $y - 8) / 2) - 1;
            $target = $g['page'] * $g['ih'] + $row;
            if ($target !== $g['index']) {
                return OverlayResult::keep($this->mutate(index: $target));
            }
            if (self::isEditable($option, $c)) {
                return OverlayResult::keep($this->editing($option, $c->config));
            }
        }

        return OverlayResult::keep($this);
    }

    /** #1791b select_next_option: step, wrapping over the tab, skipping headings. */
    private function stepped(array $g, int $direction): self
    {
        $n = count($g['entries']);
        $index = $g['index'];
        for ($i = 0; $i < $n; $i++) {
            $index = ($index + $direction + $n) % $n;
            if (!OptionsCatalog::isHeading($g['entries'][$index])) {
                break;
            }
        }

        return $this->mutate(index: $index);
    }

    private function editing(string $option, Config $config): self
    {
        return $this->mutate(edit: TextEdit::new($config->asString($option), numeric: OptionsCatalog::isInt($option)), editSet: true);
    }

    /** btop's `editing` branch: escape / a click cancel, enter applies, the rest edits. */
    private function editKey(Msg $msg, OverlayContext $c, string $option): OverlayResult
    {
        $key = KeyName::of($msg);
        if ($key === 'escape' || $key === 'mouse_click') {
            return OverlayResult::keep($this->mutate(edit: null, editSet: true));
        }
        $edit = $this->edit ?? TextEdit::new();
        if ($key === 'enter') {
            $closed = $this->mutate(edit: null, editSet: true);
            try {
                $value = self::parsed($option, $edit->text, $c->config);
            } catch (InvalidOptionValue $e) {
                return OverlayResult::keep($closed->warned($e->getMessage()));
            }

            return new OverlayResult($closed, null, null, [$option => $value]);
        }
        $next = $msg instanceof KeyMsg ? TextKeys::apply($edit, $msg) : null;

        return OverlayResult::keep($next === null ? $this : $this->mutate(edit: $next, editSet: true));
    }

    /**
     * The edited text as the value btop would store: ints through the
     * file law (digits, range) — proc_box_width_percent clamped to 100
     * first (#1476) — strings through the option's string law.
     *
     * @throws InvalidOptionValue
     */
    private static function parsed(string $option, string $text, Config $config): bool|int|string
    {
        if ($option === 'proc_box_width_percent' && $text !== '' && ctype_digit($text) && strlen(ltrim($text, '0')) <= 10 && (int) $text <= Option::INT_MAX) {
            $text = (string) min(100, (int) $text);
        }

        return OptionsCatalog::isInt($option)
            ? $config->withParsed($option, $text)->int($option)
            : $config->with($option, $text)->string($option);
    }

    /**
     * btop's left / right branch: step an int, flip a bool, cycle a list —
     * returning the writes (plus btop's companion writes: truecolor flips
     * lowcolor, force_tty drives tty_mode off a real console) or a warning
     * for a value btop rejects.
     */
    private function changed(string $option, bool $right, OverlayContext $c): OverlayResult
    {
        $config = $c->config;
        if (OptionsCatalog::isInt($option)) {
            $value = $config->int($option) + ($option === 'update_ms' ? 100 : 1) * ($right ? 1 : -1);
            if ($option === 'proc_box_width_percent' && $value >= 0) {
                $value = min(100, $value);
            }
            try {
                $config->with($option, $value);
            } catch (InvalidOptionValue $e) {
                return OverlayResult::keep($this->warned($e->getMessage()));
            }

            return new OverlayResult($this, null, null, [$option => $value]);
        }
        if (OptionsCatalog::isBool($option)) {
            $on = !$config->bool($option);
            $set = [$option => $on];
            if ($option === 'truecolor') {
                $set['lowcolor'] = !$config->bool('lowcolor');
            } elseif ($option === 'force_tty' && !$config->bool('tty_console')) {
                // btop: only when not on a real /dev/tty console (bin's
                // posix_ttyname check, carried as runtime tty_console).
                $set['tty_mode'] = $on;
            }

            return new OverlayResult($this, null, null, $set);
        }
        $list = self::choices($option, $c);
        if ($list === null) {
            return OverlayResult::keep($this);
        }
        $count = count($list);
        $i = self::position($option, $list, $c);
        if ($right) {
            $i = $i + 1 >= $count ? 0 : $i + 1;
        } else {
            $i = $i - 1 < 0 ? $count - 1 : $i - 1;
        }
        $value = $option === 'color_theme' ? self::registry($c)->configValue(self::registry($c)->entries()[$i]) : $list[$i];
        try {
            $config->with($option, $value);
        } catch (InvalidOptionValue $e) {
            return OverlayResult::keep($this->warned($e->getMessage()));
        }

        return new OverlayResult($this, null, null, [$option => $value]);
    }

    /** btop's warning msgBox: `min(78, ulen + 10)` wide, the text cut to 74. */
    private function warned(string $text): self
    {
        $box = MsgBox::ok(
            min(78, mb_strlen($text) + 10),
            Lang::t('overlay.title.warning'),
            static fn (Ink $ink): array => [MenuDraw::cut($text, 74)],
        );

        return $this->mutate(warning: $box, warningSet: true);
    }

    /**
     * The values `←`/`→` cycle for a list option (btop optionsList), null
     * for options edited as text.
     *
     * @return list<string>|null
     */
    public static function choices(string $option, OverlayContext $c): ?array
    {
        if ($option === 'color_theme') {
            return self::registry($c)->names();
        }
        if (in_array($option, self::RUNTIME_LISTS, true)) {
            return $c->choices[$option] ?? (str_starts_with($option, 'cpu_graph_') ? Schema::CPU_GRAPH_FIELDS : ['Auto']);
        }
        $allowed = Schema::option($option)?->allowed;

        return $allowed === null ? null : array_values($allowed);
    }

    /** btop's index of the current value in the list; the list size when absent. */
    private static function position(string $option, array $list, OverlayContext $c): int
    {
        if ($option === 'color_theme') {
            $current = $c->config->colorTheme();
            foreach (self::registry($c)->entries() as $i => $entry) {
                if ($entry->path() === $current || $entry->matches($current)) {
                    return $i;
                }
            }

            return count($list);
        }
        $i = array_search($c->config->string($option), $list, true);

        return $i === false ? count($list) : (int) $i;
    }

    private static function registry(OverlayContext $c): ThemeRegistry
    {
        return $c->themes ?? ThemeRegistry::fromDirs();
    }

    /** btop is2D || isBrowsable: the row shows `←`/`→`. */
    private static function isTwoD(string $option, OverlayContext $c): bool
    {
        return !OptionsCatalog::isHeading($option)
            && (OptionsCatalog::isInt($option) || OptionsCatalog::isBool($option) || self::choices($option, $c) !== null);
    }

    /** btop isEditable: an int, or a string that is not a list. */
    private static function isEditable(string $option, OverlayContext $c): bool
    {
        return OptionsCatalog::isInt($option)
            || (!OptionsCatalog::isBool($option) && !OptionsCatalog::isHeading($option) && self::choices($option, $c) === null);
    }

    public function paint(Surface $surface, OverlayContext $c): void
    {
        $ink = $c->ink;
        $g = $this->geometry($c);
        ['x' => $x, 'y' => $y, 'height' => $height] = $g;
        $border = $c->border();
        $hi = $ink->fg('hi_fg');
        $bold = MenuDraw::BOLD;

        Banner::paint($surface, $y - 1, $ink, $c->tty());
        MenuDraw::box($surface, $c, $x, $y + 6, self::WIDTH, $height, $hi . 'tab' . $ink->fg('main_fg') . '→');
        $clip = MenuDraw::inside($x, $y + 6, self::WIDTH, $height);
        $frame = Rect::new($x - 1, $y + 5, self::WIDTH, $height);
        MenuDraw::at(
            $surface,
            $y + 8,
            $x,
            $hi . '├' . $ink->fg('div_line') . str_repeat('─', 29) . '┬' . str_repeat('─', self::WIDTH - 32) . $hi . '┤',
            '',
            $frame,
        );
        MenuDraw::at($surface, $y + 5 + $height, $x + 30, $hi . '┴', '', $frame);
        for ($i = 0; $i < $height - 4; $i++) {
            MenuDraw::at($surface, $y + 9 + $i, $x + 30, $ink->fg('div_line') . '│', '', $clip);
        }

        // Category tabs: `[name]` for the open one, `<digit>name ` else, then
        // btop's Mv::r(7) (gpu build) / Mv::r(10) gap.
        $col = $x + 4;
        foreach ($g['categories'] as $i => $cat) {
            $label = Lang::t('menu.options.category.' . $cat);
            $text = $cat === $g['category']
                ? $hi . '[' . $ink->fg('title') . $label . $hi . ']'
                : $hi . $i . $ink->fg('title') . $label . ' ';
            $col += MenuDraw::at($surface, $y + 7, $col, $bold . $text, '', $clip) + ($c->gpu ? 7 : 10);
        }

        if ($g['pages'] > 1) {
            [$open, $close] = $border->embedJunctions(true);
            MenuDraw::at(
                $surface,
                $y + 5 + $height,
                $x + 2,
                $hi . $open . $bold . '↑' . $ink->fg('title') . ' '
                    . Lang::t('overlay.options.page', ['page' => $g['page'] + 1, 'pages' => $g['pages']]) . ' '
                    . $hi . '↓' . MenuDraw::UNBOLD . $close,
                '',
                $frame,
            );
        }

        $styles = self::rowStyles($ink);
        $cy = $y + 9;
        $entries = $g['entries'];
        $from = $g['page'] * $g['ih'];
        for ($i = $from; $i < min(count($entries), $from + $g['ih']); $i++) {
            $entry = $entries[$i];
            if (OptionsCatalog::isHeading($entry)) {
                MenuDraw::at($surface, $cy, $x + 2, $hi . $bold . MenuDraw::cut(OptionsCatalog::heading($entry), 27) . MenuDraw::UNBOLD, '', $clip);
                $cy += 2;
                continue;
            }
            $selected = $i === $g['index'];
            $list = self::choices($entry, $c);
            $value = $entry === 'color_theme' ? pathinfo($c->config->colorTheme(), PATHINFO_FILENAME) : $c->config->asString($entry);
            $name = OptionsCatalog::label($entry);
            if ($selected && $list !== null) {
                $name .= ' ' . (self::position($entry, $list, $c) + 1) . '/' . count($list);
            }
            $editing = $selected && $this->edit !== null;
            [$line1, $line2] = explode("\n", OptionRow::render(
                $name,
                $editing ? $this->edit : $value,
                $selected,
                $editing,
                self::isTwoD($entry, $c),
                selBg: $styles['selBg'],
                selFg: $styles['selFg'],
                editable: self::isEditable($entry, $c),
                tty: $c->tty(),
                title: $styles['title'],
                main: $styles['main'],
            ));
            MenuDraw::at($surface, $cy, $x + 1, $line1, '', $clip);
            MenuDraw::at($surface, $cy + 1, $x + 1, $line2, '', $clip);
            $cy += 2;
            if ($selected) {
                $this->paintDescription($surface, $c, $entry, $x, $y, $height, $clip);
            }
        }

        $this->warning?->paint($surface, $c);
    }

    /** The selected option's description at x + 32: btop's bold title line, then main_fg (cut at the box bottom). */
    private function paintDescription(Surface $surface, OverlayContext $c, string $option, int $x, int $y, int $height, Rect $clip): void
    {
        $ink = $c->ink;
        $cyy = $y + 8;
        foreach (OptionsCatalog::description($option) as $n => $line) {
            $cyy++;
            if ($cyy > $y + $height + 4) {
                break;
            }
            $sgr = $n === 0 ? $ink->fg('title') . MenuDraw::BOLD : $ink->fg('main_fg');
            MenuDraw::at($surface, $cyy, $x + 32, $sgr . $line, '', $clip);
        }
    }

    /**
     * The four OptionRow styles from the theme, rendered in the App's
     * colour profile (the same escapes {@see Ink} produces).
     *
     * @return array{selBg: Style, selFg: Style, title: Style, main: Style}
     */
    private static function rowStyles(Ink $ink): array
    {
        $style = static function (string $key, bool $bg) use ($ink): Style {
            $color = $ink->palette()->color($key);
            $s = Style::new()->colorProfile($ink->profile());
            if ($color === null) {
                return $s;
            }

            return $bg ? $s->background($color) : $s->foreground($color);
        };

        return [
            'selBg' => $style('selected_bg', true),
            'selFg' => $style('selected_fg', false),
            'title' => $style('title', false),
            'main' => $style('main_fg', false),
        ];
    }

    private function mutate(
        ?string $category = null,
        ?int $index = null,
        ?TextEdit $edit = null,
        bool $editSet = false,
        ?MsgBox $warning = null,
        bool $warningSet = false,
    ): self {
        return new self(
            $category ?? $this->category,
            $index ?? $this->index,
            $editSet ? $edit : $this->edit,
            $warningSet ? $warning : $this->warning,
        );
    }
}
