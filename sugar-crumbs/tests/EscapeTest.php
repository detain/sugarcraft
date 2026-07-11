<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs\Tests;

use SugarCraft\Crumbs\Escape;
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
        // With no $separator argument the default is ' › ' (Breadcrumb's own
        // default), NOT the ASCII ' > ' — so a title carrying the real crumb
        // separator gets escaped, and an ASCII ' > ' is left untouched.
        $this->assertSame('A\ › B', Escape::title('A › B'));
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
}
