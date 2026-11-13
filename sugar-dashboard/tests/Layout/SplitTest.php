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

    /**
     * An intrinsic-size double: reports its own natural box no matter
     * what allocation setSize hands it (what real content widgets do).
     */
    private static function naturalPane(int $w, int $h, string $fill): \SugarCraft\Dashboard\Foundation\SizedItem
    {
        return new class ($w, $h, $fill) implements \SugarCraft\Dashboard\Foundation\SizedItem {
            public function __construct(
                private readonly int $nw,
                private readonly int $nh,
                private readonly string $fill,
            ) {}

            public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
            {
                return $this;
            }

            public function innerSize(): array
            {
                return [$this->nw, $this->nh];
            }

            public function render(): string
            {
                return implode("\n", array_fill(0, $this->nh, str_repeat($this->fill, $this->nw)));
            }
        };
    }

    public function testHorizontalInnerSizeSumsWidthsPlusDivider(): void
    {
        $split = Split::horizontal([
            self::naturalPane(40, 5, 'A'),
            self::naturalPane(40, 3, 'B'),
        ]);

        $this->assertSame([81, 5], $split->innerSize());
    }

    public function testVerticalInnerSizeSumsHeightsPlusDivider(): void
    {
        $split = Split::vertical([
            self::naturalPane(60, 3, 'A'),
            self::naturalPane(40, 7, 'B'),
        ]);

        $this->assertSame([60, 11], $split->innerSize());
    }

    public function testHiddenDividersLeaveTheGutterOutOfTheBox(): void
    {
        $split = Split::horizontal([
            self::naturalPane(40, 5, 'A'),
            self::naturalPane(40, 5, 'B'),
        ])->withShowDividers(false);

        $this->assertSame([80, 5], $split->innerSize());
    }

    public function testRenderedFrameFitsTheBoxItReports(): void
    {
        // The contract a Layout parent relies on: allocate a Split its own
        // reported innerSize and nothing spills past the promised box.
        // Over-allocation legitimately stretches panes (that is what a
        // container does); UNDER-allocation is exactly the clipping bug.
        foreach ([$this->horizontalFitSplit(), $this->verticalFitSplit()] as [$split, $label]) {
            [$w, $h] = $split->innerSize();
            $split = $split->setSize($w, $h);
            $lines = explode("\n", $split->render());
            $this->assertLessThanOrEqual($h, count($lines), "$label: more rendered rows than promised");
            foreach ($lines as $line) {
                $this->assertLessThanOrEqual(
                    $w,
                    \SugarCraft\Core\Util\Width::string($line),
                    "$label: line wider than promised box"
                );
            }
        }
    }

    private function horizontalFitSplit(): array
    {
        $split = Split::horizontal([
            self::naturalPane(20, 4, 'A'),
            self::naturalPane(30, 6, 'B'),
        ]);

        return [$split, 'horizontal'];
    }

    private function verticalFitSplit(): array
    {
        $split = Split::vertical([
            self::naturalPane(20, 4, 'A'),
            self::naturalPane(30, 6, 'B'),
        ]);

        return [$split, 'vertical'];
    }
}
