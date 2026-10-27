<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Menu;

use SugarCraft\Code\Theme;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Style\Style;

/**
 * Turns a {@see MenuTree} and a {@see MenuCursor} into painted
 * {@see MenuPanel} boxes — the whole geometry of a nested cascade:
 *
 *  - the root panel hangs under the open menu's title (`titleCol`, 1-based,
 *    `top`, 0-based line) and shifts left when it would cross `cols`;
 *  - every entered submenu, and a merely HOVERED one while `expanded`, is
 *    placed on its parent's highlighted row, flush against the parent's
 *    right border; when that crosses the screen the child flips to the
 *    parent's left border, and when neither side fits it right-aligns to
 *    the screen;
 *  - a panel taller than the band left below its top is first shifted up,
 *    then — still too tall — windowed around the highlighted row with one
 *    faint `…` row per clipped side, the same shape the legacy
 *    `Tui\Renderer::dropdownOverflowLine()` draws for overflow menus;
 *  - rows render `✓`/blank check columns for toggles, `icon label` for
 *    everything, a right-aligned ` ▸` on submenu rows, and `├───┤` rules
 *    for separators;
 *  - colours ride the tokens `MenuBar::dropdownLines()` uses — `border` for
 *    the box, `shellForeground` for rows, `shellPrimary` + bold for the
 *    highlight, `shellMuted` for disabled rows;
 *  - in `marked` mode every SELECTABLE row line is wrapped in
 *    `Mark::zone($node->zone ?? 'menuitem:<dot-path>')`, the dot-path
 *    being the 1-based row indexes of the panels down to this row WITHOUT
 *    the menu index (root row 3 is `menuitem:3`; row 1 of the submenu it
 *    opened is `menuitem:3.1`), so the marked twin covers exactly the cells
 *    the painted twin shows;
 *  - width law: no panel line's VISIBLE cells — either twin — are wider
 *    than `cols`; zone sentinels and their id bytes are click metadata,
 *    excluded from the measure (see {@see MenuPanel::width()}).
 *
 * Layout is pure: it reads the cursor's row indexes and the tree, writes
 * nothing, and never reorders panels — later panels composite over earlier
 * ones by paint order (child borders over parent rows, Windows-style).
 */
final class MenuCascade
{
    /** Zone-id prefix of every clickable row that carries no explicit zone. */
    public const ZONE_PREFIX = 'menuitem:';

