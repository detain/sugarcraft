<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

use SugarCraft\Core\Syntax\RegexHighlighter;
use SugarCraft\Core\Syntax\TokenKind;
use SugarCraft\Core\Util\Palettes;

/**
 * Plaintext syntax highlighter for {@see SvgRenderer} / {@see PngRenderer}.
 *
 * candy-freeze already colours code from ANSI SGR present in its input; this
 * class fills the OTHER gap — raw, unstyled source. It delegates lexing to
 * candy-core's shared {@see RegexHighlighter} primitive and maps each returned
 * {@see \SugarCraft\Core\Syntax\TokenSpan} onto a hex colour, emitting a
 * truecolor-SGR-annotated string. That string flows through the existing
 * {@see AnsiParser} → SVG/PNG path unchanged, so the two renderers need no
 * per-token awareness of their own.
 *
 * The feature is strictly OPT-IN: {@see self::highlight()} leaves
 * {@see TokenKind::Plain} spans (gap/tail text, and the whole input for an
 * unknown/empty language) completely un-annotated, so highlighting an
 * unrecognised language returns the input byte-for-byte. Plain text therefore
 * inherits the renderer's foreground fill exactly as un-highlighted input does.
 *
 * The default palette is Dracula-ish, sourced from candy-core's
 * {@see Palettes} so the hex literals live in one place across the monorepo.
 */
final class CodeHighlighter
{
    public function __construct(
        /** Colour for {@see TokenKind::Plain} spans — normally the renderer foreground. */
        public readonly string $foreground = Palettes::DRACULA['foreground'],
        public readonly string $keyword    = Palettes::DRACULA['pink'],
        public readonly string $string     = Palettes::DRACULA['yellow'],
        public readonly string $number     = Palettes::DRACULA['purple'],
        public readonly string $comment    = Palettes::DRACULA['comment'],
    ) {}

    /**
     * Build a highlighter whose {@see TokenKind::Plain} colour tracks the given
     * theme's foreground, keeping keyword/string/number/comment on the shared
     * Dracula-ish accents.
     */
    public static function forTheme(Theme $theme): self
    {
        return new self(foreground: $theme->foreground);
    }

    /**
     * The hex colour this highlighter paints a given token class with.
     */
    public function colorFor(TokenKind $kind): string
    {
        return match ($kind) {
            TokenKind::Keyword     => $this->keyword,
            TokenKind::StringToken => $this->string,
            TokenKind::Number      => $this->number,
            TokenKind::Comment     => $this->comment,
            TokenKind::Plain       => $this->foreground,
        };
    }

    /**
     * Tokenise raw `$code` in `$language` and return it with truecolor SGR
     * wrapping each recognised token.
     *
     * {@see TokenKind::Plain} spans are emitted verbatim (no SGR), so an
     * unknown/empty language — which candy-core tokenises as a single Plain
     * span — round-trips the input unchanged. Concatenating every span's text
     * reproduces the original bytes, so the added SGR is purely additive.
     */
    public function highlight(string $code, string $language): string
    {
        $spans = (new RegexHighlighter())->tokenize($code, $language);

        $out = '';
        foreach ($spans as $span) {
            if ($span->kind === TokenKind::Plain) {
                // Un-annotated: inherits the renderer foreground, and keeps the
                // opt-in guarantee that unrecognised input is byte-identical.
                $out .= $span->text;
                continue;
            }
            $out .= $this->wrap($this->colorFor($span->kind), $span->text);
        }

        return $out;
    }

    /**
     * Wrap `$text` in a truecolor SGR foreground + reset. Each newline-delimited
     * fragment is wrapped independently because {@see SvgRenderer} parses ANSI
     * one line at a time (SGR state does not carry across its line split), so a
     * multi-line token (block comment, backtick string) would otherwise lose its
     * colour on every line but the first. Empty fragments stay bare to preserve
     * the exact newline structure.
     */
    private function wrap(string $hex, string $text): string
    {
        $rgb = self::hexToRgb($hex);
        if ($rgb === null) {
            return $text;
        }
        [$r, $g, $b] = $rgb;
        $open  = "\x1b[38;2;{$r};{$g};{$b}m";
        $reset = "\x1b[0m";

        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($line !== '') {
                $lines[$i] = $open . $line . $reset;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Parse `#rgb` / `#rrggbb` into `[r, g, b]`, or null for anything else so
     * the caller degrades to un-coloured text rather than emitting garbage SGR.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
