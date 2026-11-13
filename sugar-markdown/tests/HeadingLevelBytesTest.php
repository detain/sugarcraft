<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Byte-exact pins for heading levels 3, 4 and 5 under the stock ANSI
 * theme. One test per level — never a shared loop: a single loop hides
 * a heading3<->heading5 swap (the match arms in Renderer's heading
 * dispatch are one reordering away from silently trading styles), while
 * three distinct literals pin each level's SGR bytes and its
 * str_repeat('#', level) prefix count independently.
 */
final class HeadingLevelBytesTest extends TestCase
{
    public function testHeadingLevelThreeBytes(): void
    {
        $out = (new Renderer(Theme::ansi(), wrapWidth: null))->render("### Head\n");

        $this->assertSame("\x1b[1m\x1b[93m### Head\x1b[0m", $out);
        $this->assertSame(3, substr_count($out, '#'));
    }

    public function testHeadingLevelFourBytes(): void
    {
        $out = (new Renderer(Theme::ansi(), wrapWidth: null))->render("#### Head\n");

        $this->assertSame("\x1b[1m\x1b[94m#### Head\x1b[0m", $out);
        $this->assertSame(4, substr_count($out, '#'));
    }

    public function testHeadingLevelFiveBytes(): void
    {
        $out = (new Renderer(Theme::ansi(), wrapWidth: null))->render("##### Head\n");

        $this->assertSame("\x1b[1m\x1b[95m##### Head\x1b[0m", $out);
        $this->assertSame(5, substr_count($out, '#'));
    }
}
