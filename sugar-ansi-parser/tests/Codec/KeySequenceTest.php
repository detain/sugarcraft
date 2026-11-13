<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests\Codec;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Codec\KeySequence;

final class KeySequenceTest extends TestCase
{
    public function testNamedKeysEncodeToWireBytes(): void
    {
        self::assertSame("\r", KeySequence::encode('Enter'));
        self::assertSame("\x1b", KeySequence::encode('Esc'));
        self::assertSame("\x1b[C", KeySequence::encode('Right'));
        self::assertSame("\x1b[6~", KeySequence::encode('PageDown'));
        self::assertSame("\x7f", KeySequence::encode('Backspace'));
    }

    public function testControlKeysCoverAToZ(): void
    {
        foreach (range('a', 'z') as $i => $c) {
            self::assertSame(chr($i + 1), KeySequence::encode("C-$c"));
        }
    }

    public function testUnknownAndWrongCaseReturnNull(): void
    {
        self::assertNull(KeySequence::encode('enter'));
        self::assertNull(KeySequence::encode('Nope'));
        self::assertNull(KeySequence::encode(''));
    }

    public function testNamesListsEveryTableKey(): void
    {
        self::assertCount(40, KeySequence::names());
        self::assertContains('Insert', KeySequence::names());
    }
}
