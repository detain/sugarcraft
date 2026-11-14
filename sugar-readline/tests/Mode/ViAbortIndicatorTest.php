<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

/**
 * S-08 (finding 007): attaching ViMode must not remove every reachable
 * abort, and the vi submode must become legible without host-side painting
 * (optional indicator row, off by default).
 */
final class ViAbortIndicatorTest extends TestCase
{
    /** Strip SGR sequences for cell-level assertions. */
    private static function plain(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    public function testEscapeThenCtrlCAbortsInNormalMode(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withMode(new ViMode())
            ->handleKey(Key::Escape)
            ->handleKey(Key::CtrlC);

        $this->assertTrue($prompt->isAborted(), 'Ctrl+C must abort from vi normal mode');
    }

    public function testNormalModeHardQuitAborts(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withMode(new ViMode())
            ->handleKey(Key::Escape)
            ->handleChar('Q');

        $this->assertTrue($prompt->isAborted(), '`Q` (hard quit) aborts from normal mode');
    }

    public function testLowercaseQDoesNotAbort(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withMode(new ViMode())
            ->handleKey(Key::Escape)
            ->handleChar('q');

        $this->assertFalse($prompt->isAborted(), 'only bare `Q` quits, lowercase stays an unmapped command');
    }

    public function testQWithPendingOperatorDoesNotAbort(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withMode(new ViMode())
            ->handleKey(Key::Escape)
            ->handleChar('d')   // half-typed operator: pendingMotion arms
            ->handleChar('Q');

        $this->assertFalse($prompt->isAborted(), 'Q while an operator is pending is motion input, not quit');
    }

    public function testCtrlCAbortsFromVisualMode(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withMode(new ViMode())
            ->handleKey(Key::Escape)
            ->handleChar('v')
            ->handleKey(Key::CtrlC);

        $this->assertTrue($prompt->isAborted(), 'Ctrl+C aborts from visual mode too');
    }

    public function testCtrlCFromInsertModeStillAborts(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withMode(new ViMode())
            ->handleKey(Key::CtrlC);

        $this->assertTrue($prompt->isAborted(), 'insert-mode Ctrl+C (direct path) is unchanged');
    }

    /**
     * Wire leg: raw ESC + 0x03 bytes through the real pump — the decoder
     * must land both chords so the run returns an aborted prompt. Trailing
     * LF is the loop-exit guard for the no-abort regression case.
     */
    public function testWireEscThenCtrlCAbortsThroughPump(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        fwrite($pair[1], "\x1b\x03\x0a");
        fclose($pair[1]);

        $prompt = TextPrompt::new('> ')->withMode(new ViMode());
        $final = (new Readline(new StreamInputDriver($pair[0])))->run($prompt);
        fclose($pair[0]);

        $this->assertTrue($final->isAborted(), 'ESC then Ctrl+C through the pump aborts the vi prompt');
    }

    public function testIndicatorOffByDefaultKeepsViewByteStable(): void
    {
        $withMode = TextPrompt::new('> ')->withMode(new ViMode())->handleKey(Key::Escape);
        $plain = self::plain($withMode->view());

        $this->assertStringNotContainsString('--NORMAL--', $plain);
        $this->assertStringNotContainsString('--INSERT--', $plain);
    }

    public function testIndicatorRowGolden(): void
    {
        $previous = getenv('NO_COLOR');
        putenv('NO_COLOR=1');
        try {
            $normal = TextPrompt::new('> ')
                ->withMode(new ViMode())
                ->withModeIndicator()
                ->handleKey(Key::Escape);
            $this->assertSame(">  \n--NORMAL--", $normal->view());

            $visual = $normal->handleChar('v');
            $this->assertSame(">  \n--VISUAL--", $visual->view());
        } finally {
            if ($previous === false) {
                putenv('NO_COLOR');
            } else {
                putenv('NO_COLOR=' . $previous);
            }
        }
    }

    /** Fresh attach sits in insert; normal/visual markers follow their keys. */
    public function testIndicatorTracksSubmodes(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withMode(new ViMode())
            ->withModeIndicator();

        $this->assertStringContainsString('--INSERT--', self::plain($prompt->view()), 'fresh vi attach is insert mode');

        $normal = $prompt->handleKey(Key::Escape);
        $this->assertStringContainsString('--NORMAL--', self::plain($normal->view()));

        $visual = $normal->handleChar('v');
        $this->assertStringContainsString('--VISUAL--', self::plain($visual->view()));

        // Indicator is the LAST painted row.
        $rows = explode("\n", self::plain($visual->view()));
        $this->assertSame('--VISUAL--', end($rows));
    }

    public function testIndicatorNeverPaintsWithoutViMode(): void
    {
        $emacs = TextPrompt::new('> ')
            ->withMode(new EmacsMode())
            ->withModeIndicator();
        $this->assertStringNotContainsString('--', self::plain($emacs->view()));

        $noMode = TextPrompt::new('> ')->withModeIndicator();
        $this->assertStringNotContainsString('--', self::plain($noMode->view()));
    }
}
