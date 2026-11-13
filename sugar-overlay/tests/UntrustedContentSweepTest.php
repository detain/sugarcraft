<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\Overlay\{MarkedFrame, Position, Veil};
use PHPUnit\Framework\TestCase;

/**
 * M6 (docs-fix-1023) + C2 (lib-fixes-1103) — trust boundary. The
 * compositor is marker-sensitive: U+E000/U+E001 zone sentinels inside
 * painted content become REAL hit-test zones and OSC 8 sequences become
 * live hyperlinks. withContent()/composite() pass text through verbatim
 * (this lib's own mark() zones legitimately ride the same bytes), so
 * untrusted payloads must arrive swept. withUntrustedContent() applies
 * Sanitize::untrustedForDisplay() + stripZoneSentinels() and folds TAB
 * to its measured four cells — deliberately NOT
 * untrustedForMarkedFrames(), which KEEPS CR and TAB (a frames channel
 * re-prints them raw: cursor-repaint CR and 8-vs-4-cell TAB drift).
 */
final class UntrustedContentSweepTest extends TestCase
{
    private const OPEN  = "\u{E000}";
    private const CLOSE = "\u{E001}";

    private const BG = "..............................\n..............................\n..............................";

    public function testForgedSentinelsInTheRawChannelMintPhantomZones(): void
    {
        // Documents WHY the sweep is required: a payload that forges the
        // full marker bracket (Mark::wrap emits OPEN id CLOSE content
        // OPEN /id CLOSE) gets its own zone, scannable at real coordinates.
        $forged = self::OPEN . 'EVIL' . self::CLOSE . 'hello' . self::OPEN . '/EVIL' . self::CLOSE;
        $veil   = Veil::new();
        $frame  = $veil->composite($forged, self::BG, Position::TOP, Position::LEFT);

        $scanned = $veil->scan($frame);
        $zone    = $scanned->hit(2, 1); // scanner coordinates are 1-based

        $this->assertNotNull($zone, 'un-swept sentinels become a live zone (the hazard the sanitizer exists for)');
        $this->assertSame('EVIL', $zone->id);
    }

    public function testForgedOscEightInTheRawChannelBecomesALiveLinkPen(): void
    {
        // Same hazard on the hyperlink axis: OSC 8 bytes in the payload
        // parse into a Hyperlink pen over the following cells.
        $payload = "\e]8;;https://evil.example\e\\click\e]8;;\e\\";
        $frame   = Veil::new()->composite($payload, self::BG, Position::TOP, Position::LEFT);

        $this->assertStringContainsString("https://evil.example", $frame, 'the raw channel carries the link through verbatim');
    }

    public function testWithUntrustedContentStripsSentinelsAndControlSequences(): void
    {
        $hostile = self::OPEN . 'EVIL' . self::CLOSE
            . "report\e[31mred\e[39m"
            . "\e]8;;https://evil.example\e\\link\e]8;;\e\\";

        $veil    = Veil::new()->withUntrustedContent($hostile);
        $content = $veil->content();

        // Zone sentinels gone, SGR/OSC bytes gone, visible text kept.
        $this->assertStringNotContainsString(self::OPEN, $content);
        $this->assertStringNotContainsString(self::CLOSE, $content);
        $this->assertStringNotContainsString("\e", $content);
        $this->assertStringContainsString('EVILreportredlink', $content);
    }

    public function testSweptContentCannotMintPhantomZones(): void
    {
        $forged = self::OPEN . 'EVIL' . self::CLOSE . 'hello' . self::OPEN . '/EVIL' . self::CLOSE;
        $veil   = Veil::new()->withUntrustedContent($forged);
        $frame  = $veil->composite($veil->content(), self::BG, Position::TOP, Position::LEFT);

        $scanned = $veil->scan($frame);

        // The forged marker pair is gone: no zone answers anywhere on the
        // painted row (scanner coordinates are 1-based).
        foreach (range(1, 3) as $row) {
            foreach (range(1, 30) as $col) {
                $this->assertNull($scanned->hit($col, $row));
            }
        }
    }

    public function testLegitimateMarkZonesSurviveTheSweepWhenAppliedFirst(): void
    {
        // Positive control: the LAW is sweep-then-mark. Sanitize the
        // payload, then mark() your own buttons on top — the legitimate
        // zone stays live because mark() rides after the boundary.
        $hostileBody = self::OPEN . 'EVIL' . self::CLOSE . 'body';
        $veil        = Veil::new()->withClickOutsideDismiss(true);

        $clean   = $veil->withUntrustedContent($hostileBody)->content();
        $buttons = $veil->mark('ok', '[OK]') . ' ' . $clean;
        $frame   = $veil->composite($buttons, self::BG, Position::TOP, Position::LEFT);

        $scanned = $veil->scan($frame);
        $zone    = $scanned->hit(2, 1); // 1-based: cell inside the '[OK]' span

        $this->assertNotNull($zone);
        $this->assertSame('ok', $zone->id);
        // The swept body contributed no zone of its own.
        $this->assertNull($scanned->hit(12, 1));
    }

