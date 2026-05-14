<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Card\Kbd;

final class KbdTest extends TestCase
{
    public function testNewCreatesKbd(): void
    {
        $kbd = Kbd::new(['Ctrl', 'C']);
        $this->assertNotNull($kbd);
    }

    public function testSingleCreatesSingleKey(): void
    {
        $kbd = Kbd::single('Enter');
        $this->assertNotNull($kbd);
    }

    public function testComboCreatesKeyCombo(): void
    {
        $kbd = Kbd::combo('Ctrl', 'Shift', 'Esc');
        $this->assertNotNull($kbd);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $kbd = Kbd::single('Enter');
        $rendered = $kbd->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $kbd = Kbd::single('Enter');
        [$width, $height] = $kbd->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithBgColorReturnsNewInstance(): void
    {
        $kbd = Kbd::single('A');
        $newKbd = $kbd->withBgColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertNotSame($kbd, $newKbd);
    }

    public function testWithTextColorReturnsNewInstance(): void
    {
        $kbd = Kbd::single('A');
        $newKbd = $kbd->withTextColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertNotSame($kbd, $newKbd);
    }

    public function testWithShowShadowReturnsNewInstance(): void
    {
        $kbd = Kbd::single('A');
        $newKbd = $kbd->withShowShadow(false);
        $this->assertNotSame($kbd, $newKbd);
    }

    public function testWithRoundedReturnsNewInstance(): void
    {
        $kbd = Kbd::single('A');
        $newKbd = $kbd->withRounded(false);
        $this->assertNotSame($kbd, $newKbd);
    }
}
