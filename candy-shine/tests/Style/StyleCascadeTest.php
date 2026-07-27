<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests\Style;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Style\StyleCascade;
use SugarCraft\Sprinkles\Style;

/**
 * @covers \SugarCraft\Shine\Style\StyleCascade
 */
final class StyleCascadeTest extends TestCase
{
    public function testMergeChildOverridesParentBold(): void
    {
        $parent = Style::new()->bold();
        $child  = Style::new()->italic();

        $merged = StyleCascade::merge($parent, $child);

        // Child did not explicitly set bold → parent bold inherited.
        $this->assertTrue($merged->isBold());
        // Child explicitly set italic → child italic wins.
        $this->assertTrue($merged->isItalic());
    }

    public function testMergeChildOverridesParentWhenExplicitlySet(): void
    {
        $parent = Style::new()->foreground(\SugarCraft\Core\Util\Color::hex('#ff0000'));
        $child  = Style::new()->foreground(\SugarCraft\Core\Util\Color::hex('#0000ff'));

        $merged = StyleCascade::merge($parent, $child);

        // Child explicitly set fg → child wins.
        $this->assertStringContainsString('38;2;0;0;255', $merged->render('x'));
    }

    public function testMergeParentAttributesInheritedWhenChildUnset(): void
    {
        $parent = Style::new()->bold()->italic();
        $child  = Style::new(); // nothing explicitly set

        $merged = StyleCascade::merge($parent, $child);

        $this->assertTrue($merged->isBold());
        $this->assertTrue($merged->isItalic());
    }

    public function testMergePreservesChildExplicitOverridesOnly(): void
    {
        $parent = Style::new()->bold()->italic()->underline();
        $child  = Style::new()->bold(); // only bold explicitly set

        $merged = StyleCascade::merge($parent, $child);

        // Child explicitly set bold → child value wins.
        $this->assertTrue($merged->isBold());
        // Child did NOT set italic → parent italic is inherited.
        $this->assertTrue($merged->isItalic());
        // Child did NOT set underline → parent underline is inherited.
        $this->assertTrue($merged->isUnderline());
    }

    public function testMergeReturnsNewInstance(): void
    {
        $parent = Style::new()->bold();
        $child  = Style::new()->italic();

        $merged = StyleCascade::merge($parent, $child);

        $this->assertNotSame($child, $merged);
    }
}
