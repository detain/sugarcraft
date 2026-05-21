<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs\Tests;

use SugarCraft\Crumbs\{NavStack, NavigationItem};
use PHPUnit\Framework\TestCase;

final class NavStackHtmlTest extends TestCase
{
    public function testViewHtmlEmptyStack(): void
    {
        $stack = new NavStack();
        $this->assertSame('', $stack->viewHtml());
    }

    public function testViewHtmlSingleItem(): void
    {
        $stack = new NavStack();
        $stack->push('Home');

        $html = $stack->viewHtml();
        $this->assertSame(
            '<nav class="breadcrumb" aria-label="Breadcrumb"><span aria-current="page">Home</span></nav>',
            $html
        );
    }

    public function testViewHtmlMultipleItems(): void
    {
        $stack = new NavStack();
        $stack->push('Home');
        $stack->push('Settings');
        $stack->push('Display');

        $html = $stack->viewHtml();
        $this->assertSame(
            '<nav class="breadcrumb" aria-label="Breadcrumb"><span>Home</span> &gt; <span>Settings</span> &gt; <span aria-current="page">Display</span></nav>',
            $html
        );
    }

    public function testViewHtmlLastItemHasAriaCurrent(): void
    {
        $stack = new NavStack();
        $stack->push('A');
        $stack->push('B');
        $stack->push('C');

        $html = $stack->viewHtml();

        // Only the last item should have aria-current="page"
        $this->assertStringContainsString('<span>A</span>', $html);
        $this->assertStringContainsString('<span>B</span>', $html);
        $this->assertStringContainsString('<span aria-current="page">C</span>', $html);

        // Non-last items should NOT have aria-current
        $this->assertStringNotContainsString('aria-current="page">A</span>', $html);
        $this->assertStringNotContainsString('aria-current="page">B</span>', $html);
    }

    public function testViewHtmlEscapesHtmlCharacters(): void
    {
        $stack = new NavStack();
        $stack->push('Home');
        $stack->push('Settings <Admin>');

        $html = $stack->viewHtml();

        // Should be HTML-escaped
        $this->assertStringContainsString('&lt;Admin&gt;', $html);
        $this->assertStringNotContainsString('<Admin>', $html);
    }

    public function testViewHtmlHasNavElement(): void
    {
        $stack = new NavStack();
        $stack->push('Home');

        $html = $stack->viewHtml();
        $this->assertStringStartsWith('<nav class="breadcrumb" aria-label="Breadcrumb">', $html);
        $this->assertStringEndsWith('</nav>', $html);
    }

    public function testViewHtmlUsesSeparatorConstant(): void
    {
        $stack = new NavStack();
        $stack->push('X');
        $stack->push('Y');

        $html = $stack->viewHtml();

        // Default separator is ' > ' (HTML-escaped as ' &gt; ')
        $this->assertStringContainsString(' &gt; ', $html);
    }

    public function testViewHtmlSeparatorsBetweenItemsOnly(): void
    {
        $stack = new NavStack();
        $stack->push('A');
        $stack->push('B');
        $stack->push('C');

        $html = $stack->viewHtml();

        // Count occurrences of separator (HTML-escaped)
        // Should have exactly 2 separators for 3 items
        $this->assertSame(2, \substr_count($html, ' &gt; '));
    }

    public function testViewHtmlSingleItemNoSeparator(): void
    {
        $stack = new NavStack();
        $stack->push('Only');

        $html = $stack->viewHtml();

        // No separator should appear for single item
        $this->assertStringNotContainsString(' &gt; ', $html);
    }

    public function testViewHtmlEscapesAmpersand(): void
    {
        $stack = new NavStack();
        $stack->push('Bob & Alice');

        $html = $stack->viewHtml();
        $this->assertStringContainsString('&amp;', $html);
        // Verify the raw ampersand is NOT in the output (only the escaped version)
        $this->assertStringNotContainsString('Bob & Alice', $html);
    }

    public function testViewHtmlEscapesQuotes(): void
    {
        $stack = new NavStack();
        $stack->push('Say "Hello"');

        $html = $stack->viewHtml();
        $this->assertStringContainsString('&quot;Hello&quot;', $html);
    }
}
