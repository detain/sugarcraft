<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyPressMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\KeyRepeatMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\VsGame;

/**
 * m11 + m26: character bindings actuate on presses only, and case does
 * not matter. KeyReleaseMsg/KeyRepeatMsg ride the same KeyMsg base class,
 * so an unfiltered instanceof arm fires once per press AND once per
 * release — a negotiated REPORT_EVENT_TYPES terminal would double every
 * toggle. Caps Lock stranded every lowercase-matched binding, quit
 * included (the game became impossible to leave).
 */
final class KeyActuationTest extends TestCase
{
    private static function fresh(): Game
    {
        return Game::start(new Bag(static fn (int $max): int => 0));
    }

    public function testKeyReleaseNeverActuatesASoloBinding(): void
    {
        $game = self::fresh();

        [$after, $cmd] = $game->update(new KeyReleaseMsg(KeyType::Char, 'p'));

        self::assertSame($game, $after, 'a release must not rebuild the model');
        self::assertNull($cmd, 'a release must not emit a command');
        self::assertFalse($after->paused, '...and pause specifically stays untouched');
    }

    public function testKeyRepeatNeverActuatesASoloBinding(): void
    {
        $game = self::fresh();

        [$after, $cmd] = $game->update(new KeyRepeatMsg(KeyType::Char, 'p'));

        self::assertSame($game, $after, 'auto-repeat is filtered upstream; the model must not double-act');
        self::assertNull($cmd);
    }

    public function testKeyPressActuatesPause(): void
    {
        $game = self::fresh();

        [$after, $cmd] = $game->update(new KeyPressMsg(KeyType::Char, 'p'));

        self::assertTrue($after->paused, 'a real press pauses');
        self::assertNull($cmd, 'pausing emits no command');
    }

    public function testBareKeyMsgStillActuates(): void
    {
        $game = self::fresh();

        [$after, $cmd] = $game->update(new KeyMsg(KeyType::Char, 'p'));

        self::assertTrue($after->paused, 'the InputReader default path is a bare KeyMsg — it must keep working');
        self::assertNull($cmd);
    }

    public function testUppercaseBindingsActuateLikeLowercaseWhilePlaying(): void
    {
        $game = self::fresh();

        [$paused, $cmd] = $game->update(new KeyMsg(KeyType::Char, 'P'));
        self::assertTrue($paused->paused, 'Caps Lock must not strand pause');
        self::assertNull($cmd);

        $playing = self::fresh();
        [$armedQ, $quitCmd] = $playing->update(new KeyMsg(KeyType::Char, 'Q'));
        // candy-tetris-054 moved live play to the confirm dance — which
        // makes the fold proof even stricter here: 'Q' must ARM (an
        // unfolded Q would leave armed null), and the second 'Q' leaves.
        self::assertSame('quit', $armedQ->armed, 'Caps Lock must not strand quit');
        self::assertNull($quitCmd);
        [, $secondQ] = $armedQ->update(new KeyMsg(KeyType::Char, 'Q'));
        self::assertNotNull($secondQ, 'the confirmed uppercase press leaves');
    }

    public function testUppercaseRestartRevivesAGameOverSession(): void
    {
        $dead = self::fresh()->mutate(['over' => true]);

        [$after, $cmd] = $dead->update(new KeyMsg(KeyType::Char, 'R'));

        self::assertFalse($after->over, "'R' restarts exactly like 'r'");
        self::assertNotNull($cmd, 'and re-arms the gravity chain on the way');
    }

    public function testVsPauseIgnoresReleasesAndHonoursPressesAndCaps(): void
    {
        $vs = VsGame::start();

        [$released, $releaseCmd] = $vs->update(new KeyReleaseMsg(KeyType::Char, 'p'));
        self::assertFalse($released->player->paused, 'a VS release must not toggle the inner player');
        self::assertNull($releaseCmd);

        [$pressed, $pressCmd] = $vs->update(new KeyPressMsg(KeyType::Char, 'p'));
        self::assertTrue($pressed->player->paused, 'a VS press pauses the player panel');
        self::assertNull($pressCmd);

        [$caps, $capsCmd] = $vs->update(new KeyMsg(KeyType::Char, 'P'));
        self::assertTrue($caps->player->paused, 'Caps Lock pauses in VS too');
        self::assertNull($capsCmd);
    }

