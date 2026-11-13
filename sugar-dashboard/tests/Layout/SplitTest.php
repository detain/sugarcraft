<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Layout\Split;
use SugarCraft\Dashboard\Layout\SplitDirection;
use SugarCraft\Dashboard\Layout\Frame;
use SugarCraft\Dashboard\Components\Card\Text;

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

        [$width, $height] = $split->innerSize();
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

    // ═══════════════════════════════════════════════════════════════
    // dividerColor is consulted (finding sugar-dashboard-023)
    // ═══════════════════════════════════════════════════════════════

    public function testDividerColorPaintsTheRenderedDivider(): void
    {
        $split = Split::horizontal([new Text('a'), new Text('b')])
            ->withDividerColor(\SugarCraft\Core\Util\Color::hex('#ff0000'));
        $rendered = $split->render();

        $fg = \SugarCraft\Core\Util\Color::hex('#ff0000')->toFg(\SugarCraft\Dashboard\Foundation\ColorMode::profile());
        $this->assertStringContainsString($fg . '│' . \SugarCraft\Core\Util\Ansi::reset(), $rendered);
    }

    public function testNullDividerColorEmitsPlainDividers(): void
    {
        $split = Split::horizontal([new Text('a'), new Text('b')])
            ->withDividerColor(null);
        $rendered = $split->render();

        $this->assertStringContainsString('│', $rendered);
        $this->assertStringNotContainsString("\x1b[", $rendered);
    }
}
