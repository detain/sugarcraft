<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Jumbotron;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class JumbotronTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testJumbotronImplementsSizer(): void
    {
        $jumbotron = Jumbotron::new('Welcome');
        $this->assertInstanceOf(Sizer::class, $jumbotron);
    }

    public function testJumbotronImplementsItem(): void
    {
        $jumbotron = Jumbotron::new('Welcome');
        $this->assertInstanceOf(Item::class, $jumbotron);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $jumbotron = Jumbotron::new('Welcome');
        $rendered = $jumbotron->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTitle(): void
    {
        $jumbotron = Jumbotron::new('Hello World');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testEmptyTitleReturnsEmpty(): void
    {
        $jumbotron = Jumbotron::new('');
        $rendered = $jumbotron->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $jumbotron = Jumbotron::new('Page Title', 'A subtitle');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('Page Title', $rendered);
        $this->assertStringContainsString('A subtitle', $rendered);
    }

    public function testWithButtonFactory(): void
    {
        $jumbotron = Jumbotron::withButton(
            'Get Started',
            'Sign up today',
            'Click Me'
        );
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('Get Started', $rendered);
        $this->assertStringContainsString('Click Me', $rendered);
    }

    public function testMinimalFactory(): void
    {
        $jumbotron = Jumbotron::minimal('Minimal Title');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('Minimal Title', $rendered);
        // Minimal should not have borders
        $this->assertStringNotContainsString('┌', $rendered);
    }

    public function testLeftFactory(): void
    {
        $jumbotron = Jumbotron::left('Left Aligned Title');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('Left Aligned Title', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Subtitle
    // ═══════════════════════════════════════════════════════════════

    public function testSubtitleRenders(): void
    {
        $jumbotron = Jumbotron::new('Title')->withSubtitle('My Subtitle');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('My Subtitle', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Button
    // ═══════════════════════════════════════════════════════════════

    public function testButtonRenders(): void
    {
        $jumbotron = Jumbotron::new('Title')->withButtonText('Get Started');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('[Get Started]', $rendered);
    }

    public function testButtonInWithButtonFactory(): void
    {
        $jumbotron = Jumbotron::withButton('Title', 'Subtitle', 'Sign Up');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('[Sign Up]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Borders
    // ═══════════════════════════════════════════════════════════════

    public function testBordersVisibleByDefault(): void
    {
        $jumbotron = Jumbotron::new('Title');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('└', $rendered);
    }

    public function testWithoutBorderNoBoxCharacters(): void
    {
        $jumbotron = Jumbotron::new('Title')->withBorder(false);
        $rendered = $jumbotron->render();

        $this->assertStringNotContainsString('┌', $rendered);
        $this->assertStringNotContainsString('└', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Shadow
    // ═══════════════════════════════════════════════════════════════

    public function testShadowRendersWhenEnabled(): void
    {
        $jumbotron = Jumbotron::new('Title')->withShadow(true);
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('░', $rendered);
    }

    public function testShadowNotRenderedByDefault(): void
    {
        $jumbotron = Jumbotron::new('Title');
        $rendered = $jumbotron->render();

        $this->assertStringNotContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testTitleColorAddsAnsiCodes(): void
    {
        $jumbotron = Jumbotron::new('Test')->withTitleColor(Color::ansi(12));
        $rendered = $jumbotron->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSubtitleColorAddsAnsiCodes(): void
    {
        $jumbotron = Jumbotron::new('Title')->withSubtitle('Sub')->withSubtitleColor(Color::ansi(8));
        $rendered = $jumbotron->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testButtonColorAddsAnsiCodes(): void
    {
        $jumbotron = Jumbotron::new('Title')->withButtonText('Click')->withButtonColor(Color::ansi(9));
        $rendered = $jumbotron->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBorderColorAddsAnsiCodes(): void
    {
        $jumbotron = Jumbotron::new('Title')->withBorderColor(Color::ansi(8));
        $rendered = $jumbotron->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testAlignmentSettingCenter(): void
    {
        $jumbotron = Jumbotron::new('Title')->withAlignment('center');
        $rendered = $jumbotron->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    public function testAlignmentSettingLeft(): void
    {
        $jumbotron = Jumbotron::new('Title')->withAlignment('left');
        $rendered = $jumbotron->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $resized = $original->setSize(80, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Original');
        $updated = $original->withTitle('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Updated', $original->render());
    }

    public function testWithSubtitleReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $updated = $original->withSubtitle('New Subtitle');

        $this->assertNotSame($original, $updated);
    }

    public function testWithButtonTextReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $updated = $original->withButtonText('Click Me');

        $this->assertNotSame($original, $updated);
    }

    public function testWithTitleColorReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $updated = $original->withTitleColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithSubtitleColorReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $updated = $original->withSubtitleColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithButtonColorReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $updated = $original->withButtonColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $updated = $original->withBorderColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $updated = $original->withBorder(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShadowReturnsNewInstance(): void
    {
        $original = Jumbotron::new('Title');
        $updated = $original->withShadow(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithTitle(): void
    {
        $original = Jumbotron::new('Original');
        $original->withTitle('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $jumbotron = Jumbotron::new('Title');
        [$w, $h] = $jumbotron->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithSubtitleHasMoreHeight(): void
    {
        $jumbotronNoSub = Jumbotron::new('Title');
        $jumbotronWithSub = Jumbotron::new('Title')->withSubtitle('Subtitle');

        [, $hNoSub] = $jumbotronNoSub->getInnerSize();
        [, $hWithSub] = $jumbotronWithSub->getInnerSize();

        $this->assertGreaterThan($hNoSub, $hWithSub);
    }

    public function testGetInnerSizeWithButtonHasMoreHeight(): void
    {
        $jumbotronNoButton = Jumbotron::new('Title');
        $jumbotronWithButton = Jumbotron::new('Title')->withButtonText('Click');

        [, $hNoButton] = $jumbotronNoButton->getInnerSize();
        [, $hWithButton] = $jumbotronWithButton->getInnerSize();

        $this->assertGreaterThan($hNoButton, $hWithButton);
    }

    public function testGetInnerSizeWithBordersHasMoreHeight(): void
    {
        $jumbotronNoBorder = Jumbotron::new('Title')->withBorder(false);
        $jumbotronWithBorder = Jumbotron::new('Title');

        [, $hNoBorder] = $jumbotronNoBorder->getInnerSize();
        [, $hWithBorder] = $jumbotronWithBorder->getInnerSize();

        $this->assertGreaterThan($hNoBorder, $hWithBorder);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeTitle(): void
    {
        $jumbotron = Jumbotron::new('タイトル');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('タイトル', $rendered);
    }

    public function testUnicodeSubtitle(): void
    {
        $jumbotron = Jumbotron::new('Title')->withSubtitle('サブタイトル');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('サブタイトル', $rendered);
    }

    public function testUnicodeButton(): void
    {
        $jumbotron = Jumbotron::new('Title')->withButtonText('始める');
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('[始める]', $rendered);
    }

    public function testVeryLongTitle(): void
    {
        $jumbotron = Jumbotron::new(str_repeat('x', 100));
        $rendered = $jumbotron->render();

        $this->assertStringContainsString(str_repeat('x', 100), $rendered);
    }

    public function testVeryLongButtonText(): void
    {
        $jumbotron = Jumbotron::new('Title')->withButtonText(str_repeat('x', 50));
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('[' . str_repeat('x', 50) . ']', $rendered);
    }

    public function testMultipleWithersChained(): void
    {
        $jumbotron = Jumbotron::new('Title')
            ->withSubtitle('Subtitle')
            ->withButtonText('Click')
            ->withTitleColor(Color::ansi(12))
            ->withSubtitleColor(Color::ansi(8))
            ->withBorderColor(Color::ansi(7));
        $rendered = $jumbotron->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('Subtitle', $rendered);
        $this->assertStringContainsString('[Click]', $rendered);
    }
}
