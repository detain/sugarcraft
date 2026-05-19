<?php

declare(strict_types=1);

namespace SugarCraft\Stickers;

use SugarCraft\Bits\Scrollbar\Scrollbar;
use SugarCraft\Bits\Scrollbar\ScrollbarState;
use SugarCraft\Bits\Viewport\Viewport as BitsViewport;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;

/**
 * Sticker-level viewport wrapping the canonical {@see BitsViewport}.
 *
 * Composes sugar-bits Viewport rather than reimplementing it. Sticky
 * header/footer positioning and scroll-sync are deferred to step 10.12.
 *
 * @see \SugarCraft\Bits\Viewport\Viewport
 */
final readonly class Viewport
{
    private BitsViewport $inner;

    /**
     * @param list<string> $lines
     */
    private function __construct(BitsViewport $inner)
    {
        $this->inner = $inner;
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
        return [new self($inner), $cmd];
    }

    public function view(): string
    {
        return $this->inner->view();
    }

    // ---- content + dimensions -----------------------------------------

    public function setContent(string $content): self
    {
        return new self($this->inner->setContent($content));
    }

    public function withSize(int $width, int $height): self
    {
        return new self($this->inner->withSize($width, $height));
    }

    public function setWidth(int $width): self
    {
        return new self($this->inner->setWidth($width));
    }

    public function setHeight(int $height): self
    {
        return new self($this->inner->setHeight($height));
    }

    public function getWidth(): int  { return $this->inner->getWidth(); }
    public function getHeight(): int { return $this->inner->getHeight(); }

    // ---- offset --------------------------------------------------------

    public function setYOffset(int $offset): self
    {
        return new self($this->inner->setYOffset($offset));
    }

    public function yOffset(): int { return $this->inner->yOffset(); }

    public function setXOffset(int $offset): self
    {
        return new self($this->inner->setXOffset($offset));
    }

    public function xOffset(): int { return $this->inner->xOffset(); }

    public function withHorizontalStep(int $step): self
    {
        return new self($this->inner->withHorizontalStep($step));
    }

    public function withMouseWheelEnabled(bool $on): self
    {
        return new self($this->inner->withMouseWheelEnabled($on));
    }

    public function withMouseWheelDelta(int $delta): self
    {
        return new self($this->inner->withMouseWheelDelta($delta));
    }

    public function withScrollbar(bool $on = true): self
    {
        return new self($this->inner->withScrollbar($on));
    }

    public function withScrollbarRunes(string $thumb, string $track): self
    {
        return new self($this->inner->withScrollbarRunes($thumb, $track));
    }

    public function withVerticalScrollbar(Scrollbar $scrollbar): self
    {
        return new self($this->inner->withVerticalScrollbar($scrollbar));
    }

    public function withSmoothScroll(bool $enable = true): self
    {
        return new self($this->inner->withSmoothScroll($enable));
    }

    // ---- navigation ---------------------------------------------------

    public function lineUp(int $n = 1): self
    {
        return new self($this->inner->lineUp($n));
    }

    public function lineDown(int $n = 1): self
    {
        return new self($this->inner->lineDown($n));
    }

    public function halfPageUp(): self   { return new self($this->inner->halfPageUp()); }
    public function halfPageDown(): self { return new self($this->inner->halfPageDown()); }

    public function pageUp(): self   { return new self($this->inner->pageUp()); }
    public function pageDown(): self { return new self($this->inner->pageDown()); }

    public function scrollLeft(int $n = 0): self
    {
        return new self($this->inner->scrollLeft($n));
    }

    public function scrollRight(int $n = 0): self
    {
        return new self($this->inner->scrollRight($n));
    }

    public function gotoTop(): self    { return new self($this->inner->gotoTop()); }
    public function gotoBottom(): self { return new self($this->inner->gotoBottom()); }

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
