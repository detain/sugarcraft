<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use SugarCraft\Forms\TextInput\EchoMode;
use SugarCraft\Forms\TextInput\TextInput;
use PHPUnit\Framework\TestCase;

/**
 * SF-39 (sugar-forms-053/-077 + the empty-pattern rider from sugar-forms-027
 * follow-up): clearMask() hands the pre-mask echo char back, a host that
 * retargets echoChar while masked keeps its choice, an unpatterned Mask mode
 * fails closed to full-mask instead of leaking raw, and the per-frame mask
 * preg runs under the shared scoped PCRE budget.
 */
final class MaskStateBudgetTest extends TestCase
{
    public function testClearMaskRestoresThePreMaskEchoChar(): void
    {
        $t = TextInput::new()
            ->withEchoChar("\u{2022}")
            ->withMask('[a-z]', 'X');
        $this->assertSame('X', $t->echoChar, 'masked run rides the mask char');

        $cleared = $t->clearMask();
        $this->assertSame("\u{2022}", $cleared->echoChar, 'the hosts choice comes back');

        // The findings blast shape: Password AFTER a mask round-trip.
        $password = $cleared->withEchoMode(EchoMode::Password)->setValue('s3cret');
        $this->assertStringNotContainsString('X', $password->displayedValue());
        $this->assertStringContainsString("\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}", $password->displayedValue());
    }

    public function testEchoCharSurvivesUnrelatedMutationsBeforeTheClear(): void
    {
        $t = TextInput::new()->withEchoChar('>')->withMask('[a-z]', 'X');
        $t = $t->setValue('anything')->withPrompt('$ ')->blur();
        $this->assertSame('>', $t->clearMask()->echoChar);
    }

    public function testNestedMasksRestoreTheImmediatelyPrecedingChar(): void
    {
        $t = TextInput::new()
            ->withEchoChar('dot')
            ->withMask('[a-z]', 'X')
            ->withMask('[a-z]', 'Y');
        // Per-leg semantics (disclosed): the second withMask remembers the
        // FIRST masks char, so clearing the second returns the firsts.
        $this->assertSame('X', $t->clearMask()->echoChar);
    }

    public function testHostRetargetWhileMaskedSurvivesTheClear(): void
    {
        $t = TextInput::new()
            ->withEchoChar('dot')
            ->withMask('[a-z]', 'X')
            ->withEchoChar('=');
        $this->assertSame('=', $t->clearMask()->echoChar, 'a live host edit outranks stale memory');
    }

    public function testUnpatternedMaskModeFailsClosedNotRaw(): void
    {
        // SF-25 rider: preg('//u') matches EMPTY at every position, so the
        // old renderer passed the secret through untouched. The door now
        // masks everything (withMask still refuses '' explicitly).
        $t = TextInput::new()->withEchoMode(EchoMode::Mask)->setValue('hunter2');
        $this->assertSame('*******', $t->displayedValue());
        $this->assertStringNotContainsString('hunter', $t->displayedValue());

        $threw = false;
        try {
            TextInput::new()->withMask('', '*');
        } catch (\InvalidArgumentException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the config door still refuses an empty pattern loudly');
    }

    public function testClearedMemoryCannotReviveFromAFutureCycle(): void
    {
        // After a full mask round-trip the memory is CLEARED (''), so a
        // later direct-mode Mask entry + clear must leave the hosts then-
        // current echo char untouched — no resurrection of cycle-one bytes.
        $t = TextInput::new()
            ->withEchoChar('dot')
            ->withMask('[a-z]', 'X')
            ->clearMask()
            ->withEchoChar('=')
            ->withEchoMode(EchoMode::Mask);
        $this->assertSame('=', $t->clearMask()->echoChar);
    }

    public function testExplosivePatternIsBudgetedNotAFreeze(): void
    {
        $bomb = str_repeat('a', 60) . '!';
        $t    = TextInput::new()->withMask('(a+)+$', '#')->setValue($bomb);

        $start = hrtime(true);
        $displayed = $t->displayedValue();
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame(str_repeat('#', 61), $displayed, 'budget exhaustion falls back to full mask');
        $this->assertLessThan(2000.0, $elapsedMs, "render took {$elapsedMs}ms — the budget must cut PCRE off fast");
    }

    public function testTheScopedBudgetOverridesAStarvedAmbientAndRestoresIt(): void
    {
        // Deterministic budget proof: with the ambient PCRE backtrack limit
        // starved to 10, an UNSCOPED mask preg fails (null -> full-mask
        // fallback); the scoped 200k leg renders the ordinary partial mask
        // anyway, and the finally-block hands the starved ambient back.
        // (a+)+$ over 12 a's explores ~2^11 backtracks (well above the
        // starved ambient 10, well below the scoped 200k) and then LEGALLY
        // fails to match the ZZ tail — the scoped run therefore paints the
        // unmatched subject through, while an unscoped run under ambient 10
        // hits the budget, gets null, and fails closed to full-mask. The
        // two shapes differ, so the scope is what is being asserted.
        $ambient = ini_get('pcre.backtrack_limit');
        $t = TextInput::new()->withMask('(a+)+$', '#')->setValue(str_repeat('a', 12) . 'ZZ');
        ini_set('pcre.backtrack_limit', '10');
        try {
            $this->assertSame(str_repeat('a', 12) . 'ZZ', $t->displayedValue(), 'scoped budget rides past the starved ambient');
            $this->assertSame('10', ini_get('pcre.backtrack_limit'), 'ambient restored unchanged');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $ambient);
        }
        $this->assertSame(str_repeat('a', 12) . 'ZZ', $t->displayedValue());
    }
}
