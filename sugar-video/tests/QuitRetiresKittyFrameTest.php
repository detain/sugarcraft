<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\Video\Tests\Concerns\DrivesPlayerInternals;

/**
 * Quitting a Kitty playback must retire the frame still on screen. Player::stop()
 * only closes the decoder and audio, so the quit Cmd has to carry the delete for the
 * last frame, ahead of the QuitMsg. Without it every playback leaves two images in
 * the terminal's store.
 *
 * @covers \SugarCraft\Video\Player::updateKey
 * @covers \SugarCraft\Video\Player::teardownCmd
 * @covers \SugarCraft\Video\Player::stop
 */
final class QuitRetiresKittyFrameTest extends TestCase
{
    use DrivesPlayerInternals;

    protected function setUp(): void
    {
        RendererFactory::setDiagnoseForTesting(new ProbeReport([
            Capability::KittyKeyboard->value => true,
        ]));
    }

    protected function tearDown(): void
    {
        RendererFactory::setDiagnoseForTesting(null);
    }

    /**
     * @return array<string, array{KeyMsg}>
     */
    public static function quitKeys(): array
    {
        return [
            'q'      => [new KeyMsg(KeyType::Char, 'q')],
            'escape' => [new KeyMsg(KeyType::Escape)],
            'ctrl+c' => [new KeyMsg(KeyType::Char, 'c', ctrl: true)],
        ];
    }

    /**
     * @dataProvider quitKeys
     */
    public function testQuitOnAKittyFrameRetiresThePairBeforeQuitting(KeyMsg $key): void
    {
        $player = $this->kittyPlayer();
        $retire = self::pairRetirement($this->drawnFirstId($player));

        [$stopped, $cmd] = $player->update($key);

        self::assertTrue($stopped->isStopped(), 'quitting still tears the player down');
        self::assertNotNull($cmd, 'the quit must hand the runtime a Cmd');

        $batch = $cmd();
        self::assertInstanceOf(BatchMsg::class, $batch, 'the delete and the quit travel together');
        self::assertCount(2, $batch->cmds);

        $delete = $batch->cmds[0]();
        self::assertInstanceOf(RawMsg::class, $delete, 'the first Cmd writes the delete');
        self::assertSame($retire, $delete->bytes);

        self::assertInstanceOf(QuitMsg::class, $batch->cmds[1](), 'the second Cmd is the quit');
    }

    public function testQuitOnATextFrameIsAPlainQuit(): void
    {
        $player = $this->playerIn(Mode::Ascii);
        $player->view();

        [, $cmd] = $player->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(QuitMsg::class, $cmd(), 'no graphics frame, so nothing to retire');
    }

    public function testTeardownCmdHandsTheRuntimeThePairDelete(): void
    {
        $player = $this->kittyPlayer();
        $retire = self::pairRetirement($this->drawnFirstId($player));

        $teardown = $player->teardownCmd();
        self::assertNotNull($teardown, 'a drawn Kitty frame must be retirable by a host');
        $player->stop();

        self::assertSame($retire, self::runCmd($teardown));
        self::assertTrue($player->isStopped());
    }

    public function testTeardownCmdIsNullBeforeAnyFrameIsDrawn(): void
    {
        self::assertNull($this->kittyPlayer()->teardownCmd(), 'nothing was drawn, so nothing to retire');
    }

    /**
     * The first image id the player's first view drew, read from the view bytes.
     */
    private function drawnFirstId(Player $player): string
    {
        self::assertSame(1, preg_match('/\bi=(\d+)/', $player->view(), $m), 'the Kitty view must draw an image');

        return $m[1];
    }

    /**
     * The retirement for a Kitty session whose first drawn id is $firstId: both slots of
     * its pair are deleted, in order.
     */
    private static function pairRetirement(string $firstId): string
    {
        $kitty = new KittyRenderer();

        return $kitty->delete($firstId) . $kitty->delete((string) ((int) $firstId + 1));
    }

    private function kittyPlayer(): Player
    {
        return $this->playerIn(Mode::Kitty);
    }

    private function playerIn(Mode $mode): Player
    {
        $frame = new RgbFrame(str_repeat("\x11\x22\x33", 20 * 10), 20, 10);

        return $this->mutatePlayer(
            Player::fromDecoder(
                new FakeDecoder(array_fill(0, 4, $frame)),
                cellsW: 20,
                cellsH: 10,
                fps: 24.0,
                mode: $mode,
                totalFrames: 4,
                videoPath: '/fake',
            ),
            ['currentFrame' => $frame],
        );
    }

    /**
     * Runs a Cmd the way the runtime does and returns the raw bytes it writes.
     */
    private static function runCmd(?\Closure $cmd): string
    {
        self::assertNotNull($cmd, 'a retirement must be handed to the runtime as a Cmd');
        $msg = $cmd();
        self::assertInstanceOf(RawMsg::class, $msg, 'the retirement Cmd must write raw bytes');

        return $msg->bytes;
    }
}
