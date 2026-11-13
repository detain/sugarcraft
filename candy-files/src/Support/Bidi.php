<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

/**
 * Bidi isolation for the chrome's mixed-direction runs (CF-47, closes 094).
 *
 * Arabic/Hebrew/Persian/Urdu catalogues put RTL text into the same lines as
 * Latin tokens — paths, size counts, key chords, the `·` separator. A
 * bidi-aware terminal derives the paragraph direction from the FIRST STRONG
 * character and then re-resolves every neutral (spaces, `·`, brackets)
 * against it, so chrome segments can appear in an order different from the
 * one that was emitted. Left-to-right marks (U+200E) pinned on either side
 * of a separator force a strong LTR context there: the separator keeps its
 * place between the two segments in emission order, and isolated runs
 * (tab labels) are fenced so bracket pairs stay wrapped around their own
 * path instead of drifting to the run boundary.
 *
 * Data law: this class is applied ONLY to chrome joins and chrome-only
 * strings (key-help tiers, tab labels are localized catalogue text). It
 * never runs over entry names, cwd bytes interpolated into live status
 * messages, or search queries — user bytes are sacred (the CF-27/CF-35
 * precedent); the UAX#9 intrinsic resolution already renders embedded
 * Latin inside localized `{name}` prose correctly.
 *
 * Width accounting note: the core `Width::of()` measures U+200E as one
 * cell (a conservative overcount — real terminals advance zero columns for
 * format characters), so pinned chrome budgets slightly early. Early is
 * safe; late wraps borders.
 *
 * No upstream — house pattern (charmbracelet ships no RTL chrome; the law
 * comes from the shipped `ar` catalogue).
 */
final class Bidi
{
    /** LEFT-TO-RIGHT MARK — a zero-advance strong LTR bias character. */
    public const LRM = "\u{200E}";

    /** @var list<string> base language codes whose catalogues are RTL */
    private const RTL_BASES = ['ar', 'he', 'fa', 'ur'];

    /** Is $locale (any BCP-47 spelling) one of the RTL language families? */
    public static function isRtl(string $locale): bool
    {
        $head = strtok($locale, '-_');
        $base = strtolower($head === false ? '' : $head);

        return in_array($base, self::RTL_BASES, true);
    }

    /**
     * The separator as it should sit between two chrome segments: pinned
     * between LRM marks when the locale is RTL, byte-identical otherwise
     * (the en/de/… frames must not gain invisible bytes).
     */
    public static function pinSeparator(string $sep, bool $rtl): string
    {
        if (!$rtl) {
            return $sep;
        }

        return self::LRM . $sep . self::LRM;
    }

    /**
     * Fence one whole chrome item (a `[tab label]` cell) so its neutral
     * brackets cannot be re-absorbed by an opposite-direction neighbour.
     */
    public static function isolate(string $item, bool $rtl): string
    {
        if (!$rtl) {
            return $item;
        }

        return self::LRM . $item . self::LRM;
    }

    /**
     * Pin every ` sep ` join INSIDE one chrome string (the key-help tiers
     * carry several `·`-joined chords in a single catalogue value).
     */
    public static function pinRuns(string $text, string $sep, bool $rtl): string
    {
        if (!$rtl) {
            return $text;
        }

        $pinned = ' ' . self::pinSeparator($sep, true) . ' ';

        return str_replace(' ' . $sep . ' ', $pinned, $text);
    }
}
