<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Palette;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\Core\I18n\T;
use PHPUnit\Framework\TestCase;

/**
 * CP-25 — the user-facing prose extracted to lang/ must localize while the
 * three exempt surfaces (comment() whimsy, ColorProfile label()/description()
 * display identifiers) stay byte-stable under every locale.
 */
final class DescribeTranslationTest extends TestCase
{
    private string $previousLocale;

    protected function setUp(): void
    {
        $this->previousLocale = T::locale();
    }

    protected function tearDown(): void
    {
        T::setLocale($this->previousLocale);
    }

    private function paletteOn(ColorProfile $rung): Palette
    {
        return (new Palette(env: ['FORCE_COLOR' => '2']))->withProfile($rung);
    }

    public function testDescribeIsByteIdenticalToThePreExtractionSentenceUnderEnglish(): void
    {
        T::setLocale('en');
        $this->assertSame(
            'Your terminal supports ANSI 256 (256-color palette).',
            $this->paletteOn(ColorProfile::Ansi256)->describe(),
        );
    }

    public function testDescribeLocalizesTheFrameButNotTheDisplayIdentifiers(): void
    {
        T::setLocale('de');
        $this->assertSame(
            'Ihr Terminal unterstützt ANSI 256 (256-color palette).',
            $this->paletteOn(ColorProfile::Ansi256)->describe(),
            'label()/description() are exempt display identifiers — the sentence frame is what translates',
        );
    }

    public function testCommentWhimsyStaysUntranslatedUnderGerman(): void
    {
        T::setLocale('de');
        $this->assertSame('1990s fancy', $this->paletteOn(ColorProfile::Ansi256)->comment());
        $this->assertSame('naughty!', $this->paletteOn(ColorProfile::NoTTY)->comment());
    }

    public function testColorDescriptionArmsAreByteStableUnderEnglish(): void
    {
        T::setLocale('en');
        $arms = [
            [Capability::TrueColor->value, '24-bit TrueColor (16.7 million colors)'],
            [Capability::Color256->value, '256-color ANSI'],
            [Capability::Color16->value, '16-color ANSI'],
            [Capability::NoColor->value, 'No color support'],
        ];
        foreach ($arms as [$cap, $expected]) {
            $this->assertSame($expected, (new ProbeReport([$cap => 'test:cp25']))->colorDescription(), $cap);
        }
        $this->assertSame('Basic ASCII', (new ProbeReport([]))->colorDescription());
    }

    public function testColorDescriptionLocalizesUnderGerman(): void
    {
        T::setLocale('de');
        $this->assertSame(
            '24-Bit TrueColor (16,7 Millionen Farben)',
            (new ProbeReport([Capability::TrueColor->value => 'test:cp25']))->colorDescription(),
        );
        $this->assertSame(
            'Keine Farbunterstützung',
            (new ProbeReport([Capability::NoColor->value => 'test:cp25']))->colorDescription(),
        );
        $this->assertSame('Einfaches ASCII', (new ProbeReport([]))->colorDescription());
    }
}
