<?php

/**
 * candy-tetris/tests/KeyEducationTest.php
 *
 * candy-tetris-006/-095: key education survives the budget. On an 80x24
 * terminal the sidebar help card is the first casualty of the frame
 * contract, and until this row NOTHING on a fresh run after the intro
 * listed the keys at all — the player flew blind on the most common
 * terminal size, and --help answered with flags only.
 *
 * The cure is threefold and every leg is DERIVED from the live table, so
 * a remap moves the education with it (the m27 card law extended to the
 * pointer, the overlay and --help):
 *  - the compact one-row key line where the card is budgeted out;
 *  - the live 'help' press painting the full derived card over any
 *    posture, freezing the world underneath while it reads;
 *  - a --help that carries the same derived card plus the terminal floor
 *    read from the renderer's own constants.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\GravityMsg;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Lang;
use SugarCraft\Tetris\LaunchMode;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Support\Bindings;

final class KeyEducationTest extends TestCase
{
    private function fresh(int $frameRows = Renderer::NATURAL_ROWS): Game
    {
        return Game::start()->mutate(['frameRows' => $frameRows]);
    }

    /** @param array<string,mixed> $changes */
    private function armed(Game $game, array $changes): Game
    {
        return $game->mutate($changes + ['frameRows' => Renderer::MIN_ROWS]);
    }

    // ------------------------------------------------------------------
    // The compact key line (the card's budgeted absence must not be a
    // blackout: >=1 key line on every on-frame posture).
    // ------------------------------------------------------------------

    public function testAFloorSizedFreshFrameAlwaysShowsAKeyLine(): void
    {
        $frame = $this->fresh(Renderer::MIN_ROWS)->view();

        self::assertStringContainsString(
            Lang::t('keys.mini', ['keys' => 'h']),
            $frame,
            'a 24-row terminal must show at least the press that re-opens the card (candy-tetris-006)',
        );
        // And it is the POINTER only — the full card stays the luxury it
        // was; the row count contract of the floor frame is untouched.
        self::assertStringNotContainsString('rotate ccw', $frame);
    }

    public function testTheNaturalFrameKeepsTheFullCardAndNoPointerRow(): void
    {
        $frame = $this->fresh()->view();

        self::assertStringContainsString('rotate ccw', $frame, 'full posture keeps the derived card');
        self::assertStringNotContainsString(
            Lang::t('keys.mini', ['keys' => 'h']),
            $frame,
            'the pointer replaces the card only where the card cannot fit',
        );
    }

    public function testThePointerAndTheOverlayFollowARemappedHelpKey(): void
    {
        $remapped = $this->fresh(Renderer::MIN_ROWS)
            ->mutate(['bindings' => Bindings::table()->withOverride('help', 'j')]);

        $frame = $remapped->view();
        self::assertStringContainsString(Lang::t('keys.mini', ['keys' => 'j']), $frame);
        self::assertStringNotContainsString(Lang::t('keys.mini', ['keys' => 'h']), $frame);

        // The old key is inert, the new one opens the card.
        [$afterH] = $remapped->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertFalse($afterH->helpOpen, 'h must be dead once the table moved to j');
        [$afterJ] = $remapped->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertTrue($afterJ->helpOpen);
    }

    // ------------------------------------------------------------------
    // The live overlay: reachable from play and pause, closes with the
    // same press, and the world is frozen while it reads.
    // ------------------------------------------------------------------

    public function testTheHelpPressOpensTheFullDerivedCardOverAFloorFrame(): void
    {
        $game = $this->fresh(Renderer::MIN_ROWS);
        [$opened, $cmd] = $game->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertNull($cmd, 'toggling a view flag schedules nothing');
        self::assertTrue($opened->helpOpen);
        $frame = $opened->view();
        foreach (explode("\n", Bindings::helpCard(Bindings::table())) as $row) {
            self::assertStringContainsString($row, $frame, "overlay must carry the live card row '$row'");
        }
        self::assertStringContainsString(Lang::t('help.keys'), $frame);
        self::assertStringContainsString(Lang::t('help.close', ['keys' => 'h']), $frame);
    }

    public function testTheSamePressClosesTheCardAgain(): void
    {
        $game = $this->fresh()->update(new KeyMsg(KeyType::Char, 'h'))[0];
        [$closed] = $game->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertFalse($closed->helpOpen);
        self::assertStringNotContainsString(Lang::t('help.close', ['keys' => 'h']), $closed->view());
    }

    public function testTheCardIsReachableFromPauseAndReturnsToThePauseBanner(): void
    {
        $paused = $this->fresh()->update(new KeyMsg(KeyType::Char, 'p'))[0];
        self::assertTrue($paused->paused);

        [$reading] = $paused->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertTrue($reading->helpOpen);
        self::assertStringContainsString('rotate ccw', $reading->view());

        [$back] = $reading->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertStringContainsString(Lang::t('paused', ['keys' => 'p']), $back->view());
    }

    public function testTheWorldFreezesBehindTheCard(): void
    {
        $game = $this->fresh();
        [$reading] = $game->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertTrue($reading->helpOpen);

        // A gravity tick heartbeats instead of stepping.
        [$held, $cmd] = $reading->update(new GravityMsg($reading->gravityChain));
        self::assertSame($held->piece->y, $reading->piece->y, 'no descent while the card is open');
        self::assertNotNull($cmd, 'the chain must keep its heartbeat');

        // And the gameplay keys are inert under it.
        [$still] = $held->update(new KeyMsg(KeyType::Left, ''));
        self::assertSame($still->piece->x, $reading->piece->x, 'no movement while the card is open');

        [$back] = $still->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertFalse($back->helpOpen);
        [$moved] = $back->update(new KeyMsg(KeyType::Left, ''));
        self::assertSame($moved->piece->x, $reading->piece->x - 1, 'the world resumes when it closes');
    }

    // ------------------------------------------------------------------
    // Budget law: the pointer pays LAST, behind the card, the seed and
    // only on the fully armed stamped floor terminal.
    // ------------------------------------------------------------------

    public function testTheArmedStampedFloorSacrificesTheStampThenThePointerNotABadge(): void
    {
        $corner = $this->armed($this->fresh(), [
            'lastAward'     => 'TETRIS',
            'combo'         => 3,
            'backToBack'    => true,
            'reproducible'  => true,
            'seed'          => 5,
        ]);

        $frame = $corner->view();
        self::assertStringContainsString('TETRIS', $frame);
        self::assertStringContainsString('combo x3', $frame);
        self::assertStringContainsString('B2B armed', $frame);
        self::assertStringNotContainsString('SEED', $frame, 'the stamp pays before the pointer (candy-tetris-006 rung order)');
        self::assertStringNotContainsString(
            Lang::t('keys.mini', ['keys' => 'h']),
            $frame,
            'only this corner state lets the pointer go — badges never do',
        );
        self::assertLessThanOrEqual(Renderer::MIN_ROWS, count(explode("\n", $frame)));
    }

    public function testTheFullyArmedFloorPaysThePointerTooButNeverABadge(): void
    {
        // Two or more badge rows on the 24-row floor: pointer gives way,
        // badges never do (their moment earned is their moment painted).
        $armed = $this->armed($this->fresh(), [
            'lastAward'    => 'TETRIS',
            'combo'        => 3,
            'backToBack'   => true,
            'reproducible' => false,
        ]);

        $frame = $armed->view();
        self::assertStringContainsString('B2B armed', $frame);
        self::assertStringNotContainsString(Lang::t('keys.mini', ['keys' => 'h']), $frame);
        self::assertLessThanOrEqual(Renderer::MIN_ROWS, count(explode("\n", $frame)));
    }

    public function testASingleBadgeRowOnTheFloorStillKeepsThePointer(): void
    {
        $earned = $this->armed($this->fresh(), [
            'lastAward'    => 'TETRIS',
            'reproducible' => false,
        ]);

        $frame = $earned->view();
        self::assertStringContainsString('TETRIS', $frame);
        self::assertStringContainsString(
            Lang::t('keys.mini', ['keys' => 'h']),
            $frame,
            'one badge row leaves room for the pointer — education only pays when geometry forces it',
        );
        self::assertLessThanOrEqual(Renderer::MIN_ROWS, count(explode("\n", $frame)));
    }

    // ------------------------------------------------------------------
    // The on-ramp advertises the reachable reference (fresh runs learn
    // the press before they need it).
    // ------------------------------------------------------------------

    public function testTheIntroCardNamesTheLiveHelpPress(): void
    {
        $intro = Game::start()->mutate(['introPending' => true]);
        $frame = $intro->view();

        self::assertStringContainsString('welcome to candy-tetris', $frame);
        self::assertStringContainsString(Lang::t('intro.keys', ['keys' => 'h']), $frame);
    }

    public function testTheIntroAdvertisesTheRemappedHelpPressToo(): void
    {
        $intro = Game::start(bindings: Bindings::table()->withOverride('help', 'j'))
            ->mutate(['introPending' => true]);

        self::assertStringContainsString(
            Lang::t('intro.keys', ['keys' => 'j']),
            $intro->view(),
        );
    }

    // ------------------------------------------------------------------
    // --help: keys and floor, derived, cannot drift (candy-tetris-095).
    // ------------------------------------------------------------------

    public function testHelpTextCarriesEveryLiveCardRowAndTheFloorConstants(): void
    {
        $help = LaunchMode::helpText();

        foreach (explode("\n", Bindings::helpCard(Bindings::table())) as $row) {
            self::assertStringContainsString($row, $help, "--help must teach the live row '$row'");
        }
        self::assertStringContainsString(Lang::t('help.keys'), $help);
        self::assertStringContainsString('40x24', $help, 'the floor numbers ride the Renderer constants');
        self::assertStringContainsString(Lang::t('help.floor', ['cols' => '40', 'rows' => '24']), $help);
        // The flag half of the screen is untouched (LaunchModeTest pins it).
        self::assertStringContainsString('-h, --help', $help);
    }

    public function testHelpTextNamesTheSameDefaultTokensTheRegistryShips(): void
    {
        $help = LaunchMode::helpText();

        // Spot the vocabulary, not a hand-copied card: if the shipped 'z'
        // ever moves, this pin must move with the table — never the text.
        foreach (['← →', '↑ x', 'z', '↓', 'spc', 'c', 'p', 'q'] as $glyph) {
            self::assertStringContainsString($glyph, $help, "the key column must name '$glyph'");
        }
        self::assertStringContainsString('ctrl+c', $help, 'the shipped interrupt chord is taught on --help too');
    }
}
