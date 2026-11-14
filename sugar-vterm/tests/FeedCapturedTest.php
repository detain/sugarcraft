<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Terminal as RendererTerminal;
use SugarCraft\Vterm\Terminal\Terminal;

/**
 * Pins for vterm-22: `feedCaptured()` is the ONLCR ritual made callable —
 * mixed terminator captures (`\n`, `\r`, `\r\n`) all land as clean
 * line-at-column-zero breaks, a bare `feed()` of the same bytes does NOT
 * (that asymmetry is the very gotcha the helper exists to erase, so it is
 * pinned too), and the transform is idempotent over already-CRLF data.
 * Shared docblock style across the two facades, per each `feed()`'s own
 * shape: the renderer chains, the emulator is void and forwards `$respond`.
 */
final class FeedCapturedTest extends TestCase
{
    /**
     * Rendered rows of the full-emulator facade as plain text.
     *
     * @return array<int, string>
     */
    private static function emulatorRows(Terminal $t): array
    {
        $rows = [];
        for ($r = 0; $r < 4; $r++) {
            $line = '';
            for ($c = 0; $c < 8; $c++) {
                $line .= $t->screen()->cell($r, $c)->grapheme;
            }
            $rows[] = rtrim($line);
        }
        return $rows;
    }

    /**
     * Rendered rows of the vcr renderer facade as plain text.
     *
     * @return array<int, string>
     */
    private static function rendererRows(RendererTerminal $t): array
    {
        $grid = $t->snapshot()->grid;
        $rows = [];
        for ($r = 0; $r < 4; $r++) {
            $line = '';
            for ($c = 0; $c < 8; $c++) {
                $line .= $grid->cell($r, $c)->grapheme;
            }
            $rows[] = rtrim($line);
        }
        return $rows;
    }

    public function testMixedTerminatorsLandAtColumnZeroOnBothEngines(): void
    {
        $capture = "alpha\rbeta\ngamma\r\ndelta";

        $emulator = Terminal::new(8, 4);
        $emulator->feedCaptured($capture);
        self::assertSame(['alpha', 'beta', 'gamma', 'delta'], self::emulatorRows($emulator));

        $renderer = RendererTerminal::new(8, 4);
        $renderer->feedCaptured($capture);
        self::assertSame(['alpha', 'beta', 'gamma', 'delta'], self::rendererRows($renderer));
    }

    public function testIdempotentOverAlreadyCrlfData(): void
    {
        // The property candy-vcr re-implemented caller-side: a stream that
        // already uses CRLF passes through the helper UNCHANGED, so the
        // result is identical to feeding the same bytes raw — the helper is
        // safe however many producers pre-expanded the capture.
        $capture = "a\r\nb\r\nc";

        $emulator = Terminal::new(8, 4);
        $emulator->feedCaptured($capture);
        $direct = Terminal::new(8, 4);
        $direct->feed($capture);
        self::assertSame(self::emulatorRows($direct), self::emulatorRows($emulator));
        self::assertSame($direct->cursor()->col, $emulator->cursor()->col);

        $renderer = RendererTerminal::new(8, 4);
        $renderer->feedCaptured($capture);
        $directRenderer = RendererTerminal::new(8, 4);
        $directRenderer->feed($capture);
        self::assertSame(self::rendererRows($directRenderer), self::rendererRows($renderer));
    }

    public function testBareFeedKeepsExactVtSemanticsAndStacksDiagonally(): void
    {
        // Contrast arm pinning WHY the helper exists: a bare LF moves down
        // without resetting the column on both engines.
        $emulator = Terminal::new(8, 4);
        $emulator->feed("al\nbe");
        self::assertSame(['al', '  be', '', ''], self::emulatorRows($emulator));

        $renderer = RendererTerminal::new(8, 4);
        $renderer->feed("al\nbe");
        self::assertSame(['al', '  be', '', ''], self::rendererRows($renderer));
    }

    public function testRespondChannelIsForwarded(): void
    {
        $t = Terminal::new(8, 4);
        $replies = [];
        $t->feedCaptured("\x1b[1;1H\x1b[6n\r\n", static function (string $bytes) use (&$replies): void {
            $replies[] = $bytes;
        });
        self::assertNotEmpty($replies, 'feedCaptured must forward the query→reply channel');
        self::assertStringStartsWith("\x1b[1;1R", $replies[0]);
    }
}
