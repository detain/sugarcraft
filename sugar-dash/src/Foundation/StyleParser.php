<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * Parses termui-style inline style strings into Cell arrays.
 *
 * Format: [text](fg:red,bg:blue,bold,italic,underline)
 *
 * Mirrors termui's style parsing state machine.
 */
final class StyleParser
{
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

        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $ch = $input[$i];

            if ($inBracket) {
                // Inside [...] - capture text content
                if ($ch === ']') {
                    $inBracket = false;
                    $openBracketPos = -1;
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

            if ($ch === '(') {
                // Start of style definition - parse the style
                $pendingStyle = self::parseInlineStyle($input, $i + 1, $currentStyle, $i);
                continue;
            }

            // Handle ) - marks end of style definition, skip it
            if ($ch === ')') {
                continue;
            }

            // Plain text outside brackets - flush any pending styled text first
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

            [$key, $value] = str_contains($attr, ':')
                ? explode(':', $attr, 2)
                : [$attr, null];

            $key = strtolower(trim($key));

            switch ($key) {
                case 'fg':
                case 'foreground':
                    $color = self::parseColor($value);
                    if ($color !== null) {
                        $style = $style->withForeground($color);
                    }
                    break;
                case 'bg':
                case 'background':
                    $color = self::parseColor($value);
                    if ($color !== null) {
                        $style = $style->withBackground($color);
                    }
                    break;
                case 'bold':
                    $style = $style->withBold(true);
                    break;
                case 'dim':
                    $style = new Style(
                        foreground: $style->foreground,
                        background: $style->background,
                        bold: $style->bold,
                        dim: true,
                        italic: $style->italic,
                        underline: $style->underline,
                        reverse: $style->reverse,
                        strike: $style->strike,
                    );
                    break;
                case 'italic':
                    $style = new Style(
                        foreground: $style->foreground,
                        background: $style->background,
                        bold: $style->bold,
                        dim: $style->dim,
                        italic: true,
                        underline: $style->underline,
                        reverse: $style->reverse,
                        strike: $style->strike,
                    );
                    break;
                case 'underline':
                    $style = new Style(
                        foreground: $style->foreground,
                        background: $style->background,
                        bold: $style->bold,
                        dim: $style->dim,
                        italic: $style->italic,
                        underline: true,
                        reverse: $style->reverse,
                        strike: $style->strike,
                    );
                    break;
                case 'reverse':
                    $style = new Style(
                        foreground: $style->foreground,
                        background: $style->background,
                        bold: $style->bold,
                        dim: $style->dim,
                        italic: $style->italic,
                        underline: $style->underline,
                        reverse: true,
                        strike: $style->strike,
                    );
                    break;
                case 'strike':
                    $style = new Style(
                        foreground: $style->foreground,
                        background: $style->background,
                        bold: $style->bold,
                        dim: $style->dim,
                        italic: $style->italic,
                        underline: $style->underline,
                        reverse: $style->reverse,
                        strike: true,
                    );
                    break;
            }
        }

        return $style;
    }

    /**
     * Parse a color value string to a Color, or null if unknown.
     */
    private static function parseColor(string $value): ?Color
    {
        $value = trim($value);

        // Named ANSI colors
        $named = [
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

        $valueLower = strtolower($value);
        if (isset($named[$valueLower])) {
            [$r, $g, $b] = $named[$valueLower];
            return Color::rgb($r, $g, $b);
        }

        // Hex color
        if (str_starts_with($value, '#') || ctype_xdigit(substr($value, 0, 1))) {
            return Color::hex($value);
        }

        // Unknown color - return null to signal it should be ignored
        return null;
    }
}
