<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Tui\ComposerVim;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Forms\Vim\VimState;

/**
 * Counts in the composer's vim grammar (C-TUI-1). A digit arms a `count`
 * that multiplies the doubled operator (`3dd`), the line-spanning `dj`/`dk`,
 * the deletion of `x`, the pastes and every DELEGATED motion (`3w`, `d2w`)
 * — the motion algorithm itself stays the widget's, the count just asks it
 * again, the same delegation argument the class docblock makes for `dw`.
 *
 * The `0` ambiguity is the brief's named risk and is pinned in both
 * polarities: a BARE `0` under an operator is still the lib's line-start
 * motion (`d0` must not become "append zero to a count that does not
 * exist"), while `0` AFTER a digit appends to the count. And a forgotten
 * count must cancel-and-re-route exactly like a forgotten `d` — Tab still
 * belongs to its owner one keystroke after a stray number.
 *
 * Pure-unit shape mirrors {@see ComposerVimTest}.
 */
final class ComposerVimCountTest extends TestCase
{
    /** A focused, vim-armed composer holding `$value` with the caret placed. */
    private function widget(string $value, int $row = 0, ?int $col = null): TextArea
    {
        [$focused] = TextArea::new()->withCharLimit(0)->focus();
        $placed = $focused->withVimMode(true)->setValue($value);

        return $col === null ? $placed : $placed->setCursor($row, $col);
    }

