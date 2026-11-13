<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use PHPUnit\Framework\TestCase;

final class SanitizeTest extends TestCase
{
    public function testEscInTextIsStripped(): void
    {
        // Raw ESC sequence from source must not appear in output.
        $input = "a\x1b[31mX\x1b[0mb";
        $rendered = Renderer::plain()->render($input);
        $this->assertStringNotContainsString("\x1b[31m", $rendered);
        $this->assertStringNotContainsString("\x1b[0m", $rendered);
    }

    public function testEscInInlineCodeStripped(): void
    {
        $input = "`code\x1b[31mwith\x1b[0mesc`";
        $rendered = Renderer::plain()->render($input);
        $this->assertStringNotContainsString("\x1b[31m", $rendered);
        $this->assertStringNotContainsString("\x1b[0m", $rendered);
    }

    public function testEscInHtmlBlockStripped(): void
    {
        $input = "<details>\n\x1b[31msecret\x1b[0m\n</details>";
        $rendered = Renderer::plain()->render($input);
        $this->assertStringNotContainsString("\x1b[31m", $rendered);
        $this->assertStringNotContainsString("\x1b[0m", $rendered);
    }

    public function testSanitizeFalsePassesThrough(): void
    {
        $input = "a\x1b[31mX\x1b[0mb";
        $rendered = Renderer::plain()->withSanitize(false)->render($input);
        // With sanitize off, the ESC bytes pass through (plain theme renders as-is).
        $this->assertStringContainsString("\x1b[31m", $rendered);
    }

    public function testTabAndNewlinePreserved(): void
    {
        // Tab (0x09) and newline (0x0a) must survive stripControls.
        // stripControls regex: /[\x00-\x08\x0b-\x1f\x7f]/ — excludes 0x09 and 0x0a.
        $input = "hello\tworld\nanother line";
        $this->assertSame(24, strlen($input)); // tab + newline present in input
        // Verify stripControls helper preserves tab and newline directly.
        $stripped = \SugarCraft\Markdown\Renderer::class;
        $reflector = new \ReflectionClass($stripped);
        $method = $reflector->getMethod('stripControls');
        $method->setAccessible(true);
        $after = $method->invoke(null, $input);
        $this->assertSame(24, strlen($after)); // tab + newline preserved
        // Verify tab (0x09) is present.
        $this->assertSame("\x09", $after[5]);
        // Verify newline (0x0a) is present.
        $this->assertSame("\x0a", $after[11]);
    }

    public function testEscInFencedCodeStripped(): void
    {
        $input = "```php\n<?php\x1b[31mecho\x1b[0m\n?>\n```\n";
        $rendered = Renderer::plain()->render($input);
        $this->assertStringNotContainsString("\x1b[31m", $rendered);
        $this->assertStringNotContainsString("\x1b[0m", $rendered);
    }

    /**
     * renderFencedCode strips C0/ESC control bytes BEFORE handing the body to
     * the syntax highlighter, so crafted terminal-control sequences embedded in
     * a fenced code block can never reach the terminal — even when the code is
     * routed through the *active* (colour-emitting) highlighter. Guards against
     * terminal-injection via markdown code blocks (title-set OSC, cursor DSR,
     * raw colour CSI), the one behaviour the deleted candy-mdview
     * ChromaJsonHighlighter reimplemented locally.
     */
    public function testInjectedControlBytesInHighlightedFencedCodeNeutralized(): void
    {
        $osc = "\x1b]0;INJECTED\x07"; // OSC set-window-title + BEL terminator
        $dsr = "\x1b[6n";              // device-status-report cursor query
        $csi = "\x1b[31m";             // raw red SGR
        // Number 42 sits on a clean line; the injection rides a comment line so
        // it cannot glue onto the token we assert on.
        $input = "```php\n\$x = 42;\n// note{$csi}{$osc}{$dsr}\n```\n";

        // ANSI theme => the highlighter emits real SGR: the negative assertions
        // below are meaningful, not a no-colour no-op.
        $rendered = Renderer::ansi()->render($input);

        // Injected control introducers are gone: the highlighter only ever emits
        // SGR (ending in 'm'), never an OSC-0, BEL, or DSR query.
        $this->assertStringNotContainsString("\x1b]0;", $rendered);
        $this->assertStringNotContainsString("\x07", $rendered);
        $this->assertStringNotContainsString("\x1b[6n", $rendered);

        // Payload text survives as inert, visible characters — proving the ESC/BEL
        // bytes were neutralised, not that the whole block was dropped.
        $this->assertStringContainsString('INJECTED', $rendered);

        // Sanity: the highlighter is active (number 42 → Color::ansi(11), bright
        // yellow, emitted as the palette code SGR 93), so the strip is exercised
        // on the real "before highlighting" path.
        $this->assertStringContainsString("\x1b[93m", $rendered);
    }

