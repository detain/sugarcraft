<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\Overlay\{Position, Veil};
use PHPUnit\Framework\TestCase;

/**
 * M6 (docs-fix-1023) — trust boundary. The compositor is marker-sensitive:
 * U+E000/U+E001 zone sentinels inside painted content become REAL hit-test
 * zones and OSC 8 sequences become live hyperlinks. withContent()/
 * composite() pass text through verbatim (this lib's own mark() zones
 * legitimately ride the same bytes), so untrusted payloads must arrive
 * swept; withUntrustedContent() applies sugar-core's
 * Sanitize::untrustedForMarkedFrames() law at the stack-content boundary.
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
}
