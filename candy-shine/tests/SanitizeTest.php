<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
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
        $stripped = \SugarCraft\Shine\Renderer::class;
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
     * raw colour CSI), the one behaviour the deleted sugar-glow
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

        // Sanity: the highlighter is active (number 42 → yellow), so the strip is
        // exercised on the real "before highlighting" path.
        $this->assertStringContainsString("\x1b[38;2;255;255;0m", $rendered);
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
}
