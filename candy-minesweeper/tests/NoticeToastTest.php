<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Lang;
use SugarCraft\Minesweeper\TickMsg;

/**
 * M05 (lib-reviews-1103): save/load/restart outcomes were silent — the
 * player pressing S or L had to open a file manager to learn whether the
 * press did anything. The notice channel renders a one-shot toast line
 * above the status banner; these pins own its lifecycle: announced, kept
 * across ticks, dismissed by the next action, coexisting with the
 * win/lose banner (never replacing it).
 *
 * HOME and XDG_CONFIG_HOME are sandboxed per test with the same
 * snapshot-before-mutation idiom as SaveLoadTest so the slot lives in a temp
 * dir. (Both legs: savePath() asks XDG first, so a HOME-only sandbox leaks
 * the slot to the runner's real config dir wherever XDG_CONFIG_HOME is
 * exported — the 26.04-runner red shape.)
 */
final class NoticeToastTest extends TestCase
{
    private ?string $savedHome;
    private string|false $savedXdg;
    private string $homeDir = '';
    private string $xdgDir = '';

    protected function setUp(): void
    {
        $this->savedHome = getenv('HOME');
        $this->savedXdg = getenv('XDG_CONFIG_HOME');
        $this->homeDir = sys_get_temp_dir() . '/candy-minesweeper-toast-' . bin2hex(random_bytes(6));
        mkdir($this->homeDir, 0700, true);
        $this->xdgDir = $this->homeDir . '/xdg-config';
        mkdir($this->xdgDir, 0700, true);
        putenv('HOME=' . $this->homeDir);
        putenv('XDG_CONFIG_HOME=' . $this->xdgDir);
    }

