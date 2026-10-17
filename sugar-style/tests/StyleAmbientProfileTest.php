<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;

/**
 * The process-ambient default profile (rv-devops-1 / candy-code colour
 * negotiation): {@see Style::new()} mints styles on the ambient profile when
 * a host application has negotiated one, and on TrueColor otherwise. The
 * resting state MUST stay byte-identical for the ~50 libraries that never
 * call setDefaultProfile(), and an explicit per-instance profile always
 * outranks the ambient.
 */
final class StyleAmbientProfileTest extends TestCase
{
    protected function tearDown(): void
    {
        Style::setDefaultProfile(null);
        parent::tearDown();
    }

    public function testRestingAmbientIsNullAndKeepsTrueColorBytes(): void
    {
        self::assertNull(Style::getDefaultProfile());

        self::assertSame(
            "\x1b[38;2;255;0;0mhi\x1b[0m",
            Style::new()->foreground(Color::hex('#ff0000'))->render('hi'),
        );
    }

    public function testAmbientProfileAppliesToStylesMintedAfterwards(): void
    {
        Style::setDefaultProfile(ColorProfile::Ansi256);
        self::assertSame(ColorProfile::Ansi256, Style::getDefaultProfile());

        $out = Style::new()->foreground(Color::hex('#ff0000'))->render('hi');

        self::assertStringContainsString('38;5;', $out);
        self::assertStringNotContainsString('38;2;', $out);
    }

    public function testExplicitInstanceProfileBeatsAmbient(): void
    {
        Style::setDefaultProfile(ColorProfile::Ansi256);

        $out = Style::new()
            ->colorProfile(ColorProfile::TrueColor)
            ->foreground(Color::hex('#ff0000'))
            ->render('hi');

        self::assertSame("\x1b[38;2;255;0;0mhi\x1b[0m", $out);
    }

    public function testStylesCarriedFromBeforeTheNegotiationKeepTheirOwnProfile(): void
    {
        $before = Style::new()->foreground(Color::hex('#ff0000'));

        Style::setDefaultProfile(ColorProfile::Ascii);

        // with*()/bold() rebuild through `new self(profile: $this->profile)`,
        // so a pre-negotiation instance never drifts onto the ambient.
        self::assertSame(ColorProfile::TrueColor, $before->bold()->getColorProfile());
        self::assertStringContainsString('38;2;255;0;0', $before->bold()->render('hi'));
    }

    public function testSettingNullAmbientRestoresTheTrueColorDefault(): void
    {
        Style::setDefaultProfile(ColorProfile::Ansi256);
        Style::setDefaultProfile(null);

        self::assertNull(Style::getDefaultProfile());
        self::assertSame(
            "\x1b[38;2;255;0;0mhi\x1b[0m",
            Style::new()->foreground(Color::hex('#ff0000'))->render('hi'),
        );
    }
}
