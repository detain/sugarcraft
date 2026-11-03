<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;

/**
 * Word wrap covers paragraph, blockquote and list-item bodies; headings
 * pass through unwrapped, matching glamour. The README wrap promise and
 * the withWordWrap docblock both disclose it; these pins hold the line.
 */
final class HeadingWrapScopeTest extends TestCase
{
    public function testAHeadingBeyondTheWrapStaysOnOneLine(): void
    {
        $out = Renderer::plain()->withWordWrap(40)->render('# ' . str_repeat('head ', 40));

        $this->assertSame(0, substr_count($out, "\n"), 'M19: headings are never wrapped (glamour parity)');
    }

    public function testAParagraphBeyondTheWrapBreaks(): void
    {
        $out = Renderer::plain()->withWordWrap(40)->render(str_repeat('word ', 40));

        $this->assertGreaterThan(0, substr_count($out, "\n"), 'the same renderer still wraps body text');
    }
}
