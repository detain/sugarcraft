<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Profile;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testProfileImplementsSizer(): void
    {
        $profile = Profile::new('John Doe', 'Developer');
        $this->assertInstanceOf(Sizer::class, $profile);
    }

    public function testProfileImplementsItem(): void
    {
        $profile = Profile::new('John Doe', 'Developer');
        $this->assertInstanceOf(Item::class, $profile);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $profile = Profile::new('Jane Doe', 'Designer');
        $rendered = $profile->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsName(): void
    {
        $profile = Profile::new('Sarah Connor', 'Engineer');
        $rendered = $profile->render();

        $this->assertStringContainsString('Sarah Connor', $rendered);
    }

    public function testRenderContainsRole(): void
    {
        $profile = Profile::new('John', 'Senior Developer');
        $rendered = $profile->render();

        $this->assertStringContainsString('Senior Developer', $rendered);
    }

    public function testRenderContainsBio(): void
    {
        $profile = Profile::new('John', 'Dev', 'Loves coding');
        $rendered = $profile->render();

        $this->assertStringContainsString('Loves coding', $rendered);
    }

    public function testRenderContainsAvatar(): void
    {
        $profile = Profile::new('John', 'Dev', '', 'JD');
        $rendered = $profile->render();

        $this->assertStringContainsString('[ JD ]', $rendered);
    }

    public function testRenderContainsBorderChars(): void
    {
        $profile = Profile::new('John', 'Dev');
        $rendered = $profile->render();

        $this->assertStringContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $profile = Profile::new('Alice Smith', 'Product Manager', '10 years experience');
        $rendered = $profile->render();

        $this->assertStringContainsString('Alice Smith', $rendered);
        $this->assertStringContainsString('Product Manager', $rendered);
        $this->assertStringContainsString('10 years experience', $rendered);
    }

    public function testHorizontalFactory(): void
    {
        $profile = Profile::horizontal('Bob', 'Designer', 'Creative mind');
        $rendered = $profile->render();

        $this->assertStringContainsString('Bob', $rendered);
        $this->assertStringContainsString('Designer', $rendered);
    }

    public function testCompactFactory(): void
    {
        $profile = Profile::compact('Carol', 'Analyst', 'CZ');
        $rendered = $profile->render();

        $this->assertStringContainsString('Carol', $rendered);
        $this->assertStringContainsString('Analyst', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Layouts
    // ═══════════════════════════════════════════════════════════════

    public function testVerticalLayout(): void
    {
        $profile = Profile::new('John', 'Dev', 'Bio');
        $this->assertNotSame('', $profile->render());
    }

    public function testHorizontalLayoutRenders(): void
    {
        $profile = Profile::horizontal('John', 'Dev', 'Bio');
        $this->assertNotSame('', $profile->render());
    }

    public function testCompactLayoutRenders(): void
    {
        $profile = Profile::compact('John', 'Dev');
        $this->assertNotSame('', $profile->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Contact info
    // ═══════════════════════════════════════════════════════════════

    public function testEmailRenders(): void
    {
        $profile = Profile::new('John', 'Dev', '')->withEmail('john@example.com');
        $rendered = $profile->render();

        $this->assertStringContainsString('john@example.com', $rendered);
    }

    public function testLocationRenders(): void
    {
        $profile = Profile::new('John', 'Dev', '')->withLocation('San Francisco');
        $rendered = $profile->render();

        $this->assertStringContainsString('San Francisco', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testAvatarBgColorAddsAnsiCodes(): void
    {
        $profile = Profile::new('John', 'Dev', '', 'JD')
            ->withAvatarBgColor(Color::ansi(12));
        $rendered = $profile->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNameColorAddsAnsiCodes(): void
    {
        $profile = Profile::new('John', 'Dev')
            ->withNameColor(Color::ansi(9));
        $rendered = $profile->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRoleColorAddsAnsiCodes(): void
    {
        $profile = Profile::new('John', 'Dev')
            ->withRoleColor(Color::ansi(13));
        $rendered = $profile->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBioColorAddsAnsiCodes(): void
    {
        $profile = Profile::new('John', 'Dev', 'Some bio')
            ->withBioColor(Color::ansi(8));
        $rendered = $profile->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBorderColorAddsAnsiCodes(): void
    {
        $profile = Profile::new('John', 'Dev')
            ->withBorderColor(Color::ansi(8));
        $rendered = $profile->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Profile::new('John', 'Dev');
        $resized = $original->setSize(60, 15);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithNameReturnsNewInstance(): void
    {
        $original = Profile::new('Original', 'Dev');
        $updated = $original->withName('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Original', $updated->render());
    }

    public function testWithRoleReturnsNewInstance(): void
    {
        $original = Profile::new('John', 'Old Role');
        $updated = $original->withRole('New Role');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New Role', $updated->render());
    }

    public function testWithBioReturnsNewInstance(): void
    {
        $original = Profile::new('John', 'Dev', 'Old bio');
        $updated = $original->withBio('New bio');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New bio', $updated->render());
    }

    public function testWithAvatarReturnsNewInstance(): void
    {
        $original = Profile::new('John', 'Dev', '', 'JD');
        $updated = $original->withAvatar('SK');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('[ SK ]', $updated->render());
    }

    public function testWithEmailReturnsNewInstance(): void
    {
        $original = Profile::new('John', 'Dev');
        $updated = $original->withEmail('john@test.com');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('john@test.com', $updated->render());
    }

    public function testWithLocationReturnsNewInstance(): void
    {
        $original = Profile::new('John', 'Dev');
        $updated = $original->withLocation('NYC');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('NYC', $updated->render());
    }

    public function testWithLayoutReturnsNewInstance(): void
    {
        $original = Profile::new('John', 'Dev');
        $updated = $original->withLayout('horizontal');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithName(): void
    {
        $original = Profile::new('Original', 'Dev');
        $original->withName('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $profile = Profile::new('John Doe', 'Developer', 'A bio');
        [$w, $h] = $profile->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithAvatarHasMoreHeight(): void
    {
        $withoutAvatar = Profile::new('John', 'Dev', 'Bio');
        $withAvatar = Profile::new('John', 'Dev', 'Bio', 'JD');

        [, $h1] = $withoutAvatar->getInnerSize();
        [, $h2] = $withAvatar->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongName(): void
    {
        $profile = Profile::new(str_repeat('A', 50), 'Dev');
        $rendered = $profile->render();

        $this->assertStringContainsString(str_repeat('A', 50), $rendered);
    }

    public function testVeryLongBio(): void
    {
        $profile = Profile::new('John', 'Dev', str_repeat('word ', 50));
        $rendered = $profile->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeName(): void
    {
        $profile = Profile::new('田中太郎', '開発者', '十年経験');
        $rendered = $profile->render();

        $this->assertStringContainsString('田中太郎', $rendered);
        $this->assertStringContainsString('開発者', $rendered);
    }

    public function testCompactLayoutWithAvatar(): void
    {
        $profile = Profile::compact('John', 'Dev', 'JD');
        $rendered = $profile->render();

        // Compact layout renders avatar without padding
        $this->assertStringContainsString('[JD]', $rendered);
    }

    public function testEmptyFieldsStillRenders(): void
    {
        $profile = Profile::new('', '', '');
        $rendered = $profile->render();

        $this->assertNotSame('', $rendered);
    }
}