    /**
     * @return list<MenuPanel> root first, then each entered panel, then the
     *                         hovered preview — paint in this order
     */
    public static function layout(
        MenuTree $tree,
        MenuCursor $cursor,
        Theme $theme,
        int $titleCol,
        int $top,
        int $cols,
        int $rows,
        bool $marked = false,
    ): array {
        if ($cursor->isClosed() || $cursor->rows === []) {
            return [];
        }

        $menu = $cursor->menu - 1;
        $dropdown = $tree->rows([$menu]);

        if ($dropdown === []) {
            return [];
        }

        /** @var list<array{path: list<int>, rows: list<MenuNode>, hl: int}> $plans */
        $plans = [['path' => [], 'rows' => $dropdown, 'hl' => $cursor->rows[0]]];

        for ($depth = 1; $depth < count($cursor->rows); $depth++) {
            $path = array_slice($cursor->rows, 0, $depth);
            $plans[] = [
                'path' => $path,
                'rows' => $tree->rows([$menu, ...$path]),
                'hl' => $cursor->rows[$depth],
            ];
        }

        if ($cursor->expanded) {
            $tail = $tree->node($cursor->absolutePath());

            if ($tail !== null && $tail->kind === MenuNodeKind::Submenu) {
                $children = $tree->rows($cursor->absolutePath());

                if ($children !== []) {
                    $plans[] = ['path' => $cursor->rows, 'rows' => $children, 'hl' => 0];
                }
            }
        }

        $dim = Style::new()->foreground($theme->border);
        $item = Style::new()->foreground($theme->shellForeground);
        $selected = Style::new()->foreground($theme->shellPrimary)->bold();
        $muted = Style::new()->foreground($theme->shellMuted);

        /** @var list<MenuPanel> $panels */
        $panels = [];
        /** @var list<array<int, int>> $slotsByPanel */
        $slotsByPanel = [];

        foreach ($plans as $index => $plan) {
            if ($plan['rows'] === []) {
                // A path the tree stopped answering (a cursor outliving the
                // tree it was built on): drop this panel and all below it.
                break;
            }

            $inner = self::innerWidth($plan['rows'], $cols);
            $boxWidth = $inner + 4;

            if ($index === 0) {
                $row = max(0, $top);
                $col = max(1, min($titleCol, $cols - $boxWidth + 1));
            } else {
                $parent = $panels[$index - 1];
                $spawnIndex = $plan['path'][count($plan['path']) - 1];
                $parentSlot = $slotsByPanel[$index - 1][$spawnIndex] ?? 0;

                $row = max(0, min($parent->row + 1 + $parentSlot, max(0, $rows - 2)));

                // Flush against the parent's right border; flip to its left
                // border when the right side would cross the screen; when
                // even the flip does not fit, right-align to the screen.
                $col = $parent->col + $parent->width() - 1;

                if ($col + $boxWidth - 1 > $cols) {
                    $col = $parent->col - $boxWidth + 1;
                }

                if ($col < 1 || $col + $boxWidth - 1 > $cols) {
                    $col = max(1, $cols - $boxWidth + 1);
                }
            }

            // Shift the whole box up when the band below its top cannot hold
            // it — before reaching for windowing, exactly the plan's order.
            $natural = count($plan['rows']) + 2;

            if ($row + $natural > $rows) {
                $row = max(0, $rows - $natural);
            }

            [$lines, $display, $slots, $windowStart] = self::paint(
                $plan['rows'],
                $plan['hl'],
                $inner,
                $plan['path'],
                $marked,
                $dim,
                $item,
                $selected,
                $muted,
                max(1, $rows - $row - 2),
            );

            $panels[] = MenuPanel::new($col, $row, $lines, $display, $plan['path'], $windowStart);
            $slotsByPanel[] = $slots;
        }

        return $panels;
    }

    /** Widest row content, capped so the box itself can never exceed the screen. */
    private static function innerWidth(array $nodeRows, int $cols): int
    {
        $natural = 1;

        foreach ($nodeRows as $node) {
            if ($node->kind === MenuNodeKind::Separator) {
                continue;
            }

            $width = Width::string(self::rowText($node));

            if ($node->kind === MenuNodeKind::Submenu) {
                $width += 2;
            }

            $natural = max($natural, $width);
        }

        return max(1, min($natural, $cols - 4));
    }

    /** `✓`/blank check column for toggles, then icon, then label. */
    private static function rowText(MenuNode $node): string
    {
        $text = '';

        if ($node->kind === MenuNodeKind::Toggle) {
            $text .= $node->checked ? '✓ ' : '  ';
        }

        if ($node->icon !== '') {
            $text .= $node->icon . ' ';
        }

        return $text . $node->label;
    }

