<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

use SugarCraft\Core\Syntax\RegexHighlighter;
use SugarCraft\Core\Syntax\TokenKind;

/**
 * Lightweight regex-based syntax highlighter for fenced code blocks.
 * Tokenises into four classes (comment / string / number / keyword)
 * and styles each via the matching {@see Theme} slot. Falls back to
 * the plain code-block style for unknown / empty languages.
 *
 * The lexing itself now lives in candy-core's
 * {@see \SugarCraft\Core\Syntax\RegexHighlighter} — a pure, style-agnostic
 * primitive shared with other consumers (e.g. candy-freeze). This class is
 * the render half: it maps each {@see \SugarCraft\Core\Syntax\TokenSpan} onto
 * a {@see Theme} slot and emits ANSI, plus the optional line-number gutter.
 * The set of recognised languages is intentionally small — PHP, JS, TS,
 * JSON, Python, Go, Bash, SQL.
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
     * candy-freeze ChromaThemeLoader / VsCodeThemeLoader); oversized
     * input degrades gracefully to the plain code-block style.
     */
    private const MAX_HIGHLIGHT_BYTES = 1_000_000;

    /**
     * @param bool $lineNumbers When true, each line is prefixed with its 1-based
     *                          line number styled using the theme's comment slot.
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
                return $styled . "\t" . $line;
            },
            $lines,
            array_keys($lines),
        ));
    }

    /**
     * Delegate tokenisation to candy-core's pure lexer, then style each span
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
            $out .= $style?->render($span->text) ?? ($base?->render($span->text) ?? $span->text);
        }

        return $out;
    }
}
