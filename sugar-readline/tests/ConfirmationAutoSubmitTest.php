<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\ConfirmationPrompt;
use SugarCraft\Readline\Key;

/**
 * S-38 (finding 090): ConfirmationPrompt::withAutoSubmit() — opt-in
 * y/n-commits-immediately. The companion arm of the finding (textarea
 * hint/counter setters) was adjudicated DISK-FALSE this lane: the
 * 'Ctrl+D to finish' / '[N chars]' literals have never existed in
 * sugar-readline (pickaxe over the full history is empty) — a
 * phantom-literal class like SL-20. See the lane report.
 */
final class ConfirmationAutoSubmitTest extends TestCase
{
    public function testDefaultOffKeepsEnterToCommitParity(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->handleKey('y');
        $this->assertFalse($p->isSubmitted(), 'default y selects without committing');
        $this->assertTrue($p->currentValue());

        $done = $p->handleKey(Key::Enter);
        $this->assertTrue($done->isSubmitted());
        $this->assertTrue($done->result());
    }

    public function testWithAutoSubmitArgFalseIsExplicitParity(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->withAutoSubmit(false)->handleKey('y');
        $this->assertFalse($p->isSubmitted());
    }

    public function testAutoSubmitYesCommitsInOnePress(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->withAutoSubmit()->handleKey('y');
        $this->assertTrue($p->isSubmitted(), 'armed y commits immediately');
        $this->assertTrue($p->result());
    }

    public function testAutoSubmitNoCommitsFalseResult(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->withAutoSubmit(true)->handleKey('N');
        $this->assertTrue($p->isSubmitted());
        $this->assertFalse($p->result());
    }

    public function testArrowsAndToggleStayBrowseOnlyWhenArmed(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->withAutoSubmit()->handleKey(Key::Left);
        $this->assertFalse($p->isSubmitted(), 'Left is browse, not an answer');
        $this->assertTrue($p->currentValue());

        $toggled = $p->handleKey(Key::Space);
        $this->assertFalse($toggled->isSubmitted(), 'Space toggles, never commits');
        $this->assertFalse($toggled->currentValue());
    }

    public function testEnterStillCommitsWhenArmed(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->withAutoSubmit()->handleKey(Key::Enter);
        $this->assertTrue($p->isSubmitted());
        $this->assertFalse($p->result(), 'commit of the default selection');
    }

    public function testTerminalStateIsIdempotentWhenArmed(): void
    {
        $p = ConfirmationPrompt::new('Sure?')->withAutoSubmit()->handleKey('y');
        $again = $p->handleKey('n');
        $this->assertSame($p, $again, 'post-submit keys are inert');
        $this->assertTrue($again->result());
    }
}
