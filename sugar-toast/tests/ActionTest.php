<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\Action;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testConstruction(): void
    {
        $callback = static function () {};

        $action = new Action('Confirm', $callback);

        $this->assertSame('Confirm', $action->label);
        $this->assertSame($callback, $action->callback);
    }

    public function testNewFactory(): void
    {
        $action = Action::new('Cancel', static function () {});
        $this->assertSame('Cancel', $action->label);
    }

    public function testCallbackIsInvoked(): void
    {
        $invoked = false;
        $action = new Action('DoIt', static function () use (&$invoked) { $invoked = true; });

        ($action->callback)();
        $this->assertTrue($invoked);
    }

    // TST-SGRRE: the kept arm's parameter class is [0-9;] only — DEC-private
    // '?' sequences must die at the drop arm, never ride the keep path as
    // canonically re-emitted mode switches (alt-screen, bracketed paste).

    public function testDecPrivateMFinalIsDroppedNotReEmitted(): void
    {
        $action = Action::new("Save\x1b[?1049mOK\x1b[0m", static function (): void {});
        $this->assertStringNotContainsString('?1049', $action->label, 'DEC-private alt-screen sequence survived label ingress');
        $this->assertStringNotContainsString("\x1b[?", $action->label, 'a ?-parameter CSI reached the wire');
        $this->assertStringStartsWith('SaveOK', $action->label);
        // The genuine reset SGR keeps its documented ride (label carries style).
        $this->assertStringEndsWith("\x1b[0m", $action->label);
    }

    public function testDecPrivateNonMFinalAndDegeneratePrivateAreDropped(): void
    {
        // Hiding the cursor (?25h) was already drop-arm territory; the bare
        // '?m' (DEC echo, empty parameter) is the boundary case the narrowed
        // kept arm must ALSO refuse (it used to canonicalise this one).
        $action = Action::new("a\x1b[?25hb\x1b[?mc", static function (): void {});
        $this->assertStringNotContainsString("\x1b[?", $action->label);
        $this->assertSame('abc', $action->label);
    }

    public function testPlainSgrWithoutQuestionMarkKeepsRidingByteIdentical(): void
    {
        // Positive control: the narrowing must cost genuine styling nothing.
        $label = "\x1b[1;31mRed\x1b[0m";
        $this->assertSame($label, Action::new($label, static function (): void {})->label);
    }
}
