<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use SugarCraft\Cli\AutoWidth;
use SugarCraft\Cli\StatusLine;
use SugarCraft\Cli\Theme;
use SugarCraft\Cli\Tests\Support\SandboxTerminalColumns;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class StatusLineTest extends TestCase
{
    use SandboxTerminalColumns;

    protected function setUp(): void
    {
        // The default clamp resolves AutoWidth — hide any terminal width the
        // runner advertises so the 80-fallback pins below stay honest.
        $this->sandboxTerminalColumns();
    }

    protected function tearDown(): void
    {
        $this->restoreTerminalColumns();
    }

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
        $line = StatusLine::success('done', Theme::ansi());
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

    /** A message wider than the budget is cut with the house ellipsis. */
    public function testLongMessageIsClippedToWidth(): void
    {
        $out = StatusLine::success(str_repeat('x', 100), Theme::plain(), width: 10);
        $this->assertSame('✓ xxxxxxx…', $out);
        $this->assertLessThanOrEqual(10, Width::string($out));
    }

    /** Messages that already fit come back byte-identical — the clamp is opt-nothing. */
    public function testFittingMessageUnchangedByWidth(): void
    {
        $this->assertSame('✓ done', StatusLine::success('done', Theme::plain(), width: 6));
    }

    /**
     * A budget under the glyph+separator drops the separator instead of
     * overflowing (finding 032): the line is exactly the glyph, styling kept.
     */
    public function testWidthOneDegradesToBareGlyph(): void
    {
        $out = StatusLine::success('anything at all', Theme::plain(), width: 1);
        $this->assertSame('✓', $out);
        $this->assertSame(1, Width::string($out));
        $styled = StatusLine::warn('x', Theme::ansi(), width: 1);
        $this->assertStringStartsWith("\x1b[", $styled);
        $this->assertSame(1, Width::string($styled));
    }

    /** At exactly the prefix width the separator survives; only the message goes. */
    public function testWidthTwoKeepsSeparatorWithEmptyMessage(): void
    {
        $this->assertSame('✓ ', StatusLine::success('far too long to fit', Theme::plain(), width: 2));
    }

    /** null opts out of the clamp entirely: the full message renders. */
    public function testNullWidthKeepsFullMessage(): void
    {
        $long = str_repeat('y', 200);
        $this->assertSame('✓ ' . $long, StatusLine::success($long, Theme::plain(), null));
    }

    /** The default resolves the advertised terminal width, not the hardcoded 80. */
    public function testAutoClipsToAdvertisedColumns(): void
    {
        $this->pinTerminalColumns(8);
        $out = StatusLine::success(str_repeat('z', 40), Theme::plain());
        // Budget 8-2=6 cells: five z's + the house ellipsis.
        $this->assertSame('✓ zzzzz…', $out);
    }

    /** The SGR around the glyph costs no cells: the budget is measured in cells. */
    public function testClampMeasuresStyledPrefixInCells(): void
    {
        $out = StatusLine::success(str_repeat('w', 50), Theme::ansi(), width: 12);
        $this->assertStringContainsString("\x1b[", $out, 'glyph still styled');
        $this->assertSame(12, Width::string($out), 'styled bytes cost no cells: full budget lands on the message');
        $this->assertStringEndsWith(str_repeat('w', 9) . '…', $out);
    }

    /** Multi-cell glyphs clip by cells, never mid-character. */
    public function testCjkMessageClipsByCells(): void
    {
        $out = StatusLine::success('日本語のテキスト', Theme::plain(), width: 7);
        $this->assertLessThanOrEqual(7, Width::string($out), "line fits: '{$out}'");
        $this->assertStringStartsWith('✓ 日本', $out);
    }

    /** AutoWidth::Auto may be named explicitly (parity with omitting it). */
    public function testExplicitAutoEqualsOmitted(): void
    {
        $long = str_repeat('v', 120);
        $this->assertSame(
            StatusLine::info($long, Theme::plain()),
            StatusLine::info($long, Theme::plain(), AutoWidth::Auto),
        );
    }

    public function testWidthBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatusLine::warn('x', Theme::plain(), 0);
    }
}
