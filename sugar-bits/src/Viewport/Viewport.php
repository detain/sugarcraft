<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Viewport;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Util\Width;

/**
 * Scrollable text area. Holds a fixed-size window over arbitrarily long
 * content; navigation methods (lineUp/lineDown/pageUp/…) advance the
 * window, clamped so the viewport never goes past the start or end.
 *
 * `update()` recognises the standard navigation keys: ↑/k, ↓/j, PgUp/b,
 * PgDn/space/f, Ctrl+U / Ctrl+D (half page), Home/g, End/G, plus
 * ←/h and →/l for horizontal scroll.
 */
final class Viewport implements Model
{
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        /** @var list<string> */
        public readonly array $lines,
        public readonly int $yOffset,
        public readonly int $xOffset = 0,
        public readonly int $horizontalStep = 6,
        public readonly bool $mouseWheelEnabled = true,
        public readonly int $mouseWheelDelta = 3,
        public readonly bool $showScrollbar = false,
        public readonly string $scrollbarChar = '█',
        public readonly string $scrollbarTrack = '│',
    ) {}

    /** Construct a fresh instance with default state. */
    public static function new(int $width = 80, int $height = 24): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('viewport width/height must be >= 0');
        }
        return new self($width, $height, [''], 0);
    }

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof MouseWheelMsg && $this->mouseWheelEnabled) {
            // SGR mouse: action carries WheelUp/WheelDown semantics.
            return match ($msg->action) {
                MouseAction::WheelUp   => [$this->lineUp($this->mouseWheelDelta),   null],
                MouseAction::WheelDown => [$this->lineDown($this->mouseWheelDelta), null],
                default                => [$this, null],
            };
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        return match (true) {
            $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $msg->rune === 'k')
                => [$this->lineUp(1), null],
            $msg->type === KeyType::Down
                || ($msg->type === KeyType::Char && $msg->rune === 'j')
                => [$this->lineDown(1), null],
            $msg->type === KeyType::Left
                || ($msg->type === KeyType::Char && $msg->rune === 'h')
                => [$this->scrollLeft(), null],
            $msg->type === KeyType::Right
                || ($msg->type === KeyType::Char && $msg->rune === 'l')
                => [$this->scrollRight(), null],
            $msg->type === KeyType::PageUp
                || ($msg->type === KeyType::Char && $msg->rune === 'b')
                => [$this->pageUp(), null],
            $msg->type === KeyType::PageDown
                || $msg->type === KeyType::Space
                || ($msg->type === KeyType::Char && $msg->rune === 'f')
                => [$this->pageDown(), null],
            $msg->ctrl && $msg->rune === 'u'
                => [$this->halfPageUp(), null],
            $msg->ctrl && $msg->rune === 'd'
                => [$this->halfPageDown(), null],
            $msg->type === KeyType::Home
                || ($msg->type === KeyType::Char && $msg->rune === 'g')
                => [$this->gotoTop(), null],
            $msg->type === KeyType::End
                || ($msg->type === KeyType::Char && $msg->rune === 'G')
                => [$this->gotoBottom(), null],
            default => [$this, null],
        };
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        $top    = max(0, $this->yOffset);
        $window = array_slice($this->lines, $top, $this->height);
        if ($this->xOffset > 0) {
            $window = array_map(
                fn(string $l) => Width::dropAnsi($l, $this->xOffset),
                $window,
            );
        }
        if (!$this->showScrollbar) {
            return implode("\n", $window);
        }
        return $this->paintScrollbar($window);
    }

    // ---- content + dimensions ----------------------------------------

    public function setContent(string $content): self
    {
        $lines = $content === '' ? [''] : explode("\n", $content);
        return $this->copy(lines: $lines)->clamp();
    }

    public function withSize(int $width, int $height): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('viewport width/height must be >= 0');
        }
        return $this->copy(width: $width, height: $height)->clamp();
    }

    /**
     * Resize only the visible width. Mirrors upstream Bubbles'
     * `Viewport.SetWidth(int)` — separate from `SetHeight` so callers
     * can resize one axis at a time without rebuilding the whole
     * geometry. Negative values throw.
     */
    public function setWidth(int $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException('viewport width must be >= 0');
        }
        return $this->copy(width: $width)->clamp();
    }

    /**
     * Resize only the visible height. Mirrors upstream Bubbles'
     * `Viewport.SetHeight(int)`. Negative values throw.
     */
    public function setHeight(int $height): self
    {
        if ($height < 0) {
            throw new \InvalidArgumentException('viewport height must be >= 0');
        }
        return $this->copy(height: $height)->clamp();
    }

    /** Read-only accessor for the configured visible width (cells). */
    public function getWidth(): int  { return $this->width; }
    /** Read-only accessor for the configured visible height (rows). */
    public function getHeight(): int { return $this->height; }

    /** Direct seek to `$offset`. Clamped to `[0, maxOffset]`. */
    public function setYOffset(int $offset): self
    {
        return $this->copy(yOffset: $offset)->clamp();
    }

    public function yOffset(): int { return $this->yOffset; }

    /** Direct seek of the horizontal offset, clamped to `[0, maxXOffset]`. */
    public function setXOffset(int $offset): self
    {
        return $this->copy(xOffset: $offset)->clamp();
    }

    public function xOffset(): int { return $this->xOffset; }

    /** Cells advanced per `scrollLeft()` / `scrollRight()`. Default 6. */
    public function withHorizontalStep(int $step): self
    {
        return $this->copy(horizontalStep: max(1, $step));
    }

    public function withMouseWheelEnabled(bool $on): self
    {
        return $this->copy(mouseWheelEnabled: $on);
    }

    /** Lines moved per wheel notch. Default 3. */
    public function withMouseWheelDelta(int $delta): self
    {
        return $this->copy(mouseWheelDelta: max(1, $delta));
    }

    /** Render a vertical scrollbar in the rightmost column. Default off. */
    public function withScrollbar(bool $on = true): self
    {
        return $this->copy(showScrollbar: $on);
    }

    public function withScrollbarRunes(string $thumb, string $track): self
    {
        return $this->copy(scrollbarChar: $thumb, scrollbarTrack: $track);
    }

    // ---- navigation --------------------------------------------------

    public function lineUp(int $n = 1): self
    {
        return $this->copy(yOffset: $this->yOffset - max(0, $n))->clamp();
    }

    public function lineDown(int $n = 1): self
    {
        return $this->copy(yOffset: $this->yOffset + max(0, $n))->clamp();
    }

    public function halfPageUp(): self   { return $this->lineUp((int) max(1, intdiv($this->height, 2))); }
    public function halfPageDown(): self { return $this->lineDown((int) max(1, intdiv($this->height, 2))); }

    public function pageUp(): self   { return $this->lineUp(max(1, $this->height)); }
    public function pageDown(): self { return $this->lineDown(max(1, $this->height)); }

    public function scrollLeft(int $n = 0): self
    {
        $n = $n > 0 ? $n : $this->horizontalStep;
        return $this->copy(xOffset: max(0, $this->xOffset - $n));
    }

    public function scrollRight(int $n = 0): self
    {
        $n = $n > 0 ? $n : $this->horizontalStep;
        return $this->copy(xOffset: $this->xOffset + $n)->clamp();
    }

    public function gotoTop(): self
    {
        return $this->copy(yOffset: 0);
    }

    public function gotoBottom(): self
    {
        return $this->copy(yOffset: $this->maxOffset())->clamp();
    }

    // ---- queries -----------------------------------------------------

    public function totalLineCount(): int
    {
        return count($this->lines);
    }

    public function visibleLineCount(): int
    {
        return min($this->height, max(0, $this->totalLineCount() - $this->yOffset));
    }

    public function atTop(): bool    { return $this->yOffset <= 0; }
    public function atBottom(): bool { return $this->yOffset >= $this->maxOffset(); }

    /** True when the horizontal offset is at the leftmost column (0). */
    public function atLeftmost(): bool { return $this->xOffset <= 0; }

    /** True when the horizontal offset is at the maximum needed for any line. */
    public function atRightmost(): bool { return $this->xOffset >= $this->maxXOffset(); }

    /** Scroll position 0.0 (top) → 1.0 (bottom). 1.0 when content fits. */
    public function scrollPercent(): float
    {
        $max = $this->maxOffset();
        if ($max <= 0) {
            return 1.0;
        }
        return min(1.0, max(0.0, $this->yOffset / $max));
    }

    /** Horizontal scroll position 0.0 (leftmost) → 1.0 (rightmost). */
    public function horizontalScrollPercent(): float
    {
        $max = $this->maxXOffset();
        if ($max <= 0) {
            return 1.0;
        }
        return min(1.0, max(0.0, $this->xOffset / $max));
    }

    private function maxOffset(): int
    {
        return max(0, $this->totalLineCount() - $this->height);
    }

    private function maxXOffset(): int
    {
        $widest = 0;
        foreach ($this->lines as $line) {
            $w = Width::string($line);
            if ($w > $widest) {
                $widest = $w;
            }
        }
        return max(0, $widest - $this->width);
    }

    private function clamp(): self
    {
        $offset  = max(0, min($this->yOffset, $this->maxOffset()));
        $xOffset = max(0, min($this->xOffset, $this->maxXOffset()));
        if ($offset === $this->yOffset && $xOffset === $this->xOffset) {
            return $this;
        }
        return $this->copy(yOffset: $offset, xOffset: $xOffset);
    }

    /**
     * Append a single-column scrollbar to each visible line. The thumb
     * occupies a vertical slot proportional to the visible/total
     * ratio; the track fills the rest. Lines shorter than `$width-1`
     * are padded with spaces so the scrollbar stays right-aligned.
     *
     * @param list<string> $window
     */
    private function paintScrollbar(array $window): string
    {
        $rendered = count($window);
        $total    = $this->totalLineCount();
        $thumbHeight = $total > 0
            ? max(1, (int) round($rendered * ($rendered / $total)))
            : $rendered;
        $maxThumbStart = max(0, $rendered - $thumbHeight);
        $thumbStart = $total > $rendered
            ? (int) round($maxThumbStart * ($this->yOffset / max(1, $this->maxOffset())))
            : 0;
        $bodyWidth = max(0, $this->width - 1);
        $out = [];
        foreach ($window as $i => $line) {
            $padded = $bodyWidth > 0
                ? Width::padRight($line, $bodyWidth)
                : $line;
            $sb = ($i >= $thumbStart && $i < $thumbStart + $thumbHeight)
                ? $this->scrollbarChar
                : $this->scrollbarTrack;
            $out[] = $padded . $sb;
        }
        return implode("\n", $out);
    }

    /**
     * @param list<string>|null $lines
     */
    private function copy(
        ?int $width = null,
        ?int $height = null,
        ?array $lines = null,
        ?int $yOffset = null,
        ?int $xOffset = null,
        ?int $horizontalStep = null,
        ?bool $mouseWheelEnabled = null,
        ?int $mouseWheelDelta = null,
        ?bool $showScrollbar = null,
        ?string $scrollbarChar = null,
        ?string $scrollbarTrack = null,
    ): self {
        return new self(
            width:              $width             ?? $this->width,
            height:             $height            ?? $this->height,
            lines:              $lines             ?? $this->lines,
            yOffset:            $yOffset           ?? $this->yOffset,
            xOffset:            $xOffset           ?? $this->xOffset,
            horizontalStep:     $horizontalStep    ?? $this->horizontalStep,
            mouseWheelEnabled:  $mouseWheelEnabled ?? $this->mouseWheelEnabled,
            mouseWheelDelta:    $mouseWheelDelta   ?? $this->mouseWheelDelta,
            showScrollbar:      $showScrollbar     ?? $this->showScrollbar,
            scrollbarChar:      $scrollbarChar     ?? $this->scrollbarChar,
            scrollbarTrack:     $scrollbarTrack    ?? $this->scrollbarTrack,
        );
    }
}
