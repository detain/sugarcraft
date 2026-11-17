<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests\Html;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Html\HtmlToMarkdown;

/**
 * The builder added when the converter moved into sugar-markdown: the byte
 * ceiling is a per-instance value, set through an immutable builder.
 */
final class HtmlToMarkdownOptionsTest extends TestCase
{
    public function testDefaultCeilingIsTheConstant(): void
    {
        $this->assertSame(HtmlToMarkdown::MAX_PARSE_BYTES, HtmlToMarkdown::new()->maxBytes);
    }

    public function testWithMaxBytesRaisesTheCeilingForThatInstanceOnly(): void
    {
        $big = '<p>' . str_repeat('word ', 120_000) . '</p>';
        $this->assertGreaterThan(HtmlToMarkdown::MAX_PARSE_BYTES, strlen($big));

        $this->assertNull(HtmlToMarkdown::new()->convert($big, ''));
        $this->assertNotNull(HtmlToMarkdown::new()->withMaxBytes(strlen($big))->convert($big, ''));
    }

    public function testWithMaxBytesLowersTheCeiling(): void
    {
        $this->assertNull(HtmlToMarkdown::new()->withMaxBytes(8)->convert('<p>longer than eight bytes</p>', ''));
    }

    public function testBuilderDoesNotMutateTheReceiver(): void
    {
        $base = HtmlToMarkdown::new();
        $base->withMaxBytes(10);

        $this->assertSame(HtmlToMarkdown::MAX_PARSE_BYTES, $base->maxBytes);
    }

    public function testNonPositiveCeilingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HtmlToMarkdown::new()->withMaxBytes(0);
    }
}
