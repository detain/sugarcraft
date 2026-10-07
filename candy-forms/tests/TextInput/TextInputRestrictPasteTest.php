<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use SugarCraft\Forms\TextInput\TextInput;
use PHPUnit\Framework\TestCase;

/**
 * E736 F2: the restrict gate applies per codepoint to paste payloads.
 *
 * Before the fix one unanchored preg_match guarded the WHOLE payload, so a
 * paste of '12ab34' under a '\d' restrict matched the '12' and admitted the
 * letters too. Each pasted rune now faces the identical gate a typed rune
 * faces (mirrors {@see RestrictTest} for the keystroke path).
 */
final class TextInputRestrictPasteTest extends TestCase
{
    private function focused(string $restrict): TextInput
    {
        $t = TextInput::new()->withRestrict($restrict);
        [$t, ] = $t->focus();
        return $t;
    }

    public function testPasteOfDigitsAndLettersUnderDigitRestrictKeepsOnlyDigits(): void
    {
        // The exact audit probe shape: '12ab34' must not sneak 'ab' through.
        $t = $this->focused('\d');
        $t = $t->paste('12ab34');

        $this->assertSame('1234', $t->value);
        $this->assertSame(4, $t->cursorPos);
    }

    public function testPasteFiltersToTheTypedAcceptSet(): void
    {
        $t = $this->focused('[a-z]');
        $t = $t->paste('aB1c');

        $this->assertSame('ac', $t->value);
    }

    public function testPasteFullyRejectedLeavesInstanceUntouched(): void
    {
        $t = $this->focused('[0-9]');
        $this->assertSame($t, $t->paste('abc'), 'nothing matching must short-circuit to $this');
        $this->assertSame('', $t->value);
        $this->assertSame(0, $t->cursorPos);
    }

    public function testEmptyPasteUnderRestrictCostsNothing(): void
    {
        $t = $this->focused('\d');
        $this->assertSame($t, $t->paste(''));
        $this->assertSame('', $t->value);
    }

    public function testPasteFiltersMultibyteCodepoints(): void
    {
        $t = $this->focused('[a-z]');
        $t = $t->paste('a日b');

        $this->assertSame('ab', $t->value);
        $this->assertSame(2, $t->cursorPos);
    }

    public function testRefusedRunesNeverConsumeTheCharLimitBudget(): void
    {
        // Filter first, clip second: the rejected 'd' cannot eat a slot, and
        // the accepted surplus is then clipped to the room left.
        $t = $this->focused('[a-c]')->withCharLimit(3);
        $t = $t->paste('abcdab');

        $this->assertSame('abc', $t->value);
        $this->assertSame(3, $t->cursorPos);
    }

    public function testPasteAtFullBudgetWithRejectsStillAcceptsNothing(): void
    {
        $t = $this->focused('\d')->setValue('99')->withCharLimit(2)->cursorEnd();
        $this->assertSame($t, $t->paste('ab'));

        $this->assertSame('99', $t->value);
    }
}
