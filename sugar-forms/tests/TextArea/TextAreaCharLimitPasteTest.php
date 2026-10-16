<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextArea;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * charLimit budget on the TextArea insert paths. totalLength() — what the
 * guard measures — counts each line break as one unit, so setValue(), the
 * single-segment insert and the multi-line paste all share that accounting.
 */
final class TextAreaCharLimitPasteTest extends TestCase
{
    public function testInsertStringIsClippedToTheRemainingBudget(): void
    {
        $t = TextArea::new()->withCharLimit(5)->setValue('abcd')->insertString('XYZ12');
        $this->assertSame('abcdX', $t->value());
        $this->assertSame(5, $t->lineInfo()['totalChars']);
    }

    public function testMultiLinePasteNeverExceedsTheLimit(): void
    {
        $t = TextArea::new()->withCharLimit(8);
        [$t] = $t->focus();
        [$t] = $t->update(new PasteMsg("one\ntwo\nthree"));
        // "one" (3) + "\n" (1) + "two" (3) + "\n" (1) = 8 — "three" has no room.
        $this->assertSame("one\ntwo\n", $t->value());
        $this->assertLessThanOrEqual(8, $t->lineInfo()['totalChars']);
    }

    public function testTabNearTheLimitInsertsOnlyTheRoomLeft(): void
    {
        $t = TextArea::new()->withCharLimit(3)->setValue('ab');
        [$t] = $t->focus();
        [$t] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('ab ', $t->value());
    }

    public function testDefaultLimitHoldsAgainstAHugePaste(): void
    {
        $t = TextArea::new()->setValue(str_repeat('a', 65535))->insertString(str_repeat('b', 100000));
        $this->assertSame(65536, $t->lineInfo()['totalChars']);
    }

    public function testSetValueCountsLineBreaksAgainstTheLimit(): void
    {
        $t = TextArea::new()->withCharLimit(4)->setValue("ab\ncd");
        $this->assertSame("ab\nc", $t->value());
        $this->assertSame(4, $t->lineInfo()['totalChars']);

        $exact = TextArea::new()->withCharLimit(5)->setValue("hello\nworld");
        $this->assertSame('hello', $exact->value(), 'no room left for the line break');
    }
}
