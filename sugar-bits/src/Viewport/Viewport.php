<?php

declare(strict_types=1);

namespace CandyCore\Bits\Viewport;

use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Msg\MouseWheelMsg;
use CandyCore\Core\MouseAction;

/**
 * Scrollable text area. Holds a fixed-size window over arbitrarily long
 * content; navigation methods (lineUp/lineDown/pageUp/…) advance the
 * window, clamped so the viewport never goes past the start or end.
 *
 * `update()` recognises the standard navigation keys: ↑/k, ↓/j, PgUp/b,
 * PgDn/space/f, Ctrl+U / Ctrl+D (half page), Home/g, End/G.
 */
final class Viewport implements Model
{
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        /** @var list<string> */
        public readonly array $lines,
        public readonly int $yOffset,
        public readonly bool $mouseWheelEnabled = true,
        public readonly int $mouseWheelDelta = 3,
        public readonly bool $showScrollbar = false,
        public readonly string $scrollbarChar = '█',
        public readonly string $scrollbarTrack = '│',
    ) {}

    public static function new(int $width = 80, int $height = 24): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('viewport width/height must be >= 0');
        }
        return new self($width, $height, [''], 0);
    }

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

    public function view(): string
    {
        $top    = max(0, $this->yOffset);
        $window = array_slice($this->lines, $top, $this->height);
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

    /** Direct seek to `$offset`. Clamped to `[0, maxOffset]`. */
    public function setYOffset(int $offset): self
    {
        return $this->copy(yOffset: $offset)->clamp();
    }

    public function yOffset(): int { return $this->yOffset; }

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

    /** Scroll position 0.0 (top) → 1.0 (bottom). 1.0 when content fits. */
    public function scrollPercent(): float
    {
        $max = $this->maxOffset();
        if ($max <= 0) {
            return 1.0;
        }
        return min(1.0, max(0.0, $this->yOffset / $max));
    }

    private function maxOffset(): int
    {
        return max(0, $this->totalLineCount() - $this->height);
    }

    private function clamp(): self
    {
        $offset = max(0, min($this->yOffset, $this->maxOffset()));
        if ($offset === $this->yOffset) {
            return $this;
        }
        return $this->copy(yOffset: $offset);
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
                ? \CandyCore\Core\Util\Width::padRight($line, $bodyWidth)
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
            mouseWheelEnabled:  $mouseWheelEnabled ?? $this->mouseWheelEnabled,
            mouseWheelDelta:    $mouseWheelDelta   ?? $this->mouseWheelDelta,
            showScrollbar:      $showScrollbar     ?? $this->showScrollbar,
            scrollbarChar:      $scrollbarChar     ?? $this->scrollbarChar,
            scrollbarTrack:     $scrollbarTrack    ?? $this->scrollbarTrack,
        );
    }
}
