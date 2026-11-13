<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Position, Toast, ToastType};

/**
 * Pins the host zone-marker carry-through — the frame tearing defect:
 * of 27 sweep geometries 8 lost a host marker and 5 rows came back
 * with UNPAIRED sentinels; a TopLeft box fully erased an
 * OPEN..payload..CLOSE span on row 0.
 *
 * sugar-toast is structurally zone-NEUTRAL: it neither forges markers nor
 * depends on sugar-mouse. What it must not do is DAMAGE the host's own
 * markers when compositing. The rule now: every ORIGINAL background cell
 * inside a host zone span survives the blit, so sentinel counts stay exact
 * and row-local pairing never breaks — at the documented cosmetic cost of a
 * chipped box border where a zone ran underneath.
 */
final class ToastHostZoneCarryTest extends TestCase
{
    private const OPEN = "\xEE\x80\x80";   // U+E000
    private const CLOSE = "\xEE\x80\x81";  // U+E001

    /** @param list<string> $rows */
    private function markedBackground(array $rows): string
    {
        return \implode("\n", $rows) . "\n";
    }

    private function toast(Position $position = Position::TopLeft): Toast
    {
        return Toast::new(40)
            ->withPosition($position)
            ->alert(ToastType::Info, 'chrome over content', actions: []);
    }

    public function testZoneFullyUnderTheBoxSurvivesPaired(): void
    {
        // The a11y erasure point: OPEN+payload+CLOSE on row 0 directly under
        // a TopLeft box used to come back open=0 close=0 — APPZ gone.
        $bg = $this->markedBackground([
            self::OPEN . 'APPZ' . self::CLOSE . ' tail text',
            ...(\array_fill(1, 11, 'plain background row')),
        ]);
        $out = $this->toast()->view($bg, 80, 12);

        $this->assertSame(1, \substr_count($out, self::OPEN));
        $this->assertSame(1, \substr_count($out, self::CLOSE));
        $this->assertStringContainsString('APPZ', $out);
    }

    public function testCarryDefaultsOnAndIsByteIdenticalWhenExplicitlyEnabled(): void
    {
        // The knob must not perturb published bytes: unset == withHostZoneCarry(true).
        $bg = $this->markedBackground([
            self::OPEN . 'APPZ' . self::CLOSE . ' tail text',
            ...(\array_fill(1, 11, 'plain background row')),
        ]);
        $toast = $this->toast();

        $this->assertSame(
            $toast->view($bg, 80, 12),
            $toast->withHostZoneCarry(true)->view($bg, 80, 12)
        );
    }

    public function testOptOutBlitsOpaqueChromeAndDropsTheCoveredZone(): void
    {
        // OFF is the mirror trade: full chrome, zone span sacrificed. Every
        // zone cell here (cols 0..15 of row 0) sits under the 40-wide
        // TopLeft box, so the composited frame must be byte-identical to
        // the same frame rendered over an UNMARKED background of identical
        // cell content — no sentinels, no payload, no holes.
        $marked = $this->markedBackground([
            self::OPEN . 'APPZ' . self::CLOSE . ' tail text',
            ...(\array_fill(1, 11, 'plain background row')),
        ]);
        $plain = $this->markedBackground([
            'ABCDEFGHIJKLMNOP tail text',
            ...(\array_fill(1, 11, 'plain background row')),
        ]);
        $toast = $this->toast()->withHostZoneCarry(false);

        $off = $toast->view($marked, 80, 12);
        $this->assertSame($toast->view($plain, 80, 12), $off);
        $this->assertStringNotContainsString(self::OPEN, $off);
        $this->assertStringNotContainsString(self::CLOSE, $off);
        $this->assertStringNotContainsString('APPZ', $off);

        // And the same object with carry ON keeps the markers paired —
        // discriminative against a guard wired to the wrong branch.
        $on = $toast->withHostZoneCarry(true)->view($marked, 80, 12);
        $this->assertSame(1, \substr_count($on, self::OPEN));
        $this->assertSame(1, \substr_count($on, self::CLOSE));
    }