    private function k(string $rune, bool $ctrl = false, bool $alt = false): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, alt: $alt, ctrl: $ctrl);
    }

    public function testThreeDdKillsThreeLines(): void
    {
        $input = $this->widget("a\nb\nc\nd", 1, 0);

        [, $counted] = ComposerVim::start($this->k('3'), $input, ComposerVim::empty());
        self::assertSame(3, $counted->count);
        self::assertTrue($counted->isArmed());

        [, $armed] = ComposerVim::resolve($this->k('d'), $input, $counted);
        self::assertSame('d', $armed->pending, 'the operator arms THROUGH the live count');
        self::assertSame(3, $armed->count);

        [$out, $done, $handled] = ComposerVim::resolve($this->k('d'), $input, $armed);
        self::assertTrue($handled);
        self::assertSame('a', $out->value());
        self::assertSame([0, 0], [$out->row, $out->col]);
        self::assertSame("b\nc\nd", $done->register);
        self::assertTrue($done->registerLinewise);
        self::assertFalse($done->isArmed());
    }

    public function testTwoYyThenPasteDropsTheWholeBlock(): void
    {
        $input = $this->widget("one\ntwo\nthree", 0, 0);

        [, $counted] = ComposerVim::start($this->k('2'), $input, ComposerVim::empty());
        [, $armed] = ComposerVim::resolve($this->k('y'), $input, $counted);
        [$yanked, $held, $handled] = ComposerVim::resolve($this->k('y'), $input, $armed);
        self::assertTrue($handled);
        self::assertSame("one\ntwo\nthree", $yanked->value(), 'yank never edits');
        self::assertSame("one\ntwo", $held->register);
        self::assertTrue($held->registerLinewise);

        [$pasted, $spent] = ComposerVim::start($this->k('p'), $yanked, $held);
        self::assertSame("one\none\ntwo\ntwo\nthree", $pasted->value(), 'the two-line block rides p once, intact');
        self::assertSame([1, 0], [$pasted->row, $pasted->col]);
        self::assertFalse($spent->isArmed());
    }

    public function testD2wCutsTwoWordsThroughTheDelegatedMotion(): void
    {
        $input = $this->widget('alpha beta gamma', 0, 0);

        [, $armed] = ComposerVim::start($this->k('d'), $input, ComposerVim::empty());
        [, $counted] = ComposerVim::resolve($this->k('2'), $input, $armed);
        self::assertSame(2, $counted->count, 'a digit after the operator arms the count');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('w'), $input, $counted);
        self::assertTrue($handled);
        self::assertSame('gamma', $out->value());
        self::assertSame('alpha beta ', $done->register);
        self::assertFalse($done->isArmed());
    }

    public function testThreeXDeletesThreeCharacters(): void
    {
        $input = $this->widget('abcdef', 0, 0);

        [, $counted] = ComposerVim::start($this->k('3'), $input, ComposerVim::empty());
        [$out, $done, $handled] = ComposerVim::resolve($this->k('x'), $input, $counted);
        self::assertTrue($handled);
        self::assertSame('def', $out->value(), 'x is the LIBS binding — the count just asks it three times');
        self::assertSame([0, 0], [$out->row, $out->col]);
        self::assertFalse($done->isArmed(), 'the count is spent, not remembered');
    }

    public function testBareZeroUnderAnOperatorIsStillTheLineStartMotion(): void
    {
        $input = $this->widget('alpha beta', 0, 5);

        [, $armed] = ComposerVim::start($this->k('d'), $input, ComposerVim::empty());
        [$out, $done, $handled] = ComposerVim::resolve($this->k('0'), $input, $armed);
        self::assertTrue($handled);
        self::assertSame(' beta', $out->value(), 'd0 kills to the head of the line — a phantom zero-count would beep here instead');
        self::assertSame('alpha', $done->register);
        self::assertNull($done->count);
    }

    public function testZeroAfterADigitAppendsToTheCount(): void
    {
        $input = $this->widget('alpha beta', 0, 0);

        [, $counted] = ComposerVim::start($this->k('2'), $input, ComposerVim::empty());
        [, $widened] = ComposerVim::resolve($this->k('0'), $input, $counted);
        self::assertSame(20, $widened->count, '2 then 0 is twenty, exactly like vim');

        [, $armed] = ComposerVim::resolve($this->k('d'), $input, $widened);
        [$out, , $handled] = ComposerVim::resolve($this->k('w'), $input, $armed);
        self::assertTrue($handled);
        self::assertSame('', $out->value(), 'd20w clamps at the EOL the clamped motion answers back');
    }

    public function testThreeWWalksThreeWordsAsAPureMotion(): void
    {
        $input = $this->widget('one two three four', 0, 0);

        [, $counted] = ComposerVim::start($this->k('3'), $input, ComposerVim::empty());
        [$out, $done, $handled] = ComposerVim::resolve($this->k('w'), $input, $counted);
        self::assertTrue($handled);
        self::assertSame('one two three four', $out->value(), 'a count alone with a motion is a MOTION, not an edit');
        self::assertSame([0, 14], [$out->row, $out->col], 'three lib w-steps: starts of two, three, four (14 is where the widget itself answers the third)');
        self::assertFalse($done->isArmed());
    }

    public function testDjWithCountSpansCountPlusOneLines(): void
    {
        $input = $this->widget("1\n2\n3\n4", 0, 0);

        [, $armed] = ComposerVim::start($this->k('d'), $input, ComposerVim::empty());
        [, $counted] = ComposerVim::resolve($this->k('2'), $input, $armed);
        [$out, $done, $handled] = ComposerVim::resolve($this->k('j'), $input, $counted);
        self::assertTrue($handled);
        self::assertSame('4', $out->value());
        self::assertSame("1\n2\n3", $done->register);
    }

    public function testCountedDjBeepsWhenTheMotionClampsToItsOwnRow(): void
    {
        $input = $this->widget("one\ntwo", 1, 0);

        [, $armed] = ComposerVim::start($this->k('d'), $input, ComposerVim::empty());
        [, $counted] = ComposerVim::resolve($this->k('5'), $input, $armed);
        [$out, $done, $handled] = ComposerVim::resolve($this->k('j'), $input, $counted);
        self::assertTrue($handled, 'the beep still consumes');
        self::assertSame("one\ntwo", $out->value(), 'down five from the last row clamps ON the caret row — nothing is killed');
        self::assertFalse($done->isArmed());
    }

    public function testTwoCharwisePastesLandSideBySide(): void
    {
        $input = $this->widget('ab', 0, 0);
        $state = new ComposerVim(null, 'X', false, null, 2);

        [$out, $done, $handled] = ComposerVim::resolve($this->k('p'), $input, $state);
        self::assertTrue($handled);
        self::assertSame('aXXb', $out->value());
        self::assertFalse($done->isArmed());
    }

    public function testAForgottenCountCancelsOnTabAndReRoutesToTheCaller(): void
    {
        $input = $this->widget('x', 0, 1);

        [, $counted] = ComposerVim::start($this->k('3'), $input, ComposerVim::empty());
        [$out, $done, $handled] = ComposerVim::resolve(new KeyMsg(KeyType::Tab), $input, $counted);
        self::assertFalse($handled, 'Tab belongs to its owner — the count may only cost the user nothing');
        self::assertSame('x', $out->value());
        self::assertFalse($done->isArmed());
    }

    public function testEscapeCancelsTheCountAndKeepsTheRegister(): void
    {
        $state = new ComposerVim(null, 'held', true, null, 3);

        [$out, $done, $handled] = ComposerVim::resolve(new KeyMsg(KeyType::Escape), $this->widget('alpha', 0, 0), $state);
        self::assertTrue($handled);
        self::assertSame('alpha', $out->value());
        self::assertFalse($done->isArmed());
        self::assertSame('held', $done->register);
    }

    public function testCapitalCChangesToLineEndThenInserts(): void
    {
        [$out, $done] = ComposerVim::start($this->k('C'), $this->widget('abcdef', 0, 3), ComposerVim::empty());
        self::assertSame('abc', $out->value());
        self::assertSame('def', $done->register);
        self::assertFalse($done->registerLinewise);
        self::assertSame(VimState::Insert, $out->vimState, 'C = the D kill then Insert, fed through the lib');
    }

    public function testDoubledCClearsTheCaretLineIntoInsert(): void
    {
        $input = $this->widget("one\ntwo", 1, 2);

        [, $armed] = ComposerVim::start($this->k('c'), $input, ComposerVim::empty());
        [$out, $done, $handled] = ComposerVim::resolve($this->k('c'), $input, $armed);
        self::assertTrue($handled);
        self::assertSame('one', $out->value(), 'cc is the dd kill then Insert, per the class rule');
        self::assertSame([0, 0], [$out->row, $out->col]);
        self::assertSame(VimState::Insert, $out->vimState);
        self::assertSame('two', $done->register);
        self::assertTrue($done->registerLinewise);
    }

    public function testCwChangesTheWordBodyLikeCe(): void
    {
        $input = $this->widget('alpha beta', 0, 0);

        [, $armed] = ComposerVim::start($this->k('c'), $input, ComposerVim::empty());
        [$out, $done, $handled] = ComposerVim::resolve($this->k('w'), $input, $armed);
        self::assertTrue($handled);
        self::assertSame(' beta', $out->value(), "vim's rule: cw is ce, not the caret-to-next-w-start `w` span");
        self::assertSame('alpha', $done->register);
        self::assertSame(VimState::Insert, $out->vimState);
    }

    public function testCwUnderACountIsAMotionSpanNotCeEquivalence(): void
    {
        $input = $this->widget('alpha beta gamma', 0, 0);

        [, $armed] = ComposerVim::start($this->k('c'), $input, ComposerVim::empty());
        [, $counted] = ComposerVim::resolve($this->k('2'), $input, $armed);
        [$out, $done, $handled] = ComposerVim::resolve($this->k('w'), $input, $counted);
        self::assertTrue($handled);
        self::assertSame('gamma', $out->value(), 'with a count, cw is the motion span (two words), ce-equivalence is the BARE c-w');
        self::assertFalse($done->isArmed());
        self::assertSame(VimState::Insert, $out->vimState);
    }

    public function testCountUnderAnOperatorIsSpentByTheActionAndNotAccumulatedTwice(): void
    {
        $input = $this->widget('one two three four five', 0, 0);

        [, $armed] = ComposerVim::start($this->k('d'), $input, ComposerVim::empty());
        [, $first] = ComposerVim::resolve($this->k('2'), $input, $armed);
        [, $second] = ComposerVim::resolve($this->k('3'), $input, $first);
        self::assertSame(23, $second->count, 'digits accumulate decimal-style, exactly like vim counts');
    }

    public function testStartRefusesZeroBecauseZeroIsTheLibsMotionFirst(): void
    {
        $idle = ComposerVim::empty();
        self::assertFalse($idle->claimsKey('0'), 'unarmed, 0 belongs to the lib as line-start');

        [$out, $state] = ComposerVim::start($this->k('1'), $this->widget('alpha', 0, 0), $idle);
        self::assertSame('alpha', $out->value(), 'arming a count never touches the buffer');
        self::assertSame(1, $state->count);
    }
}
