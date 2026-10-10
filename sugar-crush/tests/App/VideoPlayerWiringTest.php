<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tests\Support\VideoPlayerScriptedDecoder;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\VideoPlayer\OpenVideoPlayerMsg;
use SugarCraft\Crush\Tui\VideoPlayer\PlayerReadyMsg;
use SugarCraft\Crush\Tui\VideoPlayer\VideoPlayerScreen;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Msg\TickMsg as ReelTickMsg;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\Mode;

/**
 * The W5.4 shell wiring around the player: the slot's lifecycle through
 * App::update — open behind the Preparing frame, absorb the answer, the
 * modal key gate with its close interception and its quit-chord exception,
 * replace-if-open teardown, inert mouse, and self-resizing — all pinned
 * with a scripted, child-free decoder behind the openVideoPlayer seam.
 */
final class VideoPlayerWiringTest extends TestCase
{
    private const CLIP = '/media/w5b/clip.mp4';

    private ProviderInterface $vwProvider;

    protected function setUp(): void
    {
        $this->vwProvider = $this->createMock(ProviderInterface::class);
        $this->vwProvider->method('name')->willReturn('stub');
        TuiRenderer::setSize(200, 60);
        VideoPlayerScreen::stopActive();
    }

    protected function tearDown(): void
    {
        VideoPlayerScreen::stopActive();
        TuiRenderer::setSize(200, 60);
    }

    public function testOpenArmRaisesPreparingAndArmsTheBuildPromise(): void
    {
        [$app, $cmd] = $this->vwApp()->update(new OpenVideoPlayerMsg('/media/w5b/late.mp4'));

        $screen = $app->videoPlayer;
        self::assertNotNull($screen);
        self::assertSame(VideoPlayerScreen::STATE_PREPARING, $screen->state);
        self::assertNotNull($cmd, 'the probe/spawn rides a promise Cmd, never the sync path');
    }

