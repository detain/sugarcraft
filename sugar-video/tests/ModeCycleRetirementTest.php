<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\Video\Tests\Concerns\DrivesPlayerInternals;

/**
 * A mode cycle from a Kitty frame into a text mode must retire the Kitty frame
 * the player drew last. The text successor draws no graphics, so the delete has
 * to ride the next view instead of the renderer.
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

    public function testKittyToTextCycleRetiresTheLastFrameOnTheNextView(): void
    {
        $frame = new RgbFrame(str_repeat("\x11\x22\x33", 20 * 10), 20, 10);
        $player = $this->mutatePlayer(
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

        self::assertSame(1, preg_match('/\bi=(\d+)/', $player->view(), $m), 'the Kitty view must draw an image');
        $retire = (new KittyRenderer())->delete($m[1]);

        [$text,] = $player->update(new KeyMsg(KeyType::Char, 'm'));
        self::assertSame(Mode::Ascii, $text->mode, 'Kitty is the last graphics rung, so m wraps to a text mode');

        self::assertStringStartsWith($retire, $text->view(), 'the first text view must retire the Kitty frame');

        [$later,] = $text->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertStringNotContainsString($retire, $later->view(), 'the delete is written once, not on every view');
    }

    public function testRetirementSurvivesAMessageThatArrivesBeforeTheView(): void
    {
        $frame = new RgbFrame(str_repeat("\x11\x22\x33", 20 * 10), 20, 10);
        $player = $this->mutatePlayer(
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

        self::assertSame(1, preg_match('/\bi=(\d+)/', $player->view(), $m), 'the Kitty view must draw an image');
        $retire = (new KittyRenderer())->delete($m[1]);

        [$text,] = $player->update(new KeyMsg(KeyType::Char, 'm'));
        // The runtime renders from a one-shot timer, so a tick can be dispatched
        // between the mode cycle and the view that finally writes the frame.
        [$ticked,] = $text->update(TickMsg::instance());

        self::assertStringStartsWith($retire, $ticked->view(), 'a message coalesced before the view must not spend the delete');
    }

    public function testRetirementSurvivesATextToTextCycleBeforeTheView(): void
    {
        $frame = new RgbFrame(str_repeat("\x11\x22\x33", 20 * 10), 20, 10);
        $player = $this->mutatePlayer(
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

        self::assertSame(1, preg_match('/\bi=(\d+)/', $player->view(), $m), 'the Kitty view must draw an image');
        $retire = (new KittyRenderer())->delete($m[1]);

        [$text,] = $player->update(new KeyMsg(KeyType::Char, 'm'));
        [$nextText,] = $text->update(new KeyMsg(KeyType::Char, 'm'));

        self::assertStringStartsWith($retire, $nextText->view(), 'a further text cycle must keep the owed delete');
    }
}
