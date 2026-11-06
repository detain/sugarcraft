<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Alert, Position, Toast, ToastType};

/**
 * Pins the accessibility environment doors.
 *
 * Before this pass REDUCE_MOTION changed nothing — an SGR 5 smuggled in via
 * untrusted message text reached the wire as blink — and NO_COLOR / CLICOLOR
 * were ignored, so a colour-blind or low-contrast host got byte-identical
 * truecolor. Both gates are ENV-ONLY (toast is a compositing library; it
 * never queries a TTY, its host prints) and fleet-idiom truthiness
 * (set && !== '' && !== '0'; CLICOLOR_FORCE overrides NO_COLOR).
 *
 * Every test snapshots and restores the five variables it touches so the
 * suite is order-independent on hosts that DO carry these in the parent env.
 */
final class ToastMotionAndColorEnvTest extends TestCase
{
    private const ENV_VARS = ['NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'REDUCE_MOTION', 'PREFERS_REDUCED_MOTION'];

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        $this->envSnapshot = [];
        foreach (self::ENV_VARS as $name) {
            $this->envSnapshot[$name] = \getenv($name);
            \putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private function frame(string $message): string
    {
        return Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Info, $message)
            ->view('', 60, 8);
    }

    public function testBaselineCarriesColourAndBlink(): void
    {
        // Positive control: without the env the wire keeps the Info icon's
        // truecolor and a smuggled blink — the pins below would pass
        // vacuously against a renderer that never styled anything.
        $this->assertStringContainsString("\x1b[0;38;2;0;0;238m", $this->frame('x'));
        $this->assertStringContainsString("\x1b[0;5m", $this->frame("\x1b[5mBLINK\x1b[0m"));
    }

    public function testReduceMotionSuppressesBlinkButKeepsTheText(): void
    {
        \putenv('REDUCE_MOTION=1');
        $out = $this->frame("\x1b[5mBLINK\x1b[0m");
        $this->assertStringNotContainsString("\x1b[0;5m", $out, 'blink reached the wire against REDUCE_MOTION');
        $this->assertStringContainsString('BLINK', $out, 'containment must not be silent text loss');
    }

    public function testPrefersReducedMotionIsHonouredToo(): void
    {
        \putenv('PREFERS_REDUCED_MOTION=1');
        $this->assertStringNotContainsString("\x1b[0;5m", $this->frame("\x1b[5mBLINK\x1b[0m"));
    }

    public function testReduceMotionKeepsNonMotionStyling(): void
    {
        // Blink is the motion; bold/underline/etc. survive the flag.
        \putenv('REDUCE_MOTION=1');
        $out = $this->frame("\x1b[1;5mMIX\x1b[0m");
        $this->assertStringContainsString("\x1b[0;1m", $out, 'bold must survive reduced motion');
        $this->assertDoesNotMatchRegularExpression('/[;0]5m/', $out, 'no blink emission may survive');
    }

    public function testReduceMotionZeroIsNotSuppression(): void
    {
        // Fleet idiom (documented NO_COLOR-spec divergence): an explicit
        // "0" means the user turned the preference OFF.
        \putenv('REDUCE_MOTION=0');
        $this->assertStringContainsString("\x1b[0;5m", $this->frame("\x1b[5mBLINK\x1b[0m"));
    }

    public function testNoColorSuppressesRendererColourAndKeepsGlyphs(): void
    {
        \putenv('NO_COLOR=1');
        $out = $this->frame('plain');
        $this->assertStringNotContainsString('38;2;', $out, 'truecolor survived NO_COLOR');
        $this->assertStringContainsString('plain', $out);
        // The box itself — borders, icon glyph, layout — still renders.
        $this->assertStringContainsString('╭', $out);
    }

    public function testNoColorSuppressesInlineMessageColourToo(): void
    {
        \putenv('NO_COLOR=1');
        $out = $this->frame("\x1b[31mRED\x1b[0m");
        $this->assertStringContainsString('RED', $out);
        $this->assertStringNotContainsString('38;2;', $out);
    }

    public function testNoColorKeepsAttributes(): void
    {
        // NO_COLOR is about COLOUR; the bold wire shape stays.
        \putenv('NO_COLOR=1');
        $this->assertStringContainsString("\x1b[0;1m", $this->frame("\x1b[1mB\x1b[0m"));
    }

    public function testNoColorZeroIsNotSuppressionFleetIdiom(): void
    {
        \putenv('NO_COLOR=0');
        $this->assertStringContainsString('38;2;', $this->frame('x'));
    }

    public function testClicolorZeroSuppresses(): void
    {
        \putenv('CLICOLOR=0');
        $this->assertStringNotContainsString('38;2;', $this->frame('x'));
    }

    public function testClicolorForceOverridesNoColor(): void
    {
        \putenv('NO_COLOR=1');
        \putenv('CLICOLOR_FORCE=1');
        $this->assertStringContainsString('38;2;', $this->frame('x'));
    }

    public function testCustomAlertColoursAreSuppressedToo(): void
    {
        // A host-pinned backgroundColor is colour output as much as the
        // severity palette is — NO_COLOR wins over author intent.
        $alert = (new Alert(ToastType::Info, 'styled'))
            ->withBackgroundColor(\SugarCraft\Core\Util\Color::hex('#102030'))
            ->withBorderColor(\SugarCraft\Core\Util\Color::hex('#ABCDEF'));
        $build = static fn(): string => Toast::new(60)
            ->withPosition(Position::TopLeft)
            ->push($alert)
            ->view('', 60, 8);

        \putenv('NO_COLOR');
        $withColour = $build();
        $this->assertStringContainsString('48;2;', $withColour, 'probe: custom bg must be on the wire pre-suppression');

        \putenv('NO_COLOR=1');
        $this->assertStringNotContainsString('48;2;', $build());
    }
}