    /**
     * Contrast/regression guard: with sanitisation disabled the SAME injected
     * fenced-code sequence leaks through verbatim. This proves the strip in
     * renderFencedCode — not the tokeniser — is what neutralises the injection,
     * making {@see testInjectedControlBytesInHighlightedFencedCodeNeutralized}
     * load-bearing.
     */
    public function testFencedCodeSanitizeFalseLeaksInjection(): void
    {
        $input = "```php\n\$x = 42;\n// note\x1b]0;INJECTED\x07\n```\n";
        // Plain theme keeps the highlighter output free of its own SGR, so the
        // leaked bytes are unambiguously the source injection.
        $rendered = Renderer::plain()->withSanitize(false)->render($input);
        $this->assertStringContainsString("\x1b]0;", $rendered);
        $this->assertStringContainsString("\x07", $rendered);
    }

    public function testHyperlinkUrlEscStripped(): void
    {
        // ESC ]2; is the OSC-8 opener; BEL terminates it — neither belongs in URL.
        $input = "[x](http://e\x1b]2;evil\x07)";
        $rendered = Renderer::ansi()->withHyperlinks(true)->render($input);
        $this->assertStringNotContainsString("\x1b]2;", $rendered);
        $this->assertStringNotContainsString("\x07", $rendered);
    }

    public function testImageUrlEscStripped(): void
    {
        $input = "![alt](http://e\x1b]2;evil\x07)";
        $rendered = Renderer::ansi()->withHyperlinks(true)->render($input);
        $this->assertStringNotContainsString("\x1b]2;", $rendered);
        $this->assertStringNotContainsString("\x07", $rendered);
    }

    public function testNormalUrlUnaffected(): void
    {
        $input = "[link](https://example.com/a?b=1#c)";
        $rendered = Renderer::ansi()->withHyperlinks(true)->render($input);
        $this->assertStringContainsString("https://example.com/a?b=1#c", $rendered);
    }

    /**
     * Per-node granularity (load-bearing): raw HTML is emitted verbatim to
     * the terminal, so its control bytes are stripped UNCONDITIONALLY — even
     * with withSanitize(false), which only relaxes the general text pipeline.
     * Revert renderHtmlBlock to gate the strip on $this->sanitize and this
     * test fails (the injected OSC/CSI/BEL leak straight to the terminal).
     */
    public function testHtmlBlockControlsStrippedEvenWhenSanitizeOff(): void
    {
        $input = "<div>\x1b[31msecret\x1b]0;pwn\x07\x1b[0m</div>";
        $rendered = Renderer::plain()->withSanitize(false)->render($input);
        $this->assertStringNotContainsString("\x1b", $rendered);
        $this->assertStringNotContainsString("\x07", $rendered);
        // Payload text survives as inert characters — proves the bytes were
        // neutralised, not that the whole block was dropped.
        $this->assertStringContainsString('secret', $rendered);
    }

    /**
     * Companion to {@see testHtmlBlockControlsStrippedEvenWhenSanitizeOff}
     * for inline HTML: an ESC hidden inside a tag attribute (which lands in
     * the HtmlInline node's literal, not an adjacent Text node) is stripped
     * regardless of the sanitize toggle. Revert renderHtmlSpan's strip →
     * this fails.
     */
    public function testHtmlInlineControlsStrippedEvenWhenSanitizeOff(): void
    {
        $input = "text <span title=\"\x1b[31m\x1b]0;x\x07\">y</span> end";
        $rendered = Renderer::plain()->withSanitize(false)->render($input);
        $this->assertStringNotContainsString("\x1b", $rendered);
        $this->assertStringNotContainsString("\x07", $rendered);
    }

    /**
     * Narrowness guard: the always-on raw-HTML strip must NOT bleed into the
     * general text pipeline. With withSanitize(false), plain-text control
     * bytes still pass through (the documented behaviour for trusted input),
     * proving the HTML strip is per-node, not a global force-strip. This is
     * the contrast that keeps the two HTML tests above meaningful.
     */
    public function testSanitizeOffStillLeaksPlainTextControls(): void
    {
        $input = "before \x1b[31mtext\x1b[0m after";
        $rendered = Renderer::plain()->withSanitize(false)->render($input);
        $this->assertStringContainsString("\x1b[31m", $rendered);
    }

    /**
     * Emoji shortcodes expand AFTER parsing, in renderText(); the sanitizer
     * strips control bytes from the text node first, so bytes riding
     * alongside — or, for a future shortcode, produced by — the expansion are
     * stripped rather than carried into the terminal, while the emoji itself
     * survives. Guards the sanitize-then-expand ordering in renderText().
     */
    public function testEmojiExpansionSanitizedAfterExpansion(): void
    {
        $input = ":fire:\x1b[31mX\x1b]0;pwn\x07";
        $rendered = Renderer::plain()->withEmoji(true)->render($input);
        $this->assertStringContainsString('🔥', $rendered);
        $this->assertStringNotContainsString("\x1b", $rendered);
        $this->assertStringNotContainsString("\x07", $rendered);
    }

