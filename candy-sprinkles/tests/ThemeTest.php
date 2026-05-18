<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Theme;

final class ThemeTest extends TestCase
{
    // ─── Factory: dark() ────────────────────────────────────────────────────

    public function testDarkHasAllSlotsPopulated(): void
    {
        $t = Theme::dark();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    public function testDarkForegroundIsLightOnDark(): void
    {
        $t = Theme::dark();
        // Dark theme foreground should be a light colour (luminance > 0.5).
        $this->assertGreaterThan(0.5, $t->foreground->luminance());
    }

    public function testDarkBackgroundIsDark(): void
    {
        $t = Theme::dark();
        $this->assertTrue($t->background->isDark());
    }

    // ─── Factory: light() ─────────────────────────────────────────────────

    public function testLightHasAllSlotsPopulated(): void
    {
        $t = Theme::light();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    public function testLightForegroundIsDarkOnLight(): void
    {
        $t = Theme::light();
        // Light theme foreground should be dark (luminance < 0.5).
        $this->assertLessThan(0.5, $t->foreground->luminance());
    }

    public function testLightBackgroundIsLight(): void
    {
        $t = Theme::light();
        $this->assertFalse($t->background->isDark());
    }

    // ─── Factory: dracula() ────────────────────────────────────────────────

    public function testDraculaHasAllSlotsPopulated(): void
    {
        $t = Theme::dracula();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    public function testDraculaColorsMatchPublishedSpec(): void
    {
        // Verify a representative subset of the Dracula palette against
        // the published spec: https://draculatheme.com/
        $t = Theme::dracula();

        // Background: #282A36
        $this->assertSame('#282a36', $t->background->toHex());

        // Foreground: #F8F8F2
        $this->assertSame('#f8f8f2', $t->foreground->toHex());

        // Primary (purple): #BD93F9
        $this->assertSame('#bd93f9', $t->primary->toHex());

        // Secondary = Green (comment): #50FA7B
        $this->assertSame('#50fa7b', $t->secondary->toHex());

        // Accent (pink): #FF79C6
        $this->assertSame('#ff79c6', $t->accent->toHex());

        // Error: #FF5555
        $this->assertSame('#ff5555', $t->error->toHex());

        // Warning: #FFB86C
        $this->assertSame('#ffb86c', $t->warning->toHex());

        // Success: #50FA7B (same as secondary)
        $this->assertSame('#50fa7b', $t->success->toHex());

        // Info: #8BE9FD
        $this->assertSame('#8be9fd', $t->info->toHex());

        // Border: #44475A
        $this->assertSame('#44475a', $t->border->toHex());
    }

    // ─── Factory: tokyoNight() ─────────────────────────────────────────────

    public function testTokyoNightHasAllSlotsPopulated(): void
    {
        $t = Theme::tokyoNight();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    // ─── Factory: oneDark() ───────────────────────────────────────────────

    public function testOneDarkHasAllSlotsPopulated(): void
    {
        $t = Theme::oneDark();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    public function testOneDarkBackgroundIsDark(): void
    {
        $t = Theme::oneDark();
        $this->assertTrue($t->background->isDark());
    }

    // ─── Factory: githubDark() ──────────────────────────────────────────────

    public function testGithubDarkHasAllSlotsPopulated(): void
    {
        $t = Theme::githubDark();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    // ─── Factory: solarizedDark() / solarizedLight() ─────────────────────

    public function testSolarizedDarkHasAllSlotsPopulated(): void
    {
        $t = Theme::solarizedDark();
        foreach (['foreground', 'background', 'primary', 'secondary',
                  'accent', 'muted', 'error', 'warning', 'success',
                  'info', 'border', 'separator', 'cursor'] as $slot) {
            $this->assertInstanceOf(Color::class, $t->{$slot}, "slot {$slot}");
        }
    }

    public function testSolarizedLightHasAllSlotsPopulated(): void
    {
        $t = Theme::solarizedLight();
        foreach (['foreground', 'background', 'primary', 'secondary',
                  'accent', 'muted', 'error', 'warning', 'success',
                  'info', 'border', 'separator', 'cursor'] as $slot) {
            $this->assertInstanceOf(Color::class, $t->{$slot}, "slot {$slot}");
        }
    }

    // ─── Factory: ansi() ───────────────────────────────────────────────────

    public function testAnsiHasAllSlotsPopulated(): void
    {
        $t = Theme::ansi();
        $this->assertInstanceOf(Color::class, $t->foreground);
        $this->assertInstanceOf(Color::class, $t->background);
        $this->assertInstanceOf(Color::class, $t->primary);
        $this->assertInstanceOf(Color::class, $t->secondary);
        $this->assertInstanceOf(Color::class, $t->accent);
        $this->assertInstanceOf(Color::class, $t->muted);
        $this->assertInstanceOf(Color::class, $t->error);
        $this->assertInstanceOf(Color::class, $t->warning);
        $this->assertInstanceOf(Color::class, $t->success);
        $this->assertInstanceOf(Color::class, $t->info);
        $this->assertInstanceOf(Color::class, $t->border);
        $this->assertInstanceOf(Color::class, $t->separator);
        $this->assertInstanceOf(Color::class, $t->cursor);
    }

    // ─── Factory: adaptive() ─────────────────────────────────────────────

    public function testAdaptiveDarkWhenBgIndexLt8(): void
    {
        // COLORFGBG=0;7 → bg=7 (< 8 → dark)
        putenv('COLORFGBG=0;7');
        $t = Theme::adaptive();
        $this->assertTrue($t->background->isDark());
    }

    public function testAdaptiveLightWhenBgIndexGte8(): void
    {
        // COLORFGBG=0;15 → bg=15 (>= 8 → light)
        putenv('COLORFGBG=0;15');
        $t = Theme::adaptive();
        $this->assertFalse($t->background->isDark());
    }

    public function testAdaptiveFallsBackToDarkWhenEnvNotSet(): void
    {
        putenv('COLORFGBG');
        $t = Theme::adaptive();
        $this->assertTrue($t->background->isDark());
    }

    public function testAdaptiveFallsBackToDarkForMalformedValue(): void
    {
        putenv('COLORFGBG=not-a-number');
        $t = Theme::adaptive();
        $this->assertTrue($t->background->isDark());
        putenv('COLORFGBG=7'); // only one part
        $t = Theme::adaptive();
        $this->assertTrue($t->background->isDark());
    }

    // ─── Fluent withers ───────────────────────────────────────────────────

    public function testWithForegroundReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withForeground(Color::hex('#ffffff'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#ffffff', $b->foreground->toHex());
        $this->assertSame($a->background->toHex(), $b->background->toHex());
    }

    public function testWithBackgroundReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withBackground(Color::hex('#ffffff'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#ffffff', $b->background->toHex());
    }

    public function testWithPrimaryReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withPrimary(Color::hex('#ff0000'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#ff0000', $b->primary->toHex());
        $this->assertSame($a->secondary->toHex(), $b->secondary->toHex());
    }

    public function testWithSecondaryReturnsNewInstance(): void
    {
        $a = Theme::dracula();
        $b = $a->withSecondary(Color::hex('#0000ff'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#0000ff', $b->secondary->toHex());
    }

    public function testWithAccentReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withAccent(Color::hex('#00ff00'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#00ff00', $b->accent->toHex());
    }

    public function testWithMutedReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withMuted(Color::hex('#888888'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#888888', $b->muted->toHex());
    }

    public function testWithErrorReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withError(Color::hex('#cc0000'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#cc0000', $b->error->toHex());
    }

    public function testWithWarningReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withWarning(Color::hex('#ddaa00'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#ddaa00', $b->warning->toHex());
    }

    public function testWithSuccessReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withSuccess(Color::hex('#00cc00'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#00cc00', $b->success->toHex());
    }

    public function testWithInfoReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withInfo(Color::hex('#0099cc'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#0099cc', $b->info->toHex());
    }

    public function testWithBorderReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withBorder(Color::hex('#333333'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#333333', $b->border->toHex());
    }

    public function testWithSeparatorReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withSeparator(Color::hex('#222222'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#222222', $b->separator->toHex());
    }

    public function testWithCursorReturnsNewInstance(): void
    {
        $a = Theme::dark();
        $b = $a->withCursor(Color::hex('#aabbcc'));
        $this->assertNotSame($a, $b);
        $this->assertSame('#aabbcc', $b->cursor->toHex());
    }

    public function testWitherPreservesOtherSlots(): void
    {
        $a = Theme::dracula();
        $fgBefore = $a->foreground->toHex();
        $bgBefore = $a->background->toHex();
        $b = $a->withPrimary(Color::hex('#abcdef'));
        $this->assertSame($fgBefore, $b->foreground->toHex());
        $this->assertSame($bgBefore, $b->background->toHex());
        $this->assertSame('#abcdef', $b->primary->toHex());
    }

    public function testChainedWithersWorkCorrectly(): void
    {
        $t = Theme::dark()
            ->withForeground(Color::hex('#111111'))
            ->withBackground(Color::hex('#eeeeee'))
            ->withPrimary(Color::hex('#333333'));
        $this->assertSame('#111111', $t->foreground->toHex());
        $this->assertSame('#eeeeee', $t->background->toHex());
        $this->assertSame('#333333', $t->primary->toHex());
    }
}
