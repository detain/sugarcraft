<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Renderer;
use SugarCraft\Core\Util\Ansi;

/**
 * Cell-diff byte accounting for string-terminated sequences.
 *
 * crush_libs.md candy-core #4: `tokenByteLength()` charged every OSC / DCS /
 * APC / SOS / PM a 2-byte ST, but the parser also accepts a 1-byte BEL, so a
 * row opening with a BEL-closed OSC was measured one byte long and the
 * partial repaint's `substr()` started one byte late — the first changed
 * byte never reached the terminal.
 */
final class RendererStringTokenTest extends TestCase
{
    /** Bytes the cell-diff renderer emits for `$second` after painting `$first`. */
    private function renderPair(string $first, string $second): string
    {
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        $r = new Renderer($out, inline: false, cellDiff: true);
        $r->render($first);
        $mark = ftell($out);
        $r->render($second);
        fseek($out, $mark);
        $delta = (string) stream_get_contents($out);
        fclose($out);
        return $delta;
    }

    /** @return iterable<string, array{string}> */
    public static function stringSequences(): iterable
    {
        yield 'OSC closed by BEL' => ["\x1b]0;title\x07"];
        yield 'OSC closed by ST' => ["\x1b]0;title\x1b\\"];
        yield 'APC closed by BEL' => ["\x1b_zone\x07"];
        yield 'DCS closed by ST' => ["\x1bPq#0\x1b\\"];
    }

    #[DataProvider('stringSequences')]
    public function testPartialRepaintAfterAStringSequenceKeepsEveryChangedByte(string $seq): void
    {
        $delta = $this->renderPair($seq . 'helloworld', $seq . 'helloWORLD');

        $this->assertSame(
            Ansi::syncBegin() . Ansi::cursorTo(1, 6) . Ansi::eraseToLineEnd() . 'WORLD' . Ansi::syncEnd(),
            $delta,
        );
    }

    /**
     * Found while verifying #4: Parser is a stream tokeniser, so an escape
     * left unterminated at the end of one row was buffered and prepended to
     * the next parse() — the next row's tokens then described bytes that row
     * does not contain, and the diff degraded to a full repaint of it (or,
     * with the token cache, cached the contaminated tokens under that row).
     */
    public function testUnterminatedEscapeDoesNotLeakIntoTheNextRowsTokens(): void
    {
        $delta = $this->renderPair("abc\x1b]8;;\nline2 here", "abX\x1b]8;;\nline2 hexe");

        // Row 2 diverges at "here" → "hexe": a partial repaint from col 9.
        $this->assertStringContainsString(Ansi::cursorTo(2, 9) . Ansi::eraseToLineEnd() . 'xe', $delta);
        $this->assertStringNotContainsString('line2', $delta);
    }
}
