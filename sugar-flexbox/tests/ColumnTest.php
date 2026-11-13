<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Flexbox\Table\{Column, Table};

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
        // finding-045: the old pin fed a FULL CSI and only asserted the
        // literals survived — total sanitizer deletion kept it green, so the
        // test never earned its name. A bare ESC introducer (ESC + final char,
        // no sequence to complete) must have the escape itself stripped:
        // 'hello' + ESC + 'Z' + 'world' → exact 'helloworld', no residue.
        $col = Column::make('Test', 20);
        $result = $col->format("hello\x1bZworld", 0);
        $this->assertStringNotContainsString("\x1b", $result);
        $this->assertSame('helloworld', $result);
    }

    public function testSanitizeStripsFullCsiResidueFree(): void
    {
        // The payload companion of the bare-introducer pin: the CSI's bytes
        // vanish entirely — the SGR parameters do not survive as text.
        $col = Column::make('Test', 20);
        $result = $col->format("hello\x1b[31mworld", 0);
        $this->assertSame('helloworld', $result);
    }

    public function testSanitizeRemovesC0Controls(): void
    {
        $col = Column::make('Test', 20);
        $input = "hel\x00lo\x01\x02world";
        $result = $col->format($input, 0);
        $this->assertStringNotContainsString("\x00", $result);
        $this->assertStringNotContainsString("\x01", $result);
    }

    public function testSanitizeCollapsesTabNewlineAndCarriageReturn(): void
    {
        // A cell contributes ONE visual line: tabs and newlines collapse to
        // spaces (they would forge columns/rows the cell grid never sees)
        // and carriage returns are dropped outright.
        $col = Column::make('Test', 40);
        $this->assertSame('hel lo world', $col->format("hel\tlo\nworld", 0));
        $this->assertSame('a bc', $col->format("a\r\nb\rc", 0), 'CRLF becomes one space; lone CR vanishes');
        $this->assertStringNotContainsString("\r", $col->format("x\ry", 0));
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

    // ---- per-column style reaches the render ----------------------------

    public function testColumnAnsiStyleIsAppliedToDataCellsAtRender(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('N', 5)->withStyle('31'))
            ->addColumn(Column::make('M', 4))
            ->addRow(['ab', 'z']);

        $out = $t->render();

        // The style wraps the FULL padded cell of column N (3 trailing spaces).
        $this->assertStringContainsString("\x1b[31mab   \x1b[0m", $out);
        // Column M (no style) must NOT be wrapped in red.
        $this->assertStringNotContainsString("\x1b[31mz", $out);
    }

    public function testColumnStyleComposesInsideTheCursorStyle(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('N', 5)->withStyle('31'))
            ->addRow(['ab'])
            ->setCursor(0)
            ->withCursorStyle('7');

        // Cursor wraps outside the column style, so its opener lands first.
        $this->assertStringContainsString("\x1b[7m\x1b[31mab   \x1b[0m\x1b[0m", $t->render());
    }

    // ---- Column family hardening (lib-fixes-1103) -------------------------

    public function testNegativeWidthIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('width must be >= 0 cells; got -5');
        Column::new('X', -5);
    }

    public function testZeroWidthColumnRendersEmptyCells(): void
    {
        $col = Column::new('T', 0);
        $this->assertSame('', $col->padded('abc', 0));
    }

    public function testWithAlignRefusesSpellingsOutsideTheVocabulary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Column align must be one of 'left', 'right', 'center'; got 'middle'");
        Column::new('X', 4)->withAlign('middle');
    }

    public function testWithAlignAcceptsTheFullVocabulary(): void
    {
        foreach (['left', 'right', 'center'] as $vocab) {
            $this->assertSame($vocab, Column::new('X', 4)->withAlign($vocab)->align);
        }
    }

    public function testSortedNormalizesDirection(): void
    {
        $this->assertSame(1, Column::new('X', 4)->sorted(5)->sortDir());
        $this->assertSame(-1, Column::new('X', 4)->sorted(-5)->sortDir());
        $this->assertSame(0, Column::new('X', 4)->sorted(0)->sortDir());
    }

    public function testSortedCarriesPriorityWithoutConsumingIt(): void
    {
        // The accessor round-trips the carrier, but Table's sort target is
        // decided solely by sortBy(); the priority never reorders columns.
        $col = Column::new('X', 4)->sorted(1, 7);
        $this->assertSame(7, $col->sortPriority());
        $this->assertSame(1, $col->sortDir());
    }

    public function testNewAndMakeAgree(): void
    {
        $a = Column::new('T', 3);
        $b = Column::make('T', 3);
        $this->assertSame($a->title, $b->title);
        $this->assertSame($a->width, $b->width);
        $this->assertSame('left', $b->align);
    }
}
