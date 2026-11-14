<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\AdaptiveColor;
use SugarCraft\Style\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function testDefaultsAreTrueColorAndDarkBg(): void
    {
        $r = Renderer::new();
        $this->assertSame(ColorProfile::TrueColor, $r->colorProfile);
        $this->assertTrue($r->hasDarkBackground);
    }

    public function testWithColorProfile(): void
    {
        $r = Renderer::new()->withColorProfile(ColorProfile::Ansi256);
        $this->assertSame(ColorProfile::Ansi256, $r->colorProfile);
        $this->assertTrue($r->hasDarkBackground);
    }

    public function testWithHasDarkBackground(): void
    {
        $r = Renderer::new()->withHasDarkBackground(false);
        $this->assertFalse($r->hasDarkBackground);
    }

    public function testNewStyleInheritsProfile(): void
    {
        $r = Renderer::new()->withColorProfile(ColorProfile::Ansi);
        $style = $r->newStyle();
        $this->assertSame(ColorProfile::Ansi, $style->getColorProfile());
    }

    public function testLightDarkPicksByFlag(): void
    {
        $light = Color::hex('#000000');
        $dark  = Color::hex('#ffffff');

        $rDark = Renderer::new()->withHasDarkBackground(true);
        $pickD = $rDark->lightDark();
        $this->assertSame($dark, $pickD($light, $dark));

        $rLight = Renderer::new()->withHasDarkBackground(false);
        $pickL = $rLight->lightDark();
        $this->assertSame($light, $pickL($light, $dark));
    }

    public function testResolveAdaptive(): void
    {
        $light = Color::hex('#005f87');
        $dark  = Color::hex('#5fafd7');
        $adaptive = new AdaptiveColor($light, $dark);

        $this->assertSame($dark,  Renderer::new()->withHasDarkBackground(true)->resolveAdaptive($adaptive));
        $this->assertSame($light, Renderer::new()->withHasDarkBackground(false)->resolveAdaptive($adaptive));
    }

    public function testFromEnvironmentReturnsRenderer(): void
    {
        $r = Renderer::fromEnvironment();
        $this->assertInstanceOf(ColorProfile::class, $r->colorProfile);
    }

    public function testFromEnvironmentMemoisesPerEnvironmentFingerprint(): void
    {
        $originalTerm = getenv('TERM');
        Renderer::resetEnvironmentMemoForTesting();

        try {
            $a = Renderer::fromEnvironment();
            // Unchanged environment → the identical immutable Renderer; the
            // detection ladder ran exactly once for this fingerprint.
            $this->assertSame($a, Renderer::fromEnvironment());
            // Contract leg: the memoised value matches a live detect().
            $this->assertSame(ColorProfile::detect(), $a->colorProfile);

            putenv('TERM=zz-nostalgic-terminal');
            $b = Renderer::fromEnvironment();
            // A changed environment recomputes into its own slot instead of
            // serving the stale instance.
            $this->assertNotSame($a, $b);
            $this->assertSame($b, Renderer::fromEnvironment());

            if ($originalTerm === false) {
                putenv('TERM');
            } else {
                putenv("TERM={$originalTerm}");
            }
            // The original key survived the unrelated flip → reused.
            $this->assertSame($a, Renderer::fromEnvironment());

            // The memo is bounded: crossing the cap evicts, so a repeated
            // original environment re-memoises instead of aliasing $a.
            for ($i = 0; $i < 40; $i++) {
                putenv("TERM=zz-churn-{$i}");
                Renderer::fromEnvironment();
            }
            if ($originalTerm === false) {
                putenv('TERM');
            } else {
                putenv("TERM={$originalTerm}");
            }
            $c = Renderer::fromEnvironment();
            $this->assertNotSame($a, $c);
            $this->assertSame($c, Renderer::fromEnvironment());
        } finally {
            if ($originalTerm === false) {
                putenv('TERM');
            } else {
                putenv("TERM={$originalTerm}");
            }
            Renderer::resetEnvironmentMemoForTesting();
        }
    }

    public function testImmutability(): void
    {
        $a = Renderer::new();
        $b = $a->withColorProfile(ColorProfile::Ansi256);
        $c = $a->withHasDarkBackground(false);
        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(ColorProfile::TrueColor, $a->colorProfile);
        $this->assertTrue($a->hasDarkBackground);
    }
}
