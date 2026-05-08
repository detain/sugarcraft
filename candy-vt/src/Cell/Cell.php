<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Cell;

use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Hyperlink\Hyperlink;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * A single cell in the terminal grid.
 *
 * Readonly snapshot — instances are always newly constructed by Buffer.
 * Mirrors charmbracelet/x/vt Cell.
 */
final readonly class Cell
{
    public function __construct(
        public string $grapheme = ' ',
        public ?Sgr $sgr = null,
        public bool $continuation = false,
        public ?Hyperlink $hyperlink = null,
    ) {}

    public static function empty(): self
    {
        static $empty = null;
        return $empty ??= new self();
    }

    /** Second cell of a wide character — carries no grapheme, marks continuation. */
    public static function continuation(self $prev): self
    {
        return new self(
            grapheme: '',
            sgr: $prev->sgr,
            continuation: true,
            hyperlink: $prev->hyperlink,
        );
    }

    public function sgr(): Sgr
    {
        return $this->sgr ?? Sgr::empty();
    }

    public function foreground(): ?Color
    {
        return $this->sgr()?->foreground;
    }

    public function background(): ?Color
    {
        return $this->sgr()?->background;
    }

    public function equals(self $other): bool
    {
        if ($this->grapheme !== $other->grapheme) return false;
        if ($this->continuation !== $other->continuation) return false;
        if ((string)($this->hyperlink?->id ?? '') !== (string)($other->hyperlink?->id ?? '')) return false;

        $thisFg = $this->foreground();
        $otherFg = $other->foreground();
        if ($thisFg === null && $otherFg === null) {
            $fgEqual = true;
        } elseif ($thisFg === null || $otherFg === null) {
            $fgEqual = false;
        } else {
            $fgEqual = $thisFg->equals($otherFg);
        }

        $thisBg = $this->background();
        $otherBg = $other->background();
        if ($thisBg === null && $otherBg === null) {
            $bgEqual = true;
        } elseif ($thisBg === null || $otherBg === null) {
            $bgEqual = false;
        } else {
            $bgEqual = $thisBg->equals($otherBg);
        }

        $thisSgr = $this->sgr();
        $otherSgr = $other->sgr();
        $sgrEqual = $thisSgr->bold === $otherSgr->bold
            && $thisSgr->italic === $otherSgr->italic
            && $thisSgr->underline === $otherSgr->underline
            && $thisSgr->strikethrough === $otherSgr->strikethrough
            && $thisSgr->blink === $otherSgr->blink
            && $thisSgr->reverse === $otherSgr->reverse
            && $thisSgr->dim === $otherSgr->dim
            && $thisSgr->hidden === $otherSgr->hidden
            && $thisSgr->invisible === $otherSgr->invisible;

        return $fgEqual && $bgEqual && $sgrEqual;
    }
}
