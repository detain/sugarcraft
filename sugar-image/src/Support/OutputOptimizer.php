<?php

declare(strict_types=1);

namespace SugarCraft\Image\Support;

/**
 * Streaming SGR pen-delta optimizer for character-mosaic emitters.
 *
 * Chafa keeps a "current pen" per output stream and prints only the
 * attributes that actually change between cells (chafa-canvas-printer.c:139-215
 * `emit_attributes_truecolor`), and it never wastes a control sequence on a
 * blank cell: blanks copy the previous cell's foreground (chafa-symbol-
 * renderer.c:862-886) because a space carries no foreground ink. Row ends
 * restore the pen the caller had BEFORE the mosaic was emitted rather than
 * clobbering the whole attribute set with `ESC[0m`, so a blinking/underlined
 * caller frame survives a rendered image riding through it.
 *
 * This class is that idiom: pen state is keyed on the six SGR channels the
 * block/ascii renderers can move (foreground, background, bright/bold,
 * underline, inverse, blink). Pen values are raw SGR parameter strings
 * (e.g. "38;2;255;0;0", "38;5;196", "31"); `null` means "terminal default"
 * (entry style). Emission is a pure delta: bytes leave this class only when
 * a channel's value differs from the tracked one.
 *
 * A-14 row capacity: chafa preallocates each row at
 * (cells+1)*(TERM_SEQ_MAX*3+6)+1 (chafa-canvas-printer.c:654-669). PHP strings
 * grow on their own, so the analogue here is the documented worst-case bound
 * {@see OutputOptimizer::rowByteCapacity()} — every produced row is pinned to
 * stay within it (tests/Support/OutputOptimizerTest), which is what catches an
 * accidental per-cell-reset regression (that shape blows the bound: unbounded
 * resets ride every cell).
 *
 * Idiom split (recorded in CALIBER_LEARNINGS): sugar-cellbuffer's
 * Buffer::toAnsi() runs an independent adjacent-run coalescer over its own
 * cell grid. The two MUST NOT be unified — toAnsi walks a finished grid, this
 * optimizer streams a render loop.
 */
final class OutputOptimizer
{
    /**
     * Worst-case bytes a single cell can add: a full foreground sequence, a
     * full background sequence, one attribute toggle, plus the glyph.
     * Mirrors chafa's 3*TERM_SEQ_MAX + 6 per-cell budget (printer.c:663).
     */
    public const BYTES_PER_CELL = 3 * 20 + 6;

    /** Slack for the row-end style restore sequence. */
    public const ROW_TAIL_BYTES = 32;

    /** Normalized SGR parameters for a 24-bit colour. */
    public static function trueColor(int $r, int $g, int $b): string
    {
        return "38;2;{$r};{$g};{$b}";
    }

    /** Normalized SGR parameters for a 256-palette index. */
    public static function indexed256(int $index): string
    {
        return "38;5;{$index}";
    }

    /** SGR codes that switch an attribute channel on. */
    private const ON_CODES = ['bright' => '1', 'underline' => '4', 'inverse' => '7', 'blink' => '5'];

    /** SGR codes that switch an attribute back off (the entry state of every attribute channel). */
    private const OFF_CODES = ['bright' => '22', 'underline' => '24', 'inverse' => '27', 'blink' => '25'];

    private string $buffer = '';

    /** @var array{foreground: ?string, background: ?string, bright: ?string, underline: ?string, inverse: ?string, blink: ?string} */
    private array $pen;

    /**
     * @param string|null $entryForeground SGR parameters of the caller's pen foreground (null = terminal default)
     * @param string|null $entryBackground SGR parameters of the caller's pen background (null = terminal default)
     */
    public function __construct(
        private readonly ?string $entryForeground = null,
        private readonly ?string $entryBackground = null,
    ) {
        $this->pen = [
            'foreground' => $this->entryForeground,
            'background' => $this->entryBackground,
            'bright'     => null,
            'underline'  => null,
            'inverse'    => null,
            'blink'      => null,
        ];
    }

