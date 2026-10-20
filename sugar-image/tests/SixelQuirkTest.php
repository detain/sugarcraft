<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Capability;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\TermDb;
use SugarCraft\Image\TermInfo;

/**
 * F-02: terminal quirks consumption — the SIXEL_OVERSHOOT parking verdict
 * and the MLTERM_EDGE_ROWS re-home ride the TermDb chain into the Sixel
 * renderer and the Capability snapshot.
 *
 * Byte pins mirror chicle ground truth (tools/chafa/chafa.c:445-451): a
 * sixel poster ends with a parking newline unless the terminal chain is
 * known to overshoot; an unprobed chain (null quirks) takes neither
 * branch, which is what every pre-F-02 golden captured with.
 */
final class SixelQuirkTest extends TestCase
{
    private const ST = "\x1b\\";

    public function testUnprobedRendererTakesNoParkingDecision(): void
    {
        $renderer = new SixelRenderer(Dither::None);

        $this->assertNull($renderer->quirks());
        $this->assertSame('', $renderer->parkingNewline());
        $this->assertFalse($renderer->hasQuirk(TermInfo::QUIRK_SIXEL_OVERSHOOT));
    }

    public function testKnownNonOvershootTerminalEarnsTheParkingNewline(): void
    {
        $renderer = (new SixelRenderer(Dither::None))->withQuirks(TermInfo::QUIRK_NONE);

        $this->assertSame("\n", $renderer->parkingNewline());

        $out = $renderer->render($this->redBlock(), 1, 1);
        // Chicle order (chafa.c:445-451): ST first, then the vertical space.
        $this->assertStringEndsWith(self::ST . "\n", $out);
    }

    public function testOvershootQuirkSuppressesTheParkingNewline(): void
    {
        $renderer = (new SixelRenderer(Dither::None))
            ->withQuirks(TermInfo::QUIRK_SIXEL_OVERSHOOT);

        $this->assertSame('', $renderer->parkingNewline());
        $this->assertTrue($renderer->hasQuirk(TermInfo::QUIRK_SIXEL_OVERSHOOT));

        $out = $renderer->render($this->redBlock(), 1, 1);
        // The quirky terminal already parked the cursor a row below itself;
        // writing another newline would scroll the poster away (chicle #255).
        $this->assertStringEndsWith(self::ST, $out);
    }

    public function testStreamedBandsParkExactlyOnceAfterTheFinalTerminator(): void
    {
        $renderer = (new SixelRenderer(Dither::None))
            ->withQuirks(TermInfo::QUIRK_NONE);

        $chunks = [];
        $renderer->encodeBandStream($this->redBlock(16, 40), 16, static function (string $c) use (&$chunks): void {
            $chunks[] = $c;
        });

        $stream = implode('', $chunks);
        // Park is part of the success stream and the concatenation law:
        // streamed bytes equal the one-shot render.
        $this->assertSame($renderer->render($this->redBlock(16, 40), 16), $stream);
        $this->assertStringEndsWith(self::ST . "\n", $stream);
        $this->assertSame(0, substr_count($stream, "\n" . "\x1bP"), 'park closes the poster — no band header follows it');
    }

    public function testMltermEdgeRowsBitArmsTheEdgeForcingIdentically(): void
    {
        $blank = $this->fullyTransparent();

        // The quirks bit must reach the SAME bytes the F-03 manual flag
        // produced — the re-home changes the source of truth, not output.
        // quirks=3 (mlterm chain): edge forcing from the MLTERM bit, park
        // suppressed by the OVERSHOOT bit — same bytes as the manual arm.
        $viaQuirk = (new SixelRenderer(Dither::None))
            ->withQuirks(TermInfo::QUIRK_SIXEL_OVERSHOOT | TermInfo::QUIRK_MLTERM_EDGE_ROWS)
            ->render($blank, 2, 2);
        $viaFlag = (new SixelRenderer(Dither::None))
            ->withQuirks(TermInfo::QUIRK_SIXEL_OVERSHOOT)
            ->withMltermEdgeRows(true)
            ->render($blank, 2, 2);

        $this->assertSame($viaFlag, $viaQuirk);
        $this->assertNotSame(
            (new SixelRenderer(Dither::None))->render($blank, 2, 2),
            $viaQuirk,
        );
    }

    public function testFingerprintIsolatesEveryQuirksState(): void
    {
        $frozen = (new SixelRenderer(Dither::None))->configFingerprint();
        $parked = (new SixelRenderer(Dither::None))
            ->withQuirks(TermInfo::QUIRK_NONE)->configFingerprint();
        $overshooting = (new SixelRenderer(Dither::None))
            ->withQuirks(TermInfo::QUIRK_SIXEL_OVERSHOOT)->configFingerprint();
        $edged = (new SixelRenderer(Dither::None))
            ->withQuirks(TermInfo::QUIRK_MLTERM_EDGE_ROWS)->configFingerprint();

        $this->assertStringNotContainsString('|quirks', $frozen, 'null = frozen segment stays byte-absent');
        $this->assertStringEndsWith('|quirks=0', $parked);
        $this->assertStringEndsWith('|quirks=1', $overshooting);
        // The MLTERM bit folds into mltermEdgeRows, so the F-03 segment
        // arms too — and it must, or the cache would share its output.
        $this->assertStringContainsString('|mlterm=1', $edged);
        $this->assertStringEndsWith('|quirks=2', $edged);
        $this->assertNotSame($frozen, $parked, 'queried-nothing differs from never-queried');
    }

