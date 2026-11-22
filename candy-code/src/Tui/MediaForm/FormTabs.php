<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Theme;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Tabs\ScrollWindow;
use SugarCraft\Widgets\Tabs\Tabs;
use SugarCraft\Widgets\Tabs\TabSpan;

/**
 * The generate form's section strip (crush-media W3.7): the tab headers
 * above the rows, the `media:tab:N` hit spans, and the jump keys between
 * sections.
 *
 * Drawn by sugar-widgets Tabs (P2-2), configured to the frame the strip
 * drew through SettingsTabStrip: a bare `│` divider, ` label ` cells, `‹`/`›`
 * scroll marks joined to the tabs, and the first window that keeps the
 * active tab. The strip and its hit spans both come from that one bar.
 * Hit-testing reads {@see Tabs::spans()} rather than the bar's Mark zones:
 * MediaForm records its `media:tab:N` zones from columns, and the frame
 * carries no zone scanner, so zone markup would reach the terminal.
 *
 * KEYBOARD RULING (W3.7). The strip is reached by the section-jump keys —
 * Left/Right, Tab, h/l, and (new here) PageUp/PageDown — and by clicking a
 * `media:tab:N` zone; Up/Down stay row navigation. That keeps every widget
 * key conflict out of existence: NumberField's ↑/↓ inc/dec lives only
 * inside the editing branch of {@see MediaForm::update()} where the field
 * owns the keys outright, so no separate strip-focus region (and no
 * MediaFormState change) is needed for the strip to be fully operable.
 * The bar is never focused and never sees a key, so its own key map is
 * inert here.
 */
final class FormTabs
{
    private function __construct()
    {
    }

    /**
     * Paint the strip and report the span each painted tab occupies.
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

    /**
     * The columns the WHOLE strip would occupy unscrolled: every
     * `' label '` cell plus one `│` separator between neighbors, with no
     * `‹`/`›` scroll marks (P-IMAGE-MENU — the popup's natural width is
     * measured so the tab bar is never cut; the bar's own scroll window
     * stays the fallback for a band narrower than this).
     *
     * Mirrors the cell/separator math {@see render()} configures; the marks
     * and the active-tab reverse style cost no extra cells.
     *
     * @param list<string> $labels
     */
    public static function naturalWidth(array $labels): int
    {
        if ($labels === []) {
            return 0;
        }

        $used = 0;
        foreach ($labels as $label) {
            $used += Width::string(' ' . $label . ' ');
        }

        return $used + count($labels) - 1;
    }
}
