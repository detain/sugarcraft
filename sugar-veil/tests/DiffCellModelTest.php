<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Veil\Animation\AnimationKind;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\RenderSession;
use SugarCraft\Veil\Veil;
use SugarCraft\Veil\VeilStack;

/**
 * Pins for the audit fix wave: the terminal-faithful diff cell model
 * (width-aware cells, OSC/SGR pens, zero-column zone sentinels), the
 * negative-x composite clip, the VeilStack content channel, and the
 * immutability/flag hygiene on Veil + RenderSession.
 */
final class DiffCellModelTest extends TestCase
{
    private const PAD_BG = "background line padding here\n";

    public function testWideGlyphDeltaUsesRealTerminalColumns(): void
    {
        $bg = str_repeat(self::PAD_BG, 6);

        $veil = Veil::new();
        $veil->composite("日本語テスト\nコンポジット", $bg, Position::CENTER, Position::CENTER);
        $delta = $veil->composite("日本語テスト\nXンポジット", $bg, Position::CENTER, Position::CENTER);

        // X replaces the width-2 head at display column 8 (CUP is 1-based).
        $this->assertStringStartsWith("\e[4;9HX", $delta, 'wide-head change must be addressed at the real terminal column');
        // The pre-fix width-blind model shifted every following glyph one
        // phantom cell left and sprayed the background tail to compensate.
        $this->assertStringNotContainsString('ding here', $delta, 'corrupted width-blind spray must not return');
    }

    public function testOsc8HyperlinkConsumesZeroColumnsAndLaterUpdateSurvives(): void
    {
        $bg = str_repeat(self::PAD_BG, 6);
        $link = "\e]8;;http://example.com/a\e\\LINK\e]8;;\e\\ tail";

        $veil = Veil::new();
        $full = $veil->composite($link . "\nEND", $bg, Position::TOP, Position::LEFT);
        $row0 = Width::truncateAnsi($full, 80);
        $this->assertStringContainsString('LINK', $row0);
        $this->assertLessThanOrEqual(
            28,
            Width::string(\explode("\n", $full)[0]),
            'the OSC-8 markup must not consume background columns',
        );

        // The frame after a link must still diff: END -> ENS at column 2.
        $delta = $veil->composite($link . "\nENS", $bg, Position::TOP, Position::LEFT);
        $this->assertSame("\e[2;3HS", $delta, 'update after a hyperlink must land at the real column');
    }

    public function testStyleOnlyFrameChangeEmitsDelta(): void
    {
        $bg = str_repeat(self::PAD_BG, 6);

        $veil = Veil::new();
        $veil->composite("ab", $bg, Position::TOP, Position::LEFT);
        $delta = $veil->composite("\e[1mab\e[0m", $bg, Position::TOP, Position::LEFT);

        $this->assertNotSame('', $delta, 'an SGR-only change must be visible to the diff');
        $this->assertStringContainsString("\e[", $delta);
        $this->assertStringContainsString('ab', $delta);
    }

    public function testLinkSwapAloneEmitsDelta(): void
    {
        $bg = str_repeat(self::PAD_BG, 6);

        $veil = Veil::new();
        $veil->composite("\e]8;;http://a.example\e\\WORD\e]8;;\e\\", $bg, Position::TOP, Position::LEFT);
        $delta = $veil->composite("\e]8;;http://b.example\e\\WORD\e]8;;\e\\", $bg, Position::TOP, Position::LEFT);

        $this->assertNotSame('', $delta, 'a URL-only change must be visible to the diff');
        $this->assertStringContainsString('b.example', $delta);
    }

    public function testBackdropOpacityChangeAloneProducesDelta(): void
    {
        $bg = str_repeat(self::PAD_BG, 6);

        $veil = Veil::new()->withBackdrop(0);
        $veil->composite("X", $bg, Position::TOP, Position::LEFT);
        $delta = $veil->withBackdrop(60)->composite("X", $bg, Position::TOP, Position::LEFT);

        $this->assertNotSame('', $delta, 'an opacity-only frame change must not diff to empty');
        $this->assertStringContainsString('38;2;', $delta, 'the delta must carry the new dim pen');
    }

