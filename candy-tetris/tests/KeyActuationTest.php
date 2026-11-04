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
        [, $quitCmd] = $playing->update(new KeyMsg(KeyType::Char, 'Q'));
        self::assertNotNull($quitCmd, 'Caps Lock must not strand quit');
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
}