    public function testReadyAnswerThenCloseChordStopsTheChildAndDropsTheSlot(): void
    {
        [$player, $decoder] = $this->vwScripted();
        $app = $this->vwReadyApp($player);

        self::assertSame(VideoPlayerScreen::STATE_READY, $app->videoPlayer?->state);

        [$closed, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertNull($cmd);
        self::assertNull($closed->videoPlayer);
        self::assertSame(1, $decoder->vpCloseCount(), 'q stops the clip it closes');
    }

    public function testEscapeClosesTheSameWay(): void
    {
        [$player, $decoder] = $this->vwScripted();
        $app = $this->vwReadyApp($player);

        [$closed] = $app->update(new KeyMsg(KeyType::Escape));

        self::assertNull($closed->videoPlayer);
        self::assertSame(1, $decoder->vpCloseCount());
    }

    public function testQuitChordYieldsTheGateAndLeavesTheScreenUp(): void
    {
        // Ctrl+C stays the app-wide chord (settingsEditorYields law): it must
        // NOT be consumed as a screen close. The player's reap on that exit
        // path is the shutdown teardown, pinned separately below.
        [$player, $decoder] = $this->vwScripted();
        $app = $this->vwReadyApp($player);

        [$after, $cmd] = $app->update(new KeyMsg(KeyType::Char, "\x03"));

        self::assertNotNull($after->videoPlayer, 'the chord is yielded to the app, not eaten here');
        self::assertSame($player, $after->videoPlayer?->player());
    }

    public function testGateForwardingKeepsPlayerKeysInsideTheScreen(): void
    {
        $decoder = new VideoPlayerScriptedDecoder($this->vwFrames(4));
        $paused = Player::fromDecoder($decoder, 8, 3, 10.0, Mode::HalfBlock, 4, self::CLIP, paused: true);
        $app = $this->vwReadyApp($paused);

        [$playing, $cmd] = $app->update(new KeyMsg(KeyType::Space, rune: ' '));

        self::assertNotNull($cmd, 'space reaches the Player and arms its tick through the shell');
        self::assertNotNull($playing->videoPlayer);
    }

    public function testReplaceWhileOpenStopsTheOldClipFirst(): void
    {
        [$first, $firstDecoder] = $this->vwScripted();
        $app = $this->vwReadyApp($first);

        [$second, $secondDecoder] = $this->vwScripted();
        [$replaced, $cmd] = $app->openVideoPlayer('/media/w5b/next.mp4',
            static fn (): Player => $second);

        self::assertSame(1, $firstDecoder->vpCloseCount(), 'at most one decoder child lives per slot');
        self::assertSame(VideoPlayerScreen::STATE_PREPARING, $replaced->videoPlayer?->state);
        self::assertNotNull($cmd);
    }

    public function testMouseIsInertOverThePicture(): void
    {
        [$player] = $this->vwScripted();
        $app = $this->vwReadyApp($player);

        [$same, $cmd] = $app->update(new MouseClickMsg(5, 5, MouseButton::Left, MouseAction::Press));

        self::assertNull($cmd);
        self::assertSame($player, $same->videoPlayer?->player(), 'no zone, no scroll, no focus move');
    }

    public function testWindowSizeReachesThePlayersOwnRebuild(): void
    {
        [$player, $decoder] = $this->vwScripted();
        $app = $this->vwReadyApp($player);

        [$resized, $cmd] = $app->update(new WindowSizeMsg(120, 30));

        self::assertNotNull($resized->videoPlayer);
        self::assertSame(30, $resized->rows);
        self::assertSame(120, $resized->cols);
    }

    public function testStrayReelTickWithoutASlotIsDroppedNotDelegated(): void
    {
        $app = $this->vwApp();

        [$same, $cmd] = $app->update(new ReelTickMsg());

        self::assertNull($cmd);
        self::assertNull($same->videoPlayer);
    }

    public function testLateAnswerAfterCloseStopsTheOrphanBuild(): void
    {
        [$player, $decoder] = $this->vwScripted();
        $app = $this->vwReadyApp($player);
        [$closed] = $app->update(new KeyMsg(KeyType::Char, 'q'));

        [$orphan, $orphanDecoder] = $this->vwScripted();
        [$same, $cmd] = $closed->update(new PlayerReadyMsg($orphan, null));

        self::assertNull($cmd);
        self::assertNull($same->videoPlayer);
        self::assertSame(1, $orphanDecoder->vpCloseCount(), 'a player built for a dead slot dies at the door');
    }

    public function testBuildPromiseAdoptsThePlayerBeforeTheAnswerCanLose(): void
    {
        [$player, $decoder] = $this->vwScripted();
        $app = $this->vwApp();

        [, $cmd] = $app->openVideoPlayer(self::CLIP, static fn (): Player => $player);
        self::assertNotNull($cmd);

        // Invoking the promise Cmd runs the build synchronously — exactly
        // what the Program's AsyncCmd does — and adoption must already
        // cover the player at this instant, before any PlayerReadyMsg lands.
        ($cmd)();
        self::assertTrue(VideoPlayerScreen::hasActivePlayer(), 'adopt happens inside the promise');

        // An app exit from HERE runs the shutdown hook and stops the clip.
        // A mere close of the slot instead hands the same duty to the
        // superseding adopt: the late answer onto the closed slot dies at
        // the door (driveVideoPlayer law, pinned by the test above) and a
        // newer build's adopt stops the memo it supersedes.
        [$second, $secondDecoder] = $this->vwScripted();
        [, $cmd2] = $app->openVideoPlayer('/media/w5b/next.mp4', static fn (): Player => $second);
        ($cmd2)();

        self::assertSame(1, $decoder->vpCloseCount(), 'the superseded adopted build is stopped by the newer adopt');
        self::assertSame(0, $secondDecoder->vpCloseCount());
        self::assertTrue(VideoPlayerScreen::hasActivePlayer());
    }

    private function vwApp(): App
    {
        return App::new($this->vwProvider, 'test-model');
    }

    /**
     * @return array{0: Player, 1: VideoPlayerScriptedDecoder}
     */
    private function vwScripted(): array
    {
        $decoder = new VideoPlayerScriptedDecoder($this->vwFrames(4));

        return [Player::fromDecoder($decoder, 8, 3, 10.0, Mode::HalfBlock, 4, self::CLIP, paused: false), $decoder];
    }

    /**
     * @return list<RgbFrame>
     */
    private function vwFrames(int $count): array
    {
        $frames = [];

        for ($i = 0; $i < $count; $i++) {
            $frames[] = new RgbFrame(str_repeat(chr(40 + $i * 30), 8 * 6 * 3), 8, 6);
        }

        return $frames;
    }

    private function vwReadyApp(Player $player): App
    {
        [$preparing] = $this->vwApp()->openVideoPlayer(self::CLIP, static fn (): Player => $player);
        [$ready] = $preparing->update(new PlayerReadyMsg($player, null));

        return $ready;
    }
}
