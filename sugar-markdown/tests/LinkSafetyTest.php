<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

/**
 * Link-safety guard (docs-fix-1023 markdown security row).
 *
 * `[x](javascript:alert(1))` used to ride the clickable OSC-8 envelope
 * verbatim, so a hostile document could turn a terminal click into code
 * execution — the same vector `data:` and `vbscript:` carry. The emitter
 * now refuses those schemes (case-insensitive, checked on the RESOLVED
 * url so a NUL-smuggled `java\x00script:` cannot reform past the gate):
 * text and `(url)` suffix still render, inert; only clickability dies.
 * Every removal of one leg of this trio reddens the matching case —
 * the schemes are independently pinned, never a blanket regex whose
 * vacuity would hide a dropped member.
 */
final class LinkSafetyTest extends TestCase
{
    /** Opening bytes of any OSC-8 hyperlink (`ESC ] 8 ; ...`). */
    private const OSC8_OPEN = "\x1b]8;";

    /**
     * @return iterable<string, array{0:string, 1:string}> [markdown, refused url]
     */
    public static function refusedSchemeProvider(): iterable
    {
        yield 'javascript' => ['[click me](javascript:alert(1))', 'javascript:alert(1)'];
        yield 'data'       => ['[click me](data:text/html;base64,PHNjcmlwdD4=)', 'data:text/html;base64,PHNjcmlwdD4='];
        yield 'vbscript'   => ['[click me](vbscript:msgbox(1))', 'vbscript:msgbox(1)'];
    }

    /**
     * @dataProvider refusedSchemeProvider
     */
    public function testARefusedSchemeNeverOpensAnOsc8Envelope(string $markdown, string $url): void
    {
        $out = (new Renderer(Theme::plain()))->render($markdown);

        $this->assertStringNotContainsString(self::OSC8_OPEN, $out, $url . ' must not be clickable');
        // Visible and inert: no silent content loss — the reader sees the
        // target the click WOULD have used.
        $this->assertStringContainsString('click me', $out);
        $this->assertStringContainsString('(' . $url . ')', $out);
    }

    public function testTheJavascriptLegRefusesAMixedCaseScheme(): void
    {
        $out = (new Renderer(Theme::plain()))->render('[click me](JaVaScRiPt:alert(1))');

        $this->assertStringNotContainsString(self::OSC8_OPEN, $out);
        $this->assertStringContainsString('(JaVaScRiPt:alert(1))', $out);
    }

    public function testAControlByteCannotSmuggleASchemePastTheGate(): void
    {
        // With the sanitizer off, the NUL rides the parse and is removed by
        // resolveUrl's safeUrl — the scheme check runs on that RESOLVED url,
        // so the reformed 'javascript:' is still refused.
        $out = (new Renderer(Theme::plain()))
            ->withSanitize(false)
            ->render("[click me](java\x00script:alert(1))");

        $this->assertStringNotContainsString(self::OSC8_OPEN, $out);
    }

    public function testSanitizedInputRefusesTheEntitySpellingToo(): void
    {
        // &#0; decodes to NUL during link-destination parsing; the default
        // scrub strips it before the parse, so the plain scheme arrives.
        $out = (new Renderer(Theme::plain()))->render('[click me](java&#0;script:alert(1))');

        $this->assertStringNotContainsString(self::OSC8_OPEN, $out);
    }

    /**
     * Pins the README's claim: the renderer never entity-decodes link
     * destinations, so a numeric-entity scheme spelling reaches the
     * terminal as literal text - inert, and never a live javascript:.
     */
    public function testANumericEntitySpellingStaysLiteralAndInert(): void
    {
        $rendered = (new Renderer())->render("[click](&#006A;avascript:alert(1))\n");

        self::assertStringNotContainsString(self::OSC8_OPEN . "javascript:", $rendered);
        self::assertStringContainsString('&#006A;avascript:alert(1)', $rendered);
    }

    public function testRefusalSurvivesAConfiguredBaseURL(): void
    {
        // An absolute refused target must not be laundered by the base-url
        // join (which only touches scheme-less relative targets).
        $out = (new Renderer(Theme::plain()))
            ->withBaseURL('https://docs.example.com/')
            ->render('[click me](javascript:alert(1))');

        $this->assertStringNotContainsString(self::OSC8_OPEN, $out);
    }

    // ---- non-vacuity: the channel still fires for lawful targets --------

    public function testAnHttpLinkStillOpensAnOsc8Envelope(): void
    {
        $out = (new Renderer(Theme::plain()))->render('[click me](https://example.com)');

        $this->assertStringContainsString(self::OSC8_OPEN, $out);
        $this->assertStringContainsString("\x1b]8;;https://example.com\x1b\\", $out);
    }

    public function testAMailtoAutolinkIsNotCollateralDamage(): void
    {
        $out = (new Renderer(Theme::plain()))->render('<alice@example.com>');

        $this->assertStringContainsString(self::OSC8_OPEN, $out);
    }