    /**
     * Append a coloured cell, emitting SGR only for channels that change.
     *
     * A space glyph carries no foreground ink: its foreground request is
     * ignored and the tracked foreground is copied through (chafa blank
     * idiom, symbol-renderer.c:862-886). Background still applies — a space
     * on a coloured background paints.
     */
    public function cell(string $glyph, ?string $foreground = null, ?string $background = null): void
    {
        if ($glyph !== ' ') {
            $this->setPen('foreground', $foreground);
        }

        $this->setPen('background', $background);
        $this->buffer .= $glyph;
    }

    /**
     * Append a blank cell: foreground copied from the previous cell (zero
     * bytes), background dropped back to the entry style.
     */
    public function blank(): void
    {
        $this->cell(' ');
    }

    /**
     * Switch an attribute channel on (or back to the entry/off state with null).
     *
     * @param string $channel one of bright|underline|inverse|blink
     */
    public function attribute(string $channel, bool $on): void
    {
        if (!isset(self::OFF_CODES[$channel])) {
            throw new \InvalidArgumentException("unknown attribute channel [{$channel}]");
        }

        $value = $on ? $channel : null;
        if ($value === $this->pen[$channel]) {
            return;
        }

        $this->buffer .= "\x1b[" . ($on ? self::ON_CODES[$channel] : self::OFF_CODES[$channel]) . 'm';
        $this->pen[$channel] = $value;
    }

    /**
     * Close the current row: restore the entry pen (scoped resets, never an
     * `ESC[0m` clobber) and return the row's bytes. Pen state is back at the
     * entry style afterwards, so the next row (or the caller) starts clean.
     */
    public function finishRow(): string
    {
        $restore = [];

        foreach (self::OFF_CODES as $channel => $off) {
            if ($this->pen[$channel] !== null) {
                $restore[] = $off;
                $this->pen[$channel] = null;
            }
        }

        foreach (['foreground' => ['39', $this->entryForeground], 'background' => ['49', $this->entryBackground]] as $channel => [$default, $entry]) {
            if ($this->pen[$channel] === $entry) {
                continue;
            }

            $restore[] = $entry === null ? $default
                : ($channel === 'background' && str_starts_with($entry, '38;') ? '48;' . substr($entry, 3) : $entry);
            $this->pen[$channel] = $entry;
        }

        if ($restore !== []) {
            $this->buffer .= "\x1b[" . implode(';', $restore) . 'm';
        }

        $row = $this->buffer;
        $this->buffer = '';

        return $row;
    }

    /**
     * Documented worst-case row size for a row of $cells cells (A-14).
     */
    public static function rowByteCapacity(int $cells): int
    {
        if ($cells < 0) {
            throw new \InvalidArgumentException('cell count must not be negative');
        }

        return ($cells + 1) * self::BYTES_PER_CELL + self::ROW_TAIL_BYTES;
    }

    /** Current tracked foreground parameters (null = terminal default). */
    public function foreground(): ?string
    {
        return $this->pen['foreground'];
    }

    /** Current tracked background parameters (null = terminal default). */
    public function background(): ?string
    {
        return $this->pen['background'];
    }

    private function setPen(string $channel, ?string $value): void
    {
        if ($value === $this->pen[$channel]) {
            return;
        }

        if ($value === null) {
            $this->buffer .= $channel === 'foreground' ? "\x1b[39m" : "\x1b[49m";
            $this->pen[$channel] = $value;

            return;
        }

        // Pen values are normalized to the foreground spelling ("38;2;r;g;b",
        // "38;5;i"); the background channel rewrites the colour selector so a
        // single value form serves both channels for delta tracking.
        $params = $channel === 'background' && str_starts_with($value, '38;')
            ? '48;' . substr($value, 3)
            : $value;
        $this->buffer .= "\x1b[" . $params . 'm';

        $this->pen[$channel] = $value;
    }
}