    /**
     * Paint one box at inner width $inner, windowed to at most $interior
     * content lines around the highlighted row.
     *
     * @param list<MenuNode> $nodeRows
     * @param list<int>      $path dropdown indexes of the panels above
     * @return array{0: list<string>, 1: list<MenuNode>, 2: array<int, int>, 3: int}
     *         lines (borders first/last), the displayed nodes in order, the
     *         content-slot of each displayed FULL row index (child placement
     *         and tests both read the map), and the window's start index
     */
    private static function paint(
        array $nodeRows,
        int $highlight,
        int $inner,
        array $path,
        bool $marked,
        Style $dim,
        Style $item,
        Style $selected,
        Style $muted,
        int $interior,
    ): array {
        $total = count($nodeRows);

        if ($total <= $interior) {
            $start = 0;
            $visible = $total;
            $topEllipsis = false;
            $bottomEllipsis = false;
        } else {
            $visible = $interior;
            $start = self::windowStart($highlight, $visible, $total);

            if ($start > 0 && $start + $visible < $total && $visible >= 3) {
                $visible -= 2;
                $start = self::windowStart($highlight, $visible, $total);
                $topEllipsis = true;
                $bottomEllipsis = true;
            } elseif ($start > 0 && $visible >= 2) {
                $visible -= 1;
                $start = self::windowStart($highlight, $visible, $total);
                $topEllipsis = true;
            } elseif ($start + $visible < $total && $visible >= 2) {
                $visible -= 1;
                $bottomEllipsis = true;
            }
        }

        $display = array_slice($nodeRows, $start, max(0, $visible));

        /** @var array<int, int> $slots */
        $slots = [];
        /** @var list<string> $content */
        $content = [];

        if ($topEllipsis) {
            $content[] = self::overflowLine($inner, $dim);
        }

        foreach (array_values($display) as $offset => $node) {
            $rowIndex = $start + $offset;
            $slots[$rowIndex] = count($content);

            if ($node->kind === MenuNodeKind::Separator) {
                $content[] = $dim->render('├' . str_repeat('─', $inner + 2) . '┤');
                continue;
            }

            $text = self::clip(self::rowText($node), $inner - ($node->kind === MenuNodeKind::Submenu ? 2 : 0));

            if ($node->kind === MenuNodeKind::Submenu) {
                $text .= ' ▸';
            }

            $text .= str_repeat(' ', max(0, $inner - Width::string($text)));

            if ($rowIndex === $highlight) {
                $style = $selected;
            } else {
                $style = $node->enabled ? $item : $muted;
            }

            $line = $dim->render('│ ') . $style->render($text) . $dim->render(' │');
            $content[] = $marked && $node->selectable()
                ? Mark::zone($node->zone ?? self::zoneId($path, $rowIndex), $line)
                : $line;
        }

        // Windowing always fills its band exactly (the ellipsis budget comes
        // out of $visible), so filler only guards arithmetic corners; a
        // NON-windowed panel keeps its natural height and never pads.
        $fill = $total <= $interior ? 0 : max(0, $interior - count($content) - ($bottomEllipsis ? 1 : 0));

        for ($blank = 0; $blank < $fill; $blank++) {
            $content[] = self::blankLine($inner, $dim);
        }

        if ($bottomEllipsis) {
            $content[] = self::overflowLine($inner, $dim);
        }

        $lines = [
            $dim->render('┌' . str_repeat('─', $inner + 2) . '┐'),
            ...$content,
            $dim->render('└' . str_repeat('─', $inner + 2) . '┘'),
        ];

        return [$lines, array_values($display), $slots, $start];
    }

    private static function windowStart(int $highlight, int $visible, int $total): int
    {
        return max(0, min($highlight - intdiv($visible, 2), $total - $visible));
    }

    private static function overflowLine(int $inner, Style $dim): string
    {
        return $dim->render('│ …' . str_repeat(' ', max(0, $inner - 1)) . ' │');
    }

    private static function blankLine(int $inner, Style $dim): string
    {
        return $dim->render('│ ' . str_repeat(' ', $inner) . ' │');
    }

    /**
     * @param list<int> $path
     */
    private static function zoneId(array $path, int $rowIndex): string
    {
        $numbers = [...$path, $rowIndex];

        return self::ZONE_PREFIX . implode('.', array_map(static fn(int $i): string => (string) ($i + 1), $numbers));
    }

    private static function clip(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (Width::string($text) <= $limit) {
            return $text;
        }

        if ($limit === 1) {
            return '…';
        }

        $cut = '';
        $width = 0;

        foreach (mb_str_split($text) as $character) {
            $characterWidth = Width::string($character);

            if ($width + $characterWidth > $limit - 1) {
                break;
            }

            $cut .= $character;
            $width += $characterWidth;
        }

        return $cut . '…';
    }
}
