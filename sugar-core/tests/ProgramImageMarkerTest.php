<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\View;

/**
 * What {@see Program} writes to the terminal for a frame carrying image
 * markers and Private-Use text (audit 15b-17): only a real
 * {@see ImageOverlay::marker()} paints, a bare U+E002 + n in model or tool text
 * does not, Powerline / Nerd Font glyphs reach the terminal intact, and a
 * marker's authenticating escape never does — even on a frame whose image
 * layer is empty.
 */
final class ProgramImageMarkerTest extends TestCase
{
    private const POWERLINE = "\u{E0B0}";
    private const NERD_FOLDER = "\u{F115}";
    private const FORGED_ID_0 = "\u{E002}";

    public function testOnlyTheRealMarkerPaintsAndPrivateUseGlyphsReachTheTerminal(): void
    {
        $body = ImageOverlay::marker(0) . "   \n"
            . 'model says ' . self::FORGED_ID_0 . ' sep ' . self::POWERLINE . ' dir ' . self::NERD_FOLDER;
        $written = $this->renderOnce(new View($body, images: [0 => new ImagePlacement('<SIXEL-BLOB>', 4, 1)]));

        self::assertSame(1, substr_count($written, '<SIXEL-BLOB>'), 'one image, one paint');
        self::assertStringContainsString(self::POWERLINE, $written);
        self::assertStringContainsString(self::NERD_FOLDER, $written);
        self::assertStringContainsString(self::FORGED_ID_0, $written, 'the forged cell is shown as the text it is');
        self::assertStringNotContainsString('candy-image', $written);
    }

    public function testAMarkerOnAFrameWithNoImagesNeverReachesTheTerminal(): void
    {
        $written = $this->renderOnce(new View('pic ' . ImageOverlay::marker(3) . ' ' . self::POWERLINE));

        self::assertStringNotContainsString('candy-image', $written);
        self::assertStringNotContainsString("\u{E005}", $written, 'the marker cell is blanked');
        self::assertStringContainsString(self::POWERLINE, $written);
    }

    private function renderOnce(View $view): string
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$in, $writer] = $sockets;
        $out = fopen('php://memory', 'w+');
        self::assertNotFalse($out);
        $loop = new StreamSelectLoop();

        $model = new class ($view) implements Model {
            use SubscriptionCapable;

            public function __construct(private readonly View $v)
            {
            }

            public function init(): ?\Closure
            {
                return null;
            }

            public function update(Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): View
            {
                return $this->v;
            }
        };

        $program = new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 240.0,
            input: $in,
            output: $out,
            loop: $loop,
        ));
        $loop->addTimer(0.05, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        fclose($writer);
        fclose($in);
        fclose($out);

        return $written;
    }
}
