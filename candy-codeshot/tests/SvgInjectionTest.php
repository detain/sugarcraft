<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeShot\SvgRenderer;
use SugarCraft\CodeShot\Tests\Support\SvgOracle;
use SugarCraft\CodeShot\Theme;

/**
 * cs-03 (-004, TEST-02 folded): the SVG escape/injection suite.
 *
 * Every leg runs through the well-formedness oracle AND pins the escaped
 * literals, so a dropped ENT_QUOTES or a reintroduced unswept channel
 * reddens here instead of passing under substring-only assertions.
 * The escape semantics pinned are the FINAL ones — cs-04's sweep + DEL
 * policy + invalid-UTF-8 conversion leg — per the annotation's ordering.
 */
final class SvgInjectionTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Test-of-the-test: the oracle itself must be provably live.
    // ---------------------------------------------------------------------

    public function testOracleRejectsTheKnownBadDocumentAndAcceptsAGoodOne(): void
    {
        // A bare U+FFFE is the exact byte (-015) that used to kill whole
        // renders while every string pin stayed green. If the oracle ever
        // disables itself (libxml toggled off, errors swallowed), this leg
        // goes red in both directions — a dead oracle cannot pass it.
        $broken = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg">' . "\u{FFFE}" . '</svg>';

        $this->assertNotSame(
            [],
            SvgOracle::errors($broken),
            'the oracle must reject the very class of document that killed renders pre-cs-04',
        );

        $good = SvgRenderer::dark()->withWindow(false)->render('x');

        $this->assertSame(
            [],
            SvgOracle::errors($good),
            'the oracle must accept a healthy render, or every leg below passes vacuously',
        );
    }

    // ---------------------------------------------------------------------
    // Attribute channels: theme colours reach fill/stroke/flood-color.
    // ---------------------------------------------------------------------

    public function testHostileThemeColoursStayEscapedInsideAttributes(): void
    {
        // Every colour field lands in a double-quoted attribute. The
        // payloads carry all four XML-dangerous characters; the raw
        // break-out substrings must never appear, and the escaped forms
        // (including &apos;, which only ENT_QUOTES produces) must.
        $theme = new Theme(
            background:   '#0d1117" onmouseover="evil()',
            foreground:   "it's\"a&b<c>d",
            border:       '#30363d/></svg><script>',
            shadow:       "rgba(0,0,0,'&\")",
            lineNumber:   '#6e7681" style="x',
            windowRed:    "#ff5f56'><i>",
            windowYellow: '&amp;#ffbd2e"y',
            windowGreen:  '<![CDATA[]]>#27c93f',
        );
        $svg = SvgRenderer::dark()->withTheme($theme)->withLineNumbers(true)->render("line\n");

        SvgOracle::assertWellFormed($svg, 'hostile theme colours must not break the document');

        // Escaped-literal presence: drop ENT_QUOTES and &apos; disappears
        // (apostrophes inside double-quoted attributes are otherwise
        // silently well-formed — this pin is the net that catches it).        $this->assertStringContainsString('&apos;', $svg, 'ENT_QUOTES escape of the apostrophe is part of the contract');
        $this->assertStringContainsString('&quot;', $svg, 'quotes must arrive escaped');
        $this->assertStringContainsString('&amp;', $svg);
        $this->assertStringContainsString('&lt;', $svg);

        // Raw-payload absence: the exact break-out substrings.
        $this->assertStringNotContainsString('" onmouseover="', $svg);
        $this->assertStringNotContainsString('it\'s"', $svg);
        $this->assertStringNotContainsString('></svg><script>', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringNotContainsString('" style="', $svg);
        $this->assertStringNotContainsString('&1', $svg);
        $this->assertStringNotContainsString('<![CDATA[]]>', $svg);
    }

    public function testHighlightColourChannelIsRefusedAtTheDoor(): void
    {
        // cs-32 (candy-codeshot-067) raised this defence a layer: the hostile
        // colour no longer reaches the escaper — withHighlight refuses any
        // value outside the hex family at the boundary, so the escape-leg
        // render of the old pin is unreachable by design. Keep the classic
        // payload as the probe; the typed refusal is the new contract. The
        // xmlEscape in the fill sprintf stays as depth for theme bands.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a #rgb, #rrggbb or #rrggbbaa hex value/');

        SvgRenderer::dark()->withHighlight(1, 2, '#fff" onmouseover=\'x&y<z\'');
    }

    // ---------------------------------------------------------------------
    // Element-text channels: title and segment text.
    // ---------------------------------------------------------------------

    public function testTitleTextChannelEscapesMarkupAndEntities(): void
    {
        $svg = SvgRenderer::dark()
            ->withTitle("t\"><script>alert(&1)</script>'&")
            ->withWindow(false)
            ->render('x');

        SvgOracle::assertWellFormed($svg, 'a hostile title must not break the document');
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertStringContainsString('&amp;1', $svg, 'a bare & must arrive as &amp;, never as a live entity head');
        $this->assertStringContainsString('&apos;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringNotContainsString('&1', $svg);
    }

    public function testTextChannelMarkupControlBytesAndCdataTerminator(): void
    {
        $payload = "<img src=x onerror=alert(1)> ]]>&#65534; a\x01\x02\x0b\x0c\x7fb\xC2\x85c\u{FDD0}\u{FFFE}end";
        $svg     = SvgRenderer::dark()->withWindow(false)->render($payload . "\n");

        SvgOracle::assertWellFormed($svg, 'markup/control/]]> text payloads must leave a parsable document');
        SvgOracle::assertNoSurvivorBytes($svg);

        $this->assertStringContainsString('&lt;img', $svg, 'tag text must arrive escaped');
        $this->assertStringNotContainsString('<img ', $svg);
        $this->assertStringNotContainsString('&#65534;', $svg, 'a numeric reference to an illegal scalar must never be live');
        $this->assertStringContainsString('&amp;#65534;', $svg, '…and must survive as literal text, honestly');
        $this->assertStringContainsString('end', $svg, 'survivor letters on either side of the junk must stay (M12 rule)');
    }

    public function testC0ControlBytesAreDroppedNotEncoded(): void
    {
        // XML 1.0 forbids C0 scalars outright — no numeric reference is a
        // legal encoding either, so the policy is drop (cs-04's contract).
        $svg = SvgRenderer::dark()->withWindow(false)->render("a\x00b\x01c\x02d\x08e\n");

        SvgOracle::assertWellFormed($svg);
        foreach (["\x00", "\x01", "\x02", "\x08"] as $byte) {
            $this->assertSame(0, substr_count($svg, $byte), 'C0 byte ' . ord($byte) . ' survived into the document');
        }
        $this->assertStringContainsString('abcde', $svg, 'the letters around the dropped bytes must survive');
    }

    public function testInvisibleBidiOverridesSurviveUnmarkedAsDocumented(): void
    {
        // cs-14 (-019, SEC-04) G1-disclose arm: invisible formatting
        // characters are NOT a markup threat (they cannot escape a text
        // node), and a screenshot tool that rewrote source bytes would
        // lie about the code it shows — so the policy is documented
        // pass-through, pinned here so the README Security sentence can
        // never drift into silent marking. U+202E (RIGHT-TO-LEFT
        // OVERRIDE) plus the isolate/zero-width family must arrive in
        // the artifact verbatim and unmarked, still well-formed.
        $payload = "a\u{202E}b\u{2066}c\u{2069}d\u{200B}e\u{200D}f\u{FEFF}g\n";
        $svg     = SvgRenderer::dark()->withWindow(false)->render($payload);

        SvgOracle::assertWellFormed($svg, 'legal bidi/zero-width scalars must not break the document');
        foreach (["\u{202E}", "\u{2066}", "\u{2069}", "\u{200B}", "\u{200D}", "\u{FEFF}"] as $char) {
            $this->assertStringContainsString(
                $char,
                $svg,
                'codepoint U+' . strtoupper(dechex(mb_ord($char))) . ' must pass through unmarked (README Security disclosure)',
            );
        }
        $this->assertStringNotContainsString('<U+', $svg, 'the artifact must NOT carry Sanitize markers — that is the caller-side opt-in');
        $this->assertNotFalse(
            strpos($svg, rtrim($payload, "\n")),
            'the whole payload must arrive verbatim and contiguous — invisible bytes included, nothing marked or reordered',
        );
    }

    // ---------------------------------------------------------------------
    // Property leg: arbitrary bytes must always yield a parsable document.
    // ---------------------------------------------------------------------

    public function testRandomBytePayloadsAlwaysProduceWellFormedDocuments(): void
    {
        // Fixed seed: the sequence is reproducible run to run, so a red
        // here is a real regression, never a lottery. Covers the invalid-
        // UTF-8 conversion path (0xFF/0xFE), every sweep class (ESC-formed
        // junk, C0/C1, DEL) and ordinary printable noise.
        mt_srand(0xC0DE5);

        for ($i = 0; $i < 40; $i++) {
            $payload = '';
            $length  = mt_rand(0, 64);
            for ($j = 0; $j < $length; $j++) {
                $payload .= chr(mt_rand(0, 255));
            }

            $svg = SvgRenderer::dark()->render($payload);
            SvgOracle::assertWellFormed($svg, 'random payload #' . $i . ' must still render a parsable document');
            SvgOracle::assertNoSurvivorBytes($svg);
        }
    }
}
