<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Palette;
use SugarCraft\ColorProfile\Profile;

/**
 * B-09: the two five-level vocabularies (renderer-facing Profile, detection-
 * facing ColorProfile) are consolidated into ColorProfile as canonical, with
 * Ansi240/Ansi8/Fgbg minted from chafa's canvas-mode ladder. These pins hold
 * the BREAKING surface honest: old spellings keep resolving, wire bytes of
 * the original five arms never moved, detection cannot mint the request-side
 * arms, and each new arm converts/degrades per its promise.
 */
final class ColorProfileVocabularyTest extends TestCase
{
    public function testTheProfileFacadeIsTheSameTypeAsTheCanonicalEnum(): void
    {
        // ::class is a compile-time string that keeps the name written at the
        // call site, so the call-site spelling cannot reveal the alias — the
        // runtime types can.
        $this->assertInstanceOf(ColorProfile::class, Profile::Ansi);
        $this->assertSame(ColorProfile::class, Profile::Ansi::class);
        $this->assertSame(ColorProfile::Ansi, Profile::tryFrom('ansi'));
        $this->assertSame(ColorProfile::Ansi256, Profile::tryFrom('ansi256'));
    }

    public function testEightCasesInRichnessOrderWithStableWireValues(): void
    {
        $this->assertSame([
            'truecolor', 'ansi256', 'ansi240', 'ansi', 'ansi8', 'fgbg', 'ascii', 'notty',
        ], array_column(ColorProfile::cases(), 'value'));
    }

    public function testDeprecatedScreamingSpellingsResolveToTheSameCaseObjects(): void
    {
        $this->assertSame(ColorProfile::Ansi256, ColorProfile::ANSI256);
        $this->assertSame(ColorProfile::Ansi, ColorProfile::ANSI);
        $this->assertSame(ColorProfile::Ansi256, Profile::ANSI256);
        $this->assertSame(ColorProfile::Ansi, Profile::ANSI);
        $this->assertSame(ColorProfile::TrueColor, Profile::TrueColor);
        $this->assertSame(ColorProfile::NoTTY, Profile::NoTTY);
    }

    public function testFromColorProfileSurvivesAsIdentityBridge(): void
    {
        foreach (ColorProfile::cases() as $case) {
            $this->assertSame($case, ColorProfile::fromColorProfile($case));
            $this->assertSame($case, Profile::fromColorProfile($case));
        }
    }

    public function testLadderWalksAllEightRungsAndTerminatesAtNoTty(): void
    {
        $walk = [];
        $p = ColorProfile::TrueColor;
        while ($p !== null) {
            $walk[] = $p->value;
            $p = $p->degradedTo();
        }
        $this->assertSame(
            ['truecolor', 'ansi256', 'ansi240', 'ansi', 'ansi8', 'fgbg', 'ascii', 'notty'],
            $walk,
        );
        $this->assertNull(ColorProfile::NoTTY->degradedTo());
    }

    public function testNewArmsCarryLabelDescriptionAndColorBudget(): void
    {
        $this->assertSame('ANSI 240', ColorProfile::Ansi240->label());
        $this->assertSame('ANSI 8', ColorProfile::Ansi8->label());
        $this->assertSame('FGBG', ColorProfile::Fgbg->label());
        $this->assertSame(240, ColorProfile::Ansi240->maxColors());
        $this->assertSame(8, ColorProfile::Ansi8->maxColors());
        $this->assertSame(2, ColorProfile::Fgbg->maxColors());
        $this->assertStringContainsString('240', ColorProfile::Ansi240->description());
        $this->assertTrue(ColorProfile::Fgbg->allowsColor());
    }

    public function testOldFiveArmsKeepTheirHumanStrings(): void
    {
        $this->assertSame('TrueColor', ColorProfile::TrueColor->label());
        $this->assertSame('ANSI 256', ColorProfile::Ansi256->label());
        $this->assertSame('ANSI', ColorProfile::Ansi->label());
        $this->assertSame('ASCII', ColorProfile::Ascii->label());
        $this->assertSame('No TTY', ColorProfile::NoTTY->label());
        $this->assertSame('16-color standard', ColorProfile::Ansi->description());
        $this->assertSame(16, ColorProfile::Ansi->maxColors());
    }

    /** chafa INDEXED_240: quantisation must land on the fixed 16-255 range only. */
    public function testAnsi240QuantisationNeverTouchesTheThemeableSixteen(): void
    {
        foreach ([[0, 0, 0], [205, 0, 0], [0, 0, 238], [229, 229, 229], [128, 128, 128]] as [$r, $g, $b]) {
            $idx = (new Color($r, $g, $b))->convert(ColorProfile::Ansi240)->toAnsi256Index();
            $this->assertGreaterThanOrEqual(16, $idx);
            $this->assertLessThanOrEqual(255, $idx);
        }
    }