    public function testARelativePathThatMerelyStartsWithDataIsNotRefused(): void
    {
        $out = (new Renderer(Theme::plain()))
            ->withBaseURL('https://docs.example.com/')
            ->render('[report](data/report.html)');

        $this->assertStringContainsString(self::OSC8_OPEN, $out);
        $this->assertStringContainsString('https://docs.example.com/data/report.html', $out);
    }
    /**
     * M14: a refused scheme split by an invisible formatting character (raw
     * UTF-8 bytes, percent spelling, or numeric entity) must never become a
     * clickable OSC-8 target. CommonMark percent-escapes raw non-ASCII bytes
     * in destinations, so every form lands on the wire as the same spelled
     * url; the probes strip the bytes after decoding and after entity
     * decoding, so all three refuse together.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invisibleSchemeSplitProvider(): iterable
    {
        $forms = [
            'soft-hyphen'         => "\xC2\xAD",
            'mongolian-vowel'     => "\xE1\xA0\x8E",
            'zero-width-space'    => "\xE2\x80\x8B",
            'word-joiner'         => "\xE2\x81\xA0",
            'byte-order-mark'     => "\xEF\xBB\xBF",
            'right-to-left'       => "\xE2\x80\xAE",
            // S09: the roster the gate used to miss — directionality
            // initiates, marks, isolates, the invisible operators, the
            // interlinear anchors, and the C1 controls' pair spelling.
            'left-to-right-override'  => "\xE2\x80\xAA",
            'left-to-right-mark'      => "\xE2\x80\x8E",
            'right-to-left-mark'      => "\xE2\x80\x8F",
            'first-strong-isolate'    => "\xE2\x81\xA6",
            'right-to-left-isolate'   => "\xE2\x81\xA7",
            'pop-directional-isolate' => "\xE2\x81\xA9",
            'invisible-times'         => "\xE2\x81\xA1",
            'arabic-letter-mark'      => "\xD9\x9C",
            'interlinear-anchor'      => "\xEF\xBF\xB9",
            'c1-control-pair'         => "\xC2\x9B",
        ];
        foreach ($forms as $label => $bytes) {
            yield $label => [
                '[x](java' . $bytes . 'script:alert(1))',
                rawurlencode($bytes),
            ];
        }

        yield 'percent-form' => ['[x](java%E2%80%8Bscript:alert(1))', '%E2%80%8B'];
        yield 'entity-form'  => ['[x](java&#8203;script:alert(1))', '%E2%80%8B'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invisibleSchemeSplitProvider')]
    public function testARefusedSchemeSplitByInvisiblesNeverOpensAnEnvelope(string $markdown, string $spelling): void
    {
        $out = (new Renderer())->render($markdown);
        $this->assertStringNotContainsString(self::OSC8_OPEN, $out, $markdown . ' must not be clickable');

        $plain = (new Renderer(Theme::plain()))->render($markdown);
        $this->assertSame('x (java' . $spelling . 'script:alert(1))', $plain);
    }

    public function testInvisibleBytesBeforeARefusedSchemeStillRefuse(): void
    {
        $out = (new Renderer())->render("[x](\xE2\x80\x8Bjavascript:alert(1))");
        $this->assertStringNotContainsString(self::OSC8_OPEN, $out);

        $out = (new Renderer())->render("[x](\xEF\xBB\xBFjavascript:alert(1))");
        $this->assertStringNotContainsString(self::OSC8_OPEN, $out);

        $plain = (new Renderer(Theme::plain()))->render("[x](\xE2\x80\x8Bjavascript:alert(1))");
        $this->assertSame('x (%E2%80%8Bjavascript:alert(1))', $plain);
    }

    /**
     * S09: the annotation's named forgeries. The isolate/override between
     * the scheme's letters must not smuggle a live target, in raw,
     * percent-encoded, or C1-pair spellings, on the schemes the old
     * six-byte roster missed entirely.
     */
    public function testIsolatesAndOverridesSplitTheDataAndVbscriptSchemesToo(): void
    {
        $ansi = new Renderer();

        foreach ([
            '[x](da' . "\xE2\x81\xA6" . 'ta:text/html,x)',
            '[x](da%E2%81%A6ta:text/html,x)',
            '[x](vb' . "\xE2\x80\xAA" . 'script:msgbox(1))',
            '[x](java' . "\xC2\x9B" . 'script:alert(1))',
            '[x](java%C2%9Bscript:alert(1))',
        ] as $md) {
            $this->assertStringNotContainsString(self::OSC8_OPEN, $ansi->render($md), $md . ' must not be clickable');
        }

        // And nothing invisible survives the visible suffix.
        $plain = (new Renderer(Theme::plain()))->render('[x](da' . "\xE2\x81\xA6" . 'ta:text/html,x)');
        $this->assertSame('x (da%E2%81%A6ta:text/html,x)', $plain);
    }

    /**
     * n4: an empty destination is not a target. No OSC-8 envelope, no bare
     * ' ()' noise; the link text stays visible. A real destination keeps the
     * suffix (control leg).
     */
    public function testAnEmptyDestinationCarriesNoEnvelopeAndNoSuffix(): void
    {
        $plain = new Renderer(Theme::plain());
        $ansi  = new Renderer();

        foreach (['[a]()', '[a](<>)', '[a]( )'] as $md) {
            $this->assertSame('a', $plain->render($md), $md);
            $this->assertStringNotContainsString(self::OSC8_OPEN, $ansi->render($md), $md);
        }
        $this->assertSame('a', $plain->render('![a]()'));
        $this->assertSame('[image]', $plain->render('![]()'));

        $this->assertStringContainsString(' (b)', $plain->render('[a](b)'));
    }
}
