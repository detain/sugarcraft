<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\TextInput\TextInput;

/**
 * charLimit is a budget the interactive insert path must clip to, not a
 * pre-check gate: a paste arriving below the cap used to append its whole
 * length (limit-1 + N), defeating the paste-DoS guard new() advertises.
 */
final class TextInputCharLimitPasteTest extends TestCase
{
    public function testPasteIsClippedToTheRemainingBudget(): void
    {
        $t = TextInput::new()->withCharLimit(5)->setValue('abcd')->paste('XYZ12');
        $this->assertSame('abcdX', $t->value);
        $this->assertSame(5, $t->length());
        $this->assertSame(5, $t->cursorPos);
    }

    public function testPasteMidBufferKeepsTheTailAndClipsThePayload(): void
    {
        $t = TextInput::new()->withCharLimit(6)->setValue('abcd')->setCursor(2)->paste('XYZ');
        $this->assertSame('abXYcd', $t->value);
        $this->assertSame(4, $t->cursorPos);
    }

    public function testPasteAtTheLimitIsANoOp(): void
    {
        $t = TextInput::new()->withCharLimit(3)->setValue('abc');
        $this->assertSame($t, $t->paste('more'));
    }

    public function testPasteClipsByCodepointNotByte(): void
    {
        $t = TextInput::new()->withCharLimit(3)->setValue('a')->paste('日本語');
        $this->assertSame('a日本', $t->value);
        $this->assertSame(3, $t->length());
    }

    public function testDefaultLimitHoldsAgainstAHugePaste(): void
    {
        $t = TextInput::new()->setValue(str_repeat('a', 4095))->paste(str_repeat('b', 100000));
        $this->assertSame(4096, $t->length());
    }

    public function testZeroLimitStillPastesEverything(): void
    {
        $t = TextInput::new()->withCharLimit(0)->paste(str_repeat('b', 10000));
        $this->assertSame(10000, $t->length());
    }
}
