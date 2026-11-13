<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Profile;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    public function testTheDeprecatedAliasResolvesThroughTheAutoloader(): void
    {
        // CP-46 smoke pin: src/Profile.php is a bare class_alias — with only
        // the psr-4 map, a classmap-dumped (--classmap-authoritative) context
        // knows NOTHING for the name this test file's symbols resolve from.
        // The composer.json autoload.files entry registers the side-effect
        // file so the DOCUMENTED deprecated alias survives optimization
        // dumps; the string-form existence probe below is what such a
        // consumer effectively asks, and the identity leg pins the alias to
        // its target, not to a parallel class. (Local proof runs `composer
        // dump-autoload -o` + this probe — forbidden to this lane by the
        // no-composer law; the entry is structural, verified by CI's dump.)
        $this->assertTrue(
            class_exists('SugarCraft\\ColorProfile\\Profile', true),
            'the deprecated Profile alias must answer through the autoloader',
        );
        $this->assertInstanceOf(
            Profile::class,
            ColorProfile::TrueColor,
            'Profile must BE ColorProfile, not a second type',
        );
        $this->assertInstanceOf(
            ColorProfile::class,
            Profile::TrueColor,
            'the alias must resolve to the same single class entry',
        );
    }

    public function testTrueColorLabel(): void
    {
        $this->assertSame('TrueColor', Profile::TrueColor->label());
    }

    public function testMaxColors(): void
    {
        $this->assertSame(16_777_216, Profile::TrueColor->maxColors());
        $this->assertSame(256, Profile::ANSI256->maxColors());
        $this->assertSame(16, Profile::ANSI->maxColors());
        $this->assertSame(2, Profile::Ascii->maxColors());
        $this->assertSame(0, Profile::NoTTY->maxColors());
    }

    public function testDegradedToChain(): void
    {
        // the chain gained the chafa-mirroring intermediate rungs
        // (ANSI256 → Ansi240 → ANSI → Ansi8 → Fgbg → Ascii); full walk pinned
        // in ColorProfileVocabularyTest::testLadderWalksAllEightRungs.
        $this->assertSame(Profile::ANSI256, Profile::TrueColor->degradedTo());
        $this->assertSame(ColorProfile::Ansi240, Profile::ANSI256->degradedTo());
        $this->assertSame(Profile::ANSI, ColorProfile::Ansi240->degradedTo());
        $this->assertSame(ColorProfile::Ansi8, Profile::ANSI->degradedTo());
        $this->assertSame(ColorProfile::Fgbg, ColorProfile::Ansi8->degradedTo());
        $this->assertSame(Profile::Ascii, ColorProfile::Fgbg->degradedTo());
        $this->assertSame(Profile::NoTTY, Profile::Ascii->degradedTo());
        $this->assertNull(Profile::NoTTY->degradedTo());
    }

    public function testDescription(): void
    {
        $this->assertNotEmpty(Profile::TrueColor->description());
        $this->assertNotEmpty(Profile::ANSI256->description());
        $this->assertNotEmpty(Profile::ANSI->description());
    }

    public function testFromColorProfile(): void
    {
        $this->assertSame(Profile::TrueColor, Profile::fromColorProfile(\SugarCraft\ColorProfile\ColorProfile::TrueColor));
        $this->assertSame(Profile::ANSI256, Profile::fromColorProfile(\SugarCraft\ColorProfile\ColorProfile::Ansi256));
        $this->assertSame(Profile::ANSI, Profile::fromColorProfile(\SugarCraft\ColorProfile\ColorProfile::Ansi));
        $this->assertSame(Profile::Ascii, Profile::fromColorProfile(\SugarCraft\ColorProfile\ColorProfile::Ascii));
        $this->assertSame(Profile::NoTTY, Profile::fromColorProfile(\SugarCraft\ColorProfile\ColorProfile::NoTTY));
    }

    public function testLabelAllCases(): void
    {
        $this->assertSame('TrueColor', Profile::TrueColor->label());
        $this->assertSame('ANSI 256', Profile::ANSI256->label());
        $this->assertSame('ANSI', Profile::ANSI->label());
        $this->assertSame('ASCII', Profile::Ascii->label());
        $this->assertSame('No TTY', Profile::NoTTY->label());
    }

    public function testDescriptionAllCases(): void
    {
        $this->assertSame('24-bit full color', Profile::TrueColor->description());
        $this->assertSame('256-color palette', Profile::ANSI256->description());
        $this->assertSame('16-color standard', Profile::ANSI->description());
        $this->assertSame('black & white', Profile::Ascii->description());
        $this->assertSame('colors disabled', Profile::NoTTY->description());
    }

    public function testMaxColorsAllCases(): void
    {
        $this->assertSame(16_777_216, Profile::TrueColor->maxColors());
        $this->assertSame(256, Profile::ANSI256->maxColors());
        $this->assertSame(16, Profile::ANSI->maxColors());
        $this->assertSame(2, Profile::Ascii->maxColors());
        $this->assertSame(0, Profile::NoTTY->maxColors());
    }
}
