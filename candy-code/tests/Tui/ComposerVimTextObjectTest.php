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
 * The text-object grammar the composer drives through sugar-forms' OWN
 * TextObject machinery (C-TUI-1). Nothing here re-implements object geometry:
 * the operator arms `pendingScope` on `i`/`a`, and the target key resolves
 * through `VimKeyHandler::handleTextObject()` on the caret's line — the lib
 * consumed unchanged, per the class docblock's layering argument.
 *
 * The pins cover the brief's set — `ci"`, `da(`, `diw`, `yi{` — ON a pair,
 * BEFORE one (vim's jump-forward to a quoted region later on the line),
 * OUTSIDE every pair (the beep), the EMPTY object (`ci(` between `()`
 * inserts without deleting), and that any beep cancels the whole armed
 * grammar so no half-object can leak into the next keystroke.
 *
 * Pure-unit shape mirrors {@see ComposerVimTest}: every call goes straight
 * into `ComposerVim` with a focused, vim-armed `TextArea`.
 */
final class ComposerVimTextObjectTest extends TestCase
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

    /** `op` then `scope`: asserts both keystrokes armed cleanly and returns the state. */
    private function armOperatorScope(TextArea $input, string $op, string $scope): ComposerVim
    {
        [, $armed] = ComposerVim::start($this->k($op), $input, ComposerVim::empty());
        self::assertSame($op, $armed->pending);

        [, $scoped, $handled] = ComposerVim::resolve($this->k($scope), $input, $armed);
        self::assertTrue($handled, 'the scope key is grammar material under a live operator');
        self::assertSame($op, $scoped->pending, 'the operator survives its scope selector');
        self::assertSame($scope, $scoped->pendingScope);

        return $scoped;
    }

    public function testChangeInnerQuoteKillsTheBodyAndEntersInsert(): void
    {
        $input = $this->widget('say "hi"', 0, 5);
        $scoped = $this->armOperatorScope($input, 'c', 'i');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('"'), $input, $scoped);
        self::assertTrue($handled);
        self::assertSame('say ""', $out->value());
        self::assertSame([0, 5], [$out->row, $out->col], 'the caret lands at the killed span for typing');
        self::assertSame(VimState::Insert, $out->vimState, 'c = the d kill then Insert, fed through the lib');
        self::assertSame('hi', $done->register);
        self::assertFalse($done->isArmed());
    }

    public function testDeleteAroundParensCutsTheDelimitersToo(): void
    {
        $input = $this->widget('f(x)', 0, 2);
        $scoped = $this->armOperatorScope($input, 'd', 'a');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('('), $input, $scoped);
        self::assertTrue($handled);
        self::assertSame('f', $out->value());
        self::assertSame('(x)', $done->register);
        self::assertFalse($done->registerLinewise);
        self::assertFalse($done->isArmed());
    }

    public function testDeleteInnerWordCutsTheWordUnderTheCaret(): void
    {
        $input = $this->widget('alpha beta', 0, 7);
        $scoped = $this->armOperatorScope($input, 'd', 'i');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('w'), $input, $scoped);
        self::assertTrue($handled);
        self::assertSame('alpha ', $out->value(), 'iw is the word alone, not the gap behind it');
        self::assertSame('beta', $done->register);
    }

    public function testYankInnerBracesKeepsBufferAndRestoresTheCaret(): void
    {
        $input = $this->widget('a{b}c', 0, 1);
        $scoped = $this->armOperatorScope($input, 'y', 'i');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('{'), $input, $scoped);
        self::assertTrue($handled);
        self::assertSame('a{b}c', $out->value(), 'yank never edits');
        self::assertSame([0, 1], [$out->row, $out->col], 'yank never moves: caret ON the delimiter stays there');
        self::assertSame('b', $done->register);
    }

    public function testChangeEmptyObjectInsertsBetweenTheDelimiters(): void
    {
        $input = $this->widget('()', 0, 0);
        $scoped = $this->armOperatorScope($input, 'c', 'i');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('('), $input, $scoped);
        self::assertTrue($handled, 'an empty object IS an object — vim does not beep on ci( with () present');
        self::assertSame('()', $out->value(), 'nothing was inside to kill');
        self::assertSame([0, 1], [$out->row, $out->col]);
        self::assertSame(VimState::Insert, $out->vimState);
        self::assertSame('', $done->register);
    }

    public function testQuoteObjectAheadOfTheCaretWinsTheJumpForward(): void
    {
        $input = $this->widget('x = "one"', 0, 0);
        $scoped = $this->armOperatorScope($input, 'c', 'i');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('"'), $input, $scoped);
        self::assertTrue($handled);
        self::assertSame('x = ""', $out->value(), 'vim jumps forward to the first pair closing at/after the caret');
        self::assertSame('one', $done->register);
        self::assertSame(VimState::Insert, $out->vimState);
    }

    public function testOutsideEveryPairBeepsAndCancelsTheWholeGrammar(): void
    {
        $input = $this->widget('plain word', 0, 0);
        $scoped = $this->armOperatorScope($input, 'd', 'i');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('('), $input, $scoped);
        self::assertTrue($handled, 'the beep consumes: the key must not fall through as typing');
        self::assertSame('plain word', $out->value());
        self::assertFalse($done->isArmed(), 'operator, scope and count all die together on the beep');
        self::assertSame('', $done->register);
    }

    public function testAKeyThatNamesNoTargetBeepsTheScopeAway(): void
    {
        $input = $this->widget('say "hi"', 0, 5);
        $scoped = $this->armOperatorScope($input, 'd', 'i');

        [$out, $done, $handled] = ComposerVim::resolve($this->k('!'), $input, $scoped);
        self::assertTrue($handled);
        self::assertSame('say "hi"', $out->value());
        self::assertFalse($done->isArmed());
    }

    public function testEscapeCancelsMidObjectAndKeepsTheRegister(): void
    {
        $held = new ComposerVim('d', 'held', true, 'i');
        self::assertTrue($held->isArmed());

        [$out, $done, $handled] = ComposerVim::resolve(new KeyMsg(KeyType::Escape), $this->widget('alpha', 0, 0), $held);
        self::assertTrue($handled);
        self::assertSame('alpha', $out->value());
        self::assertFalse($done->isArmed());
        self::assertSame('held', $done->register, 'Escape cancels the grammar, never the register');
    }
}
