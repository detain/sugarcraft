<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Forms\Scrollbar\Scrollbar as FormsScrollbar;
use SugarCraft\Forms\Scrollbar\ScrollbarState;

/**
 * Immutable scrollbar wrapper over the canonical {@see FormsScrollbar}.
 *
 * Composes the sugar-forms Scrollbar directly (FBX-20): the lib once
 * imported the deprecated `SugarCraft\Widgets\…` class_alias façade;
 * the require and these imports now name the canonical classes, so the
 * wrapper hands the forms viewport the same class it wraps.
 *
 * @see \SugarCraft\Forms\Scrollbar\Scrollbar
 */
final readonly class Scrollbar
{
    use Mutable;

    private FormsScrollbar $inner;

    private function __construct(FormsScrollbar $inner)
    {
        $this->inner = $inner;
    }

    /** Vertical scrollbar with defaults (░ track, █ thumb, ▲▼ arrows). */
    public static function vertical(): self
    {
        return new self(FormsScrollbar::vertical());
    }

    /** Horizontal scrollbar with defaults (░ track, █ thumb, no arrows). */
    public static function horizontal(): self
    {
        return new self(FormsScrollbar::horizontal());
    }

    public function withTrackChar(string $char): self
    {
        return $this->mutate(['inner' => $this->inner->withTrackChar($char)]);
    }

    public function withThumbChar(string $char): self
    {
        return $this->mutate(['inner' => $this->inner->withThumbChar($char)]);
    }

    public function withArrows(bool $show): self
    {
        return $this->mutate(['inner' => $this->inner->withArrows($show)]);
    }

    /**
     * The canonical forms scrollbar this wrapper delegates to (FBX-20:
     * named directly, no alias hop). Needed so
     * {@see Viewport::withVerticalScrollbar()} can accept THIS lib's
     * Scrollbar and unwrap it — the wrapper is opaque to the forms
     * delegate otherwise. The method name keeps its historical spelling:
     * renaming a public accessor is vocabulary-owner territory.
     */
    public function widgetScrollbar(): FormsScrollbar
    {
        return $this->inner;
    }

    /**
     * Render the scrollbar into `$height` rows.
     *
     * @param ScrollbarState|array{total:int,position:int,viewport:int} $state
     *
     * @throws \InvalidArgumentException when an array state is missing one
     *     of the required keys (fail fast here, not as a PHP warning plus a
     *     confusing constructor TypeError deep inside the delegate).
     */
    public function view(ScrollbarState|array $state, int $height): string
    {
        if (is_array($state)) {
            foreach (['total', 'position', 'viewport'] as $key) {
                if (!array_key_exists($key, $state)) {
                    throw new \InvalidArgumentException(
                        "Scrollbar state array requires a '{$key}' key.",
                    );
                }
            }
            $state = new ScrollbarState($state['total'], $state['position'], $state['viewport']);
        }
        return $this->inner->view($state, $height);
    }
}
