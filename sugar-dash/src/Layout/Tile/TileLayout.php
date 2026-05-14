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

        $views = [];
        $totalFixedWidth = 0;
        $totalFixedHeight = 0;

        foreach ($this->tiles as $tile) {
            if ($tile->getSize()->fixedWidth !== null) {
                $totalFixedWidth += $tile->getSize()->fixedWidth;
            }
            if ($tile->getSize()->fixedHeight !== null) {
                $totalFixedHeight += $tile->getSize()->fixedHeight;
            }
        }

        $currentX = 0;
        $currentY = 0;

        foreach ($this->tiles as $tile) {
            [$tileW, $tileH] = Resolver::resolveTile(
                $tile,
                $this->direction,
                $w,
                $h,
                $totalFixedWidth,
                $totalFixedHeight,
            );

            $tile->setSize($tileW, $tileH);
            $views[] = $tile->render();

            if ($this->direction === Direction::Horizontal) {
                $currentX += $tileW;
            } else {
                $currentY += $tileH;
            }
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
        );
    }

    public function withDirection(Direction $direction): self
    {
        return new self(
            name: $this->name,
            direction: $direction,
            tiles: $this->tiles,
            baseSize: $this->baseSize,
        );
    }

    public function withTile(Tile $tile): self
    {
        return new self(
            name: $this->name,
            direction: $this->direction,
            tiles: [...$this->tiles, $tile],
            baseSize: $this->baseSize,
        );
    }
}
