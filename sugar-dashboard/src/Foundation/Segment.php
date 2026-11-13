<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Foundation;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A 7-segment display component.
 *
 * Renders characters using a 7-segment (like digital clock/calculator) display.
 * Each character is made of horizontal and vertical line segments.
 * Supports digits 0-9, letters A-F for hex, and some punctuation.
 *
 * Mirrors 7-segment display concepts adapted to PHP with wither-style immutable setters.
 */
final class Segment implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * 7-segment bit patterns.
     * Each character maps to which segments are lit.
     * Bits: [A, B, C, D, E, F, G] corresponding to positions in the display
     */
    private const SEGMENT_MAP = [
        '0' => [true,  true,  true,  true,  true,  true,  false], // A,B,C,D,E,F
        '1' => [false, true,  true,  false, false, false, false], // B,C
        '2' => [true,  true,  false, true,  true,  false, true],  // A,B,D,E,G
        '3' => [true,  true,  true,  true,  false, false, true],  // A,B,C,D,G
        '4' => [false, true,  true,  false, false, true,  true],  // B,C,F,G
        '5' => [true,  false, true,  true,  false, true,  true],  // A,C,D,F,G
        '6' => [true,  false, true,  true,  true,  true,  true],  // A,C,D,E,F,G
        '7' => [true,  true,  true,  false, false, false, false], // A,B,C
        '8' => [true,  true,  true,  true,  true,  true,  true],  // A,B,C,D,E,F,G
        '9' => [true,  true,  true,  true,  false, true,  true],  // A,B,C,D,F,G
        'A' => [true,  true,  true,  false, true,  true,  true],  // A,B,C,E,F,G
        'B' => [false, false, true,  true,  true,  true,  true],  // C,D,E,F,G
        'C' => [true,  false, false, true,  true,  true,  false], // A,D,E,F
        'D' => [false, true,  true,  true,  true,  false, true],  // B,C,D,E,G
        'E' => [true,  false, false, true,  true,  true,  true],  // A,D,E,F,G
        'F' => [true,  false, false, false, true,  true,  true],  // A,E,F,G
        '-' => [false, false, false, false, false, false, true],  // G only
        ' ' => [false, false, false, false, false, false, false], // all off
        '°' => [true,  true,  false, false, false, true,  false], // A,B,F (degree)
        '³' => [false, true,  true,  false, false, true,  true],  // B,C,F,G (superscript 3)
        // D-50 (finding 040) dropped two dead roster entries: the
        // two-character key 'C2' can never match the one-character
        // mb_str_split() walker, and '.' was an all-off entry that
        // rendered blank — a decimal point that is not drawn is not a
        // feature. '.' now takes the documented unknown-char blank fallback.
    ];

    /**
     * Height per digit (in character rows).
     */
    private const DIGIT_HEIGHT = 5;

    public function __construct(
        private readonly string $content,
        private readonly int $digitWidth = 3,
        private readonly bool $showColon = true,
        private readonly ?Color $onColor = null,
        private readonly ?Color $offColor = null,
    ) {}

    /**
     * Create a new 7-segment display with default styling.
     *
     * Default: purple "on" segments, dark "off" segments, 3 chars wide per digit.
     */
    public static function new(string $content): self
    {
        return new self(
            content: $content,
            digitWidth: 3,
            showColon: true,
            onColor: Color::hex('#874BFD'),
            offColor: Color::hex('#333333'),
        );
    }

    /**
     * Set the allocated dimensions for this display.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render a single segment (horizontal or vertical).
     */
    private function renderSegment(bool $isOn, bool $isHorizontal, ColorProfile $profile): string
    {
        $char = $isHorizontal ? '▔' : '▏';

        if ($isOn && $this->onColor !== null) {
            return $this->onColor->toFg($profile) . $char . Ansi::reset();
        }

        if (!$isOn && $this->offColor !== null) {
            return $this->offColor->toFg($profile) . $char . Ansi::reset();
        }

        return $isOn ? $char : ' ';
    }

    /**
     * Render a single digit.
     *
     * Layout (digitWidth=3):
     * Row 0:  A A A    (horizontal top)
     * Row 1: F   B     (vertical sides)
     * Row 2:  G G G    (horizontal middle)
     * Row 3: E   C     (vertical sides)
     * Row 4: D D D    (horizontal bottom)
     */
    private function renderDigit(string $char, ColorProfile $profile): array
    {
        $char = strtoupper($char);
        $segments = self::SEGMENT_MAP[$char] ?? self::SEGMENT_MAP[' '];

        // Adjust segment positions based on digitWidth
        $width = max(2, $this->digitWidth);
        $segmentA = str_repeat('▔', $width); // Top horizontal
        $segmentD = str_repeat('▁', $width); // Bottom horizontal
        $segmentG = str_repeat('━', $width); // Middle horizontal

        // Calculate horizontal positions
        $_hPos = (int) floor(($width - 1) / 2);
        $_fPos = 0;
        $_bPos = $width - 1;

        $rows = [
            0 => '',
            1 => '',
            2 => '',
            3 => '',
            4 => '',
        ];

        // Row 0: Top (A)
        if ($segments[0]) { // A
            if ($this->onColor !== null) {
                $rows[0] .= $this->onColor->toFg($profile) . $segmentA . Ansi::reset();
            } else {
                $rows[0] .= $segmentA;
            }
        } else {
            if ($this->offColor !== null) {
                $rows[0] .= $this->offColor->toFg($profile) . $segmentA . Ansi::reset();
            } else {
                $rows[0] .= $segmentA;
            }
        }

        // Row 1: Upper verticals (F, B)
        if ($segments[5]) { // F
            if ($this->onColor !== null) {
                $rows[1] .= $this->onColor->toFg($profile) . '▏' . Ansi::reset();
            } else {
                $rows[1] .= '▏';
            }
        } else {
            $rows[1] .= ' ';
        }
        $rows[1] .= str_repeat(' ', $width - 2);
        if ($segments[1]) { // B
            if ($this->onColor !== null) {
                $rows[1] .= $this->onColor->toFg($profile) . '▎' . Ansi::reset();
            } else {
                $rows[1] .= '▎';
            }
        } else {
            $rows[1] .= ' ';
        }

        // Row 2: Middle (G)
        if ($segments[6]) { // G
            if ($this->onColor !== null) {
                $rows[2] .= $this->onColor->toFg($profile) . $segmentG . Ansi::reset();
            } else {
                $rows[2] .= $segmentG;
            }
        } else {
            if ($this->offColor !== null) {
                $rows[2] .= $this->offColor->toFg($profile) . $segmentG . Ansi::reset();
            } else {
                $rows[2] .= $segmentG;
            }
        }

        // Row 3: Lower verticals (E, C)
        if ($segments[4]) { // E
            if ($this->onColor !== null) {
                $rows[3] .= $this->onColor->toFg($profile) . '▏' . Ansi::reset();
            } else {
                $rows[3] .= '▏';
            }
        } else {
            $rows[3] .= ' ';
        }
        $rows[3] .= str_repeat(' ', $width - 2);
        if ($segments[2]) { // C
            if ($this->onColor !== null) {
                $rows[3] .= $this->onColor->toFg($profile) . '▎' . Ansi::reset();
            } else {
                $rows[3] .= '▎';
            }
        } else {
            $rows[3] .= ' ';
        }

        // Row 4: Bottom (D)
        if ($segments[3]) { // D
            if ($this->onColor !== null) {
                $rows[4] .= $this->onColor->toFg($profile) . $segmentD . Ansi::reset();
            } else {
                $rows[4] .= $segmentD;
            }
        } else {
            if ($this->offColor !== null) {
                $rows[4] .= $this->offColor->toFg($profile) . $segmentD . Ansi::reset();
            } else {
                $rows[4] .= $segmentD;
            }
        }

        return $rows;
    }

    /**
     * Render the colon separator column.
     *
     * D-50 (finding 040): the column is exactly ONE cell wide — every
     * multi-char line is built by concatenating columns, so a colon row
     * that padded wider than it was counted would desync render from
     * innerSize (the old mixed width/width+1 rows did just that). The two
     * vertically centered dots are the 7-segment colon.
     */
    private function renderColon(ColorProfile $profile): array
    {
        $dot = $this->onColor !== null
            ? $this->onColor->toFg($profile) . '●' . Ansi::reset()
            : '●';

        return [
            0 => ' ',
            1 => $dot,
            2 => ' ',
            3 => $dot,
            4 => ' ',
        ];
    }

    /**
     * Render the 7-segment display.
     *
     * Separator contract (finding sugar-dashboard-040, rewritten honest):
     * a literal ':' in the content renders as the colon column; a digit
     * stream WITHOUT any literal colon gets clock pair-grouping
     * ('123456' renders 12:34:56); everything else — two-digit numbers,
     * letters, punctuation — grows no spurious separator. The old loop
     * inserted a colon between EVERY adjacent pair of characters after a
     * digit, so '95' became '9:5' and a literal ':' consumed the all-off
     * digit fallback as a blank.
     */
    public function render(): string
    {
        $profile = ColorMode::profile();
        $chars = mb_str_split($this->content, 1, 'UTF-8');
        $hasLiteralColon = in_array(':', $chars, true);

        $allRows = ['', '', '', '', ''];
        $consecutiveDigits = 0;

        foreach ($chars as $char) {
            if ($char === ':') {
                $colonRows = $this->renderColon($profile);
                for ($i = 0; $i < 5; $i++) {
                    $allRows[$i] .= $colonRows[$i];
                }
                $consecutiveDigits = 0;
                continue;
            }

            if (
                $this->showColon
                && !$hasLiteralColon
                && ctype_digit($char)
                && $consecutiveDigits > 0
                && $consecutiveDigits % 2 === 0
            ) {
                $colonRows = $this->renderColon($profile);
                for ($i = 0; $i < 5; $i++) {
                    $allRows[$i] .= $colonRows[$i];
                }
            }

            $digitRows = $this->renderDigit($char, $profile);
            for ($i = 0; $i < 5; $i++) {
                $allRows[$i] .= $digitRows[$i];
            }

            $consecutiveDigits = ctype_digit($char) ? $consecutiveDigits + 1 : 0;
        }

        return implode("\n", $allRows);
    }

    /**
     * Calculate the natural dimensions of this display.
     *
     * Every non-colon character occupies one digit-width column; each
     * separator (literal or pair-grouping auto) is one cell. The budget
     * render() actually spends and this reports are now the same model.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        $chars = mb_str_split($this->content, 1, 'UTF-8');
        $hasLiteralColon = in_array(':', $chars, true);

        $cells = 0;
        $literalColons = 0;
        $autoColons = 0;
        $consecutiveDigits = 0;

        foreach ($chars as $char) {
            if ($char === ':') {
                $literalColons++;
                $consecutiveDigits = 0;
                continue;
            }

            if (
                $this->showColon
                && !$hasLiteralColon
                && ctype_digit($char)
                && $consecutiveDigits > 0
                && $consecutiveDigits % 2 === 0
            ) {
                $autoColons++;
            }

            $cells++;
            $consecutiveDigits = ctype_digit($char) ? $consecutiveDigits + 1 : 0;
        }

        $width = $cells * max(2, $this->digitWidth) + $literalColons + $autoColons;
        return [$width, self::DIGIT_HEIGHT];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the digit width (horizontal size of each digit).
     */
    public function withDigitWidth(int $width): self
    {
        return new self(
            content: $this->content,
            digitWidth: max(2, $width),
            showColon: $this->showColon,
            onColor: $this->onColor,
            offColor: $this->offColor,
        );
    }

    /**
     * Show or hide the colon separator.
     */
    public function withShowColon(bool $show): self
    {
        return new self(
            content: $this->content,
            digitWidth: $this->digitWidth,
            showColon: $show,
            onColor: $this->onColor,
            offColor: $this->offColor,
        );
    }

    /**
     * Set the "on" (lit) segment color.
     */
    public function withOnColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            digitWidth: $this->digitWidth,
            showColon: $this->showColon,
            onColor: $color,
            offColor: $this->offColor,
        );
    }

    /**
     * Set the "off" (unlit) segment color.
     */
    public function withOffColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            digitWidth: $this->digitWidth,
            showColon: $this->showColon,
            onColor: $this->onColor,
            offColor: $color,
        );
    }

    /**
     * Set new content.
     */
    public function withContent(string $content): self
    {
        return new self(
            content: $content,
            digitWidth: $this->digitWidth,
            showColon: $this->showColon,
            onColor: $this->onColor,
            offColor: $this->offColor,
        );
    }
}
