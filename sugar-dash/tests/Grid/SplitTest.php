<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Split;
use SugarCraft\Dash\Grid\SplitDirection;
use SugarCraft\Dash\Grid\Frame;
use SugarCraft\Dash\Grid\Text;

final class SplitTest extends TestCase
{
    public function testNewCreatesHorizontalSplit(): void
    {
        $split = Split::horizontal([
            Frame::new(Text::new('Left')),
            Frame::new(Text::new('Right')),
        ]);

        $this->assertNotNull($split);
    }

    public function testVerticalCreatesVerticalSplit(): void
    {
        $split = Split::vertical([
            Frame::new(Text::new('Top')),
            Frame::new(Text::new('Bottom')),
        ]);

        $this->assertNotNull($split);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $split = Split::horizontal([
            Frame::new(Text::new('Left')),
            Frame::new(Text::new('Right')),
        ]);

        $rendered = $split->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $split = Split::horizontal([
            Frame::new(Text::new('Content')),
        ]);
        $split = $split->setSize(80, 24);

        [$width, $height] = $split->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithDirectionReturnsNewInstance(): void
    {
        $split = Split::horizontal([
            Frame::new(Text::new('Content')),
        ]);

        $newSplit = $split->withDirection(SplitDirection::Vertical);
        $this->assertNotSame($split, $newSplit);
    }

    public function testWithRatiosReturnsNewInstance(): void
    {
        $split = Split::horizontal([
            Frame::new(Text::new('Left')),
            Frame::new(Text::new('Right')),
        ]);

        $newSplit = $split->withRatios([0.3, 0.7]);
        $this->assertNotSame($split, $newSplit);
    }

    public function testWithShowDividersReturnsNewInstance(): void
    {
        $split = Split::horizontal([
            Frame::new(Text::new('Left')),
            Frame::new(Text::new('Right')),
        ]);

        $newSplit = $split->withShowDividers(false);
        $this->assertNotSame($split, $newSplit);
    }

    public function testEmptySplitReturnsEmpty(): void
    {
        $split = Split::horizontal([]);
        $this->assertSame('', $split->render());
    }
}
