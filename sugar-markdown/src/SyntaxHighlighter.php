<?php

declare(strict_types=1);

namespace SugarCraft\Markdown;

use SugarCraft\Markdown\Style\StyleCascade;

use SugarCraft\Core\Syntax\RegexHighlighter;
use SugarCraft\Core\Syntax\TokenKind;

/**
 * Lightweight regex-based syntax highlighter for fenced code blocks.
 * Tokenises into four classes (comment / string / number / keyword)
 * and styles each via the matching {@see Theme} slot. Falls back to
 * the plain code-block style for unknown / empty languages.
 *
 * Port origin: stands in for the chroma-backed highlighting glamour
 * consults (charmbracelet/glamour delegates to charmbracelet/chroma);
 * this is an approximation over four token kinds, not a mirror of one
 * upstream symbol.
 *
 * The lexing itself now lives in sugar-core's
 * {@see \SugarCraft\Core\Syntax\RegexHighlighter} — a pure, style-agnostic
 * primitive shared with other consumers (e.g. candy-codeshot). This class is
 * the render half: it maps each {@see \SugarCraft\Core\Syntax\TokenSpan} onto
 * a {@see Theme} slot and emits ANSI, plus the optional line-number gutter.
 * The set of recognised languages follows the core tokeniser — PHP, JS, TS,
 * JSON, Python, Go, Bash, SQL, Rust, Java, C, C++, Ruby, YAML, HTML and CSS
 * (plus core's aliases, e.g. rs, rb, yml, cc, htm); see
 * {@see \SugarCraft\Core\Syntax\RegexHighlighter::languageForPath()} for the
 * path → language mapping that feeds these ids.
 */
final class SyntaxHighlighter
{
    /**
     * Upper bound on the byte length of code we will run the tokeniser
     * regex over. The combined pattern is linear O(n), but a
     * multi-megabyte fenced block (e.g. a pasted minified bundle in a
     * README) still burns CPU proportionally — an unbounded input is a
     * cheap denial-of-service surface. Mirrors the 1 MB caps already
     * enforced by the sibling loaders (LanguageDetector,
     * candy-codeshot ChromaThemeLoader / VsCodeThemeLoader); oversized
     * input degrades gracefully to the plain code-block style.
     */
    public const MAX_HIGHLIGHT_BYTES = 1_000_000;

    /**
     * The line-number gutter separator: a box rule flanked by spaces —
     * `1 │ code`. One measurable cell plus two, never a bare tab: a tab
     * has no fixed display width, so width-budgeting consumers cannot
     * account for a tab-joined line (the case candy-code's DiffGutter
     * documents; SM-S34, 2026-11).
     */
    public const GUTTER_SEPARATOR = ' │ ';

    /**
     * @param bool $lineNumbers When true, each line is prefixed with its 1-based
     *                          line number styled using the theme's comment slot,
     *                          joined to the code by {@see GUTTER_SEPARATOR}.
     */
    public static function highlight(string $code, string $language, Theme $theme, bool $lineNumbers = false): string
    {
        // DoS guard: skip tokenisation for oversized input and fall back
        // to the plain code-block style, exactly as the unknown-language
        // path does below. Keeps highlighting a bounded-cost operation.
        if (strlen($code) > self::MAX_HIGHLIGHT_BYTES) {
            return $theme->codeBlock?->render($code) ?? $code;
        }

        $highlighted = self::renderSpans($code, $language, $theme);

        if (!$lineNumbers) {
            return $highlighted;
        }

        $commentStyle = $theme->comment;
        $lines = explode("\n", $highlighted);
        $paddedWidth = strlen((string) count($lines));

        return implode("\n", array_map(
            static function (string $line, int $i) use ($commentStyle, $paddedWidth): string {
                $padded = str_pad((string) ($i + 1), $paddedWidth, ' ', STR_PAD_LEFT);
                $styled = $commentStyle?->render($padded) ?? $padded;
                return $styled . self::GUTTER_SEPARATOR . $line;
            },
            $lines,
            array_keys($lines),
        ));
    }

    /**
     * Delegate tokenisation to sugar-core's pure lexer, then style each span
     * via the matching {@see Theme} slot. Byte-for-byte equivalent to the old
     * inline tokeniser: a {@see TokenKind::Plain} span (gap/tail text, or the
     * whole input for an unknown language / degraded regex) styles through the
     * base code-block slot; a classified span styles through its slot with the
     * same base/raw fallback chain.
     */
    private static function renderSpans(string $code, string $language, Theme $theme): string
    {
        $spans = (new RegexHighlighter())->tokenize($code, $language);

        $base = $theme->codeBlock ?? null;
        $out  = '';
        foreach ($spans as $span) {
            $style = match ($span->kind) {
                TokenKind::Comment     => $theme->comment,
                TokenKind::StringToken => $theme->string,
                TokenKind::Keyword     => $theme->keyword,
                TokenKind::Number      => $theme->number,
                TokenKind::Plain       => null,
            };
            if ($style !== null && $base !== null) {
                // The block base carries the pane-wide background/faint; a
                // token colour rides on top of it instead of replacing it
                // (M21 - no patchy band across the highlighted fence).
                $out .= StyleCascade::merge($base, $style)->render($span->text);
            } else {
                $out .= $style?->render($span->text) ?? ($base?->render($span->text) ?? $span->text);
            }
        }

        return $out;
    }
}
