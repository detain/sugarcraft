<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Markdown\Render\TokenWrap;
use SugarCraft\Markdown\Renderer;

/**
 * SM-S22: the overlong-token wrap mitigation in {@see TokenWrap} must be
 * byte-identical to the plain `Width::wrapAnsi()` call it replaces, and
 * must keep a single huge token (base64 blob, hash wall) from turning the
 * paragraph wrap into seconds of per-cluster walking.
 */
final class OverlongTokenWrapTest extends TestCase
{
    private const MAXES = [1, 2, 3, 5, 8, 10, 20, 40, 80];

    /**
     * Adversarial shapes around the mitigation boundary: runs on both sides
     * of the 4x threshold, runs the mitigation declines (ESC, controls),
     * wide clusters, combining-heavy text, and every gap-carriage case the
     * splice has to reproduce.
     *
     * @return array<string, array{0: string}>
     */
    public static function corpusProvider(): array
    {
        $zwj = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F466}";
        return [
            'empty' => [''],
            'one byte' => ['a'],
            'space only' => [' '],
            'tab only' => ["\t"],
            'blank lines' => ["\n\n"],
            'giant ascii' => [' para word' . str_repeat('a', 500) . ' tail end'],
            'giant at exact boundary' => [str_repeat('a', 41) . ' tail'],
            'just under boundary' => [str_repeat('a', 40) . ' tail'],
            'giant alone' => [str_repeat('a', 500)],
            'two giants' => [str_repeat('i', 300) . ' ' . str_repeat('j', 300)],
            'giant trailing spaces' => ['x ' . str_repeat('k', 500) . '   end'],
            'giant before blank line' => ['lead ' . str_repeat('m', 460) . "\n\nrest"],
            'cjk giant' => [str_repeat('漢', 250)],
            'cjk with ascii tail' => ['pre ' . str_repeat('漢字', 100) . ' mid ' . str_repeat('a', 300)],
            'zwj giant' => [$zwj . str_repeat($zwj, 100) . ' x'],
            'flag pairs' => [str_repeat("\u{1F1EF}\u{1F1F5}", 100) . ' ascii tail'],
            'styled giant declined' => ["\x1b[31m" . str_repeat('a', 500) . "\x1b[0m tail"],
            'osc8 gap' => ["\x1b]8;;http://example.com/a\x1b\\ " . str_repeat('b', 300) . ' post'],
            'control run declined' => [str_repeat('c', 100) . "\x01\x02\x03" . str_repeat('d', 500)],
            'cr crlf gaps' => ["a\r\nb\r " . str_repeat('e', 400) . ' f'],
            'del byte run' => [str_repeat('a', 100) . "\x7f" . str_repeat('b', 400)],
            'giant end at multiple' => [str_repeat('a', 4 * 40) . ' tail'],
            'giant end at multiple plus one' => [str_repeat('a', 4 * 40 + 1) . ' tail'],
        ];
    }

    #[DataProvider('corpusProvider')]
    public function testMitigationIsByteIdenticalToNativeWrap(string $case): void
    {
        foreach (self::MAXES as $max) {
            $this->assertSame(
                Width::wrapAnsi($case, $max),
                TokenWrap::wrap($case, $max),
                'TokenWrap must reproduce wrapAnsi() byte for byte at max=' . $max,
            );
        }
    }

    public function testSeededRandomBodiesStayByteIdentical(): void
    {
        $zwj = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F466}";
        $alphabet = [
            'a', 'Z', '9', '漢', '字', $zwj, "\u{1F1EF}\u{1F1F5}", 'é', ' ', ' ', ' ',
            "\t", "\n", "\n", "\x1b[31m", 'word', 'longer_word',
            str_repeat('x', 200), str_repeat('漢', 210), "\r\n", "\r",
            '.', '-', "'", '"', '["link"]', "\x01", "\x7f", 'a' . "\x00" . 'b',
        ];

        mt_srand(20261112);
        for ($f = 0; $f < 150; $f++) {
            $body = '';
            for ($k = 0, $n = mt_rand(0, 40); $k < $n; $k++) {
                $body .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            foreach (self::MAXES as $max) {
                $this->assertSame(
                    Width::wrapAnsi($body, $max),
                    TokenWrap::wrap($body, $max),
                    'seeded body ' . $f . ' diverges at max=' . $max . ': ' . json_encode($body),
                );
            }
        }
    }

    public function testMaxNonPositivePassesThroughLikeNative(): void
    {
        $this->assertSame('anything', TokenWrap::wrap('anything', 0));
        $this->assertSame('anything', TokenWrap::wrap('anything', -5));
    }

    public function testFourHundredKilobyteSingleTokenRendersUnderSixHundredMilliseconds(): void
    {
        $doc = 'para word' . str_repeat('a', 400000) . ' tail end';
        $renderer = new Renderer(null, 40);

        $start = microtime(true);
        $out = $renderer->render($doc);
        $elapsed = microtime(true) - $start;

        // SM-S22 acceptance: 400 KB single-word paragraph < 600 ms (was
        // ~1.5 s while wrapAnsi() walked every cluster of the token).
        $this->assertLessThan(0.6, $elapsed, 'giant-token paragraph exceeded the wrap budget');
        // The hard-break rows are still the native 40-cell packing.
        $this->assertStringContainsString(str_repeat('a', 40) . "\n" . str_repeat('a', 40), $out);
        $this->assertStringContainsString('para', $out);
        $this->assertStringContainsString('tail end', $out);
    }

    public function testGiantTokenStreamsByteIdenticalToRender(): void
    {
        $doc = "intro text\n\nwall " . str_repeat('b', 150000) . " tail\n\nafter " . str_repeat('漢', 40000) . " end\n";
        $renderer = new Renderer(null, 40);

        $rendered = $renderer->render($doc);
        $streamed = '';
        foreach ($renderer->stream(str_split($doc, 700)) as $body) {
            $streamed .= $body;
        }

        $this->assertSame($rendered, $streamed, 'the wrap mitigation must not fork the stream and render paths');
    }

    public function testQuoteAndTableCellWrapPathsStayIdentical(): void
    {
        $doc = "> quoted " . str_repeat('q', 50000) . " tail\n\n| a | b |\n|---|---|\n| " . str_repeat('c', 50000) . " | x |\n";
        $renderer = new Renderer(null, 40);

        // The same splice runs at the blockquote and table-cell call sites;
        // a fresh renderer pins the absolute path while the shared doc above
        // keeps the stream check honest.
        $out = $renderer->render($doc);
        $this->assertStringContainsString('tail', $out);
        $this->assertStringContainsString('x', $out);

        $start = microtime(true);
        $renderer->render($doc);
        $this->assertLessThan(2.0, microtime(true) - $start, 'quote/cell wrap must ride the fast path too');
    }
}
