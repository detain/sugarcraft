<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextInput\TextInput;
use PHPUnit\Framework\TestCase;

/**
 * SF-6 (sugar-forms-003): cursor positions are grapheme-cluster quantized
 * and the width scroll window is measured in terminal cells. ZWJ family
 * emoji are ONE position costing TWO cells; combining marks leave with
 * their base on a single backspace; the reverse-video cursor never paints
 * half a cluster; plain ASCII keeps the byte shape the old codepoint math
 * produced.
 */
final class TextInputCellMathTest extends TestCase
{
    private const FAM  = "\u{1F469}\u{200D}\u{1F4BB}"; // woman + ZWJ + laptop: 3 codepoints, 1 cluster, 2 cells
    private const EACUTE = "e\u{0301}";               // base + combining acute: 2 codepoints, 1 cluster, 1 cell

    private function focused(string $initial = '', int $width = 0): TextInput
    {
        $t = TextInput::new()->withPrompt('')->setValue($initial);
        if ($width > 0) {
            $t = $t->withWidth($width);
        }
        [$t, ] = $t->focus();
        return $t;
    }

    // ---- deletions consume whole clusters ------------------------------

    public function testBackspaceRemovesWholeCombiningCluster(): void
    {
        $t = TextInput::new();
        [$t, ] = $t->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'e'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, "\u{0301}"));
        $this->assertSame(self::EACUTE, $t->value);
        $this->assertSame(2, $t->cursorPos);

        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        // Old math left the orphaned combining mark behind: value "\u{0301}".
        $this->assertSame('', $t->value);
        $this->assertSame(0, $t->cursorPos);
    }

    public function testBackspaceRemovesZwjFamilyInOnePress(): void
    {
        $t = $this->focused(self::FAM);
        $t = $t->cursorEnd();
        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('', $t->value);
        $this->assertSame(0, $t->cursorPos);
    }

    public function testDeleteRemovesWholeClusterForward(): void
    {
        $t = $this->focused(self::FAM . 'x');
        $t = $t->setCursor(0);
        [$t, ] = $t->update(new KeyMsg(KeyType::Delete));
        $this->assertSame('x', $t->value);
        $this->assertSame(0, $t->cursorPos);
    }

    public function testBackspaceMidClusterTakesTheWholeRunNotHalf(): void
    {
        // Only reachable via a clipped paste: the buffer holds fam+'ab' and
        // the cursor sits at a cluster start (boundary 3).
        $t = $this->focused(self::FAM . 'ab');
        $t = $t->setCursor(3);
        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('ab', $t->value);
        $this->assertSame(0, $t->cursorPos);
    }

    public function testVimDeleteCharTakesWholeCluster(): void
    {
        $t = TextInput::new()->setValue(self::FAM . 'b')->withVimMode(true);
        [$t, ] = $t->focus();
        $t = $t->setCursor(0);
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('b', $t->value);
    }

    // ---- arrows cross whole clusters -------------------------------------

    public function testArrowKeysCrossWholeClusters(): void
    {
        $t = $this->focused('a' . self::FAM . 'b');
        $t = $t->cursorEnd();
        $this->assertSame(5, $t->cursorPos);

        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        $this->assertSame(4, $t->cursorPos); // start of 'b'
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        $this->assertSame(1, $t->cursorPos); // start of the family — never 3 or 2
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        $this->assertSame(4, $t->cursorPos);
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        $this->assertSame(5, $t->cursorPos);
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        $this->assertSame(5, $t->cursorPos);
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        $this->assertSame(0, $t->cursorPos);
    }

    public function testSetCursorSnapsDownToClusterStart(): void
    {
        $t = $this->focused('a' . self::FAM);
        $this->assertSame(1, $t->setCursor(2)->cursorPos);
        $this->assertSame(1, $t->setCursor(3)->cursorPos);
        $this->assertSame(4, $t->setCursor(4)->cursorPos);
    }

    // ---- window measured in cells ----------------------------------------

    public function testWideWindowCountsCellsNotCodepoints(): void
    {
        // width 4 cells holds two CJK glyphs, not four.
        $t = $this->focused('日本語', width: 4)->cursorEnd();
        $this->assertSame("語\x1b[7m \x1b[0m", $t->view());
    }

    public function testCursorSitsOnWholeWideCluster(): void
    {
        $t = $this->focused('日本語の', width: 4)->setCursor(3);
        // '語' + reverse-video 'の' == exactly 4 cells; '本' scrolled out.
        $this->assertSame("語\x1b[7mの\x1b[0m", $t->view());
    }

    public function testMixedWidthWindowFitsBudget(): void
    {
        $t = $this->focused('日a本b語', width: 3)->cursorEnd();
        $this->assertSame("語\x1b[7m \x1b[0m", $t->view());

        $t = $this->focused('日a本b語', width: 3)->setCursor(4);
        $this->assertSame("b\x1b[7m語\x1b[0m", $t->view());
    }

    public function testWindowNeverSplitsCluster(): void
    {
        $t = $this->focused(self::EACUTE . 'x', width: 2)->setCursor(2);
        $this->assertSame("e\u{0301}\x1b[7mx\x1b[0m", $t->view());

        $t = $this->focused(self::EACUTE . 'x', width: 2)->setCursor(0);
        $this->assertSame("\x1b[7me\u{0301}\x1b[0mx", $t->view());
    }

    public function testEmojisInWindowCostTwoCellsEach(): void
    {
        $t = $this->focused('a' . self::FAM . self::FAM, width: 3)->setCursor(1);
        // cursor run (2 cells) + room for the single 'a' before it.
        $this->assertSame("a\x1b[7m" . self::FAM . "\x1b[0m", $t->view());
    }

    // ---- ASCII byte shape is untouched ------------------------------------

    public function testAsciiWindowKeepsLegacyByteShape(): void
    {
        $t = $this->focused('hello world', width: 5)->cursorEnd();
        $this->assertSame("orld\x1b[7m \x1b[0m", $t->view());

        $t = $this->focused('hello world', width: 5)->setCursor(2);
        $this->assertSame("he\x1b[7ml\x1b[0mlo", $t->view());
    }

    public function testUnfocusedWideWindowClipsToCells(): void
    {
        $t = TextInput::new()->withPrompt('')->setValue('日本語')->withWidth(4);
        $this->assertSame('語', $t->blur()->view()); // same window math as focused; the cursor slot paints nothing
    }

    public function testSetValueCursorLandsOnClusterEnd(): void
    {
        $t = $this->focused();
        $t = $t->setValue(self::FAM);
        $this->assertSame(3, $t->cursorPos);
        $this->assertSame(3, $t->length());
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        $this->assertSame(0, $t->cursorPos);
    }
}
