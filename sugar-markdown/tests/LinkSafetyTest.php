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
}
