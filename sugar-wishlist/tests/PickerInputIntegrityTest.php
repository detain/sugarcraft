<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Wishlist\Endpoint;
use SugarCraft\Wishlist\Picker;
use PHPUnit\Framework\TestCase;

/**
 * Keyboard-input integrity pins for the audit findings (lane A):
 * ESC-bearing sequences, C0/C1 controls and multibyte codepoints must
 * never reach the filter text, never be echoed raw to the terminal, and
 * backspace/delete must work at codepoint granularity.
 */
final class PickerInputIntegrityTest extends TestCase
{
    /**
     * @return array{0:resource,1:resource,2:Picker}
     */
    private function makePicker(string $keys): array
    {
        $in  = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        fwrite($in, $keys);
        rewind($in);

        $p = new class($in, $out) extends Picker {
            /** @param unused */
            protected function setRawMode(bool $_on): void { /* noop in tests */ }
        };
        return [$in, $out, $p];
    }

    /**
     * @param list<Endpoint> $endpoints
     * @return array{0:resource,1:resource,2:Picker}
     */
    private function drive(string $keys, ?array $endpoints = null): array
    {
        [$in, $out, $p] = $this->makePicker($keys);
        $p->pick($endpoints ?? [
            new Endpoint(name: 'production', host: 'prod.example.com'),
            new Endpoint(name: 'staging',    host: 'stage.example.com'),
            new Endpoint(name: 'dev',        host: 'dev.example.com'),
        ]);
        return [$in, $out, $p];
    }

    private function filterOf(Picker $p): string
    {
        $prop = new \ReflectionProperty(Picker::class, 'filter');
        $prop->setAccessible(true);
        return (string) $prop->getValue($p);
    }

    /**
     * Every echoed "filter: …" line must carry exactly the two ESC bytes of
     * our own sgr(36)/reset pair — any additional ESC is a raw control leak.
     *
     * @param resource $out
     */
    private function assertFilterLinesCarryNoRawEsc($out): void
    {
        rewind($out);
        $output = (string) stream_get_contents($out);
        $lines = explode("\r\n", $output);
        $seen = 0;
        foreach ($lines as $line) {
            if (!str_starts_with($line, 'filter: ')) {
                continue;
            }
            $seen++;
            $this->assertSame(
                2,
                substr_count($line, "\x1b"),
                'filter echo line carries a raw ESC byte: ' . bin2hex($line)
            );
        }
        $this->assertGreaterThan(0, $seen, 'no filter line was ever drawn');
    }

    public function testRisSequenceIsAbsorbedAndNeverTyped(): void
    {
        // ESC c (RIS, full-terminal-reset) is a key event, not text: only
        // the following "p" may reach the filter.
        [, $out, $p] = $this->drive("\x1bcp\r");
        $this->assertSame('p', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testMetaAltChordIsAbsorbedAndNeverTyped(): void
    {
        // ESC p (Alt+p) arrives as the 2-byte chord "\x1bp" — dropped whole,
        // the 'p' inside it must not leak into the filter either.
        [, $out, $p] = $this->drive("\x1bp\x1bxc\r");
        $this->assertSame('c', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testSs3ArrowIsAbsorbedAndNeverTyped(): void
    {
        // ESC O A (application-cursor-mode up) is one key event. Before SS3
        // was parsed, the final byte 'A' leaked into the filter as text —
        // here only the deliberately-typed 'r' may survive.
        [, $out, $p] = $this->drive("\x1bOAr\r");
        $this->assertSame('r', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testUnmappedCsiIsConsumedNotTyped(): void
    {
        // ESC [ C (right arrow, unmapped) must be consumed as a key event,
        // never accumulate, and never echo raw.
        [, $out, $p] = $this->drive("\x1b[C\r");
        $this->assertSame('', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testLoneEscStillCancels(): void
    {
        // The genuine ESC keypress path must survive the sequence parser.
        [, , $p] = $this->makePicker("\x1b");
        $this->assertNull($p->pick([new Endpoint(name: 'a', host: 'a.test')]));
    }

    public function testRawAndEncodedC1ControlsAreDropped(): void
    {
        // A lone 0x9B byte (raw 8-bit CSI) is invalid UTF-8 → dropped.
        // "\xc2\x9b" (encoded U+009B) is well-formed but a control → dropped.
        // Only "prod" may type.
        [, $out, $p] = $this->drive("\x9b\xc2\x9bprod\r");
        $this->assertSame('prod', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testBackspaceDeletesWholeMultibyteCodepoint(): void
    {
        // 日 = three bytes. One backspace must clear the whole codepoint,
        // and no intermediate frame may echo invalid UTF-8.
        $endpoints = [
            new Endpoint(name: '日本語', host: 'jp.example.com'),
            new Endpoint(name: 'prod',  host: 'prod.example.com'),
        ];
        [, $out, $p] = $this->drive("\xe6\x97\xa5\x7fprod\r", $endpoints);
        // After 日 + one backspace the filter is empty, then "prod" types
        // clean — reaching filter 'prod' proves the CJK char was cleared in
        // a SINGLE press (byte-wise trim would need three and strand junk).
        $this->assertSame('prod', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testMultibyteBackspaceNeverEmitsInvalidUtf8(): void
    {
        [, $out, $p] = $this->drive("\xe6\x97\xa5\x7f\r");
        rewind($out);
        $output = (string) stream_get_contents($out);
        // Whole stream must decode as UTF-8: before the fix the frame
        // between lead bytes (and after one byte-wise backspace) echoed
        // "\xe6\x97" — invalid UTF-8.
        $this->assertSame(1, preg_match('//u', $output), 'output stream is not valid UTF-8');
        $this->assertSame('', $this->filterOf($p), 'one backspace must clear one whole codepoint');
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testFilterEchoIsSanitizedAtRenderAsBeltAndBraces(): void
    {
        // Even if a hostile string were placed in the filter by any future
        // path, draw() must not echo its control bytes.
        [, $out, $p] = $this->makePicker('');
        $filter = new \ReflectionProperty(Picker::class, 'filter');
        $filter->setAccessible(true);
        $filter->setValue($p, "\x1b[2Jevil\x07");

        $draw = new \ReflectionMethod(Picker::class, 'draw');
        $draw->setAccessible(true);
        $draw->invoke($p, []);

        rewind($out);
        $output = (string) stream_get_contents($out);
        $this->assertStringNotContainsString("\x1b[2J", $output);
        $this->assertStringNotContainsString("\x07", $output);
        $this->assertStringContainsString('filter: ' . "\x1b[36m" . 'evil' . "\x1b[0m", $output);
    }

    public function testHighlightWalksCodepointsNotGraphemes(): void
    {
        // "é" written as e + U+0301 is TWO codepoints but ONE grapheme.
        // candy-fuzzy indexes the haystack by codepoint, so the match on 'f'
        // arrives as index 2 — the grapheme walk used to wrap index 2's
        // WRONG cluster (none) and desync every later highlight.
        [$in, $out, $p] = $this->makePicker('');
        $haystack = "e\u{0301}f";
        $result = new MatchResult(
            needle: 'f',
            haystack: $haystack,
            score: 5,
            matchedIndices: [2],
        );
        $hl = new \ReflectionMethod(Picker::class, 'highlightLine');
        $hl->setAccessible(true);
        $rendered = (string) $hl->invoke($p, $haystack, $result);
        $this->assertStringContainsString("\x1b[1;36mf\x1b[0m", $rendered);
        // The combining sequence stays unhighlighted and intact.
        $this->assertStringStartsWith("e\u{0301}", $rendered);
    }
}
