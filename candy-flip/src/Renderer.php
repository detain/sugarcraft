<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Pty\SizeIoctl;

/**
 * Render a {@see Frame} as ANSI-coloured Unicode block-glyphs.
 *
 * Two presets:
 *   - `solid`    — every cell is `█` painted in the cell's RGB.
 *                  Looks like a real image; takes a wide terminal.
 *   - `density`  — pick a glyph from a luminance ramp (` .:-=+*#%@`).
 *                  Reads as ASCII art, easier on narrow windows.
 */
final class Renderer
{
    public const PRESET_SOLID   = 'solid';
    public const PRESET_DENSITY = 'density';

    private const RAMP = ' .:-=+*#%@';

    /** @var int<0, max>|null */
    private readonly ?int $adaptiveRows;

    /** @var int<0, max>|null */
    private readonly ?int $adaptiveCols;

    /**
     * @param int<0, max>|null $adaptiveRows  limit rows rendered to this many; null = unlimited
     * @param int<0, max>|null $adaptiveCols  limit cols rendered to this many; null = unlimited
     */
    private function __construct(
        ?int $adaptiveRows = null,
        ?int $adaptiveCols = null,
    ) {
        $this->adaptiveRows = $adaptiveRows;
        $this->adaptiveCols = $adaptiveCols;
    }

    /**
     * Return a Renderer that adapts its output to fit the current TTY dimensions.
     *
     * Queries the terminal size via ioctl(TIOCGWINSZ) — no shell-out to tput.
     * When adaptive size is set, the renderer clamps the output grid to the
     * available rows and columns so the GIF never overflow the viewport.
     *
     * @throws \RuntimeException if STDOUT is not a TTY
     */
    public static function withAdaptiveSize(): self
    {
        $size = SizeIoctl::query((int) STDOUT);
        return new self($size['rows'], $size['cols']);
    }

    /**
     * Return a fresh Renderer with no adaptive sizing (default).
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * @internal For testing only — creates a Renderer with explicit adaptive constraints.
     * @param int<0, max> $rows
     * @param int<0, max> $cols
     */
    public static function withConstraints(int $rows, int $cols): self
    {
        $rc = new \ReflectionClass(Renderer::class);
        $instance = $rc->newInstanceWithoutConstructor();

        $propRows = $rc->getProperty('adaptiveRows');
        $propRows->setAccessible(true);
        $propRows->setValue($instance, $rows);

        $propCols = $rc->getProperty('adaptiveCols');
        $propCols->setAccessible(true);
        $propCols->setValue($instance, $cols);

        return $instance;
    }

    /**
     * Static-compatible render — delegates to a zero-sized (unconstrained) instance.
     * Preserved for backward compatibility; new code should use {@see self::new()}->renderFrame().
     */
    public static function render(Frame $f, string $preset = self::PRESET_SOLID): string
    {
        return (new self())->renderFrame($f, $preset);
    }

    /**
     * Render a frame. When adaptive dimensions are set (via {@see withAdaptiveSize()}),
     * the output grid is clamped to fit within them.
     */
    public function renderFrame(Frame $f, string $preset = self::PRESET_SOLID): string
    {
        $maxRows = $this->adaptiveRows;
        $maxCols = $this->adaptiveCols;
        $rows = [];
        foreach ($f->cells as $rowIndex => $row) {
            if ($maxRows !== null && $rowIndex >= $maxRows) {
                break;
            }
            $line = '';
            foreach ($row as $colIndex => $cell) {
                if ($maxCols !== null && $colIndex >= $maxCols) {
                    break;
                }
                if ($cell === null) {
                    $line .= $this->transparent();
                } else {
                    [$r, $g, $b] = $cell;
                    $line .= $this->cell($r, $g, $b, $preset);
                }
            }
            $rows[] = $line . Ansi::reset();
        }
        return implode("\n", $rows);
    }

    private function cell(int $r, int $g, int $b, string $preset): string
    {
        if ($preset === self::PRESET_DENSITY) {
            // 0.299r + 0.587g + 0.114b is the standard luminance weight.
            $lum = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
            $idx = (int) round($lum / 255 * (strlen(self::RAMP) - 1));
            $glyph = self::RAMP[$idx] ?? ' ';
            return sprintf(Ansi::CSI . "38;2;%d;%d;%dm%s", $r, $g, $b, $glyph);
        }
        // Solid block — full-cell colour fill via 24-bit truecolor escape.
        return sprintf(Ansi::CSI . "48;2;%d;%d;%dm ", $r, $g, $b);
    }

    /**
     * Emit a transparent-cell placeholder (resets bg so the terminal
     * background shows through).
     */
    private function transparent(): string
    {
        return Ansi::sgr(49) . ' '; // Reset to default background.
    }
}
