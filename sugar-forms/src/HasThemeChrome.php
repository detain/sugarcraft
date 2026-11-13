<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\Theme;

/**
 * Shared theme-application seam for SF-9 (sugar-forms-007/-029/-094).
 *
 * Mirrors charmbracelet/huh's theme application inside each widget's
 * `View()` — the theme is a RENDER-TIME context, not per-field state:
 * `Form::view()` passes the resolved theme (group override, else form
 * level) down through every field's `view()` and into the list/picker
 * widgets those fields compose.
 *
 * Contract pinned by tests/ThemeRenderTest.php:
 *  - `$theme === null` is the standalone path — every helper returns the
 *    legacy plain bytes, so a field rendered outside a Form is
 *    byte-identical to the pre-SF-9 render (and goldens never move).
 *  - the default preset (Theme::ansi()) keeps its FIELD slots at the
 *    styles the legacy hard-coded code already painted (reverse cursor,
 *    plain options, red inline error), so in-form default renders move
 *    only where SF-9 INTENDS new pixels: the focused-Select caret, the
 *    blurred-list dim and the checked-box selectedOption styling.
 */
trait HasThemeChrome
{
    /**
     * Focus-aware title row. Uses the theme's focused/blurred title slot;
     * returns the raw title when unthemed (standalone) or empty.
     */
    private function themedTitle(string $title, ?Theme $theme): string
    {
        if ($theme === null || $title === '') {
            return $title;
        }
        return ($this->isFocused() ? $theme->focusedTitle : $theme->blurredTitle)->render($title);
    }

    /**
     * The field-inline `! error` row, styled by the theme's error slot
     * (cleaned text arrives already sanitized — the theme's SGR is the
     * only escape sequence the styled row carries).
     */
    private function themedErrorRow(string $cleanedError, ?Theme $theme): string
    {
        $row = '! ' . $cleanedError;
        return $theme === null ? $row : $theme->error->render($row);
    }

    /**
     * The highlight/cursor surface: theme cursor slot when themed, else
     * the legacy hard-coded reverse-video pair (byte-identical to the
     * ansi() preset's `Style::reverse()`).
     */
    private static function themedHighlight(string $text, ?Theme $theme): string
    {
        return $theme === null
            ? Ansi::sgr(Ansi::REVERSE) . $text . Ansi::reset()
            : $theme->cursor->render($text);
    }

    /**
     * An option/entry row: theme option slot when themed, plain bytes
     * otherwise (the ansi() option slot is plain — default-preserving).
     */
    private static function themedOption(string $text, ?Theme $theme): string
    {
        return $theme === null ? $text : $theme->option->render($text);
    }
}
