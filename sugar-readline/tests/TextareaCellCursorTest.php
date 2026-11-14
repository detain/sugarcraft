<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextareaPrompt;

/**
 * S-24 (finding 026): the textarea caret must treat grapheme clusters as
 * atoms - motions step whole clusters, edits remove whole clusters, and the
 * painted cursor covers the FULL cluster (never half a family emoji).
 *
 * Astral fixtures ride insertPastedText(): handleChar is BMP-guarded by
 * contract (it rejects astral codepoints), which is exactly why the
 * cluster-level paste path is the honest way to build these buffers.
 */
final class TextareaCellCursorTest extends TestCase
{
    private const FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // 5 codepoints, 1 cluster
    private const FLAG = "\u{1F1E9}\u{1F1EA}"; // regional pair: 2 codepoints, 1 cluster

    public function testBackspaceRemovesTheWholeFamilyCluster(): void
    {
        $p = TextareaPrompt::new('e> ')->insertPastedText(self::FAMILY);
        $this->assertSame(5, $p->cursorCol(), 'five codepoints of one cluster');

        $p = $p->handleKey(Key::Backspace);

        $this->assertSame('', $p->value(), 'one Backspace must remove the ENTIRE cluster - no dangling joiners');
        $this->assertSame(0, $p->cursorCol());
    }

    public function testDeleteForwardRemovesTheWholeCluster(): void
    {
        $p = TextareaPrompt::new('e> ')
            ->insertPastedText(self::FAMILY)
            ->handleKey(Key::Left); // cluster motion: col 5 -> 0 in one step
        $this->assertSame(0, $p->cursorCol(), 'Left steps the whole cluster');

        $p = $p->handleKey(Key::Delete);

        $this->assertSame('', $p->value(), 'Delete consumes the whole cluster ahead');
    }

    public function testFlagPairAndAsciiStepAsSeparateClusters(): void
    {
        $p = TextareaPrompt::new('e> ')
            ->insertPastedText(self::FLAG)
            ->handleChar('x');
        $this->assertSame(3, $p->cursorCol(), 'flag pair (2 cps) + x (1 cp)');

        $p = $p->handleKey(Key::Left);
        $this->assertSame(2, $p->cursorCol(), 'x steps back whole');

        $p = $p->handleKey(Key::Left);
        $this->assertSame(0, $p->cursorCol(), 'the flag pair is ONE cluster - never lands mid-flag');

        $p = $p->handleKey(Key::Right);
        $this->assertSame(2, $p->cursorCol(), 'forward skips the pair whole');

        $p = $p->handleKey(Key::Right);
        $this->assertSame(3, $p->cursorCol());
    }

    public function testWideGlyphIsPaintedWholeUnderTheCaret(): void
    {
        $p = TextareaPrompt::new('> ')
            ->insertPastedText('日本語')
            ->handleKey(Key::Left); // col 3 -> 2, caret over 本

        $view = $p->view();

        $this->assertMatchesRegularExpression(
            '/\x1b\[7m語\x1b\[0m/u',
            $view,
            'the styled cursor block must cover the full 2-cell glyph'
        );
        $this->assertStringNotContainsString("\u{FFFD}", $view, 'no replacement-char fragments in the frame');
    }

    public function testAsciiMotionAndEditingAreUnchanged(): void
    {
        $p = TextareaPrompt::new('e> ')->insertPastedText('abc');

        $p = $p->handleKey(Key::Left);
        $this->assertSame(2, $p->cursorCol(), 'ASCII still steps one codepoint');

        $p = $p->handleKey(Key::Backspace);
        $this->assertSame('ac', $p->value(), 'one codepoint deleted at the caret');
        $this->assertSame(1, $p->cursorCol());
    }
}
