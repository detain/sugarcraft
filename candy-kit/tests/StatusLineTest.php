<?php

declare(strict_types=1);

namespace CandyCore\Kit\Tests;

use CandyCore\Kit\StatusLine;
use CandyCore\Kit\Theme;
use PHPUnit\Framework\TestCase;

final class StatusLineTest extends TestCase
{
    public function testSuccessGlyphPlain(): void
    {
        $this->assertSame('✓ done', StatusLine::success('done', Theme::plain()));
    }

    public function testErrorGlyphPlain(): void
    {
        $this->assertSame('✗ kaboom', StatusLine::error('kaboom', Theme::plain()));
    }

    public function testWarnGlyphPlain(): void
    {
        $this->assertSame('⚠ careful', StatusLine::warn('careful', Theme::plain()));
    }

    public function testInfoGlyphPlain(): void
    {
        $this->assertSame('ℹ heads up', StatusLine::info('heads up', Theme::plain()));
    }

    public function testPromptGlyphPlain(): void
    {
        $this->assertSame('? continue', StatusLine::prompt('continue', Theme::plain()));
    }

    public function testAnsiSuccessIsStyledThenPlainMessage(): void
    {
        $line = StatusLine::success('done');
        // Glyph is wrapped in SGR; message is plain text after a space.
        $this->assertStringContainsString("\x1b[", $line);
        $this->assertStringContainsString(' done', $line);
        $this->assertStringContainsString(StatusLine::GLYPH_SUCCESS, $line);
    }

    public function testGlyphsAreSingleCharacters(): void
    {
        $this->assertSame(1, mb_strlen(StatusLine::GLYPH_SUCCESS));
        $this->assertSame(1, mb_strlen(StatusLine::GLYPH_ERROR));
        $this->assertSame(1, mb_strlen(StatusLine::GLYPH_WARN));
        $this->assertSame(1, mb_strlen(StatusLine::GLYPH_INFO));
    }
}