    public function testSpanStraddlingTheBoxEdgeStaysPaired(): void
    {
        // TopRight at 20 cols clipped spans mid-way — the consolidator's
        // dangling-open shape (open=1 close=0 on rows 0-2).
        $rows = [];
        for ($r = 0; $r < 12; $r++) {
            $rows[] = 'xx' . self::OPEN . 'z' . $r . self::CLOSE . \str_repeat('.', 20);
        }
        $out = $this->toast(Position::TopRight)->view($this->markedBackground($rows), 20, 12);

        $this->assertSame(12, \substr_count($out, self::OPEN));
        $this->assertSame(12, \substr_count($out, self::CLOSE));
        foreach (\explode("\n", $out) as $line) {
            $this->assertSame(
                \substr_count($line, self::OPEN),
                \substr_count($line, self::CLOSE),
                'row-local sentinel pairing broke'
            );
        }
    }

    public function testNinePositionGeometrySweepNeverTears(): void
    {
        // The C2 sweep: every position family x {20,40,80} columns, 12
        // marked rows at staggered columns, must preserve counts AND
        // row-local pairing (pre-fix this metric produced unpaired rows).
        foreach (Position::cases() as $position) {
            foreach ([20, 40, 80] as $cols) {
                $rows = [];
                for ($r = 0; $r < 12; $r++) {
                    $offset = ($r * 7) % \max(1, $cols - 10);
                    $rows[] = \str_repeat(' ', $offset)
                        . self::OPEN . 'zone' . $r . self::CLOSE
                        . \str_repeat('.', \max(0, $cols - $offset - 8));
                }
                $out = $this->toast($position)->view($this->markedBackground($rows), $cols, 12);

                $this->assertSame(12, \substr_count($out, self::OPEN), $position->name . "/" . $cols . " open count");
                $this->assertSame(12, \substr_count($out, self::CLOSE), $position->name . "/" . $cols . " close count");
                foreach (\explode("\n", $out) as $line) {
                    $this->assertSame(
                        \substr_count($line, self::OPEN),
                        \substr_count($line, self::CLOSE),
                        $position->name . "/" . $cols . " tore a row"
                    );
                }
            }
        }
    }

    public function testUnmarkedFramesAreByteIdentical(): void
    {
        // Zero host markers => the restore pass never fires => the composite
        // is exactly what it was before the carry-through (this is the guard
        // that keeps every golden valid).
        $bg = \str_repeat("ordinary frame line\n", 12);
        $plain = $this->toast()->view($bg, 80, 12);
        $this->assertStringNotContainsString(self::OPEN, $plain);
        $this->assertStringNotContainsString(self::CLOSE, $plain);
        $this->assertStringContainsString('chrome over content', $plain);
    }

    public function testToastMessageSentinelsAreNotDoubleCountedAsHostZones(): void
    {
        // A forged span in the toast's OWN message still renders 1|1 (trust
        // boundary), and a host span elsewhere on a box row still survives —
        // the two channels never interfere with each other's counts.
        $bg = $this->markedBackground([
            'row zero',
            self::OPEN . 'HOST' . self::CLOSE . ' under-stack neighbour',
            ...(\array_fill(2, 10, 'quiet row')),
        ]);
        $toast = Toast::new(40)
            ->withPosition(Position::TopLeft)
            ->alert(ToastType::Warning, "forged \u{e000}INBOX\u{e001} message");
        $out = $toast->view($bg, 80, 12);

        $this->assertSame(2, \substr_count($out, self::OPEN));
        $this->assertSame(2, \substr_count($out, self::CLOSE));
        $this->assertStringContainsString('HOST', $out);
        $this->assertStringContainsString('INBOX', $out);
    }
}
