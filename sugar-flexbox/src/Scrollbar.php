<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox;

use SugarCraft\Widgets\Scrollbar\Scrollbar as WidgetsScrollbar;
use SugarCraft\Widgets\Scrollbar\ScrollbarState;

/**
 * Immutable scrollbar wrapper over the canonical {@see WidgetsScrollbar}.
 *
 * Composes the sugar-forms Scrollbar rather than reimplementing it: the
 * `SugarCraft\Widgets\Scrollbar\Scrollbar` symbol this file imports is a
 * deprecated class_alias façade onto `SugarCraft\Forms\Scrollbar\Scrollbar`.
 *
 * @see \SugarCraft\Forms\Scrollbar\Scrollbar
 */
final readonly class Scrollbar
{
    private WidgetsScrollbar $inner;

    private function __construct(WidgetsScrollbar $inner)
    {
        $this->inner = $inner;
    }

    /** Vertical scrollbar with defaults (░ track, █ thumb, ▲▼ arrows). */
    public static function vertical(): self
    {
        return new self(WidgetsScrollbar::vertical());
    }

    /** Horizontal scrollbar with defaults (░ track, █ thumb, no arrows). */
    public static function horizontal(): self
    {
        return new self(WidgetsScrollbar::horizontal());
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
     * The canonical forms scrollbar (reached through the widgets alias) this
     * wrapper delegates to. Needed so {@see Viewport::withVerticalScrollbar()}
     * (which hands the forms viewport the same canonical class) can accept
     * THIS lib's Scrollbar and unwrap it — the wrapper is opaque otherwise.
     */
    public function widgetScrollbar(): WidgetsScrollbar
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