    public function testAnsi8LandsOnBasicSlotsAndRoundTripsExactValues(): void
    {
        for ($slot = 0; $slot <= 7; $slot++) {
            [$r, $g, $b] = Color::ANSI16_RGB[$slot];
            $this->assertSame($slot, (new Color($r, $g, $b))->toAnsi8Index());
        }
        $this->assertSame(1, (new Color(255, 0, 0))->toAnsi8Index());
        $this->assertSame(6, (new Color(0, 255, 255))->toAnsi8Index());
        $this->assertSame(7, (new Color(255, 255, 255))->toAnsi8Index());
        $converted = (new Color(255, 0, 0))->convert(ColorProfile::Ansi8);
        $this->assertSame([0xCD, 0x00, 0x00], [$converted->r, $converted->g, $converted->b]);
    }

    public function testFgbgReducesToPureBlackOrPureWhite(): void
    {
        $dark = (new Color(137, 0, 0))->convert(ColorProfile::Fgbg);
        $this->assertSame([0, 0, 0], [$dark->r, $dark->g, $dark->b]);
        $light = (new Color(250, 250, 250))->convert(ColorProfile::Fgbg);
        $this->assertSame([255, 255, 255], [$light->r, $light->g, $light->b]);
    }

    public function testDegradeAtAnsi240RequantisesThemeSlotsAndPassesFixedOnes(): void
    {
        $palette = (new Palette(env: []))->withProfile(ColorProfile::Ansi240);

        $out = $palette->degrade("\x1b[38;5;100m");
        $this->assertSame("\x1b[38;5;100m", $out, 'fixed slot >= 16 passes through');

        $out = $palette->degrade("\x1b[38;5;5m");
        $this->assertMatchesRegularExpression("/^\x1b\\[38;5;\\d{1,3}m$/", $out);
        $slot = (int) explode(';', rtrim(ltrim($out, "\x1b["), 'm'))[2];
        $this->assertGreaterThanOrEqual(16, $slot, 'B-09: slot 5 must never survive 240-depth');
        $this->assertLessThanOrEqual(255, $slot);

        $out = $palette->degrade("\x1b[48;5;0m");
        $slot = (int) explode(';', rtrim(ltrim($out, "\x1b["), 'm'))[2];
        $this->assertGreaterThanOrEqual(16, $slot);
    }

    public function testDegradeAtAnsi256StillPassesLowSlotsThrough(): void
    {
        $palette = (new Palette(env: []))->withProfile(ColorProfile::Ansi256);
        $this->assertSame("\x1b[38;5;5m", $palette->degrade("\x1b[38;5;5m"));
    }

    public function testDegradeAtAnsi8CollapsesForegroundToBasicFourBit(): void
    {
        $palette = (new Palette(env: []))->withProfile(ColorProfile::Ansi8);
        $this->assertSame("\x1b[31m", $palette->degrade("\x1b[38;5;196m"));
        $this->assertSame("\x1b[34m", $palette->degrade("\x1b[38;2;100;50;255m"));
        $this->assertSame("\x1b[58;5;1m", $palette->degrade("\x1b[58;5;196m"), '58 keeps an index');
    }

    public function testDegradeAtFgbgCollapsesToBlackOrWhiteFourBit(): void
    {
        $palette = (new Palette(env: []))->withProfile(ColorProfile::Fgbg);
        $this->assertSame("\x1b[30m", $palette->degrade("\x1b[38;2;137;0;0m"));
        $this->assertSame("\x1b[97m", $palette->degrade("\x1b[38;2;255;255;255m"));
        $this->assertSame("\x1b[40m", $palette->degrade("\x1b[48;2;0;0;0m"));
    }

    public function testDetectionNeverMintsTheRequestSideArms(): void
    {
        $detectable = [ColorProfile::TrueColor, ColorProfile::Ansi256, ColorProfile::Ansi, ColorProfile::Ascii, ColorProfile::NoTTY];
        $this->assertContains(Palette::detect(null, ['CLICOLOR_FORCE' => '1']), $detectable);
        $this->assertContains(Palette::detect(null, ['TERM' => 'xterm-256color']), $detectable);
        $this->assertContains(Palette::detect(null, ['NO_COLOR' => '1']), $detectable);
        $this->assertContains(Palette::detect(null, ['TERM' => 'vt100']), $detectable);
    }

    public function testEveryCaseHasACommentQuip(): void
    {
        $palette = (new Palette(env: []));
        foreach (ColorProfile::cases() as $case) {
            $this->assertNotSame('', $palette->withProfile($case)->comment());
        }
    }
}
