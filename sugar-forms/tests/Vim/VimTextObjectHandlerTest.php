<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Vim;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Vim\TextObjectScope;
use SugarCraft\Forms\Vim\VimAction;
use SugarCraft\Forms\Vim\VimKeyHandler;
use SugarCraft\Forms\Vim\VimOperator;
use SugarCraft\Forms\Vim\VimState;

final class VimTextObjectHandlerTest extends TestCase
{
    // =========================================================================
    // VimOperator / TextObjectScope key mapping
    // =========================================================================

    public function testOperatorFromKey(): void
    {
        $this->assertSame(VimOperator::Change, VimOperator::fromKey('c'));
        $this->assertSame(VimOperator::Delete, VimOperator::fromKey('d'));
        $this->assertSame(VimOperator::Yank, VimOperator::fromKey('y'));
        $this->assertNull(VimOperator::fromKey('x'));
        $this->assertNull(VimOperator::fromKey('C'));
        $this->assertNull(VimOperator::fromKey(''));
    }

    public function testScopeFromKey(): void
    {
        $this->assertSame(TextObjectScope::Inner, TextObjectScope::fromKey('i'));
        $this->assertSame(TextObjectScope::Around, TextObjectScope::fromKey('a'));
        $this->assertNull(TextObjectScope::fromKey('o'));
        $this->assertNull(TextObjectScope::fromKey(''));
    }

    // =========================================================================
    // Operator key emits the pending action in normal mode
    // =========================================================================

    public function testCKeyEmitsChangeLineInNormalMode(): void
    {
        $action = VimKeyHandler::handle('c', VimState::Normal);
        $this->assertSame(VimAction::ChangeLine, $action);
    }

    // =========================================================================
    // handleTextObject — operator × scope × target matrix
    // =========================================================================

    #[DataProvider('operatorScopeTargetMatrix')]
    public function testHandleTextObjectMatrix(
        VimOperator $operator,
        TextObjectScope $scope,
        string $target,
        string $buffer,
        int $cursor,
        VimAction $expectedAction,
        int $expectedStart,
        int $expectedEnd,
    ): void {
        [$action, $range] = VimKeyHandler::handleTextObject($operator, $scope, $target, $buffer, $cursor);

        $this->assertSame($expectedAction, $action);
        $this->assertNotNull($range);
        $this->assertSame($expectedStart, $range->start);
        $this->assertSame($expectedEnd, $range->end);
    }

    /**
     * @return array<string, array{VimOperator, TextObjectScope, string, string, int, VimAction, int, int}>
     */
    public static function operatorScopeTargetMatrix(): array
    {
        // buffer: say "hi" (or) [x] {y}  — a busy line to resolve against
        $buf = 'say "hi" (or) [x] {y}';

        return [
            // change
            'ci" cursor in quotes' => [VimOperator::Change, TextObjectScope::Inner, '"', $buf, 6, VimAction::ChangeTextObject, 5, 7],
            'ca" cursor in quotes' => [VimOperator::Change, TextObjectScope::Around, '"', $buf, 6, VimAction::ChangeTextObject, 4, 8],
            'ci( cursor in parens' => [VimOperator::Change, TextObjectScope::Inner, '(', $buf, 11, VimAction::ChangeTextObject, 10, 12],
            'ciw cursor in word'   => [VimOperator::Change, TextObjectScope::Inner, 'w', $buf, 1, VimAction::ChangeTextObject, 0, 3],

            // delete
            'di( cursor in parens'  => [VimOperator::Delete, TextObjectScope::Inner, '(', $buf, 11, VimAction::DeleteTextObject, 10, 12],
            'da( cursor in parens'  => [VimOperator::Delete, TextObjectScope::Around, '(', $buf, 11, VimAction::DeleteTextObject, 9, 13],
            'da{ cursor in braces'  => [VimOperator::Delete, TextObjectScope::Around, '{', $buf, 19, VimAction::DeleteTextObject, 18, 21],
            'di[ cursor in bracket' => [VimOperator::Delete, TextObjectScope::Inner, '[', $buf, 15, VimAction::DeleteTextObject, 15, 16],
            'daw cursor in word'    => [VimOperator::Delete, TextObjectScope::Around, 'w', $buf, 1, VimAction::DeleteTextObject, 0, 4],

            // yank
            "yi' quotes"           => [VimOperator::Yank, TextObjectScope::Inner, "'", "x 'ab' y", 4, VimAction::YankTextObject, 3, 5],
            'ya] brackets'         => [VimOperator::Yank, TextObjectScope::Around, ']', $buf, 15, VimAction::YankTextObject, 14, 17],
            'yiw word'             => [VimOperator::Yank, TextObjectScope::Inner, 'w', $buf, 1, VimAction::YankTextObject, 0, 3],
            'yi` backtick'         => [VimOperator::Yank, TextObjectScope::Inner, '`', 'a `bc` d', 4, VimAction::YankTextObject, 3, 5],
            'ya< angle'            => [VimOperator::Yank, TextObjectScope::Around, '<', 'T<u> v', 2, VimAction::YankTextObject, 1, 4],
        ];
    }

    // =========================================================================
    // handleTextObject — failure paths return [NoOp, null]
    // =========================================================================

    #[DataProvider('unresolvableSequences')]
    public function testHandleTextObjectUnresolvableIsNoOp(
        VimOperator $operator,
        TextObjectScope $scope,
        string $target,
        string $buffer,
        int $cursor,
    ): void {
        [$action, $range] = VimKeyHandler::handleTextObject($operator, $scope, $target, $buffer, $cursor);

        $this->assertSame(VimAction::NoOp, $action);
        $this->assertNull($range);
    }

    /**
     * @return array<string, array{VimOperator, TextObjectScope, string, string, int}>
     */
    public static function unresolvableSequences(): array
    {
        return [
            'cursor outside pair'   => [VimOperator::Change, TextObjectScope::Inner, '(', 'x (y) z', 6],
            'unmatched delimiter'   => [VimOperator::Delete, TextObjectScope::Inner, '{', 'open { only', 7],
            'no quotes on line'     => [VimOperator::Yank, TextObjectScope::Inner, '"', 'no quotes here', 3],
            'unknown target key'    => [VimOperator::Delete, TextObjectScope::Inner, 'z', 'anything', 2],
            'empty buffer'          => [VimOperator::Change, TextObjectScope::Inner, 'w', '', 0],
        ];
    }
}
