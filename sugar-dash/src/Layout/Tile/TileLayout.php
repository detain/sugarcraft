<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;

/**
 * A tile-based layout that arranges tiles horizontally or vertically.
 *
 * Based on the bubbletea tilelayout pattern.
 */
final class TileLayout implements Item, Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<Tile> $tiles
     */
    public function __construct(
        private readonly string $name = 'Root',
        private readonly Direction $direction = Direction::Horizontal,
        private readonly array $tiles = [],
        private readonly Size $baseSize = new Size(),
        private readonly int $gap = 0,
    ) {}

    public static function horizontal(string $name = 'Root'): self
    {
        return new self($name, Direction::Horizontal);
    }

    public static function vertical(string $name = 'Root'): self
    {
        return new self($name, Direction::Vertical);
    }

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    public function render(): string
    {
        if ($this->tiles === []) {
            return '';
        }

        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 || $h <= 0) {
            return '';
        }

        // Build constraints and hinters from tiles
        $constraints = [];
        $hinters = [];

        foreach ($this->tiles as $tile) {
            $size = $tile->getSize();
            $content = $tile->getContent();
            $hinters[] = $content instanceof SizeHinter ? $content : null;

            if ($this->direction === Direction::Horizontal) {
                // Horizontal: primary axis is width
                if ($size->fixedWidth !== null) {
                    $constraints[] = Constraint::fixed($size->fixedWidth);
                } elseif ($size->weight > 0) {
                    $c = Constraint::flex($size->weight);
                    if ($size->minWidth !== null) {
                        $c = $c->withMinSize($size->minWidth);
                    }
                    if ($size->maxWidth !== null) {
                        $c = $c->withMaxSize($size->maxWidth);
                    }
                    if ($size->optional) {
                        $c = $c->withOptional(true);
                    }
                    if ($size->minSizeFit) {
                        $c = $c->withMinSizeFit(true);
                    }
                    $constraints[] = $c;
                } else {
                    // Fit constraint - will query hinter for desired size
                    $c = Constraint::fit();
                    if ($size->minWidth !== null) {
                        $c = $c->withMinSize($size->minWidth);
                    }
                    if ($size->maxWidth !== null) {
                        $c = $c->withMaxSize($size->maxWidth);
                    }
                    if ($size->optional) {
                        $c = $c->withOptional(true);
                    }
                    if ($size->minSizeFit) {
                        $c = $c->withMinSizeFit(true);
                    }
                    $constraints[] = $c;
                }
            } else {
                // Vertical: primary axis is height
                if ($size->fixedHeight !== null) {
                    $constraints[] = Constraint::fixed($size->fixedHeight);
                } elseif ($size->weight > 0) {
                    $c = Constraint::flex($size->weight);
                    if ($size->minHeight !== null) {
                        $c = $c->withMinSize($size->minHeight);
                    }
                    if ($size->maxHeight !== null) {
                        $c = $c->withMaxSize($size->maxHeight);
                    }
                    if ($size->optional) {
                        $c = $c->withOptional(true);
                    }
                    if ($size->minSizeFit) {
                        $c = $c->withMinSizeFit(true);
                    }
                    $constraints[] = $c;
                } else {
                    $c = Constraint::fit();
                    if ($size->minHeight !== null) {
                        $c = $c->withMinSize($size->minHeight);
                    }
                    if ($size->maxHeight !== null) {
                        $c = $c->withMaxSize($size->maxHeight);
                    }
                    if ($size->optional) {
                        $c = $c->withOptional(true);
                    }
                    if ($size->minSizeFit) {
                        $c = $c->withMinSizeFit(true);
                    }
                    $constraints[] = $c;
                }
            }
        }

        // Resolve sizes along the primary axis
        $horizontal = $this->direction === Direction::Horizontal;
        $sizes = Resolver::resolveLinear(
            $horizontal ? $w : $h,
            $constraints,
            $this->gap,
            $hinters,
            $horizontal,
        );

        // Apply resolved sizes to tiles and collect rendered views
        $views = [];

        foreach ($this->tiles as $index => $tile) {
            if ($horizontal) {
                $tileW = $sizes[$index] ?? 0;
                $tileH = $tile->getSize()->fixedHeight ?? $h;
            } else {
                $tileW = $tile->getSize()->fixedWidth ?? $w;
                $tileH = $sizes[$index] ?? 0;
            }

            $tile->setSize($tileW, $tileH);
            $views[] = $tile->render();
        }

        if ($this->direction === Direction::Horizontal) {
            return implode('', $views);
        }

        return implode("\n", $views);
    }

    public function getInnerSize(): array
    {
        return [$this->width ?? 0, $this->height ?? 0];
    }

    /**
     * @param list<Tile> $tiles
     */
    public function withTiles(array $tiles): self
    {
        return new self(
            name: $this->name,
            direction: $this->direction,
            tiles: $tiles,
            baseSize: $this->baseSize,
            gap: $this->gap,
        );
    }

    public function withDirection(Direction $direction): self
    {
        return new self(
            name: $this->name,
            direction: $direction,
            tiles: $this->tiles,
            baseSize: $this->baseSize,
            gap: $this->gap,
        );
    }

    public function withTile(Tile $tile): self
    {
        return new self(
            name: $this->name,
            direction: $this->direction,
            tiles: [...$this->tiles, $tile],
            baseSize: $this->baseSize,
            gap: $this->gap,
        );
    }

    public function withGap(int $gap): self
    {
        return new self(
            name: $this->name,
            direction: $this->direction,
            tiles: $this->tiles,
            baseSize: $this->baseSize,
            gap: $gap,
        );
    }
}
