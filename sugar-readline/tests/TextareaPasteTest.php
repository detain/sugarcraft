<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextareaPrompt;

/**
 * S-14 (findings 011 + 015): pasted TAB must survive on the textarea
 * (the README promised it on both surfaces while the code replayed the
 * chunk through the key gate), and the cleaned chunk must splice into the
 * line in ONE immutable clone — the per-character replay paid an O(n²)
 * lines-array copy on large clips.
 */
final class TextareaPasteTest extends TestCase
{
    public function testPastedTabSurvives(): void
    {
        $p = TextareaPrompt::new('code> ')->insertPastedText("def\tfoo();");

        $this->assertStringContainsString("\t", $p->value(), 'the pasted TAB must be content, not a casualty of the key gate');
        $this->assertSame('def' . "\t" . 'foo();', $p->value());
        $this->assertSame(0, $p->cursorLine());
        $this->assertSame(10, $p->cursorCol(), 'col advances by the full spliced run');
    }

    public function testPastedControlBytesAreStillStripped(): void
    {
        $p = TextareaPrompt::new('> ')->insertPastedText("a\x07b\x00c\x1bd");

        $this->assertSame('abcd', $p->value(), 'only TAB (and line-split LF) survives the cleaner');
    }

    public function testMultiLinePasteSplitsIntoLines(): void
    {
        $p = TextareaPrompt::new('> ')->insertPastedText("alpha\nbeta\tomega\ngamma");

        $this->assertSame(['alpha', 'beta' . "\t" . 'omega', 'gamma'], $p->lines());
        $this->assertSame("alpha\nbeta\tomega\ngamma", $p->value());
        $this->assertSame(2, $p->cursorLine());
        $this->assertSame(5, $p->cursorCol());
    }

    public function testSpliceAtMidLineKeepsTheTail(): void
    {
        $p = TextareaPrompt::new('> ')
            ->handleChar('a')
            ->handleChar('b')
            ->handleKey(Key::Left)
            ->handleKey(Key::Left)
            ->insertPastedText("X\tY");

        $this->assertSame('X' . "\t" . 'Yab', $p->lines()[0], 'the splice lands at the cursor, tail intact');
        $this->assertSame(3, $p->cursorCol());
    }

    public function testTypedTabThroughTheKeyGateIsUnchanged(): void
    {
        // The key gate stays shut: making paste-batch must not widen
        // handleChar into a control-byte channel.
        $p = TextareaPrompt::new('> ')->handleChar("\t");

        $this->assertSame('', $p->value(), 'direct handleChar still rejects control bytes; the batch lives above the gate');
    }

    /**
     * Cost pin without wall-clock: the method must be a single splice.
     * A re-introduced per-character replay (the ~n² finding-015 shape) is
     * structurally visible — one clone per chunk, no handleChar call.
     */
    public function testAppendLiteralIsASingleSpliceNotAPerCharReplay(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/TextareaPrompt.php');
        self::assertIsString($src);

        $start = strpos($src, 'private function appendLiteral');
        self::assertNotFalse($start, 'appendLiteral seam must exist');
        $body = substr($src, $start, strpos($src, "\n    }", $start) - $start);

        $this->assertStringNotContainsString('handleChar', $body, 'appendLiteral must not replay the run through the per-char key gate');
        $this->assertSame(1, substr_count($body, 'clone $this'), 'exactly one immutable clone per chunk');
        $this->assertStringNotContainsString('mb_str_split', $body, 'no per-character split on the paste path');
    }
}
