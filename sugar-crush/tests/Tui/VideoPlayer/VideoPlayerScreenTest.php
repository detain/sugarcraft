<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\VideoPlayer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Tests\Support\VideoPlayerScriptedDecoder;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\MediaForm\OpenMediaFormMsg;
use SugarCraft\Crush\Tui\VideoPlayer\PlayerReadyMsg;
use SugarCraft\Crush\Tui\VideoPlayer\Scrubber;
use SugarCraft\Crush\Tui\VideoPlayer\VideoPlayerKeys;
use SugarCraft\Crush\Tui\VideoPlayer\VideoPlayerScreen;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;

/**
 * The W5.4 player surface in isolation: state machine, chrome composition,
 * the graphics-mode cursor law, and teardown accounting — all driven by a
 * scripted, child-free decoder, never ffmpeg.
 */
final class VideoPlayerScreenTest extends TestCase
{
    private const CLIP = '/media/sess/clip.mp4';

    protected function setUp(): void
    {
        VideoPlayerScreen::stopActive();
    }

    protected function tearDown(): void
    {
        VideoPlayerScreen::stopActive();
    }

    public function testPreparingIsAPlaceholderThatClaimsNoPlayer(): void
    {
        $screen = VideoPlayerScreen::preparing(self::CLIP, Mode::HalfBlock);

        self::assertSame(VideoPlayerScreen::STATE_PREPARING, $screen->state);
        self::assertNull($screen->player());
        $view = $screen->view(Theme::byName('dark'), 60, 10);
        self::assertStringContainsString('preparing clip.mp4', $view);
        self::assertStringContainsString('q close', $view);
        self::assertTrue($screen->clipsLines(), 'placeholder rows are plain text');
    }

    public function testReadyAbsorbsTheAnsweredPlayerAndKeepsTheMode(): void
    {
        $screen = VideoPlayerScreen::preparing(self::CLIP, Mode::Kitty);
        [$player] = $this->vpScriptedPlayer();

        [$ready, $cmd] = $screen->update(new PlayerReadyMsg($player, null));

        self::assertNull($cmd, 'absorbing an answer arms nothing by itself');
        self::assertSame(VideoPlayerScreen::STATE_READY, $ready->state);
        self::assertSame($player, $ready->player());
        self::assertSame(Mode::Kitty, $ready->mode);
        self::assertFalse($ready->clipsLines(), 'kitty blobs are protocol bytes');
    }

    public function testFailedStateShowsTheBuildSentenceUntilClosed(): void
    {
        $screen = VideoPlayerScreen::preparing(self::CLIP, Mode::HalfBlock);
        [$failed, $cmd] = $screen->update(new PlayerReadyMsg(null, 'ffprobe is dead'));

        self::assertNull($cmd);
        self::assertSame(VideoPlayerScreen::STATE_FAILED, $failed->state);
        $view = $failed->view(Theme::byName('dark'), 60, 10);
        self::assertStringContainsString('ffprobe is dead', $view);
        self::assertStringContainsString('q closes this screen', $view);
    }

    public function testTextModeViewIsTheBlobPlusOneChromeRow(): void
    {
        [$player, $decoder] = $this->vpScriptedPlayer();
        $screen = $this->vpReady($player);

        // One tick advances the scripted clip so the clock is live.
        [$ticked, $cmd] = $screen->update(new TickMsg());
        self::assertNotNull($cmd, 'the Player arms its own tick; the screen invents no cadence');

        $blob = (string) $ticked->player()?->view();
        $view = $ticked->view(Theme::byName('dark'), 80, 12);

        self::assertStringStartsWith($blob, $view, 'the blob leads untouched');
        $chrome = substr($view, strlen($blob));
        self::assertStringStartsWith("\r\n", $chrome, 'text mode hangs exactly one row under the blob');
        self::assertStringContainsString(Scrubber::HEAD, $chrome);
        self::assertStringContainsString('q close', $chrome);
        self::assertSame(0, substr_count($chrome, "\n") - 1, 'the chrome is one row');

        $ticked->stop();
        self::assertSame(1, $decoder->vpCloseCount());
    }

