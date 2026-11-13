<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Flexbox\Table\{Column, Table};

/**
 * Tests for Table rendering, sorting, filtering, and edge cases.
 */
final class TableTest extends TestCase
{
    // ---- computeTotalWidth -----------------------------------------------

    public function testComputeTotalWidthWithNoColumns(): void
    {
        $t = new Table();
        // Zero columns: the (n - 1) joint term used to make this NEGATIVE.
        $this->assertSame(0, $t->computeTotalWidth());
    }

    public function testComputeTotalWidthWithColumns(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 8));
        // 10 + 8 + separator (3 CELLS for ' │ ' * 1) = 21. The old byte
        // count said 23 — ' │ ' is 5 bytes but occupies 3 display cells.
        $this->assertSame(21, $t->computeTotalWidth());
    }

    public function testComputeTotalWidthWithSeparator(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('A', 5))
            ->withSeparator(' | ')
            ->addColumn(Column::make('B', 5));
        // 5 + 5 + 3 (separator) = 13
        $this->assertSame(13, $t->computeTotalWidth());
    }

    // ---- padHeader alignment --------------------------------------------

    public function testPadHeaderCenterAlignment(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('X', 10)->withAlign('center'));
        // Use reflection to access private padHeader
        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('padHeader');
        $method->setAccessible(true);

        $result = $method->invoke($t, 'Hi', 10, 'center');
        $this->assertSame(10, \strlen($result));
        $this->assertStringContainsString('Hi', $result);
    }

    public function testPadHeaderRightAlignment(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('X', 10)->withAlign('right'));
        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('padHeader');
        $method->setAccessible(true);

        $result = $method->invoke($t, 'Hi', 10, 'right');
        $this->assertSame(10, \strlen($result));
        $this->assertStringStartsWith(' ', $result);
        $this->assertStringEndsWith('Hi', $result); // '   Hi' padded on left
    }

    public function testPadHeaderTruncatesOversizedHeader(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('X', 5));
        $reflection = new \ReflectionClass($t);
        $method = $reflection->getMethod('padHeader');
        $method->setAccessible(true);

        $result = $method->invoke($t, 'HelloWorld', 5, 'left');
        // finding-043: the old leg asserted an INEQUALITY on strlen; the
        // contract is an exact cell landing (truncate on a width boundary,
        // then pad to the requested budget).
        $this->assertSame(5, \SugarCraft\Core\Util\Width::string($result));
    }

    public function testPadHeaderWideTitlesLandOnExactCellWidth(): void
    {
        // finding-043: every padHeader pin so far was ASCII, where strlen and
        // cell-count agree by accident — a wide-title regression (double-width
        // glyphs measured as bytes, or truncated mid-grapheme) sailed through.
        // CJK and emoji titles must land on EXACTLY $width measured cells, in
        // equality form, for all three alignments.
        $t = (new Table())
            ->addColumn(Column::make('X', 10));
        $method = (new \ReflectionClass($t))->getMethod('padHeader');
        $method->setAccessible(true);

        $cases = [
            ['東京東京東京', 5, 'left', '東京 '],
            ['東京東京東京', 5, 'center', '東京 '],
            ['🙂🙂🙂🙂', 5, 'right', ' 🙂🙂'],
            ['日本語テスト', 12, 'center', '日本語テスト'],
            ['ÄrgerÜ', 6, 'left', 'ÄrgerÜ'],
        ];
        foreach ($cases as [$title, $width, $align, $expected]) {
            $result = $method->invoke($t, $title, $width, $align);
            $this->assertSame(
                $expected,
                $result,
                \sprintf('padHeader(%s, %d, %s) must land on exactly %d cells', $title, $width, $align, $width)
            );
            $this->assertSame(
                $width,
                \SugarCraft\Core\Util\Width::string($result),
                \sprintf('padHeader(%s, %d, %s) measured-cell equality', $title, $width, $align, $width)
            );
            // No mid-grapheme split: byte-based truncation would leave a
            // dangling continuation byte that the cell measurement above
            // happily papers over with padding — UTF-8 validity cannot.
            $this->assertSame(
                1,
                preg_match('//u', $result),
                \sprintf('padHeader(%s, %d, %s) must not split a grapheme', $title, $width, $align)
            );
        }
    }

    // ---- withSeparator ----------------------------------------------------

    public function testWithSeparatorChangesSeparator(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5))
            ->addRow(['x', 'y'])
            ->withSeparator(' | ');

        $output = $t->render();
        $this->assertStringContainsString(' | ', $output);
    }

    // ---- sortBy -----------------------------------------------------------

    public function testSortByNumericColumn(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Num', 5))
            ->addRow(['10'])
            ->addRow(['2'])
            ->addRow(['100'])
            ->sortBy(0, true);

        $output = $t->render();
        $lines = explode("\n", $output);
        // Numeric ascending: 2, 10, 100. First data row is '2' padded to 5 chars
        // padRight pads on the right, so '2' becomes '2    ' (5 chars)
        $this->assertStringStartsWith('2', trim($lines[2] ?? ''));
    }

    public function testSortByDescending(): void
    {
        // Position pin (finding-007): the old contains-'3' assertion held
        // under EVERY permutation, so a full comparator inversion stayed
        // green. Descending must reverse the whole visible order, row by
        // row — assert each row's actual key.
        $t = Table::new()
            ->withColumn(Column::new('Num', 5))
            ->withRow(['1'])
            ->withRow(['2'])
            ->withRow(['3'])
            ->withSort(0, false); // descending

        $this->assertSame(['3', '2', '1'], $this->dataColumn($t));
    }

    public function testSortCarriesTheSelectionThroughTheFlip(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['A'])
            ->addRow(['B'])
            ->setCursor(1)
            ->sortBy(0, false); // descending: 'B' moves to the top

        $this->assertSame(0, $t->cursor());
        $this->assertSame(['B'], $t->currentRow());
    }

    // ---- filter -----------------------------------------------------------

    public function testFilterCaseInsensitive(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['BOB'])
            ->addRow(['Charlie'])
            ->filter('alice');

        $this->assertSame(1, $t->rowCount());
    }

    public function testFilterFoldsTheGermanCasePair(): void
    {
        // Finding-033 in a 12-locale ecosystem: 'Ärger' stored, 'ärger'
        // typed. mbstring's full case fold answers the pair with or
        // without ext-intl (intl's accent strip keeps the match, just
        // via a de-accented key).
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Ärger'])
            ->addRow(['Zoo'])
            ->filter('ärger');
        $this->assertSame(1, $t->rowCount());

        $u = $t->withFilter('ÄRGER');
        $this->assertSame(1, $u->rowCount(), 'fold is symmetric across stored/needle casing');
    }

    public function testFilterFoldsSharpSAgainstDoubleS(): void
    {
        // FULL (not simple) case folding: 'Straße' must answer to the
        // typed 'strasse' — the ß↔ss expansion no simple lowercase map
        // ever performs.
        $t = (new Table())
            ->addColumn(Column::make('City', 12))
            ->addRow(['Straße'])
            ->addRow(['Berlin'])
            ->filter('strasse');
        $this->assertSame(1, $t->rowCount());
    }

    public function testSortPlacesAccentedWordsWithTheirBaseLetter(): void
    {
        if (!\extension_loaded('intl')) {
            $this->markTestSkipped('accent folding (NFKD mark-stripping) requires ext-intl');
        }
        // Raw byte placement parks every 'Ä' word after ALL ASCII ones;
        // the fold sorts 'Ärger' as 'arger' — with the A's (between
        // 'Apfel' and 'Zoo'), not at the end of the alphabet.
        $t = Table::new()
            ->addColumn(Column::new('Name', 8))
            ->addRow(['Zoo'])
            ->addRow(['Ärger'])
            ->addRow(['Apfel'])
            ->sortBy(0, true);
        $this->assertSame(['Apfel', 'Ärger', 'Zoo'], $this->dataColumn($t));
    }

    public function testFilterNoMatch(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->filter('XYZ');

        $this->assertSame(0, $t->rowCount());
    }

    public function testClearFilterRestoresAllRows(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->filter('Alice')
            ->clearFilter();

        $this->assertSame(2, $t->rowCount());
    }

    public function testFilterKeepsSelectionWhenItsRowSurvives(): void
    {
        // Selection-follow law: the highlighted record stays highlighted.
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->setCursor(0)
            ->filter('lice');

        $this->assertSame(0, $t->cursor());
        $this->assertSame(['Alice'], $t->currentRow());
    }

    public function testFilterFallsBackToRowZeroWhenSelectionIsFilteredAway(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['A'])
            ->addRow(['B'])
            ->setCursor(1)
            ->filter('A');

        $this->assertSame(0, $t->cursor());
        $this->assertSame(['A'], $t->currentRow());
    }

    // ---- withRow arity ---------------------------------------------------

    public function testWithRowRejectsSurplusValues(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('got 3 value(s) but the table has 2 column(s) (Name, City)');
        $t->withRow(['Alice', 'NYC', 'ghost']);
    }

    public function testSurplusValueNeverFeedsTheFilter(): void
    {
        // The hazard FBX-19 closes: an invisible third cell used to make
        // the row match 'secret' although nothing on screen said so.
        $this->expectException(\InvalidArgumentException::class);
        (new Table())
            ->addColumn(Column::make('Name', 10))
            ->withRow(['Alice', 'secret'])
            ->filter('secret');
    }

    public function testWithRowRejectsValuesBeforeAnyColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no columns yet');
        (new Table())->withRow(['Alice']);
    }

    public function testWithRowAllowsShortRows(): void
    {
        // Fewer values than columns stays legal: the stored row is exactly
        // what was given, and the RENDER pads the missing cell blank.
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->withRow(['Alice']);

        $this->assertSame(['Alice'], $t->currentRow());

        $lines = $t->buildLines();
        $this->assertSame(
            'Alice     ' . ' │ ' . '          ', // 10 cells + joint + 10 blank cells
            $lines[2],
            'the missing City cell must render as an empty 10-cell column'
        );
    }

    // ---- currentRow / currentCell ----------------------------------------

    public function testCursorAnswersNullOnlyForAnEmptyView(): void
    {
        // Out-of-range indexes can no longer exist (the door refuses
        // them), so null is reserved for the honest case: a view with no
        // rows at all, holding the default index 0.
        $t = (new Table())->addColumn(Column::make('Name', 10));

        $this->assertNull($t->cursor());
        $this->assertNull($t->currentRow());
    }

    public function testCursorDoorRefusesAnIndexPastTheEnd(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for a settled view of 1 row(s)');
        $t->setCursor(99);
    }

    public function testCursorDoorRefusesNegativeIndexes(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        $this->expectException(\InvalidArgumentException::class);
        $t->withCursor(-1);
    }

    public function testCursorDoorChecksTheSettledViewNotTheStaleInput(): void
    {
        // A pending filter that would drop every row leaves NOTHING the
        // cursor may address: the door settles the view and refuses.
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->withFilter('zzz');

        $this->expectException(\InvalidArgumentException::class);
        $t->withCursor(0);
    }

    public function testCursorRoundTripsTheSetIndex(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['A'])
            ->addRow(['B'])
            ->addRow(['C']);

        $this->assertSame(2, $t->withCursor(2)->cursor());
        $this->assertSame(['C'], $t->withCursor(2)->currentRow());
        // The accessor answers about the receiver too — pending state is
        // settled before the answer, same law as currentRow().
        $filtered = $t->withFilter('B'); // one row survives: 'B' at index 0
        $this->assertSame(0, $filtered->cursor());
    }

    public function testCurrentCellReturnsNullForInvalidColumn(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        $this->assertNull($t->currentCell(99));
    }

    public function testCurrentCellReturnsValue(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->addRow(['Alice', 'NYC']);

        $this->assertSame('Alice', $t->currentCell(0));
        $this->assertSame('NYC', $t->currentCell(1));
    }

    // ---- withCursorStyle / withHeaderStyle -------------------------------

    public function testWithCursorStyleAcceptsValidSgr(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->withCursorStyle('7'); // reverse

        $output = $t->render();
        $this->assertStringContainsString("\x1b[7m", $output);
    }

    public function testWithCursorStyleRejectsInvalidSgr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Table())
            ->addColumn(Column::make('Name', 10))
            ->withCursorStyle('invalid');
    }

    public function testWithHeaderStyleAcceptsValidSgr(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->withHeaderStyle('1'); // bold

        $output = $t->render();
        $this->assertStringContainsString("\x1b[1m", $output);
    }

    public function testWithHeaderStyleRejectsInvalidSgr(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Table())
            ->addColumn(Column::make('Name', 10))
            ->withHeaderStyle('bad');
    }

    public function testWithHeaderStyleEmptyStringIsNoOp(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->withHeaderStyle('');

        $output = $t->render();
        $this->assertStringNotContainsString("\x1b[", $output);
    }

    // ---- buildLines edge cases -------------------------------------------

    public function testBuildLinesWithNoColumnsReturnsEmptyArray(): void
    {
        $t = new Table();
        $this->assertSame([], $t->buildLines());
    }

    public function testBuildLinesWithNoRowsReturnsHeaderAndSeparator(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10));

        $lines = $t->buildLines();
        // Should have header + separator = 2 lines
        $this->assertCount(2, $lines);
    }

    public function testBuildLinesProducesSameAsRender(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        $this->assertSame($t->render(), implode("\n", $t->buildLines()));
    }

    // ---- rowCount / colCount ---------------------------------------------

    public function testRowCountWithFilteredRows(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->addRow(['Charlie'])
            ->filter('lice'); // Only matches 'Alice'

        $this->assertSame(1, $t->rowCount());
    }

    public function testColCount(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5))
            ->addColumn(Column::make('C', 5));

        $this->assertSame(3, $t->colCount());
    }

    // ---- sortByNext ------------------------------------------------------

    public function testSortByNextTogglesDirectionOnSameColumn(): void
    {
        // Position pin (finding-007): every stage asserts the FULL ordered
        // column, not presence of one value. Insertion order is deliberately
        // not alphabetical so a table that never sorted also fails the pins.
        $t = Table::new()
            ->withColumn(Column::new('Name', 10))
            ->withRow(['Bob'])
            ->withRow(['Cara'])
            ->withRow(['Alice']);

        $ascending = $t->sortByNext(0); // fresh column starts ascending
        $this->assertSame(['Alice', 'Bob', 'Cara'], $this->dataColumn($ascending));

        $descending = $ascending->sortByNext(0); // same column toggles direction
        $this->assertSame(['Cara', 'Bob', 'Alice'], $this->dataColumn($descending));

        $again = $descending->sortByNext(0); // and toggles back
        $this->assertSame(['Alice', 'Bob', 'Cara'], $this->dataColumn($again));
    }

    public function testSortByNextSwitchesColumnToAscending(): void
    {
        // Position pin (finding-007): switching the sort column to B must
        // move the '1' row to the top of the frame. Pinning BOTH columns'
        // ordered values proves the row actually relocated — an A-order
        // illusion (no re-sort) cannot pass.
        $t = Table::new()
            ->withColumn(Column::new('A', 5))
            ->withColumn(Column::new('B', 5))
            ->withRow(['x', '2'])
            ->withRow(['y', '1'])
            ->withSort(0); // A ascending: x, y

        $t2 = $t->sortByNext(1); // switch to B ascending: (y,1) then (x,2)
        $this->assertSame(['1', '2'], $this->dataColumn($t2, 1));
        $this->assertSame(['y', 'x'], $this->dataColumn($t2, 0));
    }

    /**
     * Ordered values of one column across the rendered data rows.
     *
     * Skips the header + ruler band (lines 0-1) and splits the default
     * ' │ ' separator, so tests pin ROW POSITIONS rather than substring
     * presence (finding-007: presence-only assertions survive any sort
     * permutation).
     */
    private function dataColumn(Table $t, int $col = 0): array
    {
        $values = [];
        foreach (array_slice($t->buildLines(), 2) as $line) {
            $cells = array_map('trim', explode(' │ ', $line));
            $values[] = $cells[$col] ?? '';
        }
        return $values;
    }

    // ---- Table family hardening (lib-fixes-1103) --------------------------

    public function testComputeTotalWidthCountsCellsNotBytesForWideSeparators(): void
    {
        $t = Table::new()
            ->addColumn(Column::new('A', 2))
            ->withSeparator('│')
            ->addColumn(Column::new('B', 2));
        // 2 + 2 + one joint of 1 cell. Bytes would report 2+2+3 = 7.
        $this->assertSame(5, $t->computeTotalWidth());
    }

    public function testSelectionFollowsItsRowThroughReSort(): void
    {
        // The three cursor laws, together: an append keeps the index (no
        // row moved), a re-sort carries the selection WITH its row, and
        // only a filter that drops the row sends it back to 0.
        $t = Table::new()
            ->addColumn(Column::new('Name', 8))
            ->addRow(['ann'])
            ->addRow(['bo'])
            ->setCursor(1)          // 'bo'
            ->addRow(['cy']);       // streaming append: index untouched
        $this->assertSame(['bo'], $t->currentRow(), 'append must not move the selection');

        $sorted = $t->withSort(0, false); // desc: cy, bo, ann — 'bo' moves to 1? it's already 1
        $this->assertSame(1, $sorted->cursor());
        $this->assertSame(['bo'], $sorted->currentRow());

        $flipped = $t->withSort(0, true); // asc: ann, bo, cy — 'bo' at 1 both ways; pick a moving case
        $this->assertSame(['bo'], $flipped->currentRow());

        $moved = Table::new()
            ->addColumn(Column::new('Name', 8))
            ->addRow(['ann'])
            ->addRow(['bo'])
            ->setCursor(0)              // 'ann' at 0
            ->withSort(0, false);       // desc: bo, ann — 'ann' moved to 1
        $this->assertSame(1, $moved->cursor(), 'the selection must travel with its row');
        $this->assertSame(['ann'], $moved->currentRow());

        $dropped = $moved->withFilter('bo'); // 'ann' gone — selection falls to 0
        $this->assertSame(0, $dropped->cursor());
        $this->assertSame(['bo'], $dropped->currentRow());
    }

    public function testSortByOutOfRangeIndexIsRefused(): void
    {
        $t = Table::new()->addColumn(Column::new('A', 4));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range for a table with 1 column(s)');
        $t->sortBy(1);
    }

    public function testSortByNextOutOfRangeIndexIsRefused(): void
    {
        $t = Table::new()->addColumn(Column::new('A', 4));
        $this->expectException(\InvalidArgumentException::class);
        $t->sortByNext(5);
    }

    public function testAddRowRefusesArrayValueNamingTheColumnIndex(): void
    {
        $t = Table::new()->addColumn(Column::new('A', 4));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('column 0 must be a string or scalar; array given');
        $t->addRow([['nested']]);
    }

    public function testAddRowRefusesObjectWithoutStringCasting(): void
    {
        $t = Table::new()->addColumn(Column::new('A', 4));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass without __toString given');
        $t->addRow([new \stdClass()]);
    }

    public function testAddRowAcceptsScalarsAndStringables(): void
    {
        $stringable = new class {
            public function __toString(): string { return 'typed'; }
        };
        $t = Table::new()
            ->addColumn(Column::new('A', 6))
            ->addRow([$stringable])
            ->addRow([42])
            ->addRow([true]);
        $this->assertSame(3, $t->rowCount());
        $this->assertSame(['typed'], $t->currentRow());
    }

    public function testHeaderTitlesCannotCarryTerminalControlSequences(): void
    {
        $t = Table::new()->addColumn(Column::new("Esc\x1b[31mX", 12));
        $rendered = \implode("\n", $t->buildLines());
        $this->assertStringNotContainsString("\x1b", $rendered);
        $this->assertStringContainsString('Esc', $rendered, 'the printable text survives; only control bytes are stripped');
    }

    public function testSeparatorIsSanitizedAtIngress(): void
    {
        $t = Table::new()
            ->addColumn(Column::new('A', 3))
            ->withSeparator("\x1b[2J|")
            ->addColumn(Column::new('B', 3));
        $this->assertStringNotContainsString("\x1b", $t->render());
    }

    public function testLazyViewKeepsDerivedStateCorrectAcrossMixedWriters(): void
    {
        // N appends settle once on read: filter + sort + appends in any
        // interleaving must agree with the canonical-rows derivation.
        $t = Table::new()
            ->addColumn(Column::new('N', 4))
            ->addRow(['10'])
            ->addRow(['2'])
            ->filter('1')
            ->addRow(['100'])
            ->sortBy(0, false);
        $this->assertSame(2, $t->rowCount());
        // FBX-26 follow law: the standing selection rode its row ('10')
        // through the whole mixed chain — after desc sort + filter the
        // view is ['100'], ['10'], so the cursor sits at index 1.
        $this->assertSame(['10'], $t->currentRow());
        $this->assertSame('100', $t->withCursor(0)->currentCell(0), 'the settled view still heads with 100');
    }

    public function testNumericSortKeepsEveryDigitBeyondFloatPrecision(): void
    {
        // A float cast collapses 10^18 and 10^18+1 onto the SAME double,
        // and the stable sort then answers in insertion order — silent
        // wrongness. Digit-only cells compare exactly at any magnitude.
        $t = Table::new()
            ->addColumn(Column::new('N', 22))
            ->addRow(['1000000000000000001']) // deliberately FIRST
            ->addRow(['1000000000000000000'])
            ->addRow(['999999999999999999'])
            ->withSort(0, true);

        $this->assertSame(
            ['999999999999999999', '1000000000000000000', '1000000000000000001'],
            $this->dataColumn($t),
            'ascending must order by VALUE, not by float coincidence'
        );
    }

    public function testLeadingZerosAreTheSameNumberAndTieByStability(): void
    {
        // '007' == '7' numerically: exact decimal compare calls them
        // equal, and the settle's stable sort keeps insertion order
        // (007 before 7 here — a raw strlen compare would instead park
        // the longer spelling last).
        $t = Table::new()
            ->addColumn(Column::new('N', 6))
            ->addRow(['007'])
            ->addRow(['7'])
            ->addRow(['6'])
            ->withSort(0, true);

        $this->assertSame(['6', '007', '7'], $this->dataColumn($t));
    }

    public function testNumericFirstLaneSurvivesMixedColumns(): void
    {
        // The documented lane rule: two numeric cells compare as numbers
        // (9 before 10), and the whole numeric block precedes text.
        $t = Table::new()
            ->addColumn(Column::new('N', 8))
            ->addRow(['apple'])
            ->addRow(['10'])
            ->addRow(['9'])
            ->withSort(0, true);

        $this->assertSame(['9', '10', 'apple'], $this->dataColumn($t));
    }

    public function testFoldCacheServesFiveKeystrokesAndStaysHonestOnAppends(): void
    {
        // The cache is index-addressed over append-only allRows, so it
        // cannot answer stale for existing rows; an append adds a NEW
        // index the cache folds fresh on first touch.
        $t = Table::new()
            ->addColumn(Column::new('N', 12))
            ->addRow(['Straße'])
            ->addRow(['Berlin']);
        $f = $t->withFilter('strasse');
        $this->assertSame(1, $f->rowCount());
        $g = $f->withFilter('stra'); // cached keys, second settle
        $this->assertSame(1, $g->rowCount());
        $h = $g->addRow(['STRASSE']); // new index, folded on demand
        $this->assertSame(2, $h->withFilter('stra')->rowCount());
    }

    public function testStringSortIsCaseInsensitiveAcrossTheBinaryOrderBoundary(): void
    {
        // Binary strcmp would put uppercase 'B' (0x42) before lowercase
        // 'a' (0x61); the documented Unicode case fold must not.
        $t = Table::new()
            ->addColumn(Column::new('Name', 8))
            ->addRow(['B'])
            ->addRow(['a'])
            ->sortBy(0, true);
                $lines = $t->buildLines();
        // header + ruler + two data rows; 'a' must be the first data line.
        $this->assertStringContainsString('a', $lines[2]);
        $this->assertStringNotContainsString('B', $lines[2]);
        $this->assertStringContainsString('B', $lines[3]);
    }

    // ---- cursor affordance (finding-019 / finding-023, FBX-08) ----------

    public function testCursorStyleSpansSeparatorJoints(): void
    {
        $t = Table::new()
            ->withColumn(Column::new('Name', 10))
            ->withColumn(Column::new('Num', 5))
            ->withRow(['Alice', '1'])
            ->withCursorStyle('7');

        $line = $t->buildLines()[2]; // cursor defaults to row 0

        // The whole imploded row rides inside ONE style span: the ' │ '
        // joint renders reverse-video, not as an unstyled gutter between
        // per-cell runs (finding-019).
        $this->assertSame("\x1b[7mAlice      │ 1    \x1b[0m", $line);
        // Probe: no reset gap anywhere before the closing reset.
        $this->assertSame(1, substr_count($line, "\x1b[0m"));
    }

    public function testCursorMarkerLandsOnlyOnFocusedRow(): void
    {
        $t = Table::new()
            ->withColumn(Column::new('Name', 8))
            ->withColumn(Column::new('Num', 4))
            ->withRow(['Alice', '1'])
            ->withRow(['Bob', '2'])
            ->withCursorMarker('> ')
            ->withCursor(0);

        $lines = $t->buildLines();
        // Marker + value inside the allocated 8 cells, so the row keeps its
        // exact pre-marker width.
        $this->assertSame('> Alice  │ 1   ', $lines[2]);
        $this->assertSame('Bob      │ 2   ', $lines[3]);
    }

    public function testCursorMarkerKeepsFrameDimensionsStable(): void
    {
        $base = Table::new()
            ->withColumn(Column::new('Name', 8))
            ->withColumn(Column::new('Num', 4))
            ->withRow(['Alice', '1'])
            ->withRow(['Bob', '2']);

        $marked = $base->withCursorMarker("\u{25B6}\u{25B6}")->withCursor(1);

        foreach ($base->buildLines() as $i => $plainLine) {
            $this->assertSame(
                Width::string($plainLine),
                Width::string($marked->buildLines()[$i]),
                "line {$i} must keep its cell width under the marker"
            );
        }
    }

    public function testCursorMarkerRidesInsideTheRowStyleSpan(): void
    {
        $t = Table::new()
            ->withColumn(Column::new('Name', 8))
            ->withRow(['Alice'])
            ->withCursorMarker('> ')
            ->withCursorStyle('7');

        // Focus stays one solid run: style -> marker -> cell -> reset.
        $this->assertSame("\x1b[7m> Alice \x1b[0m", $t->buildLines()[2]);
    }

    public function testCursorMarkerIsSanitizedAtIngress(): void
    {
        $t = Table::new()
            ->withColumn(Column::new('Name', 8))
            ->withRow(['Alice'])
            ->withCursorMarker("\x1b[7m>"); // smuggled styling must not survive

        $this->assertStringNotContainsString("\x1b", $t->buildLines()[2]);
    }

    public function testCursorMarkerDefaultIsByteStable(): void
    {
        $t = Table::new()
            ->withColumn(Column::new('Name', 8))
            ->withRow(['Alice'])
            ->withCursorStyle('7');

        $this->assertSame($t->buildLines(), $t->withCursorMarker('')->buildLines());
    }

    // ---- FBX-10: per-render row-line memo + withVisibleRange -----------

    /** Twelve rows, cursor style AND marker armed — the memo's hazard set. */
    private function memoTable(): Table
    {
        $t = Table::new()
            ->withColumn(Column::new('Name', 8))
            ->withColumn(Column::new('City', 6))
            ->withCursorStyle('7')
            ->withCursorMarker('> ');
        foreach ([
            ['Alice', 'Berlin'], ['Bob', 'Cairo'], ['Carol', 'Delhi'],
            ['Dave', 'Egypt'], ['Erin', 'Florence'], ['Fay', 'Genoa'],
            ['Gus', 'Hanoi'], ['Hal', 'Innsbruck'], ['Ivy', 'Jersey'],
            ['Jo', 'Kyiv'], ['Kim', 'Lima'], ['Lou', 'Madrid'],
        ] as $row) {
            $t = $t->withRow($row);
        }
        return $t;
    }

    public function testMemoKeepsCursorSweepByteIdenticalToFreshBuilds(): void
    {
        // One chain accumulates memo history across every frame; a twin
        // built from scratch per cursor answers must agree byte for byte.
        // Poison classes pinned here: a cursored line surviving its own
        // cursor move (reverse-video ghost on the wrong row) and a stale
        // marker frame.
        $swept = $this->memoTable();
        foreach ([0, 5, 11, 3, 0, 9] as $cursor) {
            $swept = $swept->withCursor($cursor);
            $fresh = $this->memoTable()->withCursor($cursor);
            $this->assertSame(
                $fresh->buildLines(),
                $swept->buildLines(),
                "cursor {$cursor} frame must equal a fresh memoless build"
            );
        }
    }

    public function testMemoInvalidatesOnLayoutAndContentChanges(): void
    {
        // Separator and column changes ride INSIDE every cached row;
        // appends and re-sorts move content between row indices. The
        // twin-oracle comparison catches any class of stale hit.
        $t = $this->memoTable();
        $t->buildLines();

        $t2 = $t->withSeparator(' | ')->withCursor(4);
        $fresh2 = $this->memoTable()->withSeparator(' | ')->withCursor(4);
        $this->assertSame($fresh2->buildLines(), $t2->buildLines());

        $t3 = $t2->withColumn(Column::new('Flag', 4))->withRow(['Mia', 'Oslo'])->withCursor(6);
        $fresh3 = $this->memoTable()
            ->withSeparator(' | ')
            ->withColumn(Column::new('Flag', 4))
            ->withRow(['Mia', 'Oslo'])
            ->withCursor(6);
        $this->assertSame($fresh3->buildLines(), $t3->buildLines());

        // Descending re-sort places different names at the same indices.
        $t4 = $t3->withSort(0, false)->withCursor(2);
        $fresh4 = $this->memoTable()
            ->withSeparator(' | ')
            ->withColumn(Column::new('Flag', 4))
            ->withRow(['Mia', 'Oslo'])
            ->withSort(0, false)
            ->withCursor(2);
        $this->assertSame($fresh4->buildLines(), $t4->buildLines());
    }

    public function testWindowKeepsViewIndexForPositionSensitiveFormatters(): void
    {
        // Column::padded() hands the formatter the VIEW index; a window
        // must not renumber rows, or a parity-stripe formatter flips.
        $parity = static fn(string $v, int $ri): string => ($ri % 2) === 1 ? "[{$v}]" : $v;
        $t = Table::new()
            ->withColumn(Column::new('Name', 8)->withFormatter($parity))
            ->withColumn(Column::new('City', 6)->withFormatter($parity));
        foreach ([
            ['Alice', 'Berlin'], ['Bob', 'Cairo'], ['Carol', 'Delhi'], ['Dave', 'Egypt'],
            ['Erin', 'Florence'], ['Fay', 'Genoa'], ['Gus', 'Hanoi'], ['Hal', 'Innsbruck'],
        ] as $row) {
            $t = $t->withRow($row);
        }

        $full = $t->buildLines();
        $windowed = $t->withVisibleRange(2, 6)->buildLines();

        // Header + ruler always paint; the windowed data lines are exactly
        // the full-frame bytes of view rows 2..5 (full indices 4..7) — the
        // [bracket] parity markers on rows 3/5 prove the formatter saw
        // view indices, not window positions.
        $this->assertSame(
            array_merge([$full[0], $full[1]], array_slice($full, 4, 4)),
            $windowed
        );
    }

    public function testOpenEndedWindowRendersHeadPlusTailRows(): void
    {
        $t = $this->memoTable();
        $full = $t->buildLines();
        $this->assertSame(
            array_merge([$full[0], $full[1]], array_slice($full, 5)),
            $t->withVisibleRange(3)->buildLines()
        );
    }

    public function testWindowPastEndRendersHeadOnly(): void
    {
        $t = $this->memoTable();
        $full = $t->buildLines();
        $this->assertSame(
            [$full[0], $full[1]],
            $t->withVisibleRange(30, 40)->buildLines()
        );
    }

    public function testVisibleRangeRefusesNegativeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Table::new()->withVisibleRange(-1);
    }

    public function testVisibleRangeRefusesEndAtOrBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Table::new()->withVisibleRange(2, 2);
    }

    public function testCursorOutsideWindowPaintsNoFocusRow(): void
    {
        $t = $this->memoTable();
        $inside = $t->withCursor(3)->withVisibleRange(2, 6)->buildLines();
        // Window [2,6) → lines: head, ruler, rows 2,3,4,5. Row 3 is line 3.
        $this->assertStringStartsWith("\x1b[7m> ", $inside[3]);

        $outside = $t->withCursor(9)->withVisibleRange(2, 6)->buildLines();
        foreach ($outside as $i => $line) {
            $this->assertStringNotContainsString("\x1b[7m", $line, "line {$i}");
        }
        // And the unseen cursor frame must not poison the memo for later
        // plain renders of the same rows.
        $again = $t->withCursor(9)->withVisibleRange(2, 6)->withCursor(4)->withVisibleRange(0);
        $this->assertSame(
            $this->memoTable()->withCursor(4)->buildLines(),
            $again->buildLines()
        );
    }
}