    protected function tearDown(): void
    {
        if ($this->savedHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->savedHome);
        }
        if ($this->savedXdg === false) {
            putenv('XDG_CONFIG_HOME');
        } else {
            putenv('XDG_CONFIG_HOME=' . $this->savedXdg);
        }
        foreach ([$this->homeDir . '/.config', $this->xdgDir] as $configLeg) {
            $slotDir = $configLeg . '/candy-minesweeper';
            if (is_dir($slotDir)) {
                foreach (new \FilesystemIterator($slotDir) as $residue) {
                    @unlink($residue->getPathname());
                }
                @rmdir($slotDir);
            }
            @rmdir($configLeg);
        }
        @rmdir($this->homeDir);
    }

    private static function press(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    /** A live, saveable game: clock armed, one flag placed. */
    private static function liveGame(): Game
    {
        $g = Game::start(6, 6, 4, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::press(KeyType::Space));
        [$g, ] = $g->update(self::press(KeyType::Char, 'f'));
        return $g;
    }

    public function testFreshGameCarriesNoToast(): void
    {
        $g = Game::start(4, 4, 2, static fn(int $_max): int => 0);
        $this->assertNull($g->notice, 'a fresh game must not toast');
        $this->assertStringNotContainsString('» ', $g->view(), 'no toast line on a clean frame');
    }

    public function testSaveSuccessAnnouncesAndRenders(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));
        $this->assertSame(Lang::t('ui.saved'), $g->notice);
        $this->assertStringContainsString('» ' . Lang::t('ui.saved'), $g->view());
    }

    public function testToastSurvivesClockTicks(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));
        [$again, ] = $g->update(new TickMsg());
        $this->assertSame(Lang::t('ui.saved'), $again->notice, 'a tick is not an action — the toast must stay readable');
    }

    public function testNextActionDismissesTheToast(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));
        [$g, ] = $g->update(self::press(KeyType::Char, 'l')); // a real cursor move
        $this->assertNull($g->notice, 'the next state-change action dismisses the toast');
        $this->assertStringNotContainsString('» ', $g->view());
    }

    public function testLoadWithoutASlotAnnouncesNothingToLoad(): void
    {
        $g = self::liveGame();
        [$next, ] = $g->update(self::press(KeyType::Char, 'L'));
        $this->assertNotSame($g, $next);
        $this->assertSame($g->board, $next->board, 'state preserved through the refusal');
        $this->assertSame(Lang::t('ui.no_save'), $next->notice);
    }

    public function testRestartAnnounces(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'r'));
        $this->assertSame(Lang::t('ui.restarted'), $g->notice);
        $this->assertFalse($g->board->minesPlaced, 'sanity: the restart itself still resets');
    }

    public function testToastCoexistsWithTheLossBanner(): void
    {
        // Save a live game, then load onto an exploded board: 'L' sits above
        // the over-gate, and the boom banner is the board's verdict — the
        // toast must ride ABOVE it, never replace it.
        $live = self::liveGame();
        [$live, ] = $live->update(self::press(KeyType::Char, 'S'));

        $rows = [];
        for ($y = 0; $y < 4; $y++) {
            $row = [];
            for ($x = 0; $x < 4; $x++) {
                $row[] = new Cell($x === 0 && $y === 0, false, false, 0);
            }
            $rows[] = $row;
        }
        $dead = new Game(Board::fromRows(4, 4, 1, $rows, true, true, 0, 0), 0, 0, static fn(int $_max): int => 0);
        $this->assertTrue($dead->board->exploded, 'sanity: dead board fixture');

        [$loaded, ] = $dead->update(self::press(KeyType::Char, 'L'));
        $this->assertSame(Lang::t('ui.loaded'), $loaded->notice);
        $view = $loaded->view();
        $this->assertStringContainsString(Lang::t('ui.loaded'), $view, 'toast line present');
        $this->assertStringNotContainsString(Lang::t('ui.boom'), $view, 'the save replaced the board — the boom banner belongs to the game the player LEFT, not the one loaded');

        // And the reverse: loading nothing (junk slot) on the dead board keeps
        // the banner and adds the refusal toast over it.
        $slot = Game::savePath();
        self::assertIsString($slot);
        @mkdir(dirname($slot), 0700, true);
        file_put_contents($slot, '{"v":2}');
        [$refused, ] = $dead->update(self::press(KeyType::Char, 'L'));
        $refusedView = $refused->view();
        $this->assertStringContainsString(Lang::t('ui.load_refused'), $refusedView, 'toast present');
        $this->assertStringContainsString(Lang::t('ui.boom'), $refusedView, 'banner survives under the toast');
    }

    public function testFactoryCarriesABootNotice(): void
    {
        // The bin boots with the resume hint via the factory param; pin the
        // seam here without dragging the whole binary into it.
        $g = Game::start(4, 4, 2, null, null, null, Lang::t('ui.resume'));
        $this->assertSame(Lang::t('ui.resume'), $g->notice);
        $this->assertStringContainsString('» ' . Lang::t('ui.resume'), $g->view());
    }

    // ---- msw-14 (finding 063): real-change-only clearing + chord refusal toast ----

    /** A board whose (3,3) cell is a revealed number with no flags around it. */
    private static function boardWithUnsatisfiedNumber(int $number = 2): Board
    {
        $rows = [];
        for ($y = 0; $y < 6; $y++) {
            $row = [];
            for ($x = 0; $x < 6; $x++) {
                $atNumber = $x === 3 && $y === 3;
                $row[] = new Cell(
                    mine: false,
                    revealed: $atNumber,
                    adjacent: $atNumber ? $number : 0,
                );
            }
            $rows[] = $row;
        }
        return Board::fromRows(6, 6, 2, $rows, minesPlaced: true, revealedCount: 1);
    }

    private static function gameOnNumber(?string $notice = null): Game
    {
        return new Game(
            board: self::boardWithUnsatisfiedNumber(),
            cursorX: 3,
            cursorY: 3,
            rand: static fn(int $_max): int => 0,
            notice: $notice,
        );
    }

    public function testUnmappedKeyDoesNotEraseTheNotice(): void
    {
        $g = Game::start(6, 6, 2, static fn(int $_max): int => 0, notice: 'hold me');
        [$next, ] = $g->update(self::press(KeyType::Char, 'x'));
        $this->assertSame('hold me', $next->notice, 'a dead keypress must not wipe the hint');
    }

    public function testSpaceOnRevealedCellDoesNotEraseTheNotice(): void
    {
        $g = self::gameOnNumber('hold me');
        [$next, ] = $g->update(self::press(KeyType::Space));
        $this->assertSame('hold me', $next->notice, 'a dud reveal is not a state change');
        $this->assertNull($next->startedAt, 'a dud reveal must not arm the clock');
    }

    public function testFlagOnRevealedCellIsAnIdentityNoOp(): void
    {
        $board = self::boardWithUnsatisfiedNumber();
        $this->assertSame($board, $board->toggleFlag(3, 3), 'a revealed cell cannot hold a flag');

        $g = self::gameOnNumber('hold me');
        [$next, ] = $g->update(self::press(KeyType::Char, 'f'));
        $this->assertSame('hold me', $next->notice, 'a dud flag press is not a state change');
    }

    public function testRefusedChordOnUnsatisfiedNumberToasts(): void
    {
        $g = self::gameOnNumber();
        [$next, ] = $g->update(self::press(KeyType::Char, 'c'));
        $this->assertSame(Lang::t('ui.chord_not_ready'), $next->notice);
        $this->assertSame($g->board, $next->board, 'the refusal changes no state');
        $this->assertNull($next->startedAt, 'a refused chord must not arm the clock');
    }

    public function testRefusedChordOnHiddenCellStaysSilent(): void
    {
        $g = Game::start(6, 6, 2, static fn(int $_max): int => 0, notice: 'hold me');
        [$next, ] = $g->update(self::press(KeyType::Char, 'c'));
        $this->assertSame('hold me', $next->notice, 'chording unexplored ground teaches nothing');
    }

    public function testChordToastSurvivesDeadPressesAndDiesOnARealChange(): void
    {
        $g = self::gameOnNumber();
        [$toasted, ] = $g->update(self::press(KeyType::Char, 'c'));
        $this->assertSame(Lang::t('ui.chord_not_ready'), $toasted->notice);

        [$held, ] = $toasted->update(self::press(KeyType::Char, 'x'));
        $this->assertSame(Lang::t('ui.chord_not_ready'), $held->notice);

        [$cleared, ] = $held->update(self::press(KeyType::Up));
        $this->assertNull($cleared->notice, 'a real cursor move dismisses it');
    }
}
