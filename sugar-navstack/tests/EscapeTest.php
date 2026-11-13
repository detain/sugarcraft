<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use SugarCraft\NavStack\Escape;
use SugarCraft\NavStack\NavStack;
use PHPUnit\Framework\TestCase;

final class EscapeTest extends TestCase
{
    public function testTitleWithoutSeparatorUnchanged(): void
    {
        $title = 'Home Settings Display';
        $this->assertSame($title, Escape::title($title, ' > '));
    }

    public function testTitleWithSeparatorGetsEscaped(): void
    {
        $title = 'Settings > Display';
        // Explicit ' > ' separator → ' > ' is replaced with '\ > '.
        $escaped = Escape::title($title, ' > ');
        $this->assertSame('Settings\ > Display', $escaped);
    }

    public function testUnescapeRoundTrip(): void
    {
        $original = 'Home > Settings > Display';
        $escaped = Escape::title($original, ' > ');
        $restored = Escape::unescape($escaped, ' > ');
        $this->assertSame($original, $restored);
    }

    public function testUnescapeRestoresOriginal(): void
    {
        // After escaping ' > ' -> '\ > ', unescape should restore it.
        $escaped = 'Root\ > Branch > Leaf';
        $unescaped = Escape::unescape($escaped, ' > ');
        $this->assertSame('Root > Branch > Leaf', $unescaped);
    }

    public function testTitleMultipleSeparators(): void
    {
        $title = 'A > B > C > D';
        $escaped = Escape::title($title, ' > ');
        // Each ' > ' is replaced with '\ > '.
        $this->assertSame('A\ > B\ > C\ > D', $escaped);
    }

    public function testUnescapeMultipleSeparators(): void
    {
        $escaped = 'A\ > B\ > C\ > D';
        $restored = Escape::unescape($escaped, ' > ');
        $this->assertSame('A > B > C > D', $restored);
    }

    public function testUnescapeEmptyString(): void
    {
        $this->assertSame('', Escape::unescape(''));
    }

    public function testTitleEmptyString(): void
    {
        $this->assertSame('', Escape::title(''));
    }

    public function testEscapeDoesNotModifyNonSeparatorArrow(): void
    {
        $title = 'A -> B';
        $this->assertSame($title, Escape::title($title, ' > '));
    }

    public function testEscapeSeparatorWithSpacesOnly(): void
    {
        $title = 'item > subitem';
        $escaped = Escape::title($title, ' > ');
        $this->assertSame('item\ > subitem', $escaped);
    }

    public function testUnescapeEscapedTitle(): void
    {
        $title = 'Top\ > Middle > Bottom';
        $restored = Escape::unescape($title, ' > ');
        $this->assertSame('Top > Middle > Bottom', $restored);
    }

    // ─── $separator parameter (default matches Breadcrumb's ' › ') ────────────

    public function testTitleDefaultSeparatorMatchesBreadcrumbArrow(): void
    {
        // With no $separator argument the default is the single-source
        // NavStack::SEPARATOR (NS-13) that Breadcrumb also starts from,
        // NOT the ASCII ' > ' — so a title carrying the real crumb
        // separator gets escaped, and an ASCII ' > ' is left untouched.
        $sep = NavStack::SEPARATOR;
        $this->assertSame('A\\' . $sep . 'B', Escape::title('A' . $sep . 'B'));
        $this->assertSame('A > B', Escape::title('A > B'));
    }

    public function testTitleHonorsCustomSeparator(): void
    {
        $escaped = Escape::title('left | right', ' | ');
        $this->assertSame('left\ | right', $escaped);
        // Round-trips with the same custom separator.
        $this->assertSame('left | right', Escape::unescape($escaped, ' | '));
    }

    public function testDefaultSeparatorRoundTrip(): void
    {
        $original = 'Home › Settings › Display';
        $this->assertSame($original, Escape::unescape(Escape::title($original)));
    }

    // ─── NS-23: the documented lossy paths, now executable ────────────────────

    public function testUnescapeSwallowsBackslashOnNeverEscapedInput(): void
    {
        // sugar-navstack-027: a title that ALWAYS contained '\ › ' as its
        // own text (no escape ever ran) is silently rewritten by unescape()
        // — documented as expected; pinned so the docblock can't drift
        // toward claiming totality.
        $neverEscaped = 'Top\ › Middle';
        $this->assertSame('Top › Middle', Escape::unescape($neverEscaped));
    }

    public function testDoubleEscapeCostsOneUnescapePerLayer(): void
    {
        // title(title(t)) stacks two backslashes ('A\\ > B'); ONE unescape
        // removes one layer and lands on the SINGLE-escaped shape, not on
        // t — the lossy leg of "no double-escape marker". Two unescapes
        // walk back two layers, so the pair stays mechanically symmetric
        // layer-per-call even while the intermediate is ambiguous.
        $once  = Escape::title('A > B', ' > ');
        $twice = Escape::title($once, ' > ');
        // Seven bytes: A \ \ sp > sp B  (two stacked backslashes).
        $this->assertSame('A\\\\ > B', $twice);

        $this->assertSame('A\ > B', Escape::unescape($twice, ' > '));
        $this->assertSame($once, Escape::unescape($twice, ' > '), 'one layer per call');
        $this->assertSame('A > B', Escape::unescape(Escape::unescape($twice, ' > '), ' > '));
    }
}
