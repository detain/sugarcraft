<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\Color;

/**
 * Parses termui-style inline style strings into Cell arrays.
 *
 * Format: [text](fg:red,bg:blue,bold,italic,underline)
 *
 * Ported from sugar-dashboard's Foundation layer, where the parser was
 * duplicated per app; consolidated into sugar-style as the single home.
 * Mirrors termui's style parsing state machine — written as a clean-room
 * implementation from the algorithmic description, not derived from any
 * GPL'd source.
 */
final class StyleParser
{
    /**
     * Named ANSI-16 colours with their xterm default RGB triples.
     *
     * A class const, matching {@see Markup::KNOWN_COLORS}: the table is
     * static data, and rebuilding the 16-entry array literal on every
     * colour parse compounded costs for consumers who re-parse markup per
     * render (finding 027). Values mirror sugar-core's ruled ANSI16_RGB
     * roster exactly — a shared-table consolidation rides the follow-up.
     */
    private const NAMED_COLORS = [
        'black' => [0, 0, 0],
        'red' => [205, 0, 0],
        'green' => [0, 205, 0],
        'yellow' => [205, 205, 0],
        'blue' => [0, 0, 238],
        'magenta' => [205, 0, 205],
        'cyan' => [0, 205, 205],
        'white' => [229, 229, 229],
        'bright-black' => [127, 127, 127],
        'bright-red' => [255, 0, 0],
        'bright-green' => [0, 255, 0],
        'bright-yellow' => [255, 255, 0],
        'bright-blue' => [92, 92, 255],
        'bright-magenta' => [255, 0, 255],
        'bright-cyan' => [0, 255, 255],
        'bright-white' => [255, 255, 255],
    ];

    /**
     * Parse a styled string into a list of Cells.
     *
     * Format: [text](fg:red,bg:blue,bold,italic,underline)
     *
     * @return list<Cell>
     */
    public static function parse(string $input, Style $defaultStyle): array
    {
        $cells = [];
        $currentStyle = $defaultStyle;
        $pendingText = '';
        $inBracket = false;
        $pendingStyle = null; // Style to apply when flushing
        $openBracketPos = -1; // Position of unclosed '[' if any

        $flushPendingText = static function () use (&$pendingText, &$currentStyle, &$pendingStyle, &$cells): void {
            if ($pendingText === '') {
                return;
            }
            $styleToApply = $pendingStyle ?? $currentStyle;
            foreach (mb_str_split($pendingText) as $rune) {
                $cells[] = new Cell($rune, $styleToApply);
            }
            $pendingText = '';
            $pendingStyle = null;
        };

        $afterRegion = false; // true only for the character immediately after a closed ]
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $ch = $input[$i];
            $adjacentToRegion = $afterRegion;
            $afterRegion = false;

            if ($inBracket) {
                // Inside [...] - capture text content
                if ($ch === ']') {
                    $inBracket = false;
                    $openBracketPos = -1;
                    $afterRegion = true;
                } else {
                    $pendingText .= $ch;
                }
                continue;
            }

            if ($ch === '[') {
                // Start of styled text - flush any pending plain text first
                $flushPendingText();
                $inBracket = true;
                $openBracketPos = $i;
                continue;
            }

            if ($ch === '(' && $adjacentToRegion) {
                // Style definition, and only here: a ( opens one ONLY when it
                // immediately follows a parsed [...] region. Treating any
                // unpaired paren as a definition silently ate literal prose —
                // 'cost (USD)' reassembled to 'cost ' (finding 021).
                $pendingStyle = self::parseInlineStyle($input, $i + 1, $currentStyle, $i);
                $i++; // skip the closing ) the definition consumed through
                continue;
            }

            // Plain text outside brackets (parens included when they open no
            // definition) - flush any pending styled text first
            if ($pendingText !== '' && $pendingStyle !== null) {
                $flushPendingText();
            }
            $pendingText .= $ch;
        }

        // Handle unclosed bracket - add the '[' back as plain text
        if ($inBracket && $openBracketPos >= 0) {
            $pendingText = '[' . $pendingText;
        }

        $flushPendingText();
        return $cells;
    }

    /**
     * Parse inline style attributes starting after '(' up to ')'.
     *
     * Returns modified style and updates $i to position before ')'.
     */
    private static function parseInlineStyle(string $input, int $start, Style $base, int &$i): Style
    {
        $style = $base;
        $len = strlen($input);

        // Find closing )
        $end = strpos($input, ')', $start);
        if ($end === false) {
            $end = $len;
        }

        // Extract attribute string between '(' and ')'
        $attrStr = substr($input, $start, $end - $start);
        $i = $end - 1; // will be incremented by caller

        $attrs = array_map('trim', explode(',', $attrStr));
        foreach ($attrs as $attr) {
            if ($attr === '') {
                continue;
            }

            [$key, $value] = str_contains($attr, ':') === true
                ? explode(':', $attr, 2)
                : [$attr, null];

            $key = strtolower(trim($key));

            switch ($key) {
                case 'fg':
                case 'foreground':
                    $color = self::parseColor($value);
                    if ($color !== null) {
                        $style = $style->foreground($color);
                    }
                    break;
                case 'bg':
                case 'background':
                    $color = self::parseColor($value);
                    if ($color !== null) {
                        $style = $style->background($color);
                    }
                    break;
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
                    $style = $style->strikethrough(true);
                    break;
            }
        }

        return $style;
    }

    /**
     * Parse a color value string to a Color, or null if unknown.
     *
     * Accepts the 16 named ANSI colours (case-insensitive), `#`-prefixed
     * hex, and bare 3- or 6-digit hex (`ff0000`). Any other bare word —
     * including unknown names that merely START with a hex letter, such
     * as `beige` or `coffee` — is unknown and returns null, so the
     * attribute is ignored like every other unrecognised name. A
     * `#`-prefixed value is explicitly hex, so a malformed one (`#zz`)
     * throws {@see \InvalidArgumentException} from {@see Color::hex()}.
     */
    private static function parseColor(?string $value): ?Color
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        // Named ANSI colors (class const — see NAMED_COLORS for the why)
        $valueLower = strtolower($value);
        if (isset(self::NAMED_COLORS[$valueLower])) {
            [$r, $g, $b] = self::NAMED_COLORS[$valueLower];
            return Color::rgb($r, $g, $b);
        }

        // Hex colour: explicit `#…`, or a bare value that is entirely a
        // 3- or 6-digit hex string (not merely one starting with a-f).
        if (str_starts_with($value, '#') || preg_match('/^(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
            return Color::hex($value);
        }

        // Unknown color - return null to signal it should be ignored
        return null;
    }
}
