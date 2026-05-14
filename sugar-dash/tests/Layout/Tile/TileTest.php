<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Tile;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Tile\Size;
use SugarCraft\Dash\Layout\Tile\Tile;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;

final class SimpleItem implements Item
{
    public function __construct(private readonly string $content = '') {}

    public function render(): string
    {
        return $this->content;
    }
}

final class SimpleSizer implements Item, Sizer
{
    private int $w = 0;
    private int $h = 0;

    public function __construct(private readonly string $content = '') {}

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->w = $width;
        $clone->h = $height;
        return $clone;
    }

    public function render(): string
    {
        return "{$this->w}x{$this->h}:{$this->content}";
    }
}

final class TileTest extends TestCase
{
    public function testTileDefaultIsNotOptional(): void
    {
        $tile = new Tile();
        $this->assertFalse($tile->isOptional());
    }

    public function testTileWithSizeCanBeOptional(): void
    {
        $size = new Size(optional: true);
        $tile = new Tile('test', $size);
        $this->assertTrue($tile->isOptional());
    }

    public function testTileIsMinSizeFit(): void
    {
        $size = new Size(minSizeFit: true);
        $tile = new Tile('test', $size);
        $this->assertTrue($tile->isMinSizeFit());
    }

    public function testTileDefaultIsNotMinSizeFit(): void
    {
        $tile = new Tile();
        $this->assertFalse($tile->isMinSizeFit());
    }

    public function testTileRenderWithContent(): void
    {
        $content = new SimpleItem('hello');
        $tile = new Tile('test', new Size(), $content);
        $tile->setSize(10, 5);
        $this->assertSame('hello', $tile->render());
    }

    public function testTileRenderWithSizerContent(): void
    {
        $content = new SimpleSizer('world');
        $tile = new Tile('test', new Size(), $content);
        $tile = $tile->setSize(10, 5);
        $this->assertSame('10x5:world', $tile->render());
    }

    public function testTileEmptyContentReturnsEmpty(): void
    {
        $tile = new Tile('test', new Size(), null);
        $tile = $tile->setSize(10, 5);
        $this->assertSame('', $tile->render());
    }

    public function testTileWithNoSizeFallsBackToContentRender(): void
    {
        $content = new SimpleItem('hello');
        $tile = new Tile('test', new Size(), $content);
        // When width/height are null (0), render() falls back to content->render()
        $this->assertSame('hello', $tile->render());
    }

    public function testTileGetName(): void
    {
        $tile = new Tile('my-tile');
        $this->assertSame('my-tile', $tile->getName());
    }

    public function testTileGetSize(): void
    {
        $size = new Size(width: 100, height: 50);
        $tile = new Tile('test', $size);
        $this->assertSame($size, $tile->getSize());
    }

    public function testTileParentIsNullByDefault(): void
    {
        $tile = new Tile();
        $this->assertNull($tile->getParent());
    }
}