    public function testRawWithContentChannelIsUnchangedForBackwardCompatibility(): void
    {
        // The helper is opt-in: withContent() itself still stores verbatim
        // (marked frames must survive), which is why the README states the
        // law in bold. This pin fails loudly if a future change starts
        // silently rewriting withContent() payloads.
        $marked = $this->markedSample();
        $veil   = Veil::new()->withContent($marked);

        $this->assertSame($marked, $veil->content());
    }

    private function markedSample(): string
    {
        $veil = Veil::new();

        return $veil->mark('keep', '[Keep]');
    }

    // ─── C2 (lib-fixes-1103): CR/TAB must not survive a display sweep ──────────

    public function testCarriageReturnIsFoldedToLineFeedByTheSweep(): void
    {
        // untrustedForMarkedFrames KEEPS CR by contract (frames re-print
        // it). For DISPLAY content a bare CR is a cursor-repaint hazard:
        // width already counted the cells, then the terminal overwrites them.
        $veil = Veil::new()->withUntrustedContent("AB\rCD");
        $this->assertStringNotContainsString("\x0d", $veil->content(), 'lone CR must not survive withUntrustedContent');
        $this->assertSame("AB\nCD", $veil->content());
    }

    public function testCrlfFoldsToExactlyOneLineFeed(): void
    {
        $veil = Veil::new()->withUntrustedContent("AB\r\nCD");
        $this->assertStringNotContainsString("\x0d", $veil->content());
        $this->assertSame(1, substr_count($veil->content(), "\n"), 'CRLF must not leave a stray CR beside the LF');
    }

    public function testTabIsFoldedToItsMeasuredFourCells(): void
    {
        // Width::of("\t") reports 4, but many terminals stop at the next
        // 8-column tab stop — the measured/painted cell counts diverge and
        // every clip/anchor math downstream goes wrong. The sweep folds.
        $veil = Veil::new()->withUntrustedContent("a\tb");
        $this->assertSame('a    b', $veil->content());
        $this->assertSame(6, MarkedFrame::measure($veil->content()));
    }

    public function testImageMarkersOutsideTheZonePairSurviveTheSweep(): void
    {
        // stripZoneSentinels is surgical: only U+E000/U+E001 are removed,
        // so sugar-image's U+E002 marker channel is not eaten here.
        $content = "\u{E002}image0\u{E002}" . self::OPEN . 'forged' . self::CLOSE . 'text';
        $veil = Veil::new()->withUntrustedContent($content);
        $this->assertStringContainsString("\u{E002}", $veil->content(), 'the image-marker band must pass through untouched');
        $this->assertStringNotContainsString(self::OPEN, $veil->content());
        $this->assertStringNotContainsString(self::CLOSE, $veil->content());
    }

    public function testColonFormSgrFromReplayedTerminalOutputArmsTheRealPen(): void
    {
        // SO-06 trust-boundary clause, made observable: an agent echo is
        // EMULATOR output, and emulators honour ECMA-48 colon colors.
        // The compositor honours them the same way — the diff model must
        // carry the colon-painted cell as a colored cell, so a later
        // delta re-encodes it in canonical semicolon form. Before the
        // shared walker the malformed-looking leg was dropped and the cell
        // reached the grid on the DEFAULT pen.
        $veil = Veil::new()->withPosition(Position::TOP, Position::LEFT);
        $veil->composite("\e[38:2:255:0:0;1mRED", self::BG, Position::TOP, Position::LEFT);
        $delta = $veil->composite("\e[38:2:255:0:0;1mREX", self::BG, Position::TOP, Position::LEFT);

        $this->assertStringContainsString('X', $delta);
        $this->assertStringContainsString('38;2;255;0;0', $delta, 'the colon color reached the diff pen');
        $this->assertStringContainsString('1m', $delta, 'the bold riding after the color is kept too (sugar-overlay-005)');
    }

    public function testRawChannelShipsC0BytesWithTheFixedFourTabMeasure(): void
    {
        // SO-37 declared-contract pin (sugar-overlay-006 doc leg): on the
        // verbatim channel nothing folds — the TAB and CR bytes ship into
        // the composed frame while Width charges TAB 4 cells and CR 0.
        // The terminal disagrees (8-column stops; CR repaints column 0),
        // which is exactly why attacker-reachable text must enter through
        // withUntrustedContent(). Physical tab-stop accounting is
        // deliberately NOT modelled here: Width's fixed-4 is the fleet
        // measure, and the 8-stop truth belongs to sugar-core Width.
        $veil  = Veil::new()->withPosition(Position::TOP, Position::LEFT);
        $frame = $veil->composite("a\tb\rx", self::BG, Position::TOP, Position::LEFT);

        $this->assertStringContainsString("\t", $frame, 'the raw channel ships the TAB byte verbatim');
        $this->assertStringContainsString("\r", $frame, 'the raw channel ships the CR byte verbatim');
        $this->assertSame(7, MarkedFrame::measure("a\tb\rx"), 'TAB costs 4 measured cells, CR costs 0 — the desync the docblock names');
    }
}
