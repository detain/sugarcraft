<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Lang;

/**
 * Parses Rich-style inline markup into Cell arrays.
 *
 * Format: [tag1 tag2]text[/] — tag pairs with attributes, closed by [/].
 * Distinct from {@see StyleParser} which uses `[text](fg:red,bold)` syntax.
 *
 * Supported tags:
 *   - Color names: black, red, green, yellow, blue, magenta, cyan, white,
 *     bright-black, bright-red, bright-green, bright-yellow, bright-blue,
 *     bright-magenta, bright-cyan, bright-white
 *   - bold, dim, italic, underline, reverse, strike
 *   - fg: / bg: color shortcuts
 *
 * Examples:
 *   [bold]hello[/]           — bold text
 *   [red]hello[/]            — red foreground
 *   [bold red]hello[/]       — bold red text
 *   [fg:blue bg:yellow]hi[/] — blue on yellow
 *   hello [bold]world[/] end — plain text with bold section
 *
 * Literal preservation: a `[...]` region whose words are ALL unknown or
 * no-effect (`[b]`, `[on] status`, `[]`, `[[lit]]`) is re-emitted verbatim
 * as plain text, and an unterminated `[` or `[/` flushes the remainder of
 * the input verbatim — nothing is silently dropped. An effective tag still
 * swallows its own delimiters, and unknown words inside an otherwise
 * effective tag (`[bold typo]`) remain ignored. Nesting beyond
 * {@see self::MAX_NESTING_DEPTH} opening tags is refused with a
 * RuntimeException (hostile input must not grow state unboundedly).
 */
final class Markup
{
    private const KNOWN_COLORS = [
        'black' => [  0,   0,   0],
        'red' => [205,   0,   0],
        'green' => [  0, 205,   0],
        'yellow' => [205, 205,   0],
        'blue' => [  0,   0, 238],
        'magenta' => [205,   0, 205],
        'cyan' => [  0, 205, 205],
        'white' => [229, 229, 229],
        'bright-black' => [127, 127, 127],
        'bright-red' => [255,   0,   0],
        'bright-green' => [  0, 255,   0],
        'bright-yellow' => [255, 255,   0],
        'bright-blue' => [ 92,  92, 255],
        'bright-magenta' => [255,   0, 255],
        'bright-cyan' => [  0, 255, 255],
        'bright-white' => [255, 255, 255],
    ];

    /**
     * Ceiling on simultaneously-open markup regions. Beyond it the input
     * is adversarial (or deeply mistaken), not styled text.
     */
    private const MAX_NESTING_DEPTH = 128;

