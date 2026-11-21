<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\Image\Mosaic;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;

/**
 * P1-13b: a Kitty claim from TERM=xterm-kitty alone is a hypothesis. The
 * terminal must answer the APC `a=q` query (sugar-image's Detect::probe())
 * before RendererFactory selects or accepts Mode::Kitty, the same contract
 * Mosaic::auto() keeps. Without it a seat that never replies is handed APC
 * frames it cannot render.
 *
 * The live probe needs a tty, so the pins script its answer through
 * setKittyGateForTesting() and restore TERM and the memo in tearDown.
 */
final class RendererFactoryKittyConfirmationTest extends TestCase
{
    /** @var string|false */
    private $termSnapshot = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->termSnapshot = getenv('TERM');
        RendererFactory::setDiagnoseForTesting(null);
        RendererFactory::setKittyGateForTesting(null);
    }

    protected function tearDown(): void
    {
        if ($this->termSnapshot === false) {
            putenv('TERM');
        } else {
            putenv('TERM=' . $this->termSnapshot);
        }
        RendererFactory::setDiagnoseForTesting(null);
        RendererFactory::setKittyGateForTesting(null);

        parent::tearDown();
    }

    public function testWithKittyConfirmationDropsAnUnconfirmedClaimAndKeepsTheRest(): void
    {
        $report = new ProbeReport([
            Capability::Sixel->value => 'env:TERM=foot',
            Capability::KittyKeyboard->value => 'env:TERM=xterm-kitty',
            Capability::ITerm2->value => 'env:TERM_PROGRAM=iTerm.app',
        ]);

        $out = RendererFactory::withKittyConfirmation($report, false);

        self::assertFalse($out->has(Capability::KittyKeyboard));
        self::assertTrue($out->has(Capability::Sixel));
        self::assertTrue($out->has(Capability::ITerm2));
        self::assertSame('env:TERM=foot', $out->source(Capability::Sixel));
        self::assertSame($report->detectedAt, $out->detectedAt);
    }

    public function testWithKittyConfirmationKeepsAConfirmedClaim(): void
    {
        $report = new ProbeReport([Capability::KittyKeyboard->value => 'env:TERM=xterm-kitty']);

        self::assertSame($report, RendererFactory::withKittyConfirmation($report, true));
    }

    public function testWithKittyConfirmationLeavesAReportWithoutKittyUntouched(): void
    {
        $report = new ProbeReport([Capability::Sixel->value => 'env:TERM=foot']);

        self::assertSame($report, RendererFactory::withKittyConfirmation($report, false));
    }

    public function testDiagnoseRefusesATermOnlyKittyClaimTheTerminalNeverConfirmed(): void
    {
        putenv('TERM=xterm-kitty');
        // Premise: the colorprofile table alone does claim Kitty from TERM,
        // so the refusal below is the confirmation at work, not a missing claim.
        self::assertTrue(Mosaic::diagnose()->has(Capability::KittyKeyboard));

        RendererFactory::setKittyGateForTesting(static fn (): bool => false);

        self::assertFalse(RendererFactory::diagnose()->has(Capability::KittyKeyboard));
        self::assertFalse(RendererFactory::supports(Mode::Kitty));
        self::assertNotSame(Mode::Kitty, RendererFactory::autoMode());
    }

    public function testDiagnoseKeepsAKittyClaimTheTerminalConfirmed(): void
    {
        putenv('TERM=xterm-kitty');
        RendererFactory::setKittyGateForTesting(static fn (): bool => true);

        self::assertTrue(RendererFactory::diagnose()->has(Capability::KittyKeyboard));
        self::assertTrue(RendererFactory::supports(Mode::Kitty));
    }

    public function testDiagnoseSkipsTheProbeWhenThereIsNoKittyClaim(): void
    {
        putenv('TERM=xterm-256color');
        // Premise: no Kitty claim exists for this seat, so the confirming probe
        // must never run; the gate throws if it is asked.
        self::assertFalse(Mosaic::diagnose()->has(Capability::KittyKeyboard));

        RendererFactory::setKittyGateForTesting(static function (): bool {
            throw new \LogicException('the Kitty probe ran without a Kitty claim');
        });

        self::assertFalse(RendererFactory::diagnose()->has(Capability::KittyKeyboard));
    }
}
