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
 * The app-side operator grammar above the sugar-forms Vim layer (TUI-3A).
 *
 * These pins are PURE: every call goes straight into `ComposerVim` with a
 * focused, vim-armed `TextArea`, no Chat and no settings, so the split the
 * class docblock argues for — lib owns the mode machine, app owns the
 * pending operator and the register — is tested as its own contract. The
 * end-to-end wiring (who may call this class at all, and when) is pinned by
 * {@see \SugarCraft\Code\Tests\Chat\ComposerVimModeTest}.
 *
 * Motion expectations mirror the widget's OWN answers: `w` is delegated, so
 * `dw` cuts exactly where the lib's plain `w` moves (the measurement is the
 * lib's column after the key), which is the whole point of delegating.
 */
final class ComposerVimTest extends TestCase
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

    private function km(KeyType $type): KeyMsg
    {
        return new KeyMsg($type);
    }

    private function pending(string $op = 'd'): ComposerVim
    {
        return new ComposerVim($op);
    }

    public function testTheClaimSetIsExactlyTheOperatorGrammar(): void
    {
        foreach (['d', 'y', 'D', 'Y', 'o', 'O', 'e', 'p', 'P'] as $claimed) {
            self::assertTrue(ComposerVim::claims($claimed), $claimed);
        }

        // Every other plain Char belongs to the lib or is its beep: motions,
        // insert entries, the visual family and unbound letters alike.
        foreach (['i', 'a', 'I', 'A', 'h', 'j', 'k', 'l', 'w', 'b', 'x', 'v', 'V', '0', '$', 'q', '!', ' '] as $unclaimed) {
            self::assertFalse(ComposerVim::claims($unclaimed), $unclaimed);
        }
    }

    public function testWordEndWalksAtLeastOneCellThenStopsPastTheWord(): void
    {
        self::assertSame(2, ComposerVim::wordEndCol('hi there x', 0));
        self::assertSame(5, ComposerVim::wordEndCol('alpha beta', 0));
        self::assertSame(5, ComposerVim::wordEndCol('alpha', 5), 'EOL answers identity so the caller beeps');
        self::assertSame(4, ComposerVim::wordEndCol('a!!!', 0), 'punctuation-only tail runs out at EOL');
        self::assertSame(0, ComposerVim::wordEndCol('', 0));
    }

    public function testDeleteWordCutsToTheLibsOwnMotionAndThreadsTheRegister(): void
    {
        $input = $this->widget('alpha beta', 0, 0);
        [, $armed] = ComposerVim::start($this->k('d'), $input, ComposerVim::empty());
        self::assertSame('d', $armed->pending);

        [$out, $done, $handled] = ComposerVim::resolve($this->k('w'), $input, $armed);
        self::assertTrue($handled);
        self::assertSame('beta', $out->value());
        self::assertSame([0, 0], [$out->row, $out->col]);
        self::assertNull($done->pending);
        self::assertSame('alpha ', $done->register);
        self::assertFalse($done->registerLinewise);
    }

    public function testDoubleDeleteKillsTheRowAndLandsOnTheSurvivor(): void
    {
        $input = $this->widget("one\ntwo\nthree", 1, 0);

        [$out, $done] = ComposerVim::resolve($this->k('d'), $input, $this->pending());
        self::assertSame("one\nthree", $out->value());
        self::assertSame([1, 0], [$out->row, $out->col], 'the caret rides the first surviving row of the killed range');
        self::assertSame('two', $done->register);
        self::assertTrue($done->registerLinewise);
    }

    public function testDeletingTheOnlyLineFallsBackToAnEmptyBuffer(): void
    {
        [$out, $done] = ComposerVim::resolve($this->k('d'), $this->widget('alpha', 0, 0), $this->pending());
        self::assertSame('', $out->value());
        self::assertSame(1, $out->lineCount());
        self::assertSame('alpha', $done->register);
    }

    public function testYankLineKeepsTheBufferAndMarksTheRegisterLinewise(): void
    {
        $input = $this->widget("one\ntwo", 0, 2);

        [$out, $done] = ComposerVim::resolve($this->k('y'), $input, $this->pending('y'));
        self::assertSame("one\ntwo", $out->value());
        self::assertSame([0, 2], [$out->row, $out->col], 'yank never moves');
        self::assertSame('one', $done->register);
        self::assertTrue($done->registerLinewise);
    }

    public function testCapitalDDeletesToLineEndWithoutASecondKeystroke(): void
    {
        [$out, $done] = ComposerVim::start($this->k('D'), $this->widget('abcdef', 0, 3), ComposerVim::empty());
        self::assertSame('abc', $out->value());
        self::assertSame([0, 3], [$out->row, $out->col]);
        self::assertSame('def', $done->register);
        self::assertFalse($done->registerLinewise);
        self::assertNull($done->pending);
    }

    public function testCapitalYYanksTheWholeLine(): void
    {
        $input = $this->widget("one\ntwo", 1, 1);

        [$out, $done] = ComposerVim::start($this->k('Y'), $input, ComposerVim::empty());
        self::assertSame("one\ntwo", $out->value());
        self::assertSame('two', $done->register);
        self::assertTrue($done->registerLinewise);
    }

    public function testBareEIsAMotionNotAnOperator(): void
    {
        [$out, $done] = ComposerVim::start($this->k('e'), $this->widget('alpha beta', 0, 0), ComposerVim::empty());
        self::assertSame('alpha beta', $out->value(), 'the word stays; only the caret walks');
        self::assertSame([0, 5], [$out->row, $out->col]);
        self::assertNull($done->pending);
    }

    public function testLowercaseOOpensTheLineBelowAndEntersInsertThroughTheLib(): void
    {
        $out = ComposerVim::start($this->k('o'), $this->widget('alpha', 0, 2), ComposerVim::empty());

        self::assertSame("alpha\n", $out[0]->value());
        self::assertSame([1, 0], [$out[0]->row, $out[0]->col]);
        self::assertSame(VimState::Insert, $out[0]->vimState, 'Insert is entered by FEEDING the widget `i`, never by poking state');
    }

    public function testUppercaseOOpensTheLineAboveAndEntersInsert(): void
    {
        $out = ComposerVim::start($this->k('O'), $this->widget("one\ntwo", 1, 1), ComposerVim::empty());

        self::assertSame("one\n\ntwo", $out[0]->value());
        self::assertSame([1, 0], [$out[0]->row, $out[0]->col]);
        self::assertSame(VimState::Insert, $out[0]->vimState);
    }

    public function testLinewisePasteInsertsWholeRowsBelowAndAbove(): void
    {
        $state = new ComposerVim(null, 'mid', true);

        [$below] = ComposerVim::start($this->k('p'), $this->widget("top\nbottom", 0, 1), $state);
        self::assertSame("top\nmid\nbottom", $below->value());
        self::assertSame([1, 0], [$below->row, $below->col]);

        [$above] = ComposerVim::start($this->k('P'), $this->widget("top\nbottom", 1, 2), $state);
        self::assertSame("top\nmid\nbottom", $above->value());
        self::assertSame([1, 0], [$above->row, $above->col]);
    }

    public function testCharwisePasteSplicesInline(): void
    {
        $state = new ComposerVim(null, 'XY', false);

        [$after] = ComposerVim::start($this->k('p'), $this->widget('ab', 0, 0), $state);
        self::assertSame('aXYb', $after->value(), 'p lands after the caret CELL, the normal-mode convention');
        self::assertSame([0, 2], [$after->row, $after->col], 'the caret sits one past the pasted text');

        [$before] = ComposerVim::start($this->k('P'), $this->widget('ab', 0, 1), $state);
        self::assertSame('aXYb', $before->value(), 'P lands at the caret cell');
        self::assertSame([0, 3], [$before->row, $before->col], 'the caret one-past mirrors p');
    }

    public function testPasteWithAnEmptyRegisterDoesNothing(): void
    {
        $input = $this->widget('alpha', 0, 2);
        [$out] = ComposerVim::start($this->k('p'), $input, ComposerVim::empty());
        self::assertSame('alpha', $out->value());
        self::assertSame([0, 2], [$out->row, $out->col]);
    }

    public function testForeignKeysCancelTheOperatorAndReRouteToTheCaller(): void
    {
        $input = $this->widget('alpha beta', 0, 0);

        foreach ([$this->km(KeyType::Tab), $this->k('p', ctrl: true), $this->k('b', alt: true), $this->km(KeyType::Down)] as $foreign) {
            [$out, $done, $handled] = ComposerVim::resolve($foreign, $input, $this->pending());
            self::assertFalse($handled, 'the caller must still see the key it owns');
            self::assertNull($done->pending);
            self::assertSame('alpha beta', $out->value());
        }
    }

    public function testEscapeCancelsThePendingOperatorAndKeepsTheRegister(): void
    {
        $state = new ComposerVim('d', 'held', true);

        [$out, $done, $handled] = ComposerVim::resolve($this->km(KeyType::Escape), $this->widget('alpha', 0, 0), $state);
        self::assertTrue($handled, 'Escape has nothing else to close here');
        self::assertSame('alpha', $out->value());
        self::assertNull($done->pending);
        self::assertSame('held', $done->register);
    }

    public function testEnterUnderAnOperatorIsTheLinelikeSpellingOfDd(): void
    {
        [$out, $done, $handled] = ComposerVim::resolve($this->km(KeyType::Enter), $this->widget("one\ntwo", 1, 2), $this->pending());
        self::assertTrue($handled);
        self::assertSame('one', $out->value());
        self::assertSame('two', $done->register);
        self::assertTrue($done->registerLinewise);
    }

    public function testDownOperatorSpansTheCaretLinePlusOne(): void
    {
        [$out, $done] = ComposerVim::resolve($this->k('j'), $this->widget("one\ntwo\nthree", 0, 0), $this->pending());
        self::assertSame('three', $out->value());
        self::assertSame([0, 0], [$out->row, $out->col]);
        self::assertSame("one\ntwo", $done->register);
    }

    public function testDownOperatorBeepsWhenTheCaretRowIsAlreadyLast(): void
    {
        // The clamped motion lands where it started: same-row range would kill
        // the line the user did not ask to kill, so the operator cancels dry.
        $input = $this->widget("one\ntwo", 1, 0);

        [$out, $done, $handled] = ComposerVim::resolve($this->k('j'), $input, $this->pending());
        self::assertTrue($handled, 'the beep consumes: the key must not fall through to history walk');
        self::assertSame("one\ntwo", $out->value());
        self::assertNull($done->pending);
    }

    public function testMotionWithoutMovementBeepsInsteadOfCuttingAnEmptySpan(): void
    {
        [$out, $done, $handled] = ComposerVim::resolve($this->k('w'), $this->widget('alpha', 0, 5), $this->pending());
        self::assertTrue($handled);
        self::assertSame('alpha', $out->value());
        self::assertNull($done->pending);
    }

    public function testBackwardSpansKillWhatForwardTypingWould(): void
    {
        [$out, $done] = ComposerVim::resolve($this->k('b'), $this->widget('alpha beta', 0, 6), $this->pending());
        self::assertSame('beta', $out->value());
        self::assertSame([0, 0], [$out->row, $out->col]);
        self::assertSame('alpha ', $done->register);
    }

    public function testDollarAndZeroRideTheSameMotionDelegation(): void
    {
        [$cut, $state] = ComposerVim::resolve($this->k('$'), $this->widget('alpha beta', 0, 0), $this->pending());
        self::assertSame('', $cut->value(), 'd$ from column 0 empties the row');
        self::assertSame('alpha beta', $state->register);

        [$head, $kept] = ComposerVim::resolve($this->k('0'), $this->widget('alpha beta', 0, 5), $this->pending());
        self::assertSame(' beta', $head->value());
        self::assertSame('alpha', $kept->register);
    }

    public function testUnknownContinuationIsBeepedAwayNeverTyped(): void
    {
        $input = $this->widget('alpha', 0, 0);

        [$out, $done, $handled] = ComposerVim::resolve($this->k('x'), $input, $this->pending());
        self::assertTrue($handled);
        self::assertSame('alpha', $out->value(), 'a stray letter after d must not reach the widgets x-delete');
        self::assertNull($done->pending);
    }

    public function testResolveUnderNoPendingOperatorPassesThroughUntouched(): void
    {
        $input = $this->widget('alpha', 0, 0);
        [$out, $state, $handled] = ComposerVim::resolve($this->k('w'), $input, ComposerVim::empty());
        self::assertFalse($handled);
        self::assertSame($input, $out);
        self::assertNull($state->pending);
    }

    public function testEveryEntryPointFrozeItsArguments(): void
    {
        $input = $this->widget('alpha beta', 0, 0);
        $armed = ComposerVim::empty();
        [$started, $state] = ComposerVim::start($this->k('d'), $input, $armed);

        [$out, $done] = ComposerVim::resolve($this->k('w'), $input, $state);

        self::assertSame('alpha beta', $input->value());
        self::assertSame([0, 0], [$input->row, $input->col]);
        self::assertNull($armed->pending);
        self::assertSame('d', $state->pending);
        self::assertNotSame($input, $out);
        self::assertNotSame($state, $done);
        self::assertSame('alpha ', $done->register, 'the register set by applySpan survives the call');
    }

    public function testPendingClearedForgetsTheOperatorButNotTheRegister(): void
    {
        $state = new ComposerVim('d', 'held', true);
        $cleared = $state->pendingCleared();

        self::assertNull($cleared->pending);
        self::assertSame('held', $cleared->register);
        self::assertTrue($cleared->registerLinewise);
        self::assertNull($state->pendingCleared()->pendingCleared()->pending);
    }

    public function testStartRefusesRunesOutsideTheClaimSet(): void
    {
        $this->expectException(\LogicException::class);

        /** @phpstan-ignore-next-line offsets the claims/start agreement pin */
        ComposerVim::start($this->k('q'), $this->widget('alpha', 0, 0), ComposerVim::empty());
    }
}
