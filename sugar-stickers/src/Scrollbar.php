<?php

declare(strict_types=1);

namespace SugarCraft\Stickers;

use SugarCraft\Bits\Scrollbar\Scrollbar as BitsScrollbar;
use SugarCraft\Bits\Scrollbar\ScrollbarState;

/**
 * Sticker-level scrollbar wrapping the canonical {@see BitsScrollbar}.
 *
 * Composes sugar-bits Scrollbar rather than reimplementing it.
 *
 * @see \SugarCraft\Bits\Scrollbar\Scrollbar
 */
final readonly class Scrollbar
{
    private BitsScrollbar $inner;

    private function __construct(BitsScrollbar $inner)
    {
        $this->inner = $inner;
    }

    /** Vertical scrollbar with defaults (░ track, █ thumb, ▲▼ arrows). */
    public static function vertical(): self
    {
        return new self(BitsScrollbar::vertical());
    }

    /** Horizontal scrollbar with defaults (░ track, █ thumb, no arrows). */
    public static function horizontal(): self
    {
        return new self(BitsScrollbar::horizontal());
    }

    public function withTrackChar(string $char): self
    {
        return new self($this->inner->withTrackChar($char));
    }

    public function withThumbChar(string $char): self
    {
        return new self($this->inner->withThumbChar($char));
    }

    public function withArrows(bool $show): self
    {
        return new self($this->inner->withArrows($show));
    }

    /**
     * Render the scrollbar into `$height` rows.
     *
     * @param ScrollbarState|array{total:int,position:int,viewport:int} $state
     */
    public function view(ScrollbarState|array $state, int $height): string
    {
        if (is_array($state)) {
            $state = new ScrollbarState($state['total'], $state['position'], $state['viewport']);
        }
        return $this->inner->view($state, $height);
    }
}
