<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\Overlay\{Position, Veil};
use PHPUnit\Framework\TestCase;

/**
 * M2 (docs-fix-1023): the diff law, pinned as documented behavior. A Veil
 * remembers what it painted; same-size re-composites come back as a DIFF
 * (cursor moves + changed cells only), an unchanged re-composite comes
 * back empty, and withoutSession()/resetPreviousFrame() force full frames
 * again. Demos that echo() composite() twice on one veil used to print
 * raw escape-sequence garbage; the examples now run fresh veils and the
 * README states the law.
 */
final class CompositeDeltaLawTest extends TestCase
{
    private const BG = "AAAA\nAAAA";

    public function testUnchangedRecompositeOnTheSameSessionEmitsNothing(): void
    {
        $veil = Veil::new();
        $full = $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT);

        $this->assertSame("HiAA\nAAAA", $full);
        // Same state, same dimensions: nothing changed, nothing is sent.
        $this->assertSame('', $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT));
    }

    public function testChangedStateAtSameDimensionsEmitsADeltaNotAFullFrame(): void
    {
        $veil = Veil::new();
        $full = $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT);
        $delta = $veil->composite("Hj", self::BG, Position::TOP, Position::LEFT);

        // Only the changed cell rides the wire, addressed by cursor move.
        $this->assertStringContainsString("\e[", $delta, 'a delta addresses cells with cursor moves');
        // Strip the addressing; what is left visible is exactly the change.
        $visible = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $delta);
        $this->assertSame('j', $visible, 'unchanged cells (and the H that did not change) must not be re-sent');
        $this->assertLessThan(strlen($full), strlen($delta));
    }

    public function testWithoutSessionRestoresFullFrameEmission(): void
    {
        $veil = Veil::new();
        $full = $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT);
        $this->assertSame('', $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT));

        $again = $veil->withoutSession()->composite("Hi", self::BG, Position::TOP, Position::LEFT);
        $this->assertSame($full, $again, 'a fresh session has no memory, so the frame comes out full and byte-identical');
    }

    public function testResetPreviousFrameRestoresFullFrameEmission(): void
    {
        $veil = Veil::new();
        $full = $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT);
        $this->assertSame('', $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT));

        $veil->resetPreviousFrame();
        $this->assertSame($full, $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT));
    }

    public function testDimensionChangeAutomaticallyForcesAFullFrame(): void
    {
        $veil = Veil::new();
        $veil->composite("Hi", self::BG, Position::TOP, Position::LEFT);

        $grown = $veil->composite("Hi", "AAAA\nAAAA\nAAAA", Position::TOP, Position::LEFT);
        $this->assertStringContainsString('A', $grown, 'different canvas size cannot diff against the remembered frame');
    }
}
