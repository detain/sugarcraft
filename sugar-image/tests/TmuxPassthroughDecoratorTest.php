<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\Renderer;
use SugarCraft\Image\TmuxPassthroughDecorator;

/**
 * @covers \SugarCraft\Image\TmuxPassthroughDecorator
 */
final class TmuxPassthroughDecoratorTest extends TestCase
{
    public function testImplementsRendererInterface(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertInstanceOf(Renderer::class, $decorator);
    }

    public function testIsInlineDelegatesToInnerForInlineRenderer(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertTrue($decorator->isInline());
    }

    public function testIsInlineDelegatesToInnerForNonInlineRenderer(): void
    {
        $inner = new KittyRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertFalse($decorator->isInline());
    }

    public function testDeleteWrapsInnerSequenceForKittyRenderer(): void
    {
        $inner = new KittyRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $output = $decorator->delete('1');

        // Output should be wrapped in tmux passthrough envelope.
        $this->assertStringStartsWith("\x1bPtmux;", $output);
        $this->assertStringEndsWith("\x1b\\", $output);
        // Inner ESC bytes should be doubled inside the envelope.
        $this->assertStringContainsString("\x1b\x1b", $output);
    }

    public function testDeleteWrapsInnerSequenceForHalfBlockRenderer(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // HalfBlockRenderer::delete() returns ''.
        $output = $decorator->delete('1');

        $this->assertSame('', $output);
    }

    public function testWrapEnvelopesDcsSequence(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // DCS: \x1bP … \x1b\\
        $dcs = "\x1bP=1\x1b\\";
        $wrapped = $decorator->wrap($dcs);

        $this->assertStringStartsWith("\x1bPtmux;", $wrapped);
        $this->assertStringEndsWith("\x1b\\", $wrapped);
        // ESC inside should be doubled.
        $this->assertStringContainsString("\x1b\x1bP", $wrapped);
    }

    public function testWrapEnvelopesApcSequence(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // APC: \x1b_ … \x1b\\
        $apc = "\x1b_foo\x1b\\";
        $wrapped = $decorator->wrap($apc);

        $this->assertStringStartsWith("\x1bPtmux;", $wrapped);
        $this->assertStringEndsWith("\x1b\\", $wrapped);
        $this->assertStringContainsString("\x1b\x1b_", $wrapped);
    }

    public function testWrapEnvelopesOscWithBelTerminator(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // OSC with BEL terminator: \x1b] … \x07
        $osc = "\x1b]50; Гарри\x07";
        $wrapped = $decorator->wrap($osc);

        $this->assertStringStartsWith("\x1bPtmux;", $wrapped);
        $this->assertStringEndsWith("\x1b\\", $wrapped);
    }

    public function testWrapLeavesPlainBytesUnchanged(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // Plain ASCII with no DCS/APC/OSC escape sequences — must pass through unchanged.
        $plain = "Hello, World!";
        $wrapped = $decorator->wrap($plain);

        $this->assertSame($plain, $wrapped);
    }

    public function testWrapEmptyStringReturnsEmpty(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertSame('', $decorator->wrap(''));
    }

    public function testSupportsAlphaDelegatesToInner(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertFalse($decorator->supportsAlpha());
    }

    public function testNameIncludesTmuxPrefix(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertSame('tmux(halfblock)', $decorator->name());
    }

    /**
     * A single unmatched escape (every SGR/CSI the text tiers emit) must pass
     * through exactly once. Before the flush-advance fix the bytes preceding
     * the escape were drained, re-drained at the final drain, and the output
     * came back as "abc" . "abc\x1b[0m".
     */
    public function testWrapCarriesASingleUnmatchedEscapeOnce(): void
    {
        $decorator = new TmuxPassthroughDecorator(new HalfBlockRenderer());

        $this->assertSame("abc\x1b[0m", $decorator->wrap("abc\x1b[0m"));
    }

    /**
     * The text-tier frame shape: many SGR sequences and no DCS/APC/OSC at all.
     * Every one of them is unmatched by the envelope arms, so the wrapped bytes
     * must be byte-for-byte the inner frame — same length, same content.
     */
    public function testWrapCarriesAnSgrRunByteForByte(): void
    {
        $decorator = new TmuxPassthroughDecorator(new HalfBlockRenderer());

        $frame = "\x1b[31m\x1b[40m#\x1b[0m\x1b[32m\x1b[40m/\x1b[0m\r\n"
            . "\x1b[1;34mnext\x1b[0m\r\n";

        $wrapped = $decorator->wrap($frame);

        $this->assertSame($frame, $wrapped);
        $this->assertSame(strlen($frame), strlen($wrapped));
    }

    /**
     * A matched sequence between two unmatched runs: the envelope covers only
     * the OSC, and each plain span is emitted once. This is the shape a real
     * frame takes when a hyperlink (OSC 8) rides alongside SGR cells.
     */
    public function testWrapEmitsEachSpanOnceAroundAMatchedSequence(): void
    {
        $decorator = new TmuxPassthroughDecorator(new HalfBlockRenderer());

        $osc = "\x1b]8;;https://example.test\x07";
        $in  = "before\x1b[1m" . $osc . "after\x1b[0m";

        $expected = "before\x1b[1m"
            . "\x1bPtmux;" . str_replace("\x1b", "\x1b\x1b", $osc) . "\x1b\\"
            . "after\x1b[0m";

        $this->assertSame($expected, $decorator->wrap($in));
    }