    /**
     * Encoding cliff (m19): before the scrub hoist, a lone C1 byte out of a
     * sanitize-off renderer crashed inside CommonMark with an
     * UnexpectedEncodingException — sanitize governs content filtering, not
     * UTF-8 validity, and refusing to parse is neither. The byte is removed
     * (matching sugar-core Sanitize::untrusted), the rest renders.
     */
    public function testLoneC1ByteRendersInsteadOfThrowingWithSanitizeOff(): void
    {
        $input = "a\x80b\x9b done\n";
        $rendered = Renderer::plain()->withSanitize(false)->render($input);
        $this->assertStringContainsString('ab', $rendered);
        $this->assertStringContainsString('done', $rendered);
        $this->assertStringNotContainsString("\x80", $rendered);
        $this->assertStringNotContainsString("\x9b", $rendered);
    }

    /**
     * Malformed UTF-8 with sanitize off is repaired to U+FFFD (not thrown,
     * not silently dropped): the repair arm of scrubForParse now runs on
     * every channel.
     */
    public function testMalformedUtf8RepairedToReplacementCharWithSanitizeOff(): void
    {
        $rendered = Renderer::plain()->withSanitize(false)->render("x\xffy\n");
        $this->assertStringContainsString("\u{FFFD}", $rendered);
        $this->assertStringContainsString('x', $rendered);
        $this->assertStringContainsString('y', $rendered);
    }

    /**
     * The hoist moved only the cliff: hostile-encoding bytes answer the same
     * bytes whether sanitize is on or off (content filtering still diverges
     * — see the ESC pins above; encoding repair does not).
     */
    public function testEncodingScrubIsIdenticalOnBothSidesOfSanitize(): void
    {
        $input = "a\x80b\x9b ü café\n";
        $this->assertSame(
            Renderer::plain()->render($input),
            Renderer::plain()->withSanitize(false)->render($input),
        );
    }

    /**
     * The stream channel prepares each section through parseSection(), which
     * carries the same hoist — a hostile byte mid-document must not throw
     * there either, and stream() must stay byte-identical to render().
     */
    public function testStreamChannelCarriesTheSameEncodingHoist(): void
    {
        $input = "see [a] now\x80\n\n[a]: /url\n";
        $renderer = Renderer::plain()->withSanitize(false);
        $streamed = implode('', iterator_to_array($renderer->stream([$input]), false));
        $this->assertSame($renderer->render($input), $streamed);
    }

    /**
     * SM-S16 (FUZZ-03): withRawControlChars() is the loud name for the
     * opt-out; withSanitize() remains as its documented-but-soft-warning
     * alias. The pair must stay byte-exact mirrors in both states.
     */
    public function testTheLoudFlagMirrorsTheSanitizeAliasInBothStates(): void
    {
        $md = "hi `a\x1b]0;title\x07b` tail\n";

        $this->assertSame(
            Renderer::plain()->withSanitize(false)->render($md),
            Renderer::plain()->withRawControlChars(true)->render($md),
            'withRawControlChars(true) must equal withSanitize(false)',
        );
        $this->assertSame(
            Renderer::plain()->withSanitize(true)->render($md),
            Renderer::plain()->withRawControlChars(false)->render($md),
            'withRawControlChars(false) must equal the sanitizing default',
        );
    }

    /**
     * Acceptance probe for SM-S16: the default (sanitize on, equivalently
     * withRawControlChars(false)) path refuses a window-title OSC bait
     * smuggled through a code span, while the loud opt-out ships it raw —
     * documenting the residual rather than hiding it.
     */
    public function testTheDefaultPathRefusesTheOscTitleBaitAndTheLoudFlagShipsItRaw(): void
    {
        $md = "```\na\x1b]2;pwned\x07b\n```\n";

        $safe = Renderer::plain()->render($md);
        $this->assertStringNotContainsString("\x1b]2;", $safe, 'raw OSC must not reach the terminal by default');
        $this->assertStringContainsString(']2;pwned', $safe, 'the bait text survives inertly');

        $loud = Renderer::plain()->withRawControlChars(false)->render($md);
        $this->assertSame($safe, $loud, 'withRawControlChars(false) is the sanitizing state');

        $raw = Renderer::plain()->withRawControlChars(true)->render($md);
        $this->assertStringContainsString("\x1b]2;pwned\x07", $raw, 'the opt-out ships the escape verbatim (documented residual)');
    }
}
