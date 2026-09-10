<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support\Fixtures;

/**
 * A DELIBERATE OFFENDER, IN SCOPE, SO THE FLAGLESS-UNIQUID WALK HAS A REAL
 * FILE TO FIND. Nothing calls this class and nothing may.
 *
 * WHY IT EXISTS. {@see \SugarCraft\Crush\Tests\Support\ProcessUniqueTempNameTest}'s
 * first census asserts an ABSENCE (outside its inventory) over every `.php`
 * file under `tests/`, `src/` and `bin/sugarcrush`, and rule 15 says an
 * absence is worth nothing unless something proves the instrument still
 * works. That job used to be done, for this channel, by the five
 * `src/Workflows/WorkflowEngine.php` sites the E324 row described — and E324
 * fixed them, which is the one outcome that leaves an absence-census with no
 * positive input at all. The sibling census learned this first: its only real
 * row closed with E328, and the file that replaced it,
 * {@see StaticTempPathWalkControl}, is the template this one copies.
 *
 * A synthetic string handed straight to the scanner (the known-answer table
 * in that file) proves the MATCHER works. It does not prove the WALK works:
 * that `filesInScope()` still enumerates real files, that `readOrFail()` still
 * returns their bytes, and that the two are still wired to the matcher. This
 * file is the input for that, and unlike a real site it can never be "fixed"
 * out from under the census by somebody improving production code.
 *
 * IT IS NEVER EXECUTED, and that is not an accident of nobody having got
 * round to it. The class is `abstract` so it cannot be instantiated, the
 * method below is private, and nothing outside a doc-block names either.
 *
 * WHY THE CALL IS SPELLED LITERALLY, against this census's own file's rule
 * that fixtures build the offender by concatenation: that rule protects the
 * SCANNER'S OWN SOURCE, which sits in scope and would report itself. This
 * file has the opposite job — the WALK must find a real literal offender in a
 * real file on disk — and the census exempts it by inventory row, the same
 * way it exempts the offender it replaced. A sweep that "fixes" this call by
 * adding the more-entropy flag does not make the census green: the row's
 * exact count goes red, which is the arrangement doing its job.
 *
 * ABSTRACT RATHER THAN A `final` CLASS WITH A PRIVATE CONSTRUCTOR, for the
 * reason {@see StaticTempPathWalkControl} records: an empty private
 * `__construct() {}` is byte-identical to every other empty one under
 * `tests/`, so DuplicatedTestHelperDriftTest reads the file as a copied
 * helper that has drifted.
 *
 * @codeCoverageIgnore
 */
abstract class EntropylessUniqidWalkControl
{
    /**
     * The exact shape E329's four carried before that fix: a constant literal
     * prefix — provably ZERO cross-process entropy, just the same 13-hex
     * microtime suffix the bare call returns — and no more-entropy flag. A
     * computed prefix would not do: this scanner reports calls whose prefix
     * value it cannot prove rather than counting them, and this file must be
     * COUNTED.
     */
    private function neverCalled(): string
    {
        return uniqid('entropyless-walk-control-');
    }
}