    public function testZoneSentinelsOccupyZeroColumnsInDiffModel(): void
    {
        $bg = "aaaaaaaaaaaa\nbbbbbbbbbbbb";
        $mark = new Mark();

        // A marked overlay must diff byte-identically to the same overlay
        // unmarked: sentinels + ids are invisible AND zero-column.
        $plain = Veil::new();
        $plain->composite("MODAL", $bg, Position::TOP, Position::LEFT);

        $marked = Veil::new();
        $marked->composite($mark->wrap('veil:1', 'MODAL'), $bg, Position::TOP, Position::LEFT);

        $this->assertSame(
            $plain->composite("NODAL", $bg, Position::TOP, Position::LEFT),
            $marked->composite($mark->wrap('veil:1', 'NODAL'), $bg, Position::TOP, Position::LEFT),
            'the marked delta must equal the plain delta byte-for-byte',
        );
    }

    public function testNegativeXClipsForegroundHead(): void
    {
        // 7-wide fg centered on a 4-wide bg ⇒ x = floor((4-7)/2) = -2.
        $out = Veil::new()->composite("1234567", "abcd\nefgh", Position::TOP, Position::CENTER);
        $lines = \explode("\n", $out);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(4, Width::string($line), 'no row may exceed the background width');
        }
        $this->assertSame("3456", $lines[0], 'the off-screen head must be clipped, tail never duplicated');
        $this->assertSame("efgh", $lines[1]);
    }

    public function testFullyOffScreenLeftShowsNoForeground(): void
    {
        $out = Veil::new()->composite("OFF", "abcd", Position::TOP, Position::LEFT, xOffset: -10);

        $this->assertSame("abcd", Width::truncateAnsi($out, 80), 'negative-x overflow must not leak the foreground');
        $this->assertSame(4, Width::string($out));
        $this->assertStringNotContainsString('OFF', $out);
    }

    public function testStackCompositesContentOverDimLayer(): void
    {
        $bg = "................\n................\n................";

        $canvas = VeilStack::new()
            ->add(Veil::new()->withZIndex(0)->withBackdrop(50))
            ->add(Veil::new()->withZIndex(10)->withContent("MODAL"))
            ->composite($bg, Position::TOP, Position::LEFT);

        $lines = \explode("\n", $canvas);

        // z=0 dims everything, then z=10 paints its own content over the
        // accumulated canvas: row 0 carries the bright modal head plus the
        // still-dimmed tail; rows below stay fully dimmed.
        $this->assertStringContainsString('MODAL', $lines[0]);
        $this->assertStringNotContainsString('38;2;', \substr($lines[0], 0, 5), 'the overlay itself is not dimmed');
        $this->assertStringContainsString('38;2;128;128;128', $lines[0], 'the tail behind the modal is dimmed');
        $this->assertStringContainsString('38;2;', $lines[2], 'uncovered rows keep the dim layer');
    }

    public function testStackCompositeAllLayersPerVeilPositions(): void
    {
        $bg = "....................\n....................\n....................";

        $canvas = VeilStack::new()
            ->add(Veil::new()->withZIndex(0)->withContent("AAAA")->withPosition(Position::TOP, Position::LEFT, x: 0, y: 0))
            ->add(Veil::new()->withZIndex(5)->withContent("BBBB")->withPosition(Position::BOTTOM, Position::RIGHT, x: 0, y: 0))
            ->compositeAll($bg);

        $lines = \explode("\n", $canvas);

        $this->assertStringStartsWith('AAAA', Width::truncateAnsi($lines[0], 80));
        $this->assertStringContainsString('BBBB', Width::truncateAnsi($lines[2], 80));
    }

    public function testDimLineSurvivesLoneEscapeLine(): void
    {
        // A background row that is exactly one ESC byte used to read
        // $line[1] unguarded → "Uninitialized string offset" warning,
        // which failOnWarning turns red.
        $out = Veil::new()->withBackdrop(100)->composite("X", "\e\nplain", Position::TOP, Position::LEFT);

        $this->assertIsString($out);
        $this->assertStringContainsString('38;2;0;0;0', $out, 'the plain row is still dimmed');
    }

    public function testContentDefaultsEmptyAndSurvivesCloneChain(): void
    {
        $veil = Veil::new()->withContent("BOX")->withBackdrop(30)->withoutSession();

        $this->assertSame('BOX', $veil->content());
        $this->assertSame('', Veil::new()->content());
    }

    public function testResetClearsTransientSessionFlags(): void
    {
        $session = new RenderSession();
        $factory = static fn(string $o, int $w, int $h) => \SugarCraft\Buffer\Buffer::fromString($o, $w, $h);

        $session->rememberFull("frame one", 9, 1, );
        $session->diff("frame two", 9, 1, $factory);
        $session->rememberFull("frame two", 9, 1);
        // rememberFull after a diff armed justClearedFrame; reset must drop it.
        $session->reset();

        $session->diff("frame three", 9, 1, $factory); // primes previous state
        $this->assertFalse(
            $session->shouldEmitFull(9, 1),
            'a reset() session must not hand out a stale one-shot full-frame grant',
        );
    }

    public function testScanDoesNotRetroChangeSourceInstance(): void
    {
        $veil = Veil::new()->withClickOutsideDismiss();

        $zoneA = $veil->scan($veil->mark('alpha-zone', 'XYZ'));
        $zoneB = $veil->scan($veil->mark('beta-zone', 'XYZ'));

        // Scanning the clone for beta must not retro-change alpha's hit
        // state: each scan() carries its own scanner (shared-instance scans
        // used to leave BOTH clones answering with whichever ran last).
        $this->assertSame('alpha-zone', $zoneA->hit(1, 1)?->id);
        $this->assertSame('beta-zone', $zoneB->hit(1, 1)?->id);
    }

    public function testWithersAcceptNullToClear(): void
    {
        $base = Veil::new()
            ->withAnimation(AnimationKind::SLIDE)
            ->withBorder(Border::normal())
            ->withPosition(Position::TOP, Position::RIGHT, x: 4, y: 2);

        $cleared = $base
            ->withAnimation(null)
            ->withBorder(null)
            ->withPosition(null, null);

        $this->assertNull($cleared->border());
        $this->assertNull($cleared->vPosition());
        $this->assertNull($cleared->hPosition());
        $this->assertSame(0, $cleared->positionX());
        $this->assertSame(0, $cleared->positionY());
        // Base is untouched (immutability law).
        $this->assertSame(Position::TOP, $base->vPosition());

        // An animation-less veil no longer offsets the overlay mid-progress
        // (fresh sessions per call: composite/animate share the diff state).
        $bg = "bbbbbbbbbbbb\nbbbbbbbbbbbb\nbbbbbbbbbbbb";
        $this->assertSame(
            $cleared->withoutSession()->composite("SLID", $bg, Position::TOP, Position::LEFT),
            $cleared->withoutSession()->animate("SLID", $bg, Position::TOP, Position::LEFT, 0.0),
            'withAnimation(null) removes the slide effect',
        );
        // While the SLIDE-carrying veil is still fully off-screen at progress 0.
        $this->assertStringNotContainsString(
            'SLID',
            $base->withoutSession()->animate("SLID", $bg, Position::TOP, Position::LEFT, 0.0),
            'an animated veil must start off-screen',
        );
    }

    public function testSingleCharacterDeltaIsTinyOverAn80By24Viewport(): void
    {
        $bg = \implode("\n", \array_fill(0, 24, \str_repeat('.', 80)));

        $veil = Veil::new();
        $veil->composite("X", $bg, Position::TOP, Position::LEFT);
        $delta = $veil->composite("Y", $bg, Position::TOP, Position::LEFT);

        $this->assertLessThanOrEqual(8, \strlen($delta), 'one-character change must ship as a handful of bytes, not a repaint');
        $this->assertStringNotContainsString("\n", $delta);
    }
}
