<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use SugarCraft\NavStack\{NavStack, NavigationItem, Url};
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
            '<nav class="breadcrumb" aria-label="Breadcrumb"><ol><li aria-current="page">Home</li></ol></nav>',
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
            '<nav class="breadcrumb" aria-label="Breadcrumb">'
            . '<ol><li>Home</li><li>Settings</li><li aria-current="page">Display</li></ol>'
            . '</nav>',
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
        $this->assertStringContainsString('<li>A</li>', $html);
        $this->assertStringContainsString('<li>B</li>', $html);
        $this->assertStringContainsString('<li aria-current="page">C</li>', $html);

        // Non-last items should NOT have aria-current
        $this->assertStringNotContainsString('aria-current="page">A', $html);
        $this->assertStringNotContainsString('aria-current="page">B', $html);
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
        $this->assertStringStartsWith('<nav class="breadcrumb" aria-label="Breadcrumb"><ol>', $html);
        $this->assertStringEndsWith('</ol></nav>', $html);
    }

    /**
     * The semantic-list shape carries NO literal separator text — the old
     * ' &gt; ' gutter made screen readers announce noise between crumbs;
     * hosts style the separator in CSS.
     */
    public function testViewHtmlEmitsNoSeparatorText(): void
    {
        $stack = new NavStack();
        $stack->push('A');
        $stack->push('B');
        $stack->push('C');

        $html = $stack->viewHtml();
        $this->assertStringNotContainsString(' &gt; ', $html);
        $this->assertSame(0, \substr_count($html, ' &gt; '));
    }

    public function testViewHtmlSanitizesTerminalControlsBeforeEscaping(): void
    {
        // view() and the terminal render are display-safe; viewHtml() must
        // be too — htmlspecialchars alone leaves ESC/NUL/zone-sentinel bytes
        // intact, and a copy-paste of the string into a terminal reanimates
        // them. This pin holds the SHARED sanitizer (TitleSanitizer) in the
        // HTML path, not just the terminal path.
        $stack = new NavStack();
        $stack->push("Ev\x1b[31mil\x00\u{E000}crumb-0\u{E001}Name");

        $html = $stack->viewHtml();

        $this->assertStringNotContainsString("\x1b", $html);
        $this->assertStringNotContainsString("\x00", $html);
        $this->assertStringNotContainsString("\u{E000}", $html);
        $this->assertStringNotContainsString("\u{E001}", $html);
        $this->assertStringNotContainsString('[31m', $html);
        $this->assertStringContainsString('Evil', $html);
        $this->assertStringContainsString('Name', $html);
    }

    public function testViewHtmlHrefCallbackLinksAncestorsOnly(): void
    {
        $stack = new NavStack();
        $stack->push('Home');
        $stack->push('Settings');
        $stack->push('Display');

        $html = $stack->viewHtml(static fn(NavigationItem $i): ?string => '/' . \strtolower($i->title));

        $this->assertStringContainsString('<li><a href="/home">Home</a></li>', $html);
        $this->assertStringContainsString('<li><a href="/settings">Settings</a></li>', $html);
        // The current page is never a link to itself
        $this->assertStringContainsString('<li aria-current="page">Display</li>', $html);
        $this->assertStringNotContainsString('<a href="/display"', $html);
    }

    public function testViewHtmlHrefReceivesItemAndNullLeavesPlainText(): void
    {
        $stack = new NavStack();
        $stack->push('Home');
        $stack->push('Secret');

        $seen = [];
        $html = $stack->viewHtml(static function(NavigationItem $i) use (&$seen): ?string {
            $seen[] = $i->title;
            return $i->title === 'Home' ? '/home' : null;
        });

        // Only ancestors are consulted (last item has no link slot)
        $this->assertSame(['Home'], $seen);
        $this->assertStringContainsString('<li><a href="/home">Home</a></li>', $html);
        $this->assertStringContainsString('<li aria-current="page">Secret</li>', $html);
    }

    public function testViewHtmlHrefIsEscaped(): void
    {
        $stack = new NavStack();
        $stack->push('Home');
        $stack->push('End');

        $html = $stack->viewHtml(static fn(NavigationItem $i): string => '/x" onmouseover="alert(1)');

        $this->assertStringNotContainsString('onmouseover="alert', $html);
        $this->assertStringContainsString('&quot; onmouseover=&quot;', $html);
    }

    public function testViewHtmlCustomAriaLabel(): void
    {
        $stack = new NavStack();
        $stack->push('Home');

        $html = $stack->viewHtml(null, 'You are here');
        $this->assertStringContainsString('aria-label="You are here"', $html);
    }

    public function testViewHtmlAriaLabelIsEscaped(): void
    {
        $stack = new NavStack();
        $stack->push('Home');

        $html = $stack->viewHtml(null, 'a"b');
        $this->assertStringContainsString('aria-label="a&quot;b"', $html);
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

    // ─── NS-06: $allowSchemes gate on callback URLs ──────────────────────────

    private function threeDeep(): NavStack
    {
        return (new NavStack())->push('Home')->push('Docs')->push('Now');
    }

    public function testViewHtmlAllowListNullKeepsLegacyBytes(): void
    {
        $stack = $this->threeDeep();
        $href  = static fn ($item): string => '/p/' . $item->title;

        // The two-arg call and an explicit null are byte-identical, and
        // even a javascript: URL passes through untouched (trust intact).
        $this->assertSame(
            $stack->viewHtml($href),
            $stack->viewHtml($href, 'Breadcrumb', null),
        );
        $evil = $stack->viewHtml(static fn ($item): string => 'javascript:alert(1)');
        $this->assertStringContainsString('href="javascript:alert(1)"', $evil);
    }

    public function testViewHtmlRejectsHostileSchemesAsPlainText(): void
    {
        $stack = $this->threeDeep();
        $html  = $stack->viewHtml(
            static fn ($item): string => $item->title === 'Docs'
                ? 'javascript:alert(1)'
                : 'data:text/html,<script>1</script>',
            'Breadcrumb',
            ['http', 'https'],
        );

        // Rejected crumbs keep their TITLE as plain text, lose only the anchor.
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('<li>Docs</li>', $html);
        $this->assertStringContainsString('<li>Home</li>', $html);
        $this->assertStringContainsString('aria-current="page"', $html, 'last crumb unaffected');
    }

    public function testViewHtmlAdmitsAllowListedSchemesCaseInsensitively(): void
    {
        $stack = $this->threeDeep();
        $html  = $stack->viewHtml(
            static fn ($item): string => 'HTTPS://example.test/' . $item->title,
            'Breadcrumb',
            ['https'],
        );

        $this->assertStringContainsString('<a href="HTTPS://example.test/Home">', $html);
        $this->assertStringContainsString('<a href="HTTPS://example.test/Docs">', $html);
    }

    public function testViewHtmlPathDoorIsTheSlashToken(): void
    {
        $stack = $this->threeDeep();

        // With '/' in the list, derive()-style absolute paths link...
        $ok = $stack->viewHtml(static fn ($item): string => '/x/' . $item->title, 'Breadcrumb', ['/', 'https']);
        $this->assertStringContainsString('<a href="/x/Home">', $ok);

        // ...without it, scheme-less paths are rejected (the classic
        // relative-URL mistake cannot silently link).
        $no = $stack->viewHtml(static fn ($item): string => '/x/' . $item->title, 'Breadcrumb', ['https']);
        $this->assertStringNotContainsString('<a ', $no);

        // A colon after a slash is path content, not a scheme:
        $edge = $stack->viewHtml(static fn ($item): string => '/a/b:c', 'Breadcrumb', ['/']);
        $this->assertStringContainsString('<a href="/a/b:c">', $edge);
    }

    public function testViewHtmlPerItemGateKeepsGoodAnchorsAndBlanksBadOnes(): void
    {
        $stack = $this->threeDeep();
        $html  = $stack->viewHtml(
            static fn ($item): string => $item->title === 'Home'
                ? '/keep/me'
                : 'vbscript:kill',
            'Breadcrumb',
            ['/'],
        );

        $this->assertStringContainsString('<a href="/keep/me">Home</a>', $html);
        $this->assertStringContainsString('<li>Docs</li>', $html);
        $this->assertStringNotContainsString('vbscript', $html);
    }

    public function testViewHtmlOneArgHrefClosuresKeepWorkingUnchanged(): void
    {
        $stack = NavStack::new();
        $stack->push('Home')->push('Settings')->push('Display');

        // Typed for the item ALONE — the extra index argument is tolerated.
        $href = static fn (NavigationItem $item): string => '/x/' . $item->title;
        $html = $stack->viewHtml($href);

        $this->assertStringContainsString('<a href="/x/Home">Home</a>', $html);
        $this->assertStringContainsString('<a href="/x/Settings">Settings</a>', $html);
        $this->assertStringContainsString('<li aria-current="page">Display</li>', $html);
    }

    public function testViewHtmlHrefReceivesCrumbStackIndex(): void
    {
        $seen = [];
        $stack = NavStack::new();
        $stack->push('Home')->push('Settings')->push('Display');

        $html = $stack->viewHtml(static function (NavigationItem $item, int $index) use (&$seen): ?string {
            $seen[] = [$item->title, $index];
            return null;
        });

        // Non-final crumbs only, in stack order — the live crumb is text.
        $this->assertSame([['Home', 0], ['Settings', 1]], $seen);
        $this->assertStringContainsString('<li>Home</li>', $html);
    }

    public function testViewHtmlDuplicateTitlesGetDistinctDeriveToHrefs(): void
    {
        $stack = NavStack::new();
        $stack->push('Home')->push('Settings')->push('Settings');

        $html = $stack->viewHtml(
            static fn (NavigationItem $item, int $index): string => Url::deriveTo($stack, $index)
        );

        $this->assertStringContainsString('<a href="/Home">Home</a>', $html);
        $this->assertStringContainsString('<a href="/Home/Settings">Settings</a>', $html);
        $this->assertStringContainsString('<li aria-current="page">Settings</li>', $html);
        // The same title at two depths is not interchangeable: the linked
        // crumb carries the SHORTER prefix. The full trail belongs to the
        // current page, which is deliberately text, not an anchor.
        $this->assertStringNotContainsString('/Home/Settings/Settings', $html);
    }
}
