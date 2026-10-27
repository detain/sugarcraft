<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Tui\Settings\SettingsTabStrip;
use SugarCraft\Code\Theme;

/**
 * The generate form's section strip (crush-media W3.7): the tab headers
 * above the rows, the `media:tab:N` hit spans, and the jump keys between
 * sections.
 *
 * Deliberately crush-local over sugar-widgets Tabs: the same reasoning that
 * keeps SettingsTabStrip local (its sugar-mouse hit-testing pairs with the
 * mouse layer this form already speaks) applies one level up — no new dep
 * to repaint six labels with.
 *
 * KEYBOARD RULING (W3.7). The strip is reached by the section-jump keys —
 * Left/Right, Tab, h/l, and (new here) PageUp/PageDown — and by clicking a
 * `media:tab:N` zone; Up/Down stay row navigation. That keeps every widget
 * key conflict out of existence: NumberField's ↑/↓ inc/dec lives only
 * inside the editing branch of {@see MediaForm::update()} where the field
 * owns the keys outright, so no separate strip-focus region (and no
 * MediaFormState change) is needed for the strip to be fully operable.
 */
final class FormTabs
{
    private function __construct()
    {
    }

    /**
     * Paint the strip: delegates byte-for-byte to the settings engine so a
     * theme tweak lands on both surfaces at once.
     *
     * @param list<string> $labels
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int}>} [line, [from, to (exclusive), tab index]]
     */
    public static function render(array $labels, int $active, int $width, Theme $theme): array
    {
        return SettingsTabStrip::render($labels, $active, $width, $theme);
    }

    /**
     * The columns the WHOLE strip would occupy unscrolled: every
     * `' label '` cell plus one `│` separator between neighbours, with no
     * `‹`/`›` scroll marks (P-IMAGE-MENU — the popup's natural width is
     * measured so the tab bar is never cut; SettingsTabStrip's own scroll
     * arithmetic stays the fallback for a band narrower than this).
     *
     * Mirrors SettingsTabStrip's cell/separator math exactly; the marks and
     * the active-tab reverse style cost no extra cells.
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
