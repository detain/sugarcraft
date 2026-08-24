<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * One implementation of `readOrFail()` AND of the fixture that pins it, for
 * the censuses that walk a population of source files, with the UNION of every
 * justification its copies carried.
 *
 * WHY THIS EXISTS AS A TRAIT (E332). Round 50's merge left the read arm and its
 * fixture in three census files at once — byte-identical in
 * `Cli/StderrEmitterCensusTest` and `Cli/BootstrapTranscriptSeamCallSiteCensusTest`,
 * and one message apart in `Support/ProcessUniqueTempNameTest`, whose fixture
 * had also drifted in NAME (`testTheReadRefusesAFileItCannotOpen…` against
 * `testTheCensusRefusesASourceItCannotOpen…`). NOTHING IN THE TREE COULD RED ON
 * IT: {@see DuplicatedTestHelperDriftTest} skips byte-identical bodies by
 * definition, and the fixture is a PUBLIC method, which that guard's `T_PRIVATE`
 * alphabet cannot express at all. So the family arrived already two spellings
 * apart, in the shape every four-copy family in this suite has started from.
 *
 * WHAT THE ARM IS FOR — RULE 14 AT THE READ, NOT ONLY AT THE PARSE.
 * `(string) file_get_contents()` turns an unreadable file into an empty one, an
 * empty one into "the scan found nothing", and "nothing" into a clean census:
 * three silent steps from a permission bit or a mid-run rename to a green
 * suite. A census that reads every source under `src/` on every run and has no
 * arm for any of them does not report an error when one cannot be opened — the
 * file simply drops out of the count, and for a file with NO roster row that is
 * indistinguishable from a file with no sites.
 *
 * WHY THE MESSAGE IS THE LONG ONE, and why that is a union rather than a pick.
 * Two of the three copies named all three steps and the third said only "the
 * census over it is void". The long text is a superset: it names the failure
 * mode the short one asserts AND the mechanism, so nothing a copy said has been
 * dropped. What has NOT been generalised is each census's own account of what
 * its counts mean — that stays in the consuming file, beside the counts.
 *
 * WHY THE FIXTURE TRAVELS WITH THE CODE and is not left per-file. It is the
 * ONLY input in the tree that reaches this arm: no source under `src/` or
 * `tests/` is unreadable, so reverting `readOrFail()` to the cast is a mutation
 * that every other assertion in every consuming class survives. Three copies of
 * the arm meant three places for it to be quietly reverted and three fixtures
 * to keep in step; one copy of both means the fixture cannot be lost while the
 * arm stays, or the arm weakened while the fixture stays. The fixture is a
 * public test method, so PHPUnit runs it once per CONSUMING CLASS — the read is
 * shared, the coverage is not.
 *
 * WHY THE WARNING IS SWALLOWED IN THE FIXTURE AND NOT INSIDE `readOrFail()`.
 * The failed open emits a PHP-level warning and this suite runs with
 * `failOnWarning`, so it has to go somewhere. An `@` on the `file_get_contents()`
 * itself would also swallow the diagnosis on a REAL unreadable source, which is
 * the one occasion the warning is the most useful thing on the screen.
 *
 * WHY THE DOC-BLOCK IS A UNION AND NOT A PICK, on the precedent
 * {@see FlattensSourceProseTrait} and {@see DiscardsErrorLogTrait} set: a
 * consolidation that keeps one implementation and one of the reasons re-creates
 * the asymmetry it was meant to remove, because the survivor then looks
 * canonical and the dropped reason is the one nobody goes looking for.
 *
 * THREE FURTHER COPIES ARE DELIBERATELY LEFT IN PLACE, and they are named here
 * rather than swept: `Config/DocumentParagraphsTest`,
 * `Config/ConfigWriteProducerDocumentationDriftTest` and
 * `Config/GlobFigureDriftTest`. Two carry a `could not be read` message and one
 * carries `the census over it is void`, which is the one-token pair
 * {@see DuplicatedTestHelperDriftTest::ACCEPTED_DIVERGENCE} has a `readOrFail`
 * row for. Folding them in as well would strand that row — and none of the
 * three has a fixture reaching its arm, which is a finding of its own and not
 * something to fix silently inside a consolidation. Recorded, not done.
 */
trait RefusesAnUnreadableSourceTrait
{
    /**
     * `$path`'s contents, or a failed assertion — never the empty string.
     */
    private static function readOrFail(string $path): string
    {
        $text = file_get_contents($path);
        self::assertIsString($text, $path . ' is unreadable, so this census is void: an '
            . 'unreadable source scans as empty text, empty text scans as no sites, and no '
            . 'sites is what a clean file looks like');

        return $text;
    }

    /**
     * THE READ ARM IS PINNED, because nothing in the tree can exercise it.
     *
     * No source in the scanned population is unreadable, so reverting
     * {@see readOrFail()} to the cast is a mutation every other assertion in
     * every consuming class survives. This fixture is the only input that
     * reaches it.
     */
    public function testTheCensusRefusesASourceItCannotOpenInsteadOfScanningItAsEmpty(): void
    {
        $absent = \dirname(__DIR__, 2) . '/src/no_such_source_'
            . \getmypid() . '_' . \bin2hex(\random_bytes(6)) . '.php';

        self::assertFileDoesNotExist($absent);

        // The PHP-level warning from the failed open is not the thing under
        // test, and this suite runs with failOnWarning. It is swallowed HERE
        // rather than with an `@` inside readOrFail(), where it would also
        // swallow the diagnosis on a real unreadable source.
        $previous = \set_error_handler(static fn (): bool => true);
        $refused = false;

        try {
            self::readOrFail($absent);
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $refused = true;
        } finally {
            \set_error_handler($previous);
        }

        self::assertTrue($refused, 'the read returned instead of refusing a file it could not '
            . 'open, so an unreadable source now reaches this census as empty text and is '
            . 'counted as a file with no sites');
    }
}
