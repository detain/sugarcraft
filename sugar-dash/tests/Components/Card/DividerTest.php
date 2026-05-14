<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Card\Divider;

final class DividerTest extends TestCase
{
    public function testNewCreatesDivider(): void
    {
        $divider = Divider::new();
        $this->assertNotNull($divider);
    }

    public function testHCreatesHorizontalDivider(): void
    {
        $divider = Divider::h();
        $this->assertNotSame('', $divider->render());
    }

    public function testVCreatesVerticalDivider(): void
    {
        $divider = Divider::v();
        $this->assertNotSame('', $divider->render());
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $divider = Divider::new();
        $this->assertNotSame('', $divider->render());
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $divider = Divider::new();
        [$width, $height] = $divider->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithLabelReturnsNewInstance(): void
    {
        $divider = Divider::new();
        $newDivider = $divider->withLabel('Section');
        $this->assertNotSame($divider, $newDivider);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $divider = Divider::new();
        $newDivider = $divider->withStyle(Divider::StyleDashed);
        $this->assertNotSame($divider, $newDivider);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $divider = Divider::new();
        $newDivider = $divider->withColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertNotSame($divider, $newDivider);
    }

    public function testWithHorizontalReturnsNewInstance(): void
    {
        $divider = Divider::new();
        $newDivider = $divider->withHorizontal(false);
        $this->assertNotSame($divider, $newDivider);
    }

    public function testWithThicknessReturnsNewInstance(): void
    {
        $divider = Divider::new();
        $newDivider = $divider->withThickness(2);
        $this->assertNotSame($divider, $newDivider);
    }

    public function testLabeledDividerRendersWithLabel(): void
    {
        $divider = Divider::new('Section Title');
        $rendered = $divider->render();
        $this->assertStringContainsString('Section Title', $rendered);
    }
}
