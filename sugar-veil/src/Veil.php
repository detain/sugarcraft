<?php

declare(strict_types=1);

namespace SugarCraft\Veil;

use SugarCraft\Core\Util\Width;

/**
 * Terminal overlay compositor.
 *
 * Composites a foreground string over a background string at a given
 * position with optional pixel offsets.
 *
 * Port of rmhubbert/bubbletea-overlay.
 *
 * @see https://github.com/rmhubbert/bubbletea-overlay
 */
final class Veil
{
    /**
     * Create a new Veil instance.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Composite a foreground string over a background string.
     *
     * @param string    $foreground  The overlay content (e.g. a modal)
     * @param string    $background  The base content
     * @param Position  $vertical    Vertical position anchor
     * @param Position  $horizontal  Horizontal position anchor
     * @param int       $xOffset     Additional columns rightward (+) / leftward (-)
     * @param int       $yOffset     Additional lines downward (+) / upward (-)
     * @return string                The composited output
     */
    public function composite(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        $bgLines  = $this->splitLines($background);
        $fgLines  = $this->splitLines($foreground);
        $bgHeight = \count($bgLines);
        $bgWidth  = $this->maxLineWidth($bgLines);
        $fgHeight = \count($fgLines);
        $fgWidth  = $this->maxLineWidth($fgLines);

        if ($bgHeight === 0 || $bgWidth === 0) {
            return $background;
        }

        // Resolve base position
        $baseX = $horizontal->xOffset($fgWidth, $bgWidth);
        $baseY = $vertical->yOffset($fgHeight, $bgHeight);

        // Apply additional offsets
        $x = $baseX + $xOffset;
        $y = $baseY + $yOffset;

        // Clamp so the overlay stays within the background bounds
        $x = \max(0, \min($x, $bgWidth  - 1));
        $y = \max(0, \min($y, $bgHeight - 1));

        // Build output by copying background lines
        $output = $bgLines;

        // Overlay each foreground line
        for ($fy = 0; $fy < $fgHeight; $fy++) {
            $destY = $y + $fy;
            if ($destY >= $bgHeight) break;

            $fgLine = $fgLines[$fy];
            $fgLineLen = \strlen($fgLine);

            for ($fx = 0; $fx < $fgLineLen; $fx++) {
                $destX = $x + $fx;
                if ($destX >= $bgWidth) break;

                $char = $fgLine[$fx];
                if ($char !== "\n" && $char !== "\r") {
                    $output[$destY] = $this->replaceCharAt($output[$destY], $destX, $char);
                }
            }
        }

        return \implode("\n", $output);
    }

    /**
     * Split a multi-line string into an array of lines.
     *
     * @return list<string>
     */
    public function splitLines(string $text): array
    {
        $lines = \explode("\n", $text);
        // Remove trailing empty line from final \n
        if (\end($lines) === '') {
            \array_pop($lines);
        }
        return $lines;
    }

    /**
     * Get the maximum line width (in characters) of an array of lines.
     *
     * @param list<string> $lines
     */
    public function maxLineWidth(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $w = $this->lineWidth($line);
            if ($w > $max) $max = $w;
        }
        return $max;
    }

    /**
     * Get the display width of a single line (stripping ANSI escape codes).
     */
    public function lineWidth(string $line): int
    {
        return Width::string($line);
    }

    /**
     * Replace the character at position $x in $line, respecting multibyte chars.
     */
    private function replaceCharAt(string $line, int $x, string $char): string
    {
        $result = '';
        $bytePos = 0;
        $col = 0;
        $len = \strlen($line);

        while ($bytePos < $len) {
            if ($col === $x) {
                // Found the target column — replace
                $result .= $char;
                // Skip the old character (could be multibyte)
                $codepoint = '';
                if (($line[$bytePos] ?? '') >= "\x80") {
                    // Multibyte: skip UTF-8 char
                    $ord = \ord($line[$bytePos]);
                    if ($ord < 0xC0) {
                        $bytePos++;
                    } elseif ($ord < 0xE0) {
                        $bytePos += 2;
                    } elseif ($ord < 0xF0) {
                        $bytePos += 3;
                    } else {
                        $bytePos += 4;
                    }
                } else {
                    $bytePos++;
                }
                // Append rest of string
                $result .= \substr($line, $bytePos);
                return $result;
            }

            $c = $line[$bytePos];
            if ($c >= "\x80") {
                // Multibyte — decode properly
                $ord = \ord($c);
                if ($ord < 0xC0) {
                    $result .= $c;
                    $bytePos++;
                } elseif ($ord < 0xE0) {
                    $result .= \substr($line, $bytePos, 2);
                    $bytePos += 2;
                } elseif ($ord < 0xF0) {
                    $result .= \substr($line, $bytePos, 3);
                    $bytePos += 3;
                } else {
                    $result .= \substr($line, $bytePos, 4);
                    $bytePos += 4;
                }
            } else {
                $result .= $c;
                $bytePos++;
            }
            $col++;
        }

        // Position was beyond line end — pad and append
        if ($col === $x) {
            $result .= $char;
        }

        return $result;
    }
}
