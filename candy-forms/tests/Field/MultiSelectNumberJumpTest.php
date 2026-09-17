<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\MultiSelect;

/**
 * Plan 5.12 — number-jump 1-9 toggles the option at that slot directly
 * (Mirrors charmbracelet/bubbles multi-select number selection). The jump
 * is a SELECTION action, never a cursor move, and stays inert outside the
 * option range.
 */
final class MultiSelectNumberJumpTest extends TestCase
{
    private function focused(int $max = 0): MultiSelect
    {
        $f = MultiSelect::new('foods')->withOptions('Pizza', 'Burger', 'Salad');
        if ($max > 0) {
            $f = $f->withMax($max);
        }
        [$f, ] = $f->focus();
        return $f;
    }

    public function testDigitTogglesTheOptionAtThatSlot(): void
    {
        $f = $this->focused();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(['Burger'], $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '1'));
        // value() reports options in slot order, not toggle order.
        $this->assertSame(['Pizza', 'Burger'], $f->value());
        // The same digit again untoggles — it is a toggle, not a set-only.
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(['Pizza'], $f->value());
    }

    public function testDigitDoesNotMoveTheCursor(): void
    {
        $f = $this->focused();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertSame(0, $f->cursor);
        $this->assertSame(['Salad'], $f->value());
    }

    public function testZeroIsNotAJump(): void
    {
        $f = $this->focused();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, '0'));
        $this->assertSame([], $f->value());
        $this->assertSame(0, $f->cursor);
        $this->assertNull($cmd);
    }

    public function testDigitBeyondTheOptionCountIsInert(): void
    {
        $f = $this->focused();
        foreach (['4', '5', '9'] as $rune) {
            [$f, ] = $f->update(new KeyMsg(KeyType::Char, $rune));
        }
        $this->assertSame([], $f->value());
        $this->assertNull($f->getError());
    }

    /**
     * Vacuity pin: the inertness above must come from the option-count bound,
     * not from digits never reaching toggle() — a 5-option list proves '4'
     * and '5' genuinely toggle their slots.
     */
    public function testHigherDigitsReachHigherSlotsWhenTheyExist(): void
    {
        $f = MultiSelect::new('nums')->withOptions('a', 'b', 'c', 'd', 'e');
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '5'));
        $this->assertSame(['e'], $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '4'));
        $this->assertSame(['d', 'e'], $f->value());
    }

    public function testCtrlDigitIsIgnored(): void
    {
        $f = $this->focused();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '2', ctrl: true));
        $this->assertSame([], $f->value());
    }

    public function testAltDigitIsIgnored(): void
    {
        // Alt is not a jump qualifier either — only the bare rune acts.
        $f = $this->focused();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '2', alt: true));
        $this->assertSame([], $f->value());
    }

    public function testUnfocusedFieldIgnoresDigits(): void
    {
        $f = MultiSelect::new('foods')->withOptions('Pizza', 'Burger', 'Salad');
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '1'));
        $this->assertSame([], $f->value());
    }

    public function testDigitJumpHonoursTheMaxCap(): void
    {
        $f = $this->focused(max: 1);
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(['Burger'], $f->value());
        // Second selection attempt exceeds the cap: refused with the cap error.
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertSame(['Burger'], $f->value());
        $this->assertNotNull($f->getError());
    }

    public function testDigitWithNonDigitRuneIsUntouched(): void
    {
        // The j/k/g/G char arms must keep their navigation meaning — a digit
        // arm bolted too broadly would steal them.
        $f = $this->focused();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $f->cursor);
        $this->assertSame([], $f->value());
    }
}
