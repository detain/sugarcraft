<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents\Board;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Board\ActiveBoard;
use SugarCraft\Code\Agents\Board\ActiveBoardSeat;
use SugarCraft\Code\Agents\Board\Board;
use SugarCraft\Code\Agents\Board\BoardMember;

/** The seat restores the previous binding, once, when the run ends. */
final class ActiveBoardSeatTest extends TestCase
{
    /** @var list<Board> */
    private array $boards = [];

    protected function setUp(): void
    {
        ActiveBoard::restore(null, 0);
    }

    protected function tearDown(): void
    {
        ActiveBoard::restore(null, 0);
        foreach ($this->boards as $b) {
            $b->discard();
        }
    }

    private function board(): Board
    {
        $b = Board::forMembers([BoardMember::new('coder', 'task')]);
        self::assertNotNull($b);

        return $this->boards[] = $b;
    }

    public function testLeaveRestoresThePreviousBoardAndCursor(): void
    {
        $outer = $this->board();
        $inner = $this->board();
        $outerSeat = ActiveBoard::enter($outer);
        ActiveBoard::markNoticed(7);

        $seat = ActiveBoard::enter($inner);
        self::assertSame($inner, ActiveBoard::current());
        self::assertSame(0, ActiveBoard::noticed());

        $seat->leave();

        self::assertSame($outer, ActiveBoard::current());
        self::assertSame(7, ActiveBoard::noticed());
        $outerSeat->leave();
        self::assertNull(ActiveBoard::current());
    }

    public function testLeaveIsIdempotent(): void
    {
        $outer = $this->board();
        $outerSeat = ActiveBoard::enter($outer);
        $seat = ActiveBoard::enter($this->board());
        $seat->leave();
        ActiveBoard::restore(null, 3);

        $seat->leave();

        self::assertNull(ActiveBoard::current(), 'a second leave must not re-apply the saved binding');
        self::assertSame(3, ActiveBoard::noticed());
        unset($outerSeat);
    }

    public function testDestructLeaves(): void
    {
        $seat = new ActiveBoardSeat(null, 4);
        ActiveBoard::restore($this->board(), 9);

        unset($seat);

        self::assertNull(ActiveBoard::current());
        self::assertSame(4, ActiveBoard::noticed());
    }
}
