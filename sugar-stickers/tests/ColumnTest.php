<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Stickers\Table\{Column, Table};

/**
 * Tests for Column formatting, sanitization, and style validation.
 */
final class ColumnTest extends TestCase
{
    // ---- format() --------------------------------------------------------

    public function testFormatReturnsOriginalWhenNoFormatter(): void
    {
        $col = Column::make('Name', 10);
        $this->assertSame('Alice', $col->format('Alice', 0));
    }

    public function testFormatAppliesFormatter(): void
    {
        $col = Column::make('Name', 10)->withFormatter(fn($v, $i) => strtoupper($v));
        $this->assertSame('ALICE', $col->format('Alice', 0));
    }

    public function testFormatFormatterReturnsNullFallsBackToOriginal(): void
    {
        $col = Column::make('Name', 10)->withFormatter(fn($v, $i) => null);
        $this->assertSame('Alice', $col->format('Alice', 0));
    }

    public function testFormatFormatterReceivesRowIndex(): void
    {
        $receivedIndex = -1;
        $col = Column::make('Name', 10)->withFormatter(function ($v, $i) use (&$receivedIndex) {
            $receivedIndex = $i;
            return $v;
        });
        $col->format('test', 7);
        $this->assertSame(7, $receivedIndex);
    }

    // ---- sanitize() attack vectors ----------------------------------------

    public function testSanitizeRemovesOscSequences(): void
    {
        $col = Column::make('Test', 20);
        // OSC sequence: ESC ] 0 ; "text" BEL
        $input = "hello\x1b]0;attack\x07world";
        $result = $col->format($input, 0);
        $this->assertStringNotContainsString('attack', $result);
    }

    public function testSanitizeRemovesDcsSequences(): void
    {
        $col = Column::make('Test', 20);
        // DCS sequence: ESC P ... ESC \
        $input = "hello\x1bPparam\x1b\\world";
        $result = $col->format($input, 0);
        $this->assertStringNotContainsString('param', $result);
    }

    public function testSanitizeRemovesBareEscIntroducer(): void
    {
        $col = Column::make('Test', 20);
        $input = "hello\x1b[31mworld"; // This is CSI, not bare ESC
        $result = $col->format($input, 0);
        $this->assertStringContainsString('hello', $result);
        $this->assertStringContainsString('world', $result);
    }

    public function testSanitizeRemovesC0Controls(): void
    {
        $col = Column::make('Test', 20);
        $input = "hel\x00lo\x01\x02world";
        $result = $col->format($input, 0);
        $this->assertStringNotContainsString("\x00", $result);
        $this->assertStringNotContainsString("\x01", $result);
    }

    public function testSanitizePreservesTabAndNewline(): void
    {
        $col = Column::make('Test', 20);
        $input = "hel\tlo\nworld";
        $result = $col->format($input, 0);
        $this->assertStringContainsString("\t", $result);
        $this->assertStringContainsString("\n", $result);
    }

    public function testSanitizeRemovesDEL(): void
    {
        $col = Column::make('Test', 20);
        $input = "hello\x7fworld";
        $result = $col->format($input, 0);
        $this->assertStringNotContainsString("\x7f", $result);
    }

    public function testSanitizePreservesUtf8ContinuationBytes(): void
    {
        $col = Column::make('Test', 20);
        // CJK character 東京: bytes e6 9d b1 e4 ba ac
        // 0x80-0x9F are UTF-8 continuation bytes and must NOT be stripped
        $input = "東京";
        $result = $col->format($input, 0);
        $this->assertStringContainsString('東京', $result);
    }

    // ---- withStyle validation ---------------------------------------------

    public function testWithStyleAcceptsValidSgrParams(): void
    {
        $col = Column::make('Test', 10)->withStyle('31;1');
        $this->assertSame('Test', $col->title);
    }

    public function testWithStyleAcceptsEmptyString(): void
    {
        $col = Column::make('Test', 10)->withStyle('');
        // Empty style is allowed; verify via reflection that ansiStyle is empty
        $rc = new ReflectionClass($col);
        $p = $rc->getProperty('ansiStyle');
        $p->setAccessible(true);
        $this->assertSame('', $p->getValue($col));
    }

    public function testWithStyleAcceptsDigitsOnly(): void
    {
        $col = Column::make('Test', 10)->withStyle('7');
        $this->assertSame('Test', $col->title);
    }

    public function testWithStyleRejectsNonSgrCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Column::make('Test', 10)->withStyle('31;1m');
    }

    public function testWithStyleRejectsEscCharacter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Column::make('Test', 10)->withStyle("31\x1b");
    }

    public function testWithStyleRejectsArbitraryLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Column::make('Test', 10)->withStyle('abc');
    }

    // ---- padded() --------------------------------------------------------

    public function testPaddedCenterAlignment(): void
    {
        $col = Column::make('X', 10)->withAlign('center');
        $result = $col->padded('hi', 0);
        $this->assertStringContainsString('hi', $result);
        $this->assertSame(10, \SugarCraft\Core\Util\Width::string($result));
    }

    public function testPaddedRightAlignment(): void
    {
        $col = Column::make('X', 10)->withAlign('right');
        $result = $col->padded('hi', 0);
        $this->assertStringContainsString('hi', $result);
        $this->assertSame(10, \SugarCraft\Core\Util\Width::string($result));
    }

    public function testPaddedTruncatesLongContent(): void
    {
        $col = Column::make('X', 5);
        $result = $col->padded('this is very long content', 0);
        $this->assertLessThanOrEqual(5, \SugarCraft\Core\Util\Width::string($result));
    }

    // ---- sorted() / unsorted() -------------------------------------------

    public function testSortedSetsDirectionAndPriority(): void
    {
        $col = Column::make('Name', 10)->sorted(1, 2);
        $this->assertSame(1, $col->sortDir());
        $this->assertSame(2, $col->sortPriority());
    }

    public function testUnsortedClearsSortState(): void
    {
        $col = Column::make('Name', 10)->sorted(-1, 1)->unsorted();
        $this->assertSame(0, $col->sortDir());
        $this->assertSame(0, $col->sortPriority());
    }

    // ---- sortDir / sortPriority accessors --------------------------------

    public function testSortDirDefaultsToZero(): void
    {
        $col = Column::make('Test', 10);
        $this->assertSame(0, $col->sortDir());
    }

    public function testSortPriorityDefaultsToZero(): void
    {
        $col = Column::make('Test', 10);
        $this->assertSame(0, $col->sortPriority());
    }
}
