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
