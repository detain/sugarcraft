<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class RestrictTest extends TestCase
{
    private function focused(?string $initial = null): TextInput
    {
        $t = TextInput::new();
        if ($initial !== null) {
            $t = $t->setValue($initial);
        }
        [$t, ] = $t->focus();
        return $t;
    }

    // ---- withRestrict() -------------------------------------------------

    public function testWithRestrictReturnsNewInstance(): void
    {
        $t = TextInput::new();
        $t2 = $t->withRestrict('[a-z]');
        $this->assertNotSame($t, $t2);
        $this->assertSame('[a-z]', $t2->restrict);
    }

    public function testRestrictDefaultIsEmpty(): void
    {
        $t = TextInput::new();
        $this->assertSame('', $t->restrict);
    }

    // ---- Restriction behavior -------------------------------------------

    public function testRestrictAcceptsMatchingCharacters(): void
    {
        $t = $this->focused()->withRestrict('[a-z]');

        // 'a' matches [a-z]
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('a', $t->value);
        $this->assertSame(1, $t->cursorPos);
    }

    public function testRestrictRejectsNonMatchingCharacters(): void
    {
        $t = $this->focused()->withRestrict('[a-z]');

        // '1' does NOT match [a-z]
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '1'));
        $this->assertSame('', $t->value);
        $this->assertSame(0, $t->cursorPos);
    }

    public function testRestrictDigitsOnly(): void
    {
        $t = $this->focused()->withRestrict('[0-9]');

        // Type some digits and non-digits
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '1'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a')); // rejected
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '2'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '#')); // rejected

        $this->assertSame('12', $t->value);
    }

    public function testRestrictAlphanumericOnly(): void
    {
        $t = $this->focused()->withRestrict('[a-zA-Z0-9]');

        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'H'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'e'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '!'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '1'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '0'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '0'));

        $this->assertSame('He100', $t->value);
    }

    public function testRestrictEmptyPatternAcceptsAll(): void
    {
        $t = $this->focused()->withRestrict('');

        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '1'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '#'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, ' '));

        $this->assertSame('a1# ', $t->value);
    }

    public function testRestrictAppliedBeforeCharLimit(): void
    {
        // Even though charLimit is 3, 'abc' (3 chars) gets in but 'd' doesn't
        // because restrict filters before limit check
        $t = $this->focused()->withRestrict('[a-c]')->withCharLimit(3);

        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'c'));
        // 'd' is rejected by restrict before reaching charLimit
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'd'));

        $this->assertSame('abc', $t->value);
    }

    public function testRestrictWithMultibyteCharacters(): void
    {
        $t = $this->focused()->withRestrict('[a-zA-Z]');

        // Multibyte chars should be rejected by [a-zA-Z] since they don't match
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '日'));
        $this->assertSame('', $t->value);
    }

    public function testRestrictDigitsPassesMultibyteThrough(): void
    {
        // If pattern matches only ASCII, multibyte should be rejected
        $t = $this->focused()->withRestrict('[0-9]');

        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '日'));
        $this->assertSame('', $t->value);
    }

    public function testRestrictWithVimMode(): void
    {
        $t = $this->focused()->withRestrict('[a-z]')->withVimMode(true);

        // Enter insert mode first
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'i'));

        // Now type - only lowercase letters should be accepted
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'h'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '1'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'e'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '!'));

        // Only 'he' should be in the buffer
        $this->assertSame('he', $t->value);
    }

    public function testRestrictDoesNotAffectSetValue(): void
    {
        // setValue bypasses restrict - it's a programmatic set, not keystroke
        $t = TextInput::new()->withRestrict('[a-z]');
        $t = $t->setValue('ABC123!');
        $this->assertSame('ABC123!', $t->value);
    }

    public function testRestrictWithBackspace(): void
    {
        $t = $this->focused('abc')->withRestrict('[a-z]');

        // Backspace should still work even when restrict is set
        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('ab', $t->value);
        $this->assertSame(2, $t->cursorPos);
    }

    public function testRestrictWithDelete(): void
    {
        $t = $this->focused('abc')->withRestrict('[a-z]');
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        [$t, ] = $t->update(new KeyMsg(KeyType::Delete));
        $this->assertSame('bc', $t->value);
    }

    public function testRestrictPatternIsAnchoredPerCharacter(): void
    {
        // Pattern [a-z] matches single char against [a-z], not the whole string
        $t = $this->focused()->withRestrict('[a-z]');

        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('a', $t->value);

        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'B')); // uppercase rejected
        $this->assertSame('a', $t->value);
    }
}
