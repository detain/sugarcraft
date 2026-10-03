<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextArea;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * The caret row must stay inside the rendered window. rowOffset used to be
 * written only by reset(), so once the cursor walked past `height` rows
 * view() sliced the caret off-screen and the user typed blind.
 */
final class TextAreaScrollTest extends TestCase
{
    private function area(int $height, string $value = ''): TextArea
    {
        $t = TextArea::new()->withHeight($height);
        if ($value !== '') {
            $t = $t->setValue($value);
        }
        [$t] = $t->focus();
        return $t;
    }

    public function testEnterPastTheBottomPansTheWindow(): void
    {
        $t = $this->area(2);
        foreach (['a', 'b', 'c'] as $i => $ch) {
            if ($i > 0) {
                [$t] = $t->update(new KeyMsg(KeyType::Enter));
            }
            [$t] = $t->update(new KeyMsg(KeyType::Char, $ch));
        }
        $this->assertSame(2, $t->line());
        $this->assertSame(1, $t->getRowOffset());
        $rows = explode("\n", $t->view());
        $this->assertCount(2, $rows);
        $this->assertSame('b', $rows[0]);
        $this->assertStringStartsWith('c', $rows[1], 'caret row is the bottom visible row');
    }

    public function testPasteAndSetValueLandTheCaretInView(): void
    {
        $t = $this->area(2)->insertString("l1\nl2\nl3\nl4");
        $this->assertSame(3, $t->line());
        $this->assertSame(2, $t->getRowOffset());

        $s = TextArea::new()->withHeight(3)->setValue("1\n2\n3\n4\n5");
        $this->assertSame(2, $s->getRowOffset());
    }

    public function testArrowUpPastTheTopPansBack(): void
    {
        $t = $this->area(2, "1\n2\n3\n4");
        $this->assertSame(2, $t->getRowOffset());
        [$t] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame(2, $t->getRowOffset(), 'row 2 is still inside rows 2..3');
        [$t] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame(1, $t->line());
        $this->assertSame(1, $t->getRowOffset());
        $this->assertSame(0, $t->moveToBegin()->getRowOffset());
        $this->assertSame(2, $t->moveToEnd()->getRowOffset());
    }

    public function testBackspaceLineMergePansBackAndClampsAtTheEnd(): void
    {
        $t = $this->area(2, "1\n2\n3");
        $this->assertSame(1, $t->getRowOffset());
        // Merge row 2 into row 1: only 2 lines remain, which fit the window.
        $t = $t->setCursor(2, 0);
        [$t] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame(1, $t->line());
        $this->assertSame(0, $t->getRowOffset(), 'window must not scroll past the last line');
    }

    public function testHeightChangeRepansAndUnboundedHeightResets(): void
    {
        $t = TextArea::new()->setValue("1\n2\n3\n4\n5");
        $this->assertSame(0, $t->getRowOffset(), 'height 0 renders every row');
        $t = $t->withHeight(2);
        $this->assertSame(3, $t->getRowOffset());
        $this->assertSame(0, $t->withHeight(0)->getRowOffset());
    }

    public function testDynamicHeightCapsAtMaxHeight(): void
    {
        $t = TextArea::new()->withDynamic(true)->withMaxHeight(2)->setValue("1\n2\n3");
        $this->assertSame(2, $t->effectiveHeight());
        $this->assertSame(1, $t->getRowOffset());
    }

    public function testResetReturnsToTheTop(): void
    {
        $t = $this->area(2, "1\n2\n3\n4")->reset();
        $this->assertSame(0, $t->getRowOffset());
    }
}
