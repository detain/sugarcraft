<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\StatusLine;
use SugarCraft\Kit\Theme;
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

    /**
     * Security: caller messages are interpolated raw into styled output, so an
     * embedded escape / control byte would inject into the terminal. The plain
     * theme adds no SGR of its own, so any ESC / BEL in the output must have
     * come from the message — assert they are neutralized. (Revert the
     * SafeText routing in StatusLine::format() and this leaks → fails.)
     */
    public function testMessageEscapeAndControlBytesNeutralized(): void
    {
        $evil = "hi\x1b[2Jthere\x1b]0;pwned\x07tail";
        $out  = StatusLine::error($evil, Theme::plain());

        $this->assertStringNotContainsString("\x1b", $out, 'ESC injection must be stripped');
        $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
        // The visible text survives; only the control bytes are removed.
        $this->assertStringContainsString('hithere', $out);
        $this->assertStringContainsString('tail', $out);
    }

    /** Clean ASCII messages render byte-for-byte identically (no regression). */
    public function testCleanMessageUnchanged(): void
    {
        $this->assertSame('✓ Deployment complete', StatusLine::success('Deployment complete', Theme::plain()));
    }
}