    public function testSpaceOnAPausedPlayerArmsItsTickThroughTheScreen(): void
    {
        $decoder = new VideoPlayerScriptedDecoder($this->vpSolidFrames(4, Mode::HalfBlock, false));
        $paused = Player::fromDecoder($decoder, 8, 3, 10.0, Mode::HalfBlock, 4, self::CLIP, paused: true);
        $screen = $this->vpReady($paused);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Space, rune: ' '));

        self::assertNotNull($cmd, 'space reaches the Player through the screen and unpauses it');
    }

    public function testGraphicsChromeIsAppendedWithCursorOpsNotNewlines(): void
    {
        // Raw rgb24 frames: GraphicsRenderer encodes them through GD into the
        // iterm2 envelope itself — the scripted decoder never fakes a PNG.
        [$player] = $this->vpScriptedPlayer(Mode::Iterm2);
        $screen = $this->vpReady($player, Mode::Iterm2);
        [$ticked] = $screen->update(new TickMsg());

        $view = $ticked->view(Theme::byName('dark'), 80, 20);
        $cursor = Ansi::cursorTo(20, 1);

        $at = strpos($view, $cursor);
        self::assertGreaterThan(0, $at, 'the cursor op lands after the blob, not at position zero');

        $tail = substr($view, (int) $at);
        self::assertStringStartsWith($cursor . Ansi::eraseLine(), $tail);
        self::assertStringNotContainsString("\n", substr($tail, strlen($cursor)), 'no newline may ride after the cursor op');
        self::assertStringContainsString(Scrubber::HEAD, $tail);
        self::assertStringContainsString(VideoPlayerKeys::hint(), $tail);
        self::assertFalse($ticked->clipsLines());
    }

    public function testCloseKeysAreInterceptedByTheGateNotTheScreen(): void
    {
        self::assertTrue(VideoPlayerKeys::isClose(new KeyMsg(KeyType::Escape)));
        self::assertTrue(VideoPlayerKeys::isClose(new KeyMsg(KeyType::Char, 'q')));
        self::assertFalse(VideoPlayerKeys::isClose(new KeyMsg(KeyType::Char, 'q', ctrl: true)), 'ctrl+q is not the close chord');
        self::assertFalse(VideoPlayerKeys::isClose(new KeyMsg(KeyType::Char, 'a')));
        self::assertFalse(VideoPlayerKeys::isClose(new KeyMsg(KeyType::Char, ' ', alt: true)));
        self::assertFalse(VideoPlayerKeys::isClose(new KeyMsg(KeyType::Left)));
    }

    public function testStopReleasesTheAdoptedPlayerAndIsIdempotent(): void
    {
        [$player, $decoder] = $this->vpScriptedPlayer();
        VideoPlayerScreen::adopt($player);
        self::assertTrue(VideoPlayerScreen::hasActivePlayer());

        $screen = $this->vpReady($player);
        $screen->stop();
        $screen->stop();

        self::assertFalse(VideoPlayerScreen::hasActivePlayer(), 'the memo releases with the screen');
        self::assertSame(1, $decoder->vpCloseCount(), 'stop is latched, the child dies once');
    }

    /**
     * R13-C1: the video Player re-mints a new wrapper on every seek, resize
     * rebuild and mode cycle (immutability), while the live decoder rides
     * along. The shutdown memo must follow the newest wrapper — otherwise
     * the Ctrl+C teardown stops the superseded object and the child of the
     * player actually on screen is orphaned.
     */
    public function testSeekReMintMovesTheShutdownMemoOntoTheLiveWrapper(): void
    {
        [$player, $decoder] = $this->vpScriptedPlayer();
        VideoPlayerScreen::adopt($player);
        $screen = $this->vpReady($player);

        // Right = forward seek: advances the SAME decoder, mutates to a new
        // Player instance — the screen's swap branch must fire.
        [$seeked] = $screen->update(new KeyMsg(KeyType::Right));

        $live = $seeked->player();
        self::assertNotSame($player, $live, 'the seek re-minted the wrapper (swap branch premise)');
        self::assertSame($live, VideoPlayerScreen::activePlayer(), 'the memo follows the live wrapper');
        self::assertSame(0, $decoder->vpCloseCount(), 'reattaching stops nothing — the stream stays live');

        VideoPlayerScreen::stopActive();
        self::assertSame(1, $decoder->vpCloseCount(), 'the teardown stops the decoder exactly once, via the fresh memo');
    }

    public function testLateAnswerForAMovedOnScreenStopsTheBuilder(): void
    {
        [$first, $firstDecoder] = $this->vpScriptedPlayer();
        $screen = $this->vpReady($first);

        [$late, $lateDecoder] = $this->vpScriptedPlayer();
        [$same, $cmd] = $screen->update(new PlayerReadyMsg($late, null));

        self::assertNull($cmd);
        self::assertSame($screen, $same, 'a ready screen does not swap players');
        self::assertSame(1, $lateDecoder->vpCloseCount(), 'the orphaned build is stopped, never leaked');
        self::assertSame($first, $same->player());
        $first->stop();
    }

    public function testForeignMessagesAreNoOps(): void
    {
        [$player] = $this->vpScriptedPlayer();
        $screen = $this->vpReady($player);

        [$same, $cmd] = $screen->update(new OpenMediaFormMsg());

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testScrubberFormatsAndClampsDeterministically(): void
    {
        self::assertSame('0:00', Scrubber::formatTime(-5.0));
        self::assertSame('0:04', Scrubber::formatTime(4.9));
        self::assertSame('1:00', Scrubber::formatTime(60.0));
        self::assertSame('1:00:00', Scrubber::formatTime(3600.0));

        $row = Scrubber::render(4.0, 8.0, 24);
        self::assertSame('0:04 ▒▒▒▒▒▒◆░░░░░░░ 0:08', $row);

        self::assertSame('', Scrubber::render(0.0, 1.0, Scrubber::MIN_WIDTH - 1));
        self::assertSame('0:00 ◆░ 0:00', Scrubber::render(0.0, 0.0, 12));
        self::assertSame(
            '0:16 ' . str_repeat(Scrubber::PASSED, 13) . Scrubber::HEAD . ' 0:08',
            Scrubber::render(16.0, 8.0, 24),
            'a clock past its duration parks the head at the tail, never overflows',
        );
    }

    /**
     * @return array{0: Player, 1: VideoPlayerScriptedDecoder}
     */
    private function vpScriptedPlayer(Mode $mode = Mode::HalfBlock, bool $png = false): array
    {
        $decoder = new VideoPlayerScriptedDecoder($this->vpSolidFrames(4, $mode, $png));

        return [Player::fromDecoder($decoder, 8, 3, 10.0, $mode, 4, self::CLIP, paused: false), $decoder];
    }

    /**
     * @return list<RgbFrame>
     */
    private function vpSolidFrames(int $count, Mode $mode, bool $png): array
    {
        // HalfBlock decodes at cell width by double cell height; graphics
        // protocols work in pixels, but the scripted clip stays tiny.
        [$w, $h] = $mode === Mode::HalfBlock ? [8, 6] : [80, 60];
        $frames = [];

        for ($i = 0; $i < $count; $i++) {
            $tint = chr(40 + $i * 30);
            $frames[] = new RgbFrame(
                $png ? '' : str_repeat($tint, $w * $h * 3),
                $w,
                $h,
                $png ? 'vp-scripted-png' : null,
            );
        }

        return $frames;
    }

    private function vpReady(Player $player, Mode $mode = Mode::HalfBlock): VideoPlayerScreen
    {
        return VideoPlayerScreen::ready(self::CLIP, $player, $mode);
    }
}
