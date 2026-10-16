<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\Tui\Settings\SettingsTabStrip;
use SugarCraft\Crush\Theme;

/**
 * The generate form's section strip (crush-media W3.7): the tab headers
 * above the rows, the `media:tab:N` hit spans, and the jump keys between
 * sections.
 *
 * Deliberately crush-local over sugar-widgets Tabs: the same reasoning that
 * keeps SettingsTabStrip local (its candy-mouse hit-testing pairs with the
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
}
