<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

/**
 * Where an overlay lands on its canvas: a vertical and a horizontal anchor
 * plus a cell offset.
 *
 * The one placement value every sugar-overlay door shares. {@see Veil::place()}
 * consumes it, {@see Veil::composite()} and {@see Veil::centered()} build one
 * from their positional arguments, a veil carries one for stack-driven
 * compositing ({@see Veil::withPlacement()}, {@see Veil::placement()}), and
 * {@see VeilStack} and {@see ModalStack} hand one to each layer. The anchor
 * arithmetic itself lives in exactly one place (Veil's placement resolver),
 * so no door can drift from another.
 *
 * Anchors follow {@see Position}'s two-axis rule: the vertical anchor reads
 * only a case's row component, the horizontal anchor only its column
 * component. The offset is in cells: x rightward (+) / leftward (-), y
 * downward (+) / upward (-).
 *
 * Immutable: every with*() returns a new instance through mutate().
 */
final class Placement
{
    private function __construct(
        public readonly Position $vertical,
        public readonly Position $horizontal,
        public readonly int $x,
        public readonly int $y,
    ) {
    }

    /** Anchor pair plus offset; CENTER/CENTER with no offset by default. */
    public static function new(
        Position $vertical = Position::CENTER,
        Position $horizontal = Position::CENTER,
        int $x = 0,
        int $y = 0,
    ): self {
        return new self($vertical, $horizontal, $x, $y);
    }

    /** CENTER/CENTER, the modal anchor, with an optional nudge. */
    public static function centered(int $x = 0, int $y = 0): self
    {
        return new self(Position::CENTER, Position::CENTER, $x, $y);
    }

    /** Same offset, new anchors. */
    public function withAnchors(Position $vertical, Position $horizontal): self
    {
        return $this->mutate(vertical: $vertical, horizontal: $horizontal);
    }

    /** Same anchors, the offset replaced. */
    public function withOffset(int $x, int $y): self
    {
        return $this->mutate(x: $x, y: $y);
    }

    /** Same anchors, the offset moved by ($dx, $dy). */
    public function offsetBy(int $dx, int $dy): self
    {
        return ($dx === 0 && $dy === 0)
            ? $this
            : $this->mutate(x: $this->x + $dx, y: $this->y + $dy);
    }

    private function mutate(?Position $vertical = null, ?Position $horizontal = null, ?int $x = null, ?int $y = null): self
    {
        return new self($vertical ?? $this->vertical, $horizontal ?? $this->horizontal, $x ?? $this->x, $y ?? $this->y);
    }
}
