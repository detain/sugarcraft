<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Testing\Snapshot\Assertions;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;
use PHPUnit\Framework\TestCase;

final class VsRendererTest extends TestCase
{
    /** Draw the same kind every time so every frame is a fixed state. */
    private static function bag(): Bag
    {
        return new Bag(static fn(int $max): int => 0);
    }

    private static function versusStart(ColorProfile $profile): VsGame
    {
        return new VsGame(
            Game::start(self::bag()),
            Game::start(self::bag()),
            colorProfile: $profile,
        );
    }

    /**
     * -011 cure: the 373-line VS renderer had zero byte-exact coverage —
     * split-screen widths, colour posture and strip copy all lived inside
     * contains-substring asserts. One fixed versus state pinned at both
     * TrueColor and Ascii gives the whole frame (boards, divider, strip) a
     * cannot-fail-proof oracle, and gates tetris-10's zero-byte migration
     * contract. The strip copy here is the PRE-06-rework baseline — if
     * step -06 changes it, that step re-baselines these goldens.
     */
    public function testTheVersusStartFrameIsByteExactInTrueColor(): void
    {
        $output = VsRenderer::render(self::versusStart(ColorProfile::TrueColor));
        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            __DIR__ . '/fixtures/vs-starting-board-truecolor.golden',
            $output,
        );
    }

    public function testTheVersusStartFrameIsByteExactInAscii(): void
    {
        $output = VsRenderer::render(self::versusStart(ColorProfile::Ascii));
        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            __DIR__ . '/fixtures/vs-starting-board-ascii.golden',
            $output,
        );
    }
    public function testRenderReturnsNonEmptyString(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testRenderContainsBothPlayerAndComputerLabels(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('PLAYER', $output);
        $this->assertStringContainsString('COMPUTER', $output);
    }

    public function testRenderContainsVSIndicator(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('VS', $output);
    }

    public function testRenderContainsScore(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('score:', $output);
        $this->assertStringContainsString('lines:', $output);
        $this->assertStringContainsString('level:', $output);
    }

    public function testRenderOverStateShowsWinner(): void
    {
        // Create a VS game in over state
        $playerGame = Game::start();
        $computerGame = Game::start();

        // Manually set computer game to over
        $overComputer = new Game(
            $computerGame->board,
            $computerGame->piece,
            $computerGame->bag,
            $computerGame->score,
            over: true,
        );

        $vs = new VsGame($playerGame, $overComputer, over: true, winner: 'PLAYER');
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('GAME OVER', $output);
        $this->assertStringContainsString('YOU WIN!', $output);
    }

    public function testRenderComputerOverShowsComputerWinner(): void
    {
        // Create a VS game in over state with computer winner
        $playerGame = Game::start();
        $computerGame = Game::start();

        // Manually set player game to over
        $overPlayer = new Game(
            $playerGame->board,
            $playerGame->piece,
            $playerGame->bag,
            $playerGame->score,
            over: true,
        );

        $vs = new VsGame($overPlayer, $computerGame, over: true, winner: 'COMPUTER');
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('GAME OVER', $output);
        $this->assertStringContainsString('COMPUTER WINS!', $output);
    }

    public function testRenderContainsANSIEscapeCodes(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        // Check for ANSI escape codes (board rendering)
        $this->assertStringContainsString("\x1b[", $output);
    }

    public function testRenderDoesNotContainQuitPromptWhenNotOver(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        // Should not show quit prompt in normal play
        $this->assertStringNotContainsString('press q to quit', $output);
    }

    public function testRenderContainsQuitPromptWhenOver(): void
    {
        $playerGame = Game::start();
        $computerGame = Game::start();

        $overComputer = new Game(
            $computerGame->board,
            $computerGame->piece,
            $computerGame->bag,
            $computerGame->score,
            over: true,
        );

        $vs = new VsGame($playerGame, $overComputer, over: true, winner: 'PLAYER');
        $output = VsRenderer::render($vs);

        // The over banner must teach r alongside q — the versus over
        // screen answers restart (VsGame::update); a banner stuck on
        // quit_hint alone hid a live key.
        $this->assertStringContainsString('press r to restart', $output);
    }

    /**
     * -011 cure (the second cannot-fail pair): the old ghost/placed tests
     * asserted only `"\x1b["` presence — any frame at all passed. One fixed
     * TrueColor state (computer has placed blocks AND an in-flight piece
     * whose ghost reaches the floor) is now byte-exact: if the ghost layer
     * or placed-cell rendering disappears or shifts, this golden fails.
     */
    public function testTheComputerBoardGhostAndPlacedPiecesAreByteExact(): void
    {
        $computer = Game::start(self::bag());
        $rows = $computer->board->rows();
        foreach ([20, 21] as $y) {
            foreach ([3, 4] as $x) {
                $rows[$y][$x] = Tetromino::T;
            }
        }
        $computer = new Game(
            board: new Board($rows),
            piece: $computer->piece,
            bag: $computer->bag,
            score: $computer->score,
        );

        $vs = new VsGame(
            Game::start(self::bag()),
            $computer,
            colorProfile: ColorProfile::TrueColor,
        );

        $output = VsRenderer::render($vs);
        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            __DIR__ . '/fixtures/vs-computer-ghost-board-truecolor.golden',
            $output,
        );
    }

    public function testRenderComputerPiecesUseDifferentHue(): void
    {
        // MA-14: the two palettes must be disjoint by value. The old
        // (color + 150) % 256 shift put computer S exactly on player Z's
        // red, so label-presence assertions proved nothing — pin the map.
        $playerPalette = [];
        foreach (Tetromino::cases() as $kind) {
            $playerPalette[] = $kind->color();
        }
        $computerPalette = array_values(VsRenderer::COMPUTER_COLORS);

        self::assertCount(7, $computerPalette, 'every kind needs a computer colour');
        self::assertSame($computerPalette, array_values(array_unique($computerPalette)), 'computer colours must not repeat each other');
        self::assertSame([], array_values(array_intersect($playerPalette, $computerPalette)), 'no computer colour may collide with the player palette');

        $vs = new VsGame(Game::start(), Game::start());
        $output = VsRenderer::render($vs);
        $this->assertStringContainsString('PLAYER', $output);
        $this->assertStringContainsString('COMPUTER', $output);
    }

}