    public function testUnknownQuirkBitsRefuseFast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SixelRenderer(Dither::None))->withQuirks(TermInfo::ALL_QUIRKS | 4);
    }

    public function testQuirksAreCarriedAcrossEveryFluentMutation(): void
    {
        $renderer = (new SixelRenderer(Dither::None))
            ->withQuirks(TermInfo::QUIRK_NONE)
            ->withSerpentine(true)
            ->withAlphaThreshold(64);

        $this->assertSame(TermInfo::QUIRK_NONE, $renderer->quirks());
        $this->assertStringEndsWith(self::ST . "\n", $renderer->render($this->redBlock(), 1, 1));
    }

    // ─── chain surface ─────────────────────────────────────────────────────

    public function testTermDbArmsOvershootRowsPerChicleTable(): void
    {
        $cases = [
            'alacritty' => ['TERM' => 'alacritty'],
            'mintty'    => ['TERM' => 'mintty'],
            'rio'       => ['TERM' => 'rio'],
            'konsole'   => ['KONSOLE_VERSION' => '221200'],
        ];
        foreach ($cases as $name => $env) {
            $info = TermDb::matchChain($env);
            $this->assertTrue(
                $info->hasQuirk(TermInfo::QUIRK_SIXEL_OVERSHOOT),
                "chicle arms SIXEL_OVERSHOOT on {$name}",
            );
        }
    }

    public function testMltermCarriesBothBitsAndScreenCarriesNone(): void
    {
        $mlterm = TermDb::matchChain(['TERM' => 'mlterm']);
        $this->assertTrue($mlterm->hasQuirk(TermInfo::QUIRK_SIXEL_OVERSHOOT));
        $this->assertTrue($mlterm->hasQuirk(TermInfo::QUIRK_MLTERM_EDGE_ROWS));

        // chicle :786-789 gives screen the passthrough mode, no quirk bit.
        $screen = TermDb::matchChain(['TERM' => 'screen-256color']);
        $this->assertSame(TermInfo::QUIRK_NONE, $screen->quirks);
        $this->assertSame(TermInfo::PASSTHROUGH_SCREEN, $screen->passthroughMode());
    }

    public function testQuirksChainAsUnionAcrossTermAndMux(): void
    {
        // mlterm inside tmux: the tmux link adds the overshoot defect even
        // though xterm inside tmux would also overshoot — union, not
        // intersection (opposite law to safeSymbols).
        $info = TermDb::matchChain([
            'TERM' => 'xterm',
            'TERM_PROGRAM' => 'tmux',
        ]);
        $this->assertTrue($info->hasQuirk(TermInfo::QUIRK_SIXEL_OVERSHOOT));
        $this->assertSame(TermInfo::PASSTHROUGH_TMUX, $info->passthroughMode());

        // foot claims sixel and carries no quirk bit: it parks. (xterm has
        // no sixel claim in this table at all — the DA1 probe decides.)
        $plain = TermDb::matchChain(['TERM' => 'foot']);
        $this->assertSame(TermInfo::QUIRK_NONE, $plain->quirks);
        $this->assertTrue($plain->supports(TermInfo::PROTOCOL_SIXEL));
    }

    public function testTermInfoRefusesUnknownQuirkBits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TermInfo('probe', [TermInfo::PROTOCOL_SIXEL], [], null, TermInfo::ALL_QUIRKS, 8);
    }

    public function testCapabilityFactsRideBothCarriersAndValidateMode(): void
    {
        $cap = Capability::sixel()
            ->withTerminalFacts(TermInfo::QUIRK_SIXEL_OVERSHOOT, TermInfo::PASSTHROUGH_TMUX)
            ->withDefaultColors('ffffff', '000000');

        $this->assertSame(TermInfo::QUIRK_SIXEL_OVERSHOOT, $cap->quirks);
        $this->assertSame(TermInfo::PASSTHROUGH_TMUX, $cap->passthroughMode);

        $this->expectException(InvalidArgumentException::class);
        Capability::sixel()->withTerminalFacts(null, 'x11');
    }

    public function testFactlessCapabilitiesStayFrozen(): void
    {
        // The pre-F-02 constructor surface leaves quirks unconsumed —
        // byte-frozen poster paths must never gain a park by accident.
        $cap = Capability::sixel(null, true);
        $this->assertNull($cap->quirks);
        $this->assertSame(TermInfo::PASSTHROUGH_NONE, $cap->passthroughMode);
    }

    private function redBlock(int $w = 2, int $h = 2): ImageSource
    {
        $gd = imagecreatetruecolor($w, $h);
        $this->assertNotFalse($gd);
        $red = imagecolorallocate($gd, 255, 0, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($gd, $x, $y, $red);
            }
        }
        return ImageSource::fromGd($gd, 'image/png');
    }

    private function fullyTransparent(): ImageSource
    {
        $gd = imagecreatetruecolor(4, 4);
        $this->assertNotFalse($gd);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        $transparent = imagecolorallocatealpha($gd, 0, 0, 0, 127);
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                imagesetpixel($gd, $x, $y, $transparent);
            }
        }
        return ImageSource::fromGd($gd, 'image/png');
    }
}