    public function testVsGameOverAcceptsUppercaseQuitAndRestart(): void
    {
        $mutate = new \ReflectionMethod(VsGame::class, 'mutate');
        $mutate->setAccessible(true);

        $dead = $mutate->invoke(VsGame::start(), ['over' => true]);

        [, $restartCmd] = $dead->update(new KeyMsg(KeyType::Char, 'R'));
        // The over-block only answers 'r'; an uppercase 'R' must restart too.
        // Restart returns a NEW live game plus a tick command.
        self::assertNotNull($restartCmd, "'R' restarts a finished VS match");

        $deadAgain = $mutate->invoke(VsGame::start(), ['over' => true]);
        [, $quitCmd] = $deadAgain->update(new KeyMsg(KeyType::Char, 'Q'));
        self::assertNotNull($quitCmd, "'Q' quits a finished VS match");
    }

    public function testTheInterruptChordQuitsMidDropInsteadOfHolding(): void
    {
        // candy-tetris-034: Ctrl+C is the one exit every terminal already
        // speaks, but the raw-mode reader swallows the byte and the old
        // modifier-blind registry folded it into the plain 'c' token —
        // the interrupt actuated HOLD mid-drop and exited nothing. Under
        // the chord discipline it quits, and holds nothing in its wake.
        $game = self::fresh();

        [$after, $cmd] = $game->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));

        self::assertNotNull($cmd, 'ctrl+c answers the quit gate');
        self::assertNull($after->hold, 'ctrl+c must not consume the hold slot on the way out');
    }

    public function testThePlainHoldStillAnswersAndOtherChordsStayInert(): void
    {
        // The chord discipline cuts both ways: the plain 'c' keeps holding
        // (the interrupt did not eat its binding), and a ctrl press whose
        // chord nobody binds actuates NOTHING — not even its plain twin.
        $game = self::fresh();

        [$held, $holdCmd] = $game->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertNotNull($held->hold, 'a bare c still holds');
        self::assertNull($holdCmd);

        [$untouched, $cmd] = $game->update(new KeyMsg(KeyType::Char, rune: 'z', ctrl: true));
        self::assertSame($game, $untouched, 'ctrl+z is unbound: inert, and must not rotate-ccw through the fold');
        self::assertNull($cmd);
    }

    public function testTheInterruptWorksBehindEverySoloScreen(): void
    {
        // Quit is taught as ctrl+c on every frame; each screen that answers
        // 'q' must answer the chord too (intro, over, and the too-small
        // notice are the surfaces a stranded player meets).
        $intro = Game::start(new Bag(static fn (int $max): int => 0), introPending: true);
        [, $introCmd] = $intro->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));
        self::assertNotNull($introCmd, 'the on-ramp card is escapable');

        $dead = self::fresh()->mutate(['over' => true]);
        [, $overCmd] = $dead->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));
        self::assertNotNull($overCmd, 'the over screen is escapable');

        $squeezed = self::fresh()->mutate(['frameRows' => 10]);
        [, $noticeCmd] = $squeezed->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));
        self::assertNotNull($noticeCmd, 'the resize notice is escapable');
    }

    public function testTheInterruptWorksBehindEveryVersusScreen(): void
    {
        $mutate = new \ReflectionMethod(VsGame::class, 'mutate');
        $mutate->setAccessible(true);

        [, $playCmd] = VsGame::start()->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));
        self::assertNotNull($playCmd, 'ctrl+c quits a live match');

        $dead = $mutate->invoke(VsGame::start(), ['over' => true]);
        [, $overCmd] = $dead->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));
        self::assertNotNull($overCmd, 'ctrl+c quits a finished match');

        $squeezed = $mutate->invoke(VsGame::start(), ['frameRows' => 10]);
        [, $noticeCmd] = $squeezed->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));
        self::assertNotNull($noticeCmd, 'ctrl+c quits below the frame floor');
    }

    public function testTheInterruptNeverDoubleFiresOnReleaseOrRepeat(): void
    {
        // The chord leg carries the m11 press-shape rule: an auto-repeat of
        // a held ctrl+c is one exit, not every frame a quit command.
        [, $repeat] = self::fresh()->update(new KeyRepeatMsg(KeyType::Char, 'c'));
        self::assertNull($repeat);

        $repeater = new KeyRepeatMsg(KeyType::Char, rune: 'c', ctrl: true);
        [, $cmd] = self::fresh()->update($repeater);
        self::assertNull($cmd, 'a ctrl+c REPEAT must not answer the quit gate');
    }
}
