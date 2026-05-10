<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Scrollbar;

/**
 * Standalone vertical (or horizontal) scrollbar widget.
 *
 * Renders a scrollbar track with a thumb indicator positioned according
 * to {@see ScrollbarState}. Optionally prepends/append arrow glyphs at
 * the top and bottom of the track.
 *
 * Mirrors ratatui/Scrollbar.
 */
final class Scrollbar
{
    public const ARROW_UP   = '▲';
    public const ARROW_DOWN = '▼';

    private function __construct(
        public readonly bool $vertical,
        public readonly string $trackChar,
        public readonly string $thumbChar,
        public readonly bool $showArrows,
    ) {}

    /** Construct a vertical scrollbar with defaults. */
    public static function vertical(): self
    {
        return new self(
            vertical: true,
            trackChar: '░',
            thumbChar: '█',
            showArrows: true,
        );
    }

    /** Construct a horizontal scrollbar with defaults. */
    public static function horizontal(): self
    {
        return new self(
            vertical: false,
            trackChar: '░',
            thumbChar: '█',
            showArrows: false,
        );
    }

    public function withTrackChar(string $char): self
    {
        return $this->mutate(trackChar: $char);
    }

    public function withThumbChar(string $char): self
    {
        return $this->mutate(thumbChar: $char);
    }

    public function withArrows(bool $show): self
    {
        return $this->mutate(showArrows: $show);
    }

    /**
     * Render the scrollbar into `$height` rows.
     *
     * When content fits entirely in the viewport ($total <= $viewport),
     * renders the track character for all rows.
     *
     * The thumb is sized proportionally: thumbHeight = max(1, round(viewport/total * height))
     * when total > 0. Its vertical position is:
     *   thumbStart = round(position / max(1, total - viewport) * availableSpace)
     * where availableSpace = height - 2 when arrows are shown, otherwise height.
     *
     * @param ScrollbarState $state  Current scrollbar state
     * @param int            $height Number of rows to render (must be >= 0)
     * @return string Rendered scrollbar (one char per row, no newlines)
     */
    public function view(ScrollbarState $state, int $height): string
    {
        if ($height <= 0) {
            return '';
        }

        $total    = $state->total;
        $position = $state->position;
        $viewport = $state->viewport;

        // Content fits in viewport — render all track.
        if ($total <= $viewport) {
            return str_repeat($this->trackChar, $height);
        }

        $availableSpace = $this->showArrows ? $height - 2 : $height;

        // Guard against degenerate case where availableSpace is 0 or negative.
        if ($availableSpace <= 0) {
            return str_repeat($this->trackChar, $height);
        }

        $thumbHeight = max(1, (int) round($viewport / $total * $height));
        $maxThumbStart = max(0, $availableSpace - $thumbHeight);
        $thumbStart = (int) round($position / max(1, $total - $viewport) * $maxThumbStart);

        $out = '';
        for ($i = 0; $i < $height; $i++) {
            if ($this->showArrows) {
                if ($i === 0) {
                    $out .= self::ARROW_UP;
                    continue;
                }
                if ($i === $height - 1) {
                    $out .= self::ARROW_DOWN;
                    continue;
                }

                $trackIndex = $i - 1; // 0-based index into available space
                if ($trackIndex >= $thumbStart && $trackIndex < $thumbStart + $thumbHeight) {
                    $out .= $this->thumbChar;
                } else {
                    $out .= $this->trackChar;
                }
            } else {
                if ($i >= $thumbStart && $i < $thumbStart + $thumbHeight) {
                    $out .= $this->thumbChar;
                } else {
                    $out .= $this->trackChar;
                }
            }
        }

        return $out;
    }

    private function mutate(
        ?bool $vertical = null,
        ?string $trackChar = null,
        ?string $thumbChar = null,
        ?bool $showArrows = null,
    ): self {
        return new self(
            vertical:    $vertical    ?? $this->vertical,
            trackChar:   $trackChar   ?? $this->trackChar,
            thumbChar:   $thumbChar   ?? $this->thumbChar,
            showArrows:  $showArrows  ?? $this->showArrows,
        );
    }
}
