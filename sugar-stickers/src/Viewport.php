<?php

declare(strict_types=1);

namespace SugarCraft\Stickers;

use SugarCraft\Bits\Scrollbar\Scrollbar;
use SugarCraft\Bits\Viewport\Viewport as BitsViewport;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;

/**
 * Sticker-level viewport wrapping the canonical {@see BitsViewport}.
 *
 * Composes sugar-bits Viewport rather than reimplementing it. Adds
 * sticky header/footer positioning and scroll-sync (step 10.12).
 *
 * @see \SugarCraft\Bits\Viewport\Viewport
 */
final readonly class Viewport
{
    private BitsViewport $inner;
    private int $stickyHeader;
    private int $stickyFooter;
    private ?Viewport $syncedViewport;

    /**
     * @param list<string> $lines
     */
    private function __construct(
        BitsViewport $inner,
        int $stickyHeader = 0,
        int $stickyFooter = 0,
        ?Viewport $syncedViewport = null,
    ) {
        $this->inner = $inner;
        $this->stickyHeader = $stickyHeader;
        $this->stickyFooter = $stickyFooter;
        $this->syncedViewport = $syncedViewport;
    }

    /** Construct with default dimensions (80×24). */
    public static function new(int $width = 80, int $height = 24): self
    {
        return new self(BitsViewport::new($width, $height));
    }

    /**
     * @param list<string>|null $lines
     */
    public static function withContent(string $content, int $width = 80, int $height = 24): self
    {
        return new self(BitsViewport::new($width, $height)->setContent($content));
    }

    // ---- sticky positioning --------------------------------------------

    /**
     * Designate the first `$rows` lines as a sticky header — always
     * visible at the top of the viewport regardless of scroll offset.
     */
    public function withStickyHeader(int $rows): self
    {
        $rows = max(0, $rows);
        return new self(
            $this->inner,
            $rows,
            $this->stickyFooter,
            $this->syncedViewport,
        );
    }

    /**
     * Designate the last `$rows` lines as a sticky footer — always
     * visible at the bottom of the viewport regardless of scroll offset.
     */
    public function withStickyFooter(int $rows): self
    {
        $rows = max(0, $rows);
        return new self(
            $this->inner,
            $this->stickyHeader,
            $rows,
            $this->syncedViewport,
        );
    }

    /**
     * Establish a bidirectional scroll-sync relationship with `$other`.
     *
     * The caller is responsible for applying the same scroll offset to
     * both viewports when rendering (e.g. a diff viewer with two
     * side-by-side panels sharing one yOffset value).
     */
    public function syncWith(Viewport $other): self
    {
        return new self(
            $this->inner,
            $this->stickyHeader,
            $this->stickyFooter,
            $other,
        );
    }

    /** True when this viewport has a sync partner via {@see syncWith}. */
    public function isSynced(): bool
    {
        return $this->syncedViewport !== null;
    }

    // ---- Model contract ------------------------------------------------

    public function init(): ?\Closure
    {
        return $this->inner->init();
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        [$inner, $cmd] = $this->inner->update($msg);
        return [new self($inner, $this->stickyHeader, $this->stickyFooter, $this->syncedViewport), $cmd];
    }

    public function view(): string
    {
        if ($this->stickyHeader === 0 && $this->stickyFooter === 0) {
            return $this->inner->view();
        }

        return $this->renderWithSticky();
    }

    /**
     * Render the viewport with sticky header/footer regions.
     */
    private function renderWithSticky(): string
    {
        $lines = $this->inner->lines;
        $total = count($lines);
        $height = $this->inner->height;
        $yOffset = $this->inner->yOffset;

        $headerCount = min($this->stickyHeader, max(0, $height - $this->stickyFooter));
        $footerCount = min($this->stickyFooter, max(0, $height - $this->stickyHeader));

        $scrollableHeight = max(0, $height - $headerCount - $footerCount);

        // Sticky header: first N lines, always visible.
        $headerLines = $headerCount > 0 && $total > 0
            ? array_slice($lines, 0, $headerCount)
            : [];

        // Sticky footer: last M lines, always visible.
        $footerLines = $footerCount > 0 && $total > 0
            ? array_slice($lines, max(0, $total - $footerCount))
            : [];

        // Scrollable middle: window into the remaining content.
        // Clamp against the sticky-reduced scrollable range to prevent over-scroll
        // from pulling footer lines into the middle window.
        $maxMiddleStart = max($headerCount, $total - $footerCount - $scrollableHeight);
        $middleStart = min($headerCount + $yOffset, $maxMiddleStart);
        $middleLines = $scrollableHeight > 0
            ? array_slice($lines, $middleStart, $scrollableHeight)
            : [];

        // Fill each region to full width with padding.
        $width = $this->inner->width;
        $pad = fn(string $l): string => $this->padRight($l, $width);

        $headerLines = array_map($pad, $headerLines);
        $middleLines = array_map($pad, $middleLines);
        $footerLines = array_map($pad, $footerLines);

        // Pad each region to its full height.
        $headerLines = $this->padLines($headerLines, $headerCount);
        $middleLines = $this->padLines($middleLines, $scrollableHeight);
        $footerLines = $this->padLines($footerLines, $footerCount);

        $combined = array_merge($headerLines, $middleLines, $footerLines);

        if ($this->inner->showScrollbar) {
            return $this->paintScrollbar($combined, $total);
        }

        return implode("\n", $combined);
    }

    /**
     * Right-pad a line to `$width` cells, stripping ANSI sequences for length.
     */
    private function padRight(string $line, int $width): string
    {
        $visual = \SugarCraft\Core\Util\Width::string($line);
        if ($visual >= $width) {
            return $line;
        }
        return $line . str_repeat(' ', $width - $visual);
    }

    /**
     * Extend or truncate a line array to exactly `$count` entries.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function padLines(array $lines, int $count): array
    {
        $pad = str_repeat(' ', $this->inner->width);
        while (count($lines) < $count) {
            $lines[] = $pad;
        }
        return array_slice($lines, 0, $count);
    }

    /**
     * Append a single-column scrollbar to each line in the window.
     *
     * @param list<string> $window
     */
    private function paintScrollbar(array $window, int $total): string
    {
        $rendered = count($window);
        $scrollbarChar = $this->inner->scrollbarChar;
        $scrollbarTrack = $this->inner->scrollbarTrack;
        $bodyWidth = max(0, $this->inner->width - 1);

        // Correct thumb proportions relative to window size (not document size).
        // The thumb is a fraction of the window that represents the viewport.
        $thumbHeight = $total > 0 && $rendered > 0
            ? max(1, (int) round($rendered * ($rendered / $total)))
            : max(1, $rendered);
        $maxThumbStart = max(0, $rendered - $thumbHeight);

        $maxOffset = max(0, $total - $this->inner->height);
        // thumbStart maps the current yOffset onto the available thumb travel range.
        $thumbStart = $maxOffset > 0 && $total > $rendered
            ? (int) round($maxThumbStart * ($this->inner->yOffset / $maxOffset))
            : 0;
        $thumbStart = max(0, min($thumbStart, $maxThumbStart));

        $out = [];
        foreach ($window as $i => $line) {
            $padded = $bodyWidth > 0
                ? $this->padRight($line, $bodyWidth)
                : $line;
            $sb = ($i >= $thumbStart && $i < $thumbStart + $thumbHeight)
                ? $scrollbarChar
                : $scrollbarTrack;
            $out[] = $padded . $sb;
        }
        return implode("\n", $out);
    }

    // ---- content + dimensions -----------------------------------------

    public function setContent(string $content): self
    {
        return new self($this->inner->setContent($content), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withSize(int $width, int $height): self
    {
        return new self($this->inner->withSize($width, $height), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function setWidth(int $width): self
    {
        return new self($this->inner->setWidth($width), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function setHeight(int $height): self
    {
        return new self($this->inner->setHeight($height), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function getWidth(): int  { return $this->inner->getWidth(); }
    public function getHeight(): int { return $this->inner->getHeight(); }

    // ---- offset --------------------------------------------------------

    public function setYOffset(int $offset): self
    {
        return new self($this->inner->setYOffset($offset), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function yOffset(): int { return $this->inner->yOffset(); }

    public function setXOffset(int $offset): self
    {
        return new self($this->inner->setXOffset($offset), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function xOffset(): int { return $this->inner->xOffset(); }

    public function withHorizontalStep(int $step): self
    {
        return new self($this->inner->withHorizontalStep($step), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withMouseWheelEnabled(bool $on): self
    {
        return new self($this->inner->withMouseWheelEnabled($on), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withMouseWheelDelta(int $delta): self
    {
        return new self($this->inner->withMouseWheelDelta($delta), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withScrollbar(bool $on = true): self
    {
        return new self($this->inner->withScrollbar($on), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withScrollbarRunes(string $thumb, string $track): self
    {
        return new self($this->inner->withScrollbarRunes($thumb, $track), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withVerticalScrollbar(Scrollbar $scrollbar): self
    {
        return new self($this->inner->withVerticalScrollbar($scrollbar), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withSmoothScroll(bool $enable = true): self
    {
        return new self($this->inner->withSmoothScroll($enable), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    // ---- navigation ---------------------------------------------------

    public function lineUp(int $n = 1): self
    {
        return new self($this->inner->lineUp($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function lineDown(int $n = 1): self
    {
        return new self($this->inner->lineDown($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function halfPageUp(): self   { return new self($this->inner->halfPageUp(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }
    public function halfPageDown(): self { return new self($this->inner->halfPageDown(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }

    public function pageUp(): self   { return new self($this->inner->pageUp(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }
    public function pageDown(): self { return new self($this->inner->pageDown(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }

    public function scrollLeft(int $n = 1): self
    {
        return new self($this->inner->scrollLeft($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function scrollRight(int $n = 1): self
    {
        return new self($this->inner->scrollRight($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function gotoTop(): self    { return new self($this->inner->gotoTop(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }
    public function gotoBottom(): self { return new self($this->inner->gotoBottom(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }

    // ---- queries ------------------------------------------------------

    public function totalLineCount(): int   { return $this->inner->totalLineCount(); }
    public function visibleLineCount(): int { return $this->inner->visibleLineCount(); }
    public function atTop(): bool          { return $this->inner->atTop(); }
    public function atBottom(): bool       { return $this->inner->atBottom(); }
    public function atLeftmost(): bool     { return $this->inner->atLeftmost(); }
    public function atRightmost(): bool     { return $this->inner->atRightmost(); }
    public function scrollPercent(): float          { return $this->inner->scrollPercent(); }
    public function horizontalScrollPercent(): float { return $this->inner->horizontalScrollPercent(); }
}
