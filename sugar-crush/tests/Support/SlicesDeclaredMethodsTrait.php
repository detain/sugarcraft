<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * The line-slice read a reflection-driven reader cannot make safely on its
 * own: lines $start..$end of a file the reader ALREADY checked is the right
 * one, refused unless the first of them declares $method.
 *
 * WHY A SHARED HOME RATHER THAN ONE PER READER. `VhsTapeContractTest` found
 * this defect by living in it: reflection's line numbers are fixed when the
 * class is loaded, `file()` is read per call, and an edit to the sliced file
 * between those two moments shifts every slice WHILE THE FILE NAME STILL
 * MATCHES - the declaring-file assertion the reader already carries cannot
 * see it. In the run where that happened, one census reported one method's
 * figures under another method's name, under a message blaming stale prose,
 * which invites the reader to edit the figures. E286 fixed that one reader;
 * E325 lifted the fix out so the other readers can be routed through it one
 * at a time instead of each re-deriving the guard - the round that adds a
 * shared `declaredSlice()` to the readers themselves rather than a census
 * over them.
 *
 * ONE LINE IS ENOUGH TO CATCH THE SHIFT because the slice starts at the
 * declaration: if the first line does not spell `function <name>`, the
 * offsets are not addressing the method that was asked for, whatever else
 * is true. A declaration split across a line break between the keyword and
 * the name is also refused - that is a shape this reader does not
 * understand rather than a false alarm, and saying so is the point.
 *
 * THE PAIR THIS TRAIT COMPLETES: {@see ReflectionLineSliceReaderCensusTest}
 * follows the slice exactly one hop from the reflection read - same file
 * first, then by unique name across the walked tree - so a reader that
 * delegates indexing here stays COVERED, and one that delegates it further
 * shows up in that census's SLICE_UNREACHABLE roster rather than silently
 * leaving the census blind. Renaming {@see declaredSlice()} without updating
 * the census's hop expectation is that census's job to catch, not this
 * doc-block's to prevent.
 */
trait SlicesDeclaredMethodsTrait
{
    /**
     * Lines $start..$end of $lines, refused unless the first of them declares
     * $method.
     *
     * THE DECLARING-FILE CHECK THE CALLER CARRIES DOES NOT COVER THIS ONE,
     * and the failure it leaves open is the one actually observed - see the
     * class doc-block.
     *
     * @param list<string> $lines
     */
    private static function declaredSlice(array $lines, string $method, int $start, int $end): string
    {
        self::assertStringContainsString(
            'function ' . $method,
            $lines[$start - 1] ?? '',
            'the first line of the slice reflection points at does not declare ' . $method
            . '(), so these offsets address some other part of this file and every figure '
            . 'measured from them is a figure about the wrong method. The ordinary cause is '
            . 'that the sliced file was EDITED while the suite was running - a mutation '
            . 'harness rewriting it in place will do it - in which case the run is void '
            . 'rather than red. Do not adjust the census to match what came back.',
        );

        return \implode('', \array_slice($lines, $start - 1, $end - $start + 1));
    }
}
