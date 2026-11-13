<?php

/**
 * candy-tetris/tests/DestructiveInputTest.php
 *
 * candy-tetris-054/-083: destructive inputs pay a toll in live play and
 * nothing else. A single stray q used to vaporise a run silently — and
 * q is the direct neighbour of the whole pause family on the keyboard —
 * while r did nothing at all until the game was already dead. Now the
 * first press of either asks (a confirm line on the frame, naming the
 * LIVE binding), the same key again carries the act out, and any other
 * press retires the question. Menu postures pay no toll: paused, behind
 * the help card, on the too-small notice, the intro card and the over
 * screen, q leaves immediately exactly as before; the ctrl+c chord is
 * deliberate and leaves on the press (its own -034 promise); and VS
 * never arms a surrender at all — mid-match the forwarded r would reset
 * the human board behind banners that never show the question.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Lang;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Support\Bindings;
use SugarCraft\Tetris\VsGame;

final class DestructiveInputTest extends TestCase
{
    private function fresh(array $changes = []): Game
    {
        return Game::start(new Bag(static fn (int $max): int => 0))->mutate($changes);
    }

    private function key(string $which): \SugarCraft\Core\Msg\KeyMsg
    {
        return match ($which) {
            'left' => new \SugarCraft\Core\Msg\KeyMsg(KeyType::Left, ''),
            default => new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, $which),
        };
    }

    // ------------------------------------------------------------------
    // q while playing
    // ------------------------------------------------------------------

    public function testASingleStrayQArmsTheQuestionInsteadOfEndingTheRun(): void
    {
        [$armed, $cmd] = $this->fresh()->update($this->key('q'));

        self::assertNull($cmd, 'the misreach must not dispatch a quit (candy-tetris-054)');
        self::assertSame('quit', $armed->armed);
        self::assertStringContainsString(
            Lang::t('confirm.quit', ['keys' => 'q or ctrl+c']),
            $armed->view(),
            'the frame must ask, naming the live binding family',
        );
    }

    public function testTheSecondPressLeavesAndAnyOtherPressRetiresTheQuestion(): void
    {
        $armed = $this->fresh()->update($this->key('q'))[0];
        [, $leaveCmd] = $armed->update($this->key('q'));
        self::assertNotNull($leaveCmd, 'the confirmed q leaves');

        [$moved, $cmd] = $armed->update($this->key('left'));
        self::assertNull($moved->armed, 'attention moved on — the question is answered no');
        self::assertSame($armed->piece->x - 1, $moved->piece->x, 'the other press still plays');
        self::assertStringNotContainsString(Lang::t('confirm.quit', ['keys' => 'q or ctrl+c']), $moved->view());
    }

    public function testTheArmedQuestionSurvivesGravityTicks(): void
    {
        $armed = $this->fresh()->update($this->key('q'))[0];
        [$held, $cmd] = $armed->update(new \SugarCraft\Tetris\GravityMsg($armed->gravityChain));

        self::assertSame('quit', $held->armed, 'a falling piece does not answer the question');
        self::assertNotNull($cmd, 'the chain keeps beating under the prompt');
    }

    public function testMenuPosturesLeaveOnTheFirstPressExactlyAsBefore(): void
    {
        $paused = $this->fresh()->update($this->key('p'))[0];
        [, $cmd] = $paused->update($this->key('q'));
        self::assertNotNull($cmd, 'q inside pause is a menu choice, not a misreach');

        $reading = $this->fresh()->update($this->key('h'))[0];
        self::assertTrue($reading->helpOpen);
        [, $cmd] = $reading->update($this->key('q'));
        self::assertNotNull($cmd, 'behind the card q leaves at once');

        $squeezed = $this->fresh(['frameRows' => 10]);
        [, $cmd] = $squeezed->update($this->key('q'));
        self::assertNotNull($cmd, 'the notice screen keeps its immediate escape');

        $intro = $this->fresh(['introPending' => true]);
        [, $cmd] = $intro->update($this->key('q'));
        self::assertNotNull($cmd, 'the on-ramp card is escapable in one press');
    }

    public function testTheOverBannerOwnsTheBandWhenADeathCatchesAnArmedRun(): void
    {
        $dead = $this->fresh(['over' => true, 'armed' => 'quit']);
        $frame = $dead->view();

        self::assertStringContainsString(Lang::t('over.title'), $frame);
        self::assertStringNotContainsString(
            Lang::t('confirm.quit', ['keys' => 'q or ctrl+c']),
            $frame,
            'the death report is never hidden behind a question that no longer applies',
        );
    }

    public function testARemappedQuitIsAskedForByItsRealKey(): void
    {
        $game = $this->fresh(['bindings' => Bindings::table()->withOverride('quit', 'x')]);

        [$untouched, $cmd] = $game->update($this->key('q'));
        self::assertNull($cmd);
        self::assertNull($untouched->armed, 'q is not bound here — nothing arms');

        [$armed, $cmd] = $game->update($this->key('x'));
        self::assertNull($cmd);
        self::assertSame('quit', $armed->armed);
        self::assertStringContainsString(Lang::t('confirm.quit', ['keys' => 'x']), $armed->view());
    }

    // ------------------------------------------------------------------
    // r while playing (candy-tetris-083)
    // ------------------------------------------------------------------

    public function testALiveSurrenderAsksAndTheConfirmationLandsAFreshBoard(): void
    {
        $game = $this->fresh(['frameRows' => 30]);
        [$armed, $cmd] = $game->update($this->key('r'));
        self::assertNull($cmd, 'the first r surrenders nothing');
        self::assertSame('restart', $armed->armed);
        self::assertStringContainsString(Lang::t('confirm.restart', ['keys' => 'r']), $armed->view());

        [$fresh, $tickCmd] = $armed->update($this->key('r'));
        self::assertNotNull($tickCmd, 'the fresh board must have its chain armed');
        self::assertFalse($fresh->over);
        self::assertSame(0, $fresh->score->points);
        self::assertSame($armed->gravityChain + 1, $fresh->gravityChain, 'the epoch guard rides (candy-tetris-003)');
        self::assertSame(30, $fresh->frameRows, 'the live terminal size survives the surrender');
        foreach ($fresh->board->rows() as $row) {
            self::assertSame(array_fill(0, Board::COLS, null), $row, 'the surrendered board is gone, empty lands');
        }
        self::assertNull($fresh->armed, 'the new run starts with no pending question');

        // A tick in flight from the dead chain lands inert on the fresh board.
        [$untouched, $cmd] = $fresh->update(new \SugarCraft\Tetris\GravityMsg($armed->gravityChain));
        self::assertNull($cmd);
        self::assertSame($fresh->piece->y, $untouched->piece->y);
    }

    public function testTheSurrenderSettlesNothingBecauseItWasNeverOver(): void
    {
        $dir = sys_get_temp_dir() . '/tetris-confirm-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/scores.json';
        try {
            // A wired scoresPath is the strongest possible probe: if any
            // surrender path settled the unfinished run, the file would
            // appear. It must not — the record is written by the alive→
            // over crossing alone (the MA-23 law -083 keeps unbroken).
            $game = Game::start(new Bag(static fn (int $max): int => 0), scoresPath: $path);
            [$armed] = $game->update($this->key('r'));
            [$fresh] = $armed->update($this->key('r'));

            self::assertFileDoesNotExist($path, 'a surrendered run enters no record');
            self::assertNull($fresh->scores, 'the fresh run carries no settlement of the old one');
        } finally {
            @rmdir($dir);
        }
    }

    public function testTheOverScreenRestartStaysImmediateTheMenuChoiceItAlwaysWas(): void
    {
        $dead = $this->fresh(['over' => true]);
        [$fresh, $cmd] = $dead->update($this->key('r'));

        self::assertNull($fresh->armed, 'no question on a menu posture');
        self::assertFalse($fresh->over, 'the over r restarts at once');
        self::assertNotNull($cmd);
    }

    public function testVersusNeverArmsASurrender(): void
    {
        $match = VsGame::start();
        [$after, $cmd] = $match->update($this->key('r'));

        self::assertNull($cmd);
        self::assertNull($after->player->armed, 'the live surrender is a solo affordance (candy-tetris-083)');
        self::assertSame($match->player->piece->x, $after->player->piece->x, 'and the forwarded r moves nothing');
    }

    // ------------------------------------------------------------------
    // Frame law
    // ------------------------------------------------------------------

    public function testThePromptPaintsThroughTheBandMachineryWithoutGrowingTheFrame(): void
    {
        $game = $this->fresh(['frameRows' => Renderer::MIN_ROWS]);
        $before = $game->view();
        $armed = $game->update($this->key('q'))[0];
        $after = $armed->view();

        self::assertCount(
            count(explode("\n", $before)),
            explode("\n", $after),
            'a confirm line is painted INTO the frame, never below it',
        );
        self::assertStringContainsString(Lang::t('confirm.quit', ['keys' => 'q or ctrl+c']), $after);
    }
}
