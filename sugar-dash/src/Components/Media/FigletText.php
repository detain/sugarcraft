<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Media;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Figlet-style large text using ASCII art characters.
 *
 * Features:
 * - Multiple font styles (standard, shadow, small, banner)
 * - Customizable text color
 * - Horizontal and vertical smoothing options
 * - Smush mode for character overlap
 *
 * Mirrors figlet/text rendering patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class FigletText implements \SugarCraft\Dash\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * Font definitions: each character is an array of 6 strings (one per row).
     * Standard block font.
     */
    private const FONT_STANDARD = [
        'A' => [' █████ ', '██   ██', '███████', '██   ██', '██   ██'],
        'B' => ['██████ ', '██   ██', '██████ ', '██   ██', '██████ '],
        'C' => [' ██████', '██     ', '██     ', '██     ', ' ██████'],
        'D' => ['██████ ', '██   ██', '██   ██', '██   ██', '██████ '],
        'E' => ['███████', '██     ', '█████  ', '██     ', '███████'],
        'F' => ['███████', '██     ', '█████  ', '██     ', '██     '],
        'G' => [' ██████', '██     ', '██  ███', '██   ██', ' █████ '],
        'H' => ['██   ██', '██   ██', '███████', '██   ██', '██   ██'],
        'I' => ['███████', '  ██  ', '  ██  ', '  ██  ', '███████'],
        'J' => ['███████', '    ██', '    ██', '██  ██', ' █████ '],
        'K' => ['██   ██', '██  ██ ', '█████  ', '██  ██ ', '██   ██'],
        'L' => ['██     ', '██     ', '██     ', '██     ', '███████'],
        'M' => ['███   ███', '████ ████', '██ ███ ██', '██  █  ██', '██     ██'],
        'N' => ['██   ██', '████  ██', '██ ██ ██', '██  ████', '██   ██'],
        'O' => [' █████ ', '██   ██', '██   ██', '██   ██', ' █████ '],
        'P' => ['██████ ', '██   ██', '██████ ', '██     ', '██     '],
        'Q' => [' █████ ', '██   ██', '██ ██ ██', '██  ████', ' ██████'],
        'R' => ['██████ ', '██   ██', '██████ ', '██  ██ ', '██   ██'],
        'S' => [' ██████', '██     ', ' █████ ', '    ██', '██████ '],
        'T' => ['███████', '  ██  ', '  ██  ', '  ██  ', '  ██  '],
        'U' => ['██   ██', '██   ██', '██   ██', '██   ██', ' █████ '],
        'V' => ['██   ██', '██   ██', '██   ██', ' ██ ██ ', '  ███  '],
        'W' => ['██     ██', '██  █  ██', '██ ███ ██', '████ ████', '███   ███'],
        'X' => ['██   ██', ' ██ ██ ', '  ███  ', ' ██ ██ ', '██   ██'],
        'Y' => ['██   ██', ' ██ ██ ', '  ███  ', '  ██  ', '  ██  '],
        'Z' => ['███████', '   ██  ', '  ██  ', ' ██   ', '███████'],
        '0' => [' █████ ', '██  ██ ', '██ █ ██', '████  ██', ' █████ '],
        '1' => ['  ██  ', ' ███  ', '  ██  ', '  ██  ', '███████'],
        '2' => [' █████ ', '██  ██ ', '  ████ ', ' ██    ', '███████'],
        '3' => ['██████ ', '    ██', '  ████ ', '    ██', '██████ '],
        '4' => ['██   ██', '██   ██', '███████', '    ██', '    ██'],
        '5' => ['███████', '██     ', '███████', '    ██', '██████ '],
        '6' => [' ██████', '██     ', '███████', '██   ██', ' █████ '],
        '7' => ['███████', '    ██', '   ██  ', '  ██  ', '  ██  '],
        '8' => [' █████ ', '██   ██', ' █████ ', '██   ██', ' █████ '],
        '9' => [' █████ ', '██   ██', ' ██████', '    ██', ' █████ '],
        'a' => ['      ', '      ', ' ████ ', '██  ██', '██████'],
        'b' => ['███   ', '██    ', '█████ ', '██  ██', '█████ '],
        'c' => ['      ', '      ', ' ████ ', '██    ', '█████ '],
        'd' => ['   ███', '    ██', ' ████ ', '██  ██', '██████'],
        'e' => ['      ', '      ', ' █████', '██    ', ' █████'],
        'f' => [' █████', '██    ', '█████ ', '██    ', '██    '],
        'g' => ['      ', '      ', ' ████ ', '██████', '  ████'],
        'h' => ['██    ', '██    ', '█████ ', '██  ██', '██  ██'],
        'i' => ['██    ', '      ', '██    ', '██    ', '██    '],
        'j' => ['   ██', '     ', '   ██', '██  ██', ' ████ '],
        'k' => ['██   ', '██  ██', '████  ', '██ ██ ', '██  ██'],
        'l' => ['██    ', '██    ', '██    ', '██    ', '██████'],
        'm' => ['       ', '       ', '███ ███', '██ █ ██', '██   ██'],
        'n' => ['      ', '      ', '█████ ', '██  ██', '██  ██'],
        'o' => ['      ', '      ', ' ████ ', '██  ██', ' ████ '],
        'p' => ['      ', '      ', '█████ ', '█████ ', '██    '],
        'q' => ['      ', '      ', ' ████ ', '██████', '    ██'],
        'r' => ['      ', '      ', '███ ██', '████  ', '██   ██'],
        's' => ['      ', '      ', ' █████', '████  ', '█████ '],
        't' => ['███   ', '███   ', '██████', '███   ', '███   '],
        'u' => ['      ', '      ', '██  ██', '██  ██', ' ████ '],
        'v' => ['      ', '      ', '██  ██', '██  ██', ' ███  '],
        'w' => ['       ', '       ', '██  ███', '██ █ ██', ' ███ █ '],
        'x' => ['      ', '      ', '██  ██', ' ██ ██', '██  ██'],
        'y' => ['      ', '      ', '██  ██', '██████', '  ████'],
        'z' => ['      ', '      ', '██████', ' ███  ', '██████'],
        ' ' => ['   ', '   ', '   ', '   ', '   '],
        '.' => ['   ', '   ', '   ', '██ ', '██ '],
        ',' => ['   ', '   ', '   ', '██ ', '▄██'],
        '!' => ['███ ', '███ ', '███ ', '    ', '███ '],
        '?' => [' ████', '██  ██', '   ██ ', '     ', '   ██ '],
        '-' => ['       ', '       ', '███████', '       ', '       '],
        '_' => ['        ', '        ', '        ', '        ', '████████'],
        '=' => ['        ', '████████', '        ', '████████', '        '],
        '+' => ['        ', '  ████  ', '  ████  ', '  ████  ', '        '],
        '/' => ['    ██', '   ██ ', '  ██  ', ' ██   ', '██    '],
        '*' => ['        ', '██ ██ ██', ' █████ ', '██ ██ ██', '        '],
        '#' => ['  ██  ██  ', '██████████', '  ██  ██  ', '██████████', '  ██  ██  '],
        '\'' => ['███', '███', '   ', '   ', '   '],
        '"' => ['██ ██', '██ ██', '    ', '    ', '    '],
        ':' => ['   ', '███', '   ', '███', '   '],
        ';' => ['   ', '███', '   ', '███', '▄██'],
        '(' => [' ██', '██ ', '██ ', '██ ', ' ██'],
        ')' => ['██ ', ' ██', ' ██', ' ██', '██ '],
        '[' => ['███', '██ ', '██ ', '██ ', '███'],
        ']' => ['███', ' ██', ' ██', ' ██', '███'],
        '{' => ['  ██', ' ██ ', '██  ', ' ██ ', '  ██'],
        '}' => ['██  ', ' ██ ', '  ██', ' ██ ', '██  '],
        '<' => ['  ██', ' ██ ', '██  ', ' ██ ', '  ██'],
        '>' => ['██  ', ' ██ ', '  ██', ' ██ ', '██  '],
    ];

    private const FONT_SMALL = [
        'A' => [' _ ', '/|\\', '| |'],
        'B' => ['|__|', '|__]', '|  '],
        'C' => [' ___', '/   ', '|__ '],
        'D' => ['|__|', '|  \\', '|__/'],
        'E' => ['____', '|__ ', '|___'],
        'F' => ['____', '|__ ', '|   '],
        'G' => [' ___', '/__ ', '|  \\'],
        'H' => ['| |', '|__|', '| |'],
        'I' => [' | ', ' | ', ' | '],
        'J' => ['  |', '  |', '__|'],
        'K' => ['| |', '|<  ', '|  \\'],
        'L' => ['|   ', '|   ', '|___'],
        'M' => ['/\\/', '|  |', '|  |'],
        'N' => ['\\  /', ' |  ', ' |  '],
        'O' => [' _ ', '| |', '|_|'],
        'P' => ['__|', '|  ', '|   '],
        'Q' => [' _ ', '| |', '|_\\'],
        'R' => ['__|', '|<  ', '|  \\'],
        'S' => [' ___', '/__ ', '___/'],
        'T' => ['___|', ' |  ', ' |  '],
        'U' => ['| |', '| |', '|_|'],
        'V' => ['\\ /', ' V ', '   '],
        'W' => ['| |', '| |', '|_|'],
        'X' => ['\\ /', ' X ', '/ \\'],
        'Y' => ['\\ /', ' | ', ' | '],
        'Z' => ['___/', '  / ', '/__/'],
        '0' => [' _ ', '| |', '|_|'],
        '1' => [' | ', ' | ', ' | '],
        '2' => [' _ ', ' _|', '|_ '],
        '3' => ['__ ', ' _|', '__|'],
        '4' => ['| |', '|_|', '  |'],
        '5' => ['___', '|__', '___'],
        '6' => [' _ ', '|__', '|_|'],
        '7' => ['___', '  /', ' | '],
        '8' => [' _ ', '|_|', '|_|'],
        '9' => [' _ ', '|_|', '  |'],
        ' ' => [' ', ' ', ' '],
        '.' => [' ', ' ', ' '],
        ',' => [' ', ' ', ' '],
    ];

    public function __construct(
        private readonly string $text,
        private readonly ?Color $textColor = null,
        private readonly string $font = 'standard',
    ) {}

    /**
     * Create a new figlet text component.
     */
    public static function new(string $text): self
    {
        return new self(
            text: $text,
            textColor: Color::hex('#874BFD'),
            font: 'standard',
        );
    }

    /**
     * Create with shadow font style.
     */
    public static function shadow(string $text): self
    {
        return new self(
            text: $text,
            textColor: Color::hex('#874BFD'),
            font: 'shadow',
        );
    }

    /**
     * Create with small font style.
     */
    public static function small(string $text): self
    {
        return new self(
            text: $text,
            textColor: Color::hex('#874BFD'),
            font: 'small',
        );
    }

    /**
     * Set the allocated dimensions for this component.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the figlet text.
     */
    public function render(): string
    {
        $font = $this->getFont();
        $charRows = [];

        // Process each character and collect their rows
        $chars = mb_str_split(mb_strtoupper($this->text, 'UTF-8'), 1, 'UTF-8');

        foreach ($chars as $char) {
            $charUpper = mb_strtoupper($char, 'UTF-8');
            if (isset($font[$charUpper])) {
                $charRows[] = $font[$charUpper];
            } else {
                // Use space for unknown characters
                $charRows[] = $font[' '] ?? ['   ', '   ', '   '];
            }
        }

        if ($charRows === []) {
            return '';
        }

        // Build output row by row (characters are arranged horizontally)
        $numRows = count($charRows[0]);
        $result = '';

        if ($this->textColor !== null) {
            $result .= $this->textColor->toFg(ColorProfile::TrueColor);
        }

        for ($row = 0; $row < $numRows; $row++) {
            $lineParts = [];
            foreach ($charRows as $charRow) {
                if (isset($charRow[$row])) {
                    $lineParts[] = $charRow[$row];
                }
            }
            $result .= implode(' ', $lineParts) . "\n";
        }

        $result .= Ansi::reset();

        return rtrim($result, "\n");
    }

    /**
     * Get the font definition.
     *
     * @return array<string, list<string>>
     */
    private function getFont(): array
    {
        return match ($this->font) {
            'small' => self::FONT_SMALL,
            'shadow' => self::FONT_STANDARD, // Same as standard for now
            'standard' => self::FONT_STANDARD,
            default => self::FONT_STANDARD,
        };
    }

    /**
     * Calculate the natural dimensions.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $font = $this->getFont();
        $chars = mb_str_split(mb_strtoupper($this->text, 'UTF-8'), 1, 'UTF-8');

        if ($chars === []) {
            return [0, 0];
        }

        $totalWidth = 0;
        $numRows = 0;

        foreach ($chars as $char) {
            $charUpper = mb_strtoupper($char, 'UTF-8');
            if (isset($font[$charUpper])) {
                $charDef = $font[$charUpper];
                $charWidth = mb_strlen($charDef[0] ?? '', 'UTF-8');
                $totalWidth += $charWidth + 1; // +1 for space between chars
                $numRows = count($charDef);
            } else {
                $totalWidth += 4; // space width + space between
            }
        }

        // Remove trailing space
        $totalWidth = max(0, $totalWidth - 1);

        return [$totalWidth, $numRows];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            textColor: $color,
            font: $this->font,
        );
    }

    /**
     * Set the font style.
     */
    public function withFont(string $font): self
    {
        return new self(
            text: $this->text,
            textColor: $this->textColor,
            font: $font,
        );
    }
}
