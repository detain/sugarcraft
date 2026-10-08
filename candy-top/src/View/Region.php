<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * A rectangle of a {@see Surface} with its own origin: (0, 0) is the
 * rectangle's top-left cell and every write is clipped to it. This is what
 * a {@see \SugarCraft\Top\Panel\Panel} paints into — it can reach its own
 * border rows (btop embeds buttons and readouts there) but can never spill
 * into a neighbouring box, whatever width its content computes.
 */
final class Region
{
    public function __construct(
        private readonly Surface $surface,
        public readonly Rect $rect,
    ) {
    }

    public function width(): int
    {
        return $this->rect->width;
    }

    public function height(): int
    {
        return $this->rect->height;
    }

    /** Plain text at local ($x, $y); returns columns advanced. */
    public function put(int $x, int $y, string $text, string $sgr = ''): int
    {
        return $this->surface->put($this->rect->x + $x, $this->rect->y + $y, $text, $sgr, $this->rect);
    }

    /** SGR-carrying text at local ($x, $y); returns columns advanced. */
    public function ansi(int $x, int $y, string $line, string $sgr = ''): int
    {
        return $this->surface->ansi($this->rect->x + $x, $this->rect->y + $y, $line, $this->rect, $sgr);
    }

    /** Fill a local rectangle with spaces in `$sgr`. */
    public function fill(Rect $local, string $sgr = ''): void
    {
        $this->surface->fill($local->translate($this->rect->x, $this->rect->y)->intersect($this->rect), $sgr);
    }

    /** A nested region from local coordinates, clipped to this one. */
    public function sub(Rect $local): self
    {
        return new self($this->surface, $local->translate($this->rect->x, $this->rect->y)->intersect($this->rect));
    }

    /** A nested region from absolute screen coordinates (e.g. a {@see Layout} sub-box), clipped to this one. */
    public function subAbsolute(Rect $absolute): self
    {
        return new self($this->surface, $absolute->intersect($this->rect));
    }
}
