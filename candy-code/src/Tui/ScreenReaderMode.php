<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Core\Cmd;
use SugarCraft\Style\Border;

/**
 * The `screenReader` switch and the presentation helpers behind it
 * (plan_comparison.md §3, C-TUI-6.i).
 *
 * A screen reader narrates the characters a terminal holds, not their
 * styling: a reverse-video selection bar, a box-drawing frame and a
 * cycling braille spinner are all silence or noise to it. This class is
 * the one process-wide answer to "should the TUI shed that decoration",
 * shaped like the other ambient answers here ( {@see TerminalBackground}
 * negotiates the theme the same way `Bootstrap` latches it).
 *
 * READ PATH: {@see enabled()} is a thin door on
 * {@see UiSettings::bool()} — the settings layer already memoises the
 * merged file by fingerprint, so the door is cheap on the hot paths
 * (`AgentLiveRegistry::staticSpinner()` asks it on every spinner frame),
 * and `Chat::applySettings()` already drops that memo for every
 * `UiSettings::KEYS` key when a save lands. The switch is therefore
 * Live by construction: a save applies on the next read, and this class
 * holds no second cache that could go stale.
 *
 * THE HELPERS ARE OFF-BY-DEFAULT DOORS, NOT RENDERERS. Each one answers
 * the byte-identical input when the mode is off — the same string, or
 * the same border, unchanged — so step C-TUI-6.j can route every call
 * site through here unconditionally and the off-mode capture pins stay
 * byte-exact. This step ships the helpers plus the spinner pin only;
 * the renderer and menu wiring is 6.j's.
 */
final class ScreenReaderMode
{
    /** First code point of the Box Drawing block (U+2500–U+257F). */
    private const BOX_FIRST = 0x2500;

    /** Last code point of the Box Drawing block, inclusive. */
    private const BOX_LAST = 0x257F;

    /**
     * Lazily-built `strtr` map from every Box Drawing sequence to one
     * space. Built once per process and independent of the setting, so
     * it never needs invalidating. Sequence keys are matched byte-wise by
     * `strtr`; each UTF-8 encoding here is unique and unambiguous.
     *
     * @var array<string, string>|null
     */
    private static ?array $boxMap = null;

    private function __construct()
    {
    }

    /** Whether the screen-reader presentation is on right now. */
    public static function enabled(): bool
    {
        return UiSettings::bool('screenReader');
    }

    /**
     * The frame to draw where $border framed a box: the same emptiness
     * {@see Border::hidden()} paints — all thirteen slots are spaces —
     * but carrying $border's titles verbatim, embedded ones included.
     * The title text is the one part of a bordered box a screen reader
     * CAN narrate, so it is exactly what must survive the shed frame;
     * dropping it would trade decoration for information loss.
     */
    public static function border(Border $border): Border
    {
        if (!self::enabled()) {
            return $border;
        }

        return new Border(' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', titles: $border->titles());
    }

    /**
     * $text with every Box Drawing glyph (U+2500–U+257F: the frame,
     * divider and junction runes the renderer paints) replaced by a
     * space, so a line-drawn panel reaches the reader as content on
     * blank paper instead of a rattle of strokes. Text, padding and
     * every other block's glyphs (arrows, checkmarks, spinner dots)
     * pass through untouched — the spinner is motion, not glyphs, and
     * it is pinned through `AgentLiveRegistry::staticSpinner()` instead.
     */
    public static function glyph(string $text): string
    {
        if (!self::enabled()) {
            return $text;
        }

        return \strtr($text, self::boxMap());
    }

    /**
     * One menu row as text: a `>` marker on the selected row (the mode
     * must never carry selection on reverse video alone), the label, and
     * a textual position as `n/of` in plain digits and one slash — a
     * locale-neutral form that needs no translation of its own, while
     * 6.j's richer phrasing keys live under `tui.sr.*`. When the mode is
     * off the row is the bare label: the caller's own reverse-video path
     * is untouched there, so 6.j's off-mode pins stay byte-identical.
     */
    public static function menuRow(int $n, int $of, string $label, bool $selected): string
    {
        if (!self::enabled()) {
            return $label;
        }

        return ($selected ? '> ' : '  ') . $label . ' ' . $n . '/' . $of;
    }

    /**
     * The attention cue for a state change worth narrating — a BEL
     * character as a {@see Cmd::raw()} closure, which makes it a
     * command: 6.j returns it from `update()` arms and the Program
     * writes it on its own turn. `view()` must NEVER emit it; rendering
     * is pure, and a BEL hidden in a painted frame would fire on every
     * repaint diff. Null when the mode is off, so an unwired call site
     * is a no-op, not a stray byte.
     */
    public static function cue(): ?\Closure
    {
        if (!self::enabled()) {
            return null;
        }

        return Cmd::raw("\x07");
    }

    /**
     * @return array<string, string>
     */
    private static function boxMap(): array
    {
        if (self::$boxMap === null) {
            $map = [];
            for ($cp = self::BOX_FIRST; $cp <= self::BOX_LAST; $cp++) {
                $map[(string) \mb_chr($cp, 'UTF-8')] = ' ';
            }
            self::$boxMap = $map;
        }

        return self::$boxMap;
    }
}