    /**
     * Growth guard: duplication is quadratic in the escape count, so an exact
     * byte pin on a small fixture could still be satisfied by a partial fix.
     * A hundred SGR runs must cost exactly what they cost unwrapped.
     */
    public function testWrapCostDoesNotGrowWithEscapeCount(): void
    {
        $decorator = new TmuxPassthroughDecorator(new HalfBlockRenderer());

        $run   = "\x1b[38;2;10;20;30m\x1b[48;2;40;50;60mX\x1b[0m";
        $frame = str_repeat($run, 100);

        $this->assertSame(strlen($frame), strlen($decorator->wrap($frame)));
        $this->assertSame($frame, $decorator->wrap($frame));
    }

    /**
     * The defect as the user met it: a whole rendered half-block frame, put
     * through the passthrough decorator the way {@see \SugarCraft\Image\Mosaic}
     * wires it inside
     * tmux, must not inflate. Measured pre-fix: 3,667 bytes became 351,372.
     */
    public function testWrapPassesARealTextTierFrameThroughUnchanged(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $frame = (new HalfBlockRenderer())->render($image, 4, 2);

        $decorator = new TmuxPassthroughDecorator(new HalfBlockRenderer());
        $wrapped   = $decorator->render($image, 4, 2);

        $this->assertStringNotContainsString("\x1bPtmux;", $frame);
        $this->assertSame($frame, $wrapped);
    }

    /**
     * SI-41: the envelope contract of the ST-vs-BEL precedence quirk. For
     * DCS/APC the historic scan searched the WHOLE remaining string for an
     * ST before ever consulting BEL — so a BEL riding inside DCS payload
     * does NOT terminate it, even though the same byte ends an OSC at
     * first touch. The zero-copy rewrite inherits the law verbatim; if the
     * two scans were ever unified positionally, the bytes after this BEL
     * would leak outside the envelope and this pin goes red.
     */
    public function testTheStBeatsAnEarlierBelQuirkIsCarriedIntoTheZeroCopyWalk(): void
    {
        $decorator = new TmuxPassthroughDecorator(new HalfBlockRenderer());

        $dcs      = "\x1bPdata\x07more\x1b\\";
        $expected = "\x1bPtmux;" . str_replace("\x1b", "\x1b\x1b", $dcs) . "\x1b\\";

        $this->assertSame($expected, $decorator->wrap($dcs), 'the BEL is payload; the ST is the terminator');
    }

    /**
     * The hostile poster the finding measured: thousands of escape openers
     * on a big frame. Every wrapped span is built AT GENERATION time from
     * the same parts, so the expected string is an independent oracle —
     * dense escapes interleaved with plain runs, OSC closed by BEL, DCS
     * and APC closed by ST, SGR noise, and a lone trailing ESC.
     */
    public function testTheDenseEscapeCorpusWrapsByteForByte(): void
    {
        $decorator = new TmuxPassthroughDecorator(new HalfBlockRenderer());

        $in       = '';
        $expected = '';
        for ($k = 0; $k < 2000; $k++) {
            $plain = "cell{$k}\x1b[0m";
            $in       .= $plain;
            $expected .= $plain; // SGR is non-passthrough: verbatim

            $osc = "\x1b]10;rgb:1f/2e/3f\x07";
            $in       .= $osc;
            $expected .= "\x1bPtmux;" . str_replace("\x1b", "\x1b\x1b", $osc) . "\x1b\\";

            $dcs = "\x1bPq#0;G" . $k . "\x1b\\";
            $in       .= $dcs;
            $expected .= "\x1bPtmux;" . str_replace("\x1b", "\x1b\x1b", $dcs) . "\x1b\\";

            $apc = "\x1b_G" . $k . "\x1b\\";
            $in       .= $apc;
            $expected .= "\x1bPtmux;" . str_replace("\x1b", "\x1b\x1b", $apc) . "\x1b\\";
        }
        $in       .= "\x1b"; // lone trailing escape rides the final drain
        $expected .= "\x1b";

        $this->assertSame($expected, $decorator->wrap($in));
    }

    /**
     * Structural pin for the walk itself (the byte corpus above could be
     * satisfied by either walk): wrap() must never slice the tail of the
     * input buffer. The per-escape `substr($ansi, $i)` copy is exactly the
     * O(n x escapes) transient this row removed; reintroducing it (or a
     * $remaining variable) reddens this guard even when bytes match.
     */
    public function testTheWalkIndexesTheBufferInsteadOfCopyingTails(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionMethod(TmuxPassthroughDecorator::class, 'wrap'))->getFileName()
        );
        $start = strpos($source, 'public function wrap');
        $this->assertIsInt($start);
        $end = strpos($source, 'private function scanSt', $start);
        $this->assertIsInt($end);
        $body = preg_replace(
            '#/\*\*.*?\*/|//[^\n]*#s',
            '',
            substr($source, $start, $end - $start)
        );

        $this->assertStringNotContainsString('$remaining', $body, 'the tail-copy variable is retired');
        $this->assertDoesNotMatchRegularExpression('/substr\(\s*\$ansi\s*,\s*\$i\s*\)/', $body,
            'no whole-tail slice at an escape offset — scan in place and slice only what you emit');
    }
}
