<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\Video\Tests\Concerns\DrivesPlayerInternals;

/**
 * A mode cycle from a Kitty frame must retire the Kitty frame the player drew
 * last. The retirement rides the cycle's Cmd: the runtime writes it once, however
 * many views are discarded or messages coalesced before the next frame, so no
 * view() ever spends it.
 *
 * @covers \SugarCraft\Video\Player
 */
final class ModeCycleRetirementTest extends TestCase
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

    public function testKittyToTextCycleRetiresTheLastFrameThroughItsCmd(): void
    {
        $player = $this->kittyPlayer();

        self::assertSame(1, preg_match('/\bi=(\d+)/', $player->view(), $m), 'the Kitty view must draw an image');
        $retire = (new KittyRenderer())->delete($m[1]);

        [$text, $cmd] = $player->update(new KeyMsg(KeyType::Char, 'm'));
        self::assertSame(Mode::Ascii, $text->mode, 'Kitty is the last graphics rung, so m wraps to a text mode');

        self::assertSame($retire, self::runCmd($cmd), 'the mode cycle must hand the runtime the delete');
        self::assertStringNotContainsString($retire, $text->view(), 'the delete is a Cmd, never part of view()');
    }

    public function testRetirementCmdSurvivesDiscardedViewsAndCoalescedMessages(): void
    {
        $player = $this->kittyPlayer();

        self::assertSame(1, preg_match('/\bi=(\d+)/', $player->view(), $m), 'the Kitty view must draw an image');
        $retire = (new KittyRenderer())->delete($m[1]);

        [$text, $cmd] = $player->update(new KeyMsg(KeyType::Char, 'm'));
        // A view whose output is thrown away (a snapshot helper, a second layout pass)
        // and a tick dispatched before the frame is written must not cost the delete.
        $text->view();
        $text->view();
        [$ticked,] = $text->update(TickMsg::instance());
        $ticked->view();

        self::assertSame($retire, self::runCmd($cmd), 'the delete must still be owed to the runtime');
    }

    public function testTextToTextCycleOwesNoSecondDelete(): void
    {
        $player = $this->kittyPlayer();

        self::assertSame(1, preg_match('/\bi=(\d+)/', $player->view(), $m), 'the Kitty view must draw an image');
        $retire = (new KittyRenderer())->delete($m[1]);

        [$text, $firstCmd] = $player->update(new KeyMsg(KeyType::Char, 'm'));
        self::assertSame($retire, self::runCmd($firstCmd));

        [$nextText, $secondCmd] = $text->update(new KeyMsg(KeyType::Char, 'm'));
        self::assertNull($secondCmd, 'a text-to-text cycle has no graphics frame left to retire');
        self::assertStringNotContainsString($retire, $nextText->view(), 'the delete is written once, not on every view');
    }

    private function kittyPlayer(): Player
    {
        $frame = new RgbFrame(str_repeat("\x11\x22\x33", 20 * 10), 20, 10);

        return $this->mutatePlayer(
            Player::fromDecoder(
                new FakeDecoder(array_fill(0, 4, $frame)),
                cellsW: 20,
                cellsH: 10,
                fps: 24.0,
                mode: Mode::Kitty,
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
