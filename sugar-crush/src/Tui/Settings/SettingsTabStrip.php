<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Theme;

/**
 * The settings view's category strip: one line, the active tab highlighted,
 * scrolled sideways so the active tab is always on it.
 *
 * Local rather than `sugar-bits`' `Tabs` because that one hit-tests through
 * `candy-zone` while this app's clicks run on `candy-mouse`; the strip reports
 * each painted tab's column span instead, and the shell records those as
 * `settings:tab:<n>` zones (the `MenuBar` pane-tab pattern).
 */
final class SettingsTabStrip
{
    private const SEPARATOR = '│';

    private function __construct()
    {
    }

    /**
     * The strip and the span each painted tab occupies.
     *
     * @param list<string> $labels
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int}>} [line, [from, to (exclusive), tab index]]
     */
    public static function render(array $labels, int $active, int $width, Theme $theme): array
    {
        if ($labels === [] || $width <= 0) {
            return ['', []];
        }

        $cells = array_map(static fn (string $label): string => ' ' . $label . ' ', $labels);
        $first = self::firstVisible($cells, $active, $width);

        $line = '';
        $spans = [];
        $col = 0;
        if ($first > 0) {
            $line .= Style::new()->foreground($theme->shellMuted)->render('‹');
            $col = 1;
        }

        for ($i = $first, $n = \count($cells); $i < $n; $i++) {
            $cell = $cells[$i];
            $w = Width::string($cell);
            $sep = $i > $first ? 1 : 0;
            // Keep a cell for the "more" mark when tabs remain to the right.
            $reserve = $i < $n - 1 ? 1 : 0;
            if ($col + $sep + $w + $reserve > $width) {
                $line .= Style::new()->foreground($theme->shellMuted)->render('›');
                break;
            }

            if ($sep === 1) {
                $line .= Style::new()->foreground($theme->shellSeparator)->render(self::SEPARATOR);
                $col++;
            }

            $style = $i === $active
                ? Style::new()->foreground($theme->shellPrimary)->bold()->reverse()
                : Style::new()->foreground($theme->shellForeground);
            $line .= $style->render($cell);
            $spans[] = [$col, $col + $w, $i];
            $col += $w;
        }

        return [$line, $spans];
    }

    /**
     * The leftmost tab to paint so that `$active` still fits.
     *
     * @param list<string> $cells
     */
    private static function firstVisible(array $cells, int $active, int $width): int
    {
        $first = 0;
        while ($first < $active) {
            $used = $first > 0 ? 1 : 0;
            for ($i = $first; $i <= $active; $i++) {
                $used += Width::string($cells[$i]) + ($i > $first ? 1 : 0);
            }

            // One more cell for the "›" mark if anything is right of $active.
            if ($used + ($active < \count($cells) - 1 ? 1 : 0) <= $width) {
                return $first;
            }

            $first++;
        }

        return $first;
    }
}
