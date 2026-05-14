<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Media;

use SugarCraft\Dash\Components\Media\Emoji;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class EmojiTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testEmojiImplementsSizer(): void
    {
        $emoji = Emoji::thumbsUp();
        $this->assertInstanceOf(Sizer::class, $emoji);
    }

    public function testEmojiImplementsItem(): void
    {
        $emoji = Emoji::thumbsUp();
        $this->assertInstanceOf(Item::class, $emoji);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testThumbsUpFactory(): void
    {
        $emoji = Emoji::thumbsUp('Great!');
        $rendered = $emoji->render();

        $this->assertStringContainsString('👍', $rendered);
    }

    public function testThumbsDownFactory(): void
    {
        $emoji = Emoji::thumbsDown('Bad');
        $rendered = $emoji->render();

        $this->assertStringContainsString('👎', $rendered);
    }

    public function testClapFactory(): void
    {
        $emoji = Emoji::clap();
        $rendered = $emoji->render();

        $this->assertStringContainsString('👏', $rendered);
    }

    public function testFireFactory(): void
    {
        $emoji = Emoji::fire();
        $rendered = $emoji->render();

        $this->assertStringContainsString('🔥', $rendered);
    }

    public function testRocketFactory(): void
    {
        $emoji = Emoji::rocket();
        $rendered = $emoji->render();

        $this->assertStringContainsString('🚀', $rendered);
    }

    public function testStarFactory(): void
    {
        $emoji = Emoji::star();
        $rendered = $emoji->render();

        $this->assertStringContainsString('⭐', $rendered);
    }

    public function testSparkleFactory(): void
    {
        $emoji = Emoji::sparkle();
        $rendered = $emoji->render();

        $this->assertStringContainsString('✨', $rendered);
    }

    public function testCheckFactory(): void
    {
        $emoji = Emoji::check();
        $rendered = $emoji->render();

        $this->assertStringContainsString('✅', $rendered);
    }

    public function testXFactory(): void
    {
        $emoji = Emoji::x();
        $rendered = $emoji->render();

        $this->assertStringContainsString('❌', $rendered);
    }

    public function testWarningFactory(): void
    {
        $emoji = Emoji::warning();
        $rendered = $emoji->render();

        $this->assertStringContainsString('⚠️', $rendered);
    }

    public function testInfoFactory(): void
    {
        $emoji = Emoji::info();
        $rendered = $emoji->render();

        $this->assertStringContainsString('ℹ️', $rendered);
    }

    public function testQuestionFactory(): void
    {
        $emoji = Emoji::question();
        $rendered = $emoji->render();

        $this->assertStringContainsString('❓', $rendered);
    }

    public function testExclamationFactory(): void
    {
        $emoji = Emoji::exclamation();
        $rendered = $emoji->render();

        $this->assertStringContainsString('❗', $rendered);
    }

    public function testLightbulbFactory(): void
    {
        $emoji = Emoji::lightbulb();
        $rendered = $emoji->render();

        $this->assertStringContainsString('💡', $rendered);
    }

    public function testTrophyFactory(): void
    {
        $emoji = Emoji::trophy();
        $rendered = $emoji->render();

        $this->assertStringContainsString('🏆', $rendered);
    }

    public function testMedalFactory(): void
    {
        $emoji = Emoji::medal();
        $rendered = $emoji->render();

        $this->assertStringContainsString('🎖️', $rendered);
    }

    public function testSmileFactory(): void
    {
        $emoji = Emoji::smile();
        $rendered = $emoji->render();

        $this->assertStringContainsString('😊', $rendered);
    }

    public function testSadFactory(): void
    {
        $emoji = Emoji::sad();
        $rendered = $emoji->render();

        $this->assertStringContainsString('😢', $rendered);
    }

    public function testHeartFactory(): void
    {
        $emoji = Emoji::heart();
        $rendered = $emoji->render();

        $this->assertStringContainsString('❤️', $rendered);
    }

    public function testBrokenHeartFactory(): void
    {
        $emoji = Emoji::brokenHeart();
        $rendered = $emoji->render();

        $this->assertStringContainsString('💔', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $emoji = Emoji::thumbsUp();
        $rendered = $emoji->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsEmoji(): void
    {
        $emoji = new Emoji('🎉');
        $rendered = $emoji->render();

        $this->assertStringContainsString('🎉', $rendered);
    }

    public function testRenderWithLabel(): void
    {
        $emoji = new Emoji('🎉', 'Party time!', Emoji::SIZE_MEDIUM, null, true);
        $rendered = $emoji->render();

        $this->assertStringContainsString('Party time!', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size scaling
    // ═══════════════════════════════════════════════════════════════

    public function testSizeSmallRendersSingle(): void
    {
        $emoji = new Emoji('🎉', null, Emoji::SIZE_SMALL);
        $rendered = $emoji->render();

        $this->assertSame(1, substr_count($rendered, '🎉'));
    }

    public function testSizeMediumRendersDoubled(): void
    {
        $emoji = new Emoji('🎉', null, Emoji::SIZE_MEDIUM);
        $rendered = $emoji->render();

        $this->assertSame(2, substr_count($rendered, '🎉'));
    }

    public function testSizeLargeRendersTripled(): void
    {
        $emoji = new Emoji('🎉', null, Emoji::SIZE_LARGE);
        $rendered = $emoji->render();

        $this->assertSame(3, substr_count($rendered, '🎉'));
    }

    public function testInvalidSizeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Emoji('🎉', null, 999);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testTintColorAddsAnsiCodes(): void
    {
        $emoji = new Emoji('🎉', null, Emoji::SIZE_MEDIUM, Color::ansi(13));
        $rendered = $emoji->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $emoji = new Emoji('🎉', null, Emoji::SIZE_MEDIUM, Color::ansi(11));
        $rendered = $emoji->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Emoji::thumbsUp();
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $emoji = Emoji::thumbsUp();
        [$w, $h] = $emoji->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithLabel(): void
    {
        $emoji = new Emoji('🎉', 'Test', Emoji::SIZE_MEDIUM, null, true);
        [$w, ] = $emoji->getInnerSize();

        // Width should account for emoji + space + label
        $this->assertGreaterThan(1, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithEmojiReturnsNewInstance(): void
    {
        $original = Emoji::thumbsUp();
        $updated = $original->withEmoji('🎉');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('🎉', $updated->render());
    }

    public function testWithLabelReturnsNewInstance(): void
    {
        $original = Emoji::thumbsUp();
        $updated = $original->withLabel('New label');

        $this->assertNotSame($original, $updated);
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $original = Emoji::thumbsUp();
        $updated = $original->withSize(Emoji::SIZE_LARGE);

        $this->assertNotSame($original, $updated);
    }

    public function testWithTintColorReturnsNewInstance(): void
    {
        $original = Emoji::thumbsUp();
        $updated = $original->withTintColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowLabelReturnsNewInstance(): void
    {
        $original = Emoji::thumbsUp();
        $updated = $original->withShowLabel(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithers(): void
    {
        $original = Emoji::thumbsUp();
        $original->withEmoji('🎉');
        $original->withLabel('Changed');
        $original->withSize(Emoji::SIZE_LARGE);

        $rendered = $original->render();
        $this->assertStringContainsString('👍', $rendered);
        $this->assertStringNotContainsString('🎉', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Show label behavior
    // ═══════════════════════════════════════════════════════════════

    public function testShowLabelFalseHidesLabel(): void
    {
        $emoji = new Emoji('🎉', 'Hidden label', Emoji::SIZE_MEDIUM, null, false);
        $rendered = $emoji->render();

        $this->assertStringNotContainsString('Hidden label', $rendered);
    }

    public function testShowLabelTrueShowsLabel(): void
    {
        $emoji = new Emoji('🎉', 'Visible label', Emoji::SIZE_MEDIUM, null, true);
        $rendered = $emoji->render();

        $this->assertStringContainsString('Visible label', $rendered);
    }

    public function testFactoryWithLabelShowsLabel(): void
    {
        // Factory methods with label parameter set showLabel to true
        $emoji = Emoji::thumbsUp('Great job');
        $rendered = $emoji->render();

        $this->assertStringContainsString('Great job', $rendered);
    }

    public function testFactoryWithoutLabelHidesLabel(): void
    {
        // Factory methods without label parameter have showLabel=false
        $emoji = Emoji::thumbsUp();
        $rendered = $emoji->render();

        $this->assertStringContainsString('👍', $rendered);
        // No label should appear
        $this->assertStringNotContainsString(' ', $rendered, 'No space after emoji when no label');
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongLabelRenders(): void
    {
        $emoji = new Emoji('🎉', str_repeat('x', 100), Emoji::SIZE_MEDIUM, null, true);
        $rendered = $emoji->render();

        $this->assertNotSame('', $rendered);
    }

    public function testEmojiSequence(): void
    {
        $emoji = new Emoji('👨‍👩‍👧‍👦', 'Family', Emoji::SIZE_MEDIUM, null, true);
        $rendered = $emoji->render();

        $this->assertStringContainsString('👨‍👩‍👧‍👦', $rendered);
    }

    public function testSkinToneModifier(): void
    {
        $emoji = new Emoji('👍🏻', 'Thumbs up light', Emoji::SIZE_MEDIUM, null, true);
        $rendered = $emoji->render();

        $this->assertStringContainsString('👍🏻', $rendered);
    }
}
