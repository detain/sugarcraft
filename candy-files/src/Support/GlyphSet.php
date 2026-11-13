<?php

declare(strict_types=1);

namespace SugarCraft\Files\Support;

use SugarCraft\Core\I18n\T;

/**
 * Chrome glyph tiers (CF-35, closes audit 048).
 *
 * WHY: ▸ ✓ ─ ↑ ↓ · … are East Asian Width = Ambiguous. sugar-core's
 * Width law deliberately counts A as 1 cell (fleet parity, mirrors
 * GNU wcwidth's default table). But the terminals that run in CJK
 * locales — xterm/vte/st with the standard zh/ja/ko configuration,
 * the Linux console, and legacy CJK Windows codepages (CP932/936/
 * 949/950) — render A glyphs two cells wide. On those surfaces every
 * one-cell assumption in the frame arithmetic drifts a cell per
 * occurrence: borders wrap, columns shear.
 *
 * The cure is a capability tier chosen before rendering, never a
 * wholesale substitution: user filenames are data and keep their
 * bytes whatever the terminal is. This class only decides which
 * chrome glyphs the Renderer draws. Tier selection is a pure table
 * over (lang, charset, TERM) plus the CANDY_FILES_GLYPHS override, so
 * it is unit-testable without touching a terminal.
 */
final class GlyphSet
{
    /**
     * Locale bases whose stock terminal configuration doubles A glyphs.
     */
    private const CJK_LANGS = ['ja', 'zh', 'ko'];

    /**
     * Charsets of the legacy CJK codepages — every glyph in them that
     * Unicode classifies A renders 2 cells wide (conhost included).
     */
    private const CJK_CODEPAGES = [
        '932', '936', '949', '950',
        'CP932', 'CP936', 'CP949', 'CP950',
        'GBK', 'GB2312', 'GB18030',
        'BIG5', 'BIG5-HKSCS',
        'SJIS', 'SHIFT-JIS', 'SHIFTJIS',
        'EUC-JP', 'EUCJP', 'EUC-JP-MS',
        'EUC-KR', 'EUCKR',
    ];

    private function __construct(
        public readonly string $cursor,
        public readonly string $mark,
        public readonly string $rule,
        public readonly string $ellipsis,
        public readonly string $up,
        public readonly string $down,
        public readonly string $sep,
        public readonly bool $asciiBorders,
    ) {
    }

    public static function unicode(): self
    {
        return new self('▸', '✓', '─', '…', '↑', '↓', '·', false);
    }

    /**
     * The legacy-safe tier: every glyph strictly East Asian Width = Na,
     * so the Width::of cell math and the terminal agree again. The
     * active-pane cue moves from border weight to the focus label the
     * status line already leads with (N-16).
     */
    public static function ascii(): self
    {
        return new self('>', 'v', '-', '...', '^', 'v', '|', true);
    }

    /**
     * Resolve the tier for this process, honoring the
     * CANDY_FILES_GLYPHS=ascii|unicode escape hatch first.
     */
    public static function current(): self
    {
        $override = self::environment('CANDY_FILES_GLYPHS');
        if ($override !== null) {
            $wanted = mb_strtolower($override);
            if ($wanted === 'ascii') {
                return self::ascii();
            }

            if ($wanted === 'unicode') {
                return self::unicode();
            }
        }

        return self::select(T::detect(), T::charset(), self::environment('TERM'));
    }

    /**
     * The pure decision table — deterministic unit surface.
     *
     * A CJK locale base, a legacy CJK codepage, or the Linux console
     * (whose font doubles every A glyph) forces the ascii tier;
     * everything else keeps the unicode chrome.
     */
    public static function select(string $lang, ?string $charset, ?string $term): self
    {
        $base = strtolower(strtok($lang, '-') ?: $lang);
        if (in_array($base, self::CJK_LANGS, true)) {
            return self::ascii();
        }

        if ($charset !== null && in_array($charset, self::CJK_CODEPAGES, true)) {
            return self::ascii();
        }

        if ($term !== null && strtolower($term) === 'linux') {
            return self::ascii();
        }

        return self::unicode();
    }

    /**
     * Rewrite the six one-cell chrome glyphs inside a pure-catalogue
     * string (the key-help line carries ↑ ↓ ·) to this tier. Every pair
     * is display-cell identical, so callers' width math is unaffected;
     * the ellipsis is deliberately absent — it grows to three cells and
     * only the truncate() site may budget that. Never call this on
     * strings carrying user data ({@see Renderer} status note).
     */
    public function substitute(string $chrome): string
    {
        return str_replace(
            ['▸', '✓', '─', '↑', '↓', '·'],
            [$this->cursor, $this->mark, $this->rule, $this->up, $this->down, $this->sep],
            $chrome,
        );
    }

    private static function environment(string $name): ?string
    {
        $raw = $_SERVER[$name] ?? getenv($name);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
