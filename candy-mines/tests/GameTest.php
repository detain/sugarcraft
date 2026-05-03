<?php

declare(strict_types=1);

namespace CandyCore\Mines\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Mines\Game;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    private static function key(KeyType $t, string $rune = ''): KeyMsg
    {
        return new KeyMsg($t, $rune);
    }

    public function testCursorStartsAtOrigin(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $max): int => 0);
        $this->assertSame(0, $g->cursorX);
        $this->assertSame(0, $g->cursorY);
    }

    public function testArrowKeysMoveCursor(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Right));
        [$g, ] = $g->update(self::key(KeyType::Right));
        [$g, ] = $g->update(self::key(KeyType::Down));
        $this->assertSame(2, $g->cursorX);
        $this->assertSame(1, $g->cursorY);
    }

    public function testCursorClampsAtBoardEdges(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        for ($i = 0; $i < 10; $i++) {
            [$g, ] = $g->update(self::key(KeyType::Right));
        }
        $this->assertSame(2, $g->cursorX);
    }

    public function testHjklVimMovement(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'l'));
        [$g, ] = $g->update(self::key(KeyType::Char, 'j'));
        $this->assertSame(1, $g->cursorX);
        $this->assertSame(1, $g->cursorY);
    }

    public function testFlagToggle(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));
        $this->assertTrue($g->board->cell(0, 0)->flagged);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));
        $this->assertFalse($g->board->cell(0, 0)->flagged);
    }

    public function testRevealOnFirstClickPlacesMines(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        $this->assertTrue($g->board->minesPlaced);
        $this->assertTrue($g->board->cell(0, 0)->revealed);
    }

    public function testQuitProducesQuitCmd(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [, $cmd] = $g->update(self::key(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testEscalsoQuits(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [, $cmd] = $g->update(self::key(KeyType::Escape));
        $this->assertNotNull($cmd);
    }

    public function testRestartResetsBoard(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));      // reveals + places mines
        [$g, ] = $g->update(self::key(KeyType::Char, 'r'));  // restart
        $this->assertFalse($g->board->minesPlaced);
        $this->assertSame(0, $g->cursorX);
        $this->assertSame(0, $g->cursorY);
    }

    public function testNonKeyMessagesAreIgnored(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        $msg = new \CandyCore\Core\Msg\WindowSizeMsg(80, 24);
        [$next, $cmd] = $g->update($msg);
        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    public function testViewIncludesStatusLine(): void
    {
        $g = Game::start(4, 4, 2, static fn(int $max): int => 0);
        $view = $g->view();
        $this->assertStringContainsString('mines: 2', $view);
    }
}