    /**
     * Parse markup string into a list of Cells.
     *
     * @return list<Cell>
     */
    public static function parse(string $input, Style $defaultStyle): array
    {
        $cells = [];
        $currentStyle = $defaultStyle;
        $pendingText = '';
        // Style stack to track nested markup regions
        $styleStack = [];

        $flushPendingText = static function () use (&$pendingText, &$currentStyle, &$cells): void {
            if ($pendingText === '') {
                return;
            }
            foreach (mb_str_split($pendingText) as $rune) {
                $cells[] = new Cell($rune, $currentStyle);
            }
            $pendingText = '';
        };

        $len = strlen($input);
        $i = 0;

        while ($i < $len) {
            $ch = $input[$i];

            // Check for closing tag [/]
            if ($ch === '[' && $i + 1 < $len && $input[$i + 1] === '/') {
                // If style stack is empty, this closing tag has no matching opening
                // — treat as literal text and continue
                if ($styleStack === []) {
                    $pendingText .= $ch;
                    $i++;
                    continue;
                }

                // Closing tag — flush pending text and restore previous style
                $flushPendingText();
                $currentStyle = array_pop($styleStack) ?? $defaultStyle;
                $i += 2; // skip '[/'
                // Find the closing ]
                $closeEnd = strpos($input, ']', $i);
                if ($closeEnd === false) {
                    // Malformed close — the '[/' is literal AND the remainder
                    // after it is preserved verbatim (finding 022: the tail
                    // used to be dropped with it).
                    $pendingText .= '[/' . substr($input, $i);
                    $i = $len;
                } else {
                    $i = $closeEnd + 1;
                }
                continue;
            }

            // Check for opening tag [...]
            if ($ch === '[') {
                // Flush any pending plain text
                $flushPendingText();

                $i++; // skip '['
                // Find the closing ]
                $closePos = strpos($input, ']', $i);
                if ($closePos === false) {
                    // Malformed — the '[' is literal and the remainder of the
                    // input is flushed verbatim after it (finding 022: the old
                    // break dropped everything past the bracket).
                    $pendingText .= '[' . substr($input, $i);
                    break;
                }

                // Extract and parse tag content
                $tagContent = substr($input, $i, $closePos - $i);
                $markupTags = array_filter(array_map('trim', explode(' ', $tagContent)));
                $appliedStyle = self::applyTags($markupTags, $currentStyle);
                if ($appliedStyle === $currentStyle) {
                    // No word in this region carried effect. Re-emit the
                    // bracketed run verbatim instead of deleting it — the
                    // old silent drop mangled data (`a[b]c` came out `ac`).
                    // Delimiters of EFFECTIVE tags are still swallowed.
                    $pendingText .= '[' . $tagContent . ']';
                    $i = $closePos + 1;
                    continue;
                }
                if (count($styleStack) >= self::MAX_NESTING_DEPTH) {
                    throw new \RuntimeException(Lang::t('style.markup_depth', ['levels' => self::MAX_NESTING_DEPTH]));
                }
                // Push current style before applying new one
                $styleStack[] = $currentStyle;
                $currentStyle = $appliedStyle;

                $i = $closePos + 1; // skip past ']'
                continue;
            }

            // Plain text — accumulate
            $pendingText .= $ch;
            $i++;
        }

        // Flush any remaining pending text
        $flushPendingText();

        return $cells;
    }

    /**
     * Apply a set of markup tags to a style and return a new style.
     *
     * @param array<string> $tags
     * @return Style
     */
    private static function applyTags(array $tags, Style $base): Style
    {
        $style = $base;
        foreach ($tags as $tag) {
            $tagLower = strtolower($tag);

            // Color shortcut: fg:name or bg:name
            if (str_starts_with($tagLower, 'fg:')) {
                $colorName = substr($tag, 3);
                $color = self::colorFromName($colorName);
                if ($color !== null) {
                    $style = $style->foreground($color);
                }
                continue;
            }
            if (str_starts_with($tagLower, 'bg:')) {
                $colorName = substr($tag, 3);
                $color = self::colorFromName($colorName);
                if ($color !== null) {
                    $style = $style->background($color);
                }
                continue;
            }

            // Boolean style attributes
            switch ($tagLower) {
                case 'bold':
                    $style = $style->bold(true);
                    break;
                case 'dim':
                    $style = $style->dim(true);
                    break;
                case 'italic':
                    $style = $style->italic(true);
                    break;
                case 'underline':
                    $style = $style->underline(true);
                    break;
                case 'reverse':
                    $style = $style->reverse(true);
                    break;
                case 'strike':
                case 'strikethrough':
                    $style = $style->strikethrough(true);
                    break;
                default:
                    // Check if it's a color name
                    $color = self::colorFromName($tagLower);
                    if ($color !== null) {
                        $style = $style->foreground($color);
                    }
                    break;
            }
        }
        return $style;
    }

    /**
     * Look up a color by name, returning a Color or null.
     */
    private static function colorFromName(string $name): ?Color
    {
        $nameLower = strtolower($name);
        if (isset(self::KNOWN_COLORS[$nameLower])) {
            [$r, $g, $b] = self::KNOWN_COLORS[$nameLower];
            return Color::rgb($r, $g, $b);
        }
        return null;
    }
}
