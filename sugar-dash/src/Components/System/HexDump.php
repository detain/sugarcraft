<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A hexadecimal dump display component.
 *
 * Displays data in classic hexdump format:
 * - Offset column (hex address)
 * - Hex bytes (grouped by 8 or 16)
 * - ASCII representation on the right
 *
 * Supports:
 * - Custom bytes per line (8 or 16)
 * - Color themes for offsets, hex, ASCII
 * - Highlighting for non-printable bytes
 *
 * Mirrors hexdump/xxd style output adapted to PHP with
 * wither-style immutable setters.
 */
final class HexDump implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const BYTES_PER_LINE_8 = 8;
    public const BYTES_PER_LINE_16 = 16;

    public function __construct(
        private readonly string $data,
        private readonly int $bytesPerLine = self::BYTES_PER_LINE_16,
        private readonly ?Color $offsetColor = null,
        private readonly ?Color $hexColor = null,
        private readonly ?Color $asciiColor = null,
        private readonly ?Color $nonPrintableColor = null,
        private readonly bool $uppercase = true,
    ) {
        if ($bytesPerLine !== self::BYTES_PER_LINE_8 && $bytesPerLine !== self::BYTES_PER_LINE_16) {
            $bytesPerLine = self::BYTES_PER_LINE_16;
        }
    }

    /**
     * Create a new hex dump with default styling (Catppuccin Mocha theme).
     */
    public static function new(string $data): self
    {
        return new self(
            data: $data,
            bytesPerLine: self::BYTES_PER_LINE_16,
            offsetColor: Color::hex('#F38BA8'),  // Pink - offsets
            hexColor: Color::hex('#A6E3A1'),     // Green - hex bytes
            asciiColor: Color::hex('#89DCEB'),   // Cyan - ASCII
            nonPrintableColor: Color::hex('#6C7086'), // Gray - non-printable
            uppercase: true,
        );
    }

    /**
     * Create a hex dump with 8 bytes per line (compact).
     */
    public static function compact(string $data): self
    {
        return new self(
            data: $data,
            bytesPerLine: self::BYTES_PER_LINE_8,
            offsetColor: Color::hex('#F38BA8'),
            hexColor: Color::hex('#A6E3A1'),
            asciiColor: Color::hex('#89DCEB'),
            nonPrintableColor: Color::hex('#6C7086'),
            uppercase: true,
        );
    }

    /**
     * Set the allocated dimensions for this hex dump.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the hex dump as a string.
     */
    public function render(): string
    {
        if ($this->data === '') {
            return '';
        }

        $lines = [];
        $dataBytes = array_values(unpack('C*', $this->data));
        $totalBytes = count($dataBytes);
        $bytesPerLine = $this->bytesPerLine;

        $offsetWidth = $this->uppercase ? '00000000' : '00000000';
        // Format string for offset based on data size
        $offsetFormat = $this->uppercase ? '%08X' : '%08x';

        for ($i = 0; $i < $totalBytes; $i += $bytesPerLine) {
            $offset = $i;
            $lineBytes = array_slice($dataBytes, $i, $bytesPerLine);

            $hexPart = $this->renderHexPart($lineBytes, $bytesPerLine, $offset);
            $asciiPart = $this->renderAsciiPart($lineBytes);

            $line = sprintf($offsetFormat, $offset) . "  " . $hexPart . "  " . $asciiPart;
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Render the hex portion of a line.
     */
    private function renderHexPart(array $bytes, int $bytesPerLine, int $offset): string
    {
        $hexPairs = [];

        foreach ($bytes as $byte) {
            $hex = $this->uppercase ? strtoupper(dechex($byte)) : dechex($byte);
            $hex = str_pad($hex, 2, '0', STR_PAD_LEFT);
            $hexPairs[] = $hex;
        }

        // Pad remaining bytes if line is not full
        while (count($hexPairs) < $bytesPerLine) {
            $hexPairs[] = '  ';
        }

        // Group by 4 for 16-byte lines, or all together for 8-byte lines
        if ($bytesPerLine === 16) {
            $grouped = [];
            for ($i = 0; $i < 16; $i += 4) {
                $grouped[] = implode(' ', array_slice($hexPairs, $i, 4));
            }
            return implode('  ', $grouped);
        }

        return implode(' ', $hexPairs);
    }

    /**
     * Render the ASCII portion of a line.
     */
    private function renderAsciiPart(array $bytes): string
    {
        $chars = [];

        foreach ($bytes as $byte) {
            if ($byte >= 32 && $byte <= 126) {
                $chars[] = chr($byte);
            } else {
                $chars[] = '.';
            }
        }

        return implode('', $chars);
    }

    /**
     * Calculate the natural dimensions of this hex dump.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->data === '') {
            return [0, 0];
        }

        $totalBytes = strlen($this->data);
        $lines = (int) ceil($totalBytes / $this->bytesPerLine);
        $height = max(1, $lines);

        // Calculate width based on format:
        // Offset (8) + 2 spaces + hex bytes + 2 spaces + ascii (16)
        $bytesPerLine = $this->bytesPerLine;
        if ($bytesPerLine === 16) {
            // 16 bytes = 4 groups of 4 hex bytes each with 1 space = 4*4 + 3 = 19
            // Plus 2 spaces between offset and hex, 2 spaces between hex and ascii
            $width = 8 + 2 + 19 + 2 + 16;
        } else {
            // 8 bytes = 8 hex bytes with 1 space each = 8*2 + 7 = 23 (actually 8 pairs of 2 = 16 + 7 spaces = 23)
            // More precisely: 8 bytes = 8 pairs, each "XX " except last = 8*3-1 = 23
            $width = 8 + 2 + 23 + 2 + 8;
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the bytes per line (8 or 16).
     */
    public function withBytesPerLine(int $bytesPerLine): self
    {
        if ($bytesPerLine !== self::BYTES_PER_LINE_8 && $bytesPerLine !== self::BYTES_PER_LINE_16) {
            $bytesPerLine = self::BYTES_PER_LINE_16;
        }
        return new self(
            data: $this->data,
            bytesPerLine: $bytesPerLine,
            offsetColor: $this->offsetColor,
            hexColor: $this->hexColor,
            asciiColor: $this->asciiColor,
            nonPrintableColor: $this->nonPrintableColor,
            uppercase: $this->uppercase,
        );
    }

    /**
     * Set the offset column color.
     */
    public function withOffsetColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            bytesPerLine: $this->bytesPerLine,
            offsetColor: $color,
            hexColor: $this->hexColor,
            asciiColor: $this->asciiColor,
            nonPrintableColor: $this->nonPrintableColor,
            uppercase: $this->uppercase,
        );
    }

    /**
     * Set the hex bytes color.
     */
    public function withHexColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            bytesPerLine: $this->bytesPerLine,
            offsetColor: $this->offsetColor,
            hexColor: $color,
            asciiColor: $this->asciiColor,
            nonPrintableColor: $this->nonPrintableColor,
            uppercase: $this->uppercase,
        );
    }

    /**
     * Set the ASCII column color.
     */
    public function withAsciiColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            bytesPerLine: $this->bytesPerLine,
            offsetColor: $this->offsetColor,
            hexColor: $this->hexColor,
            asciiColor: $color,
            nonPrintableColor: $this->nonPrintableColor,
            uppercase: $this->uppercase,
        );
    }

    /**
     * Set the non-printable character color.
     */
    public function withNonPrintableColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            bytesPerLine: $this->bytesPerLine,
            offsetColor: $this->offsetColor,
            hexColor: $this->hexColor,
            asciiColor: $this->asciiColor,
            nonPrintableColor: $color,
            uppercase: $this->uppercase,
        );
    }

    /**
     * Set uppercase or lowercase hex output.
     */
    public function withUppercase(bool $uppercase): self
    {
        return new self(
            data: $this->data,
            bytesPerLine: $this->bytesPerLine,
            offsetColor: $this->offsetColor,
            hexColor: $this->hexColor,
            asciiColor: $this->asciiColor,
            nonPrintableColor: $this->nonPrintableColor,
            uppercase: $uppercase,
        );
    }

    /**
     * Set new data content.
     */
    public function withData(string $data): self
    {
        return new self(
            data: $data,
            bytesPerLine: $this->bytesPerLine,
            offsetColor: $this->offsetColor,
            hexColor: $this->hexColor,
            asciiColor: $this->asciiColor,
            nonPrintableColor: $this->nonPrintableColor,
            uppercase: $this->uppercase,
        );
    }
}