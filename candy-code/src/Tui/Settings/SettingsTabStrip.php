<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Settings;

use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use SugarCraft\Code\Theme;
use SugarCraft\Widgets\Tabs\ScrollWindow;
use SugarCraft\Widgets\Tabs\Tabs;
use SugarCraft\Widgets\Tabs\TabSpan;

/**
 * The settings view's category strip: one line, the active tab highlighted,
 * scrolled sideways so the active tab is always on it.
 *
 * Drawn by sugar-widgets Tabs (P2-2), configured to the frame the strip drew
 * with its own scroll arithmetic: a bare `│` divider, ` label ` cells,
 * `‹`/`›` scroll marks joined to the tabs, and the first window that keeps
 * the active tab. The strip and its hit spans both come from that one bar.
 * Hit-testing reads {@see Tabs::spans()} rather than the bar's Mark zones:
 * the shell records each span as a `settings:tab:<n>` zone from columns (the
 * `MenuBar` pane-tab pattern), and the settings frame carries no zone
 * scanner, so zone markup would reach the terminal. The bar is never focused
 * and never sees a key; SettingsEditor keeps its own tab keys.
 */
final class SettingsTabStrip
{
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

        $mark = Style::new()->foreground($theme->shellMuted);

        // Narrow-width guard: when the active tab cannot fit with the marks
        // around it, the strip has always drawn the marks alone, with no tab
        // and no hit span; Tabs would draw the active tab clipped instead.
        $marks = ($active > 0 ? 1 : 0) + ($active < \count($labels) - 1 ? 1 : 0);
        if (Width::string(' ' . $labels[$active] . ' ') + $marks > $width) {
            return [($active > 0 ? $mark->render('‹') : '') . $mark->render('›'), []];
        }

        $bar = Tabs::new($labels, $width)
            ->withDivider('│')
            ->withDividerStyle(Style::new()->foreground($theme->shellSeparator))
            ->withActiveStyle(Style::new()->foreground($theme->shellPrimary)->bold()->reverse())
            ->withInactiveStyle(Style::new()->foreground($theme->shellForeground))
            ->withLabelPadding(1, 1)
            ->withScrollMarks('‹', '›', $mark, separatorAfterLeft: false, separatorBeforeRight: false)
            ->withScrollWindow(ScrollWindow::FirstKeepingActive)
            ->withActive($active);

        return [
            $bar->view(),
            array_map(static fn (TabSpan $span): array => [$span->start, $span->end, $span->index], $bar->spans()),
        ];
    }
}
