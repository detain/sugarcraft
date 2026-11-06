<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * Pins for the environment chain behind ColorMode::profile().
 *
 * Before this class existed, every renderer hardcoded ColorProfile::TrueColor
 * (finding A3): these pins are the red-then-green discriminators — under the
 * old code NO_COLOR and TERM=dumb changed nothing, and there was no override
 * seam at all.
 *
 * Finding M-7 additionally pins the chain to answer TERM=dumb EXACTLY as
 * ColorProfile::detect() does and folds detect's BSD CLICOLOR opt-out and
 * CLICOLOR_FORCE/FORCE_COLOR overrides into it, so the library has ONE
 * capability authority instead of two chains that disagree.
 */
final class ColorModeTest extends TestCase
{
    /**
     * Every environment key either chain (or ColorProfile::detect()) consults
     * before it can answer, so the identity and divergence pins below run
     * against a hermetic environment instead of the ambient one.
     *
     * @var array<string, string|null> key => neutral value (null = unset)
     */
    private const CONTROLLED = [
        'NO_COLOR' => null,
        'TERM' => 'xterm-256color',
        'CLICOLOR' => null,
        'CLICOLOR_FORCE' => null,
        'FORCE_COLOR' => null,
        'COLORTERM' => null,
        'TERM_PROGRAM' => null,
        'WT_SESSION' => null,
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::CONTROLLED as $key => $neutral) {
            $this->saved[$key] = getenv($key);
            putenv($neutral === null ? $key : $key . '=' . $neutral);
        }
        ColorMode::resetForTesting();
    }

    protected function tearDown(): void
    {
        ColorMode::resetForTesting();
        foreach (array_keys(self::CONTROLLED) as $key) {
            $prior = $this->saved[$key] ?? false;
            putenv($prior === false ? $key : $key . '=' . $prior);
        }
    }

    public function testDefaultCapabilityIsTrueColor(): void
    {
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());
    }

    public function testNoColorOfAnyNonEmptyValueDisablesColor(): void
    {
        putenv('NO_COLOR=1');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());

        // The spec is presence-with-a-value, not truthiness: "0" still disables.
        putenv('NO_COLOR=0');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
    }

    public function testEmptyNoColorDoesNotDisableColor(): void
    {
        putenv('NO_COLOR=');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());
    }

    public function testDumbTerminalCollapsesToPlainText(): void
    {
        putenv('TERM=dumb');
        ColorMode::resetForTesting();
        // Finding M-7: this arm used to answer Ascii while ColorProfile::detect()
        // answered NoTty — two authorities, split-brain (the M-33 lesson
        // repeated). The chain now answers NoTty: no colour AND no SGR
        // decoration, exactly what a dumb line can carry.
        self::assertSame(ColorProfile::NoTty, ColorMode::profile());
    }

    public function testDumbTerminalMatchesTheDetectionChain(): void
    {
        // The split-brain keystone (finding M-7): with $stdout = null
        // ColorProfile::detect() resolves purely from the environment, so
        // under an identical environment the two chains must not diverge.
        putenv('TERM=dumb');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::detect(null, null), ColorMode::profile());
        self::assertSame(ColorProfile::NoTty, ColorMode::profile());

        // Order parity too: detect() weighs TERM=dumb above NO_COLOR, so the
        // pair must still agree with both set.
        putenv('NO_COLOR=1');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::detect(null, null), ColorMode::profile());
        self::assertSame(ColorProfile::NoTty, ColorMode::profile());
    }

    public function testNoColorPresenceSemanticsDivergeDeliberately(): void
    {
        // Documented, not accidental: the chain honours the no-color.org
        // convention (NO_COLOR present with ANY value disables, including
        // "0"), while ColorProfile::detect() applies generic truthiness.
        putenv('NO_COLOR=0');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
        self::assertSame(ColorProfile::Ansi256, ColorProfile::detect(null, null));
    }

    public function testClicolorZeroOptsOut(): void
    {
        // BSD convention folded in from detect() (finding M-7): CLICOLOR=0
        // disables colour even without NO_COLOR.
        putenv('CLICOLOR=0');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
    }

    public function testForceBeatsTheOptOuts(): void
    {
        // Same forcing convention as detect(): a truthy CLICOLOR_FORCE or
        // FORCE_COLOR skips the opt-out arms entirely.
        putenv('CLICOLOR=0');
        putenv('CLICOLOR_FORCE=1');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());

        putenv('FORCE_COLOR=1');
        putenv('TERM=dumb');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());

        putenv('NO_COLOR=1');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());
    }

    public function testExplicitOverrideBeatsTheEnvironment(): void
    {
        putenv('NO_COLOR=1');
        ColorMode::use(ColorProfile::Ansi256);
        self::assertSame(ColorProfile::Ansi256, ColorMode::profile());

        ColorMode::use(null);
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
    }

    public function testAnswerIsMemoisedUntilReset(): void
    {
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile());
        putenv('NO_COLOR=1');
        self::assertSame(ColorProfile::TrueColor, ColorMode::profile(), 'mid-process env flips must not desync repaints');
        ColorMode::resetForTesting();
        self::assertSame(ColorProfile::Ascii, ColorMode::profile());
    }

    public function testEgressActuallyDegradesUnderNoColor(): void
    {
        $orange = Color::hex('#ff8000');
        self::assertStringContainsString('38;2;', $orange->toFg(ColorMode::profile()));

        putenv('NO_COLOR=1');
        ColorMode::resetForTesting();
        self::assertStringNotContainsString('38;2;', $orange->toFg(ColorMode::profile()));
    }
}
