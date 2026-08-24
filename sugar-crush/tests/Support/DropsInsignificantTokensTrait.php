<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * One implementation of `significantTokens()` for the guards that walk PHP
 * source as a token stream, and the UNION of every justification its copies
 * carried.
 *
 * WHY THIS EXISTS AS A TRAIT AND NOT AS A SIXTH COPY (E331). Round 50's merge
 * left FIVE declarations of this helper — in `StderrEmitterCensusTest`,
 * `ContainedPathInventoryTest`, `HomeDirectoryPathReaderInventoryTest`,
 * `ProcessUniqueTempNameTest` and `ReadmeJsonErrorContractDriftTest` — plus a
 * SIXTH spelling of the same loop written inline inside
 * `BootstrapTranscriptSeamCallSiteCensusTest::countSeamCallSites()`, which no
 * search for a declaration could find. They are spelled far enough apart
 * (`$out` / `$tokens` / `$significant`, `\token_get_all` against
 * `token_get_all`, `\T_WHITESPACE` against `T_WHITESPACE`) that the divergence
 * exceeds {@see DuplicatedTestHelperDriftTest::DRIFT_BOUND} at one AND at two,
 * so the guard that exists for exactly this family could not see it. The
 * family arrived one notch outside the bound its own round had widened.
 *
 * THAT IS NOT A HYPOTHETICAL RISK HERE — IT HAS ALREADY COST A ROUND.
 * `HomeDirectoryPathReaderInventoryTest`'s copy dropped comments and NOT
 * whitespace, while the twin it was copied from dropped both. Its
 * `indirectPathCalls()` reads the neighbours of a `T_DOUBLE_COLON` BY INDEX, so
 * `$class :: path()` had a `T_WHITESPACE` sitting where the subject and the
 * method were looked for and the site was skipped before it was ever examined.
 * The "no call reaches the resolution through a variable class name" assertion
 * was, for a full round, an assertion about the zero-whitespace spelling alone.
 * Both files were green; both helpers were private; nothing in the tree could
 * say so until the drift guard's bound was widened to two tokens.
 *
 * WHAT THE STRIP ACTUALLY BUYS IS ADJACENCY, and that is the half a reader
 * guesses wrong. `token_get_all()` returns a whole comment as ONE token
 * (MEASURED, PHP 8.3.6), so a name a census keys on can never appear as a
 * `T_STRING` inside one and dropping `T_COMMENT` buys no protection against
 * that. What it buys is that every consumer here reads `$tokens[$i - 1]` and
 * `$tokens[$i + 1]`, and a comment or a run of whitespace is legal in exactly
 * those two positions. A census whose strip stops running does not report
 * more — it reports the unspaced, uncommented spelling only, which looks like
 * a clean tree.
 *
 * WHY COMMENTS AND DOC-BLOCKS GO WITH THE WHITESPACE, carried across from the
 * two inventories that each learned it the same way: a doc-comment
 * `{@see HomeDirectory::path()}` or `{@see ContainedPath::within()}` is a
 * CROSS-REFERENCE, not a call site. Counting those is precisely how
 * `WorkflowEngine` came to be listed as a caller of the home-directory
 * resolution, and it is what inflated an earlier hand count of the containment
 * compares. A guard that reads its own explanation of why it is green is a
 * guard that reds on its own doc-block.
 *
 * WHY IT IS ONE LIST AND NOT A PARAMETER, carried across from the census that
 * promoted it out of its own `scan()` rather than copying it: this list IS the
 * alphabet every channel in a multi-channel census counts over, and a second
 * copy that dropped a different token kind would give two scanners two
 * different views of the same file while both looked right.
 *
 * WHY THE DOC-BLOCK IS A UNION AND NOT A PICK, on the precedent
 * {@see FlattensSourceProseTrait} and {@see DiscardsErrorLogTrait} set: five
 * copies means five justifications, and a consolidation that keeps one
 * implementation and one reason re-creates the asymmetry it removed — the
 * survivor now looks canonical, so the dropped reason is the one nobody goes
 * looking for.
 *
 * THE FIFTH COPY IS DELIBERATELY NOT A CONSUMER, and the reason is a
 * measurement rather than an ownership boundary.
 * `Config/ReadmeJsonErrorContractDriftTest::significantTokens()` returns
 * `list<PhpToken>` from `PhpToken::tokenize()`, not `list<array|string>` from
 * `token_get_all()`. Its consumers read `$t->text` and `$t->is([...])`, which
 * no caller of this method can do. It is the same IDEA with a different return
 * type, so folding it in here would be a rewrite of that file's every walk
 * rather than a consolidation — recorded rather than done.
 *
 * EVERY CONSUMER KEEPS ITS OWN KNOWN-POSITIVE CONTROL, and sharing the code is
 * not sharing the control (rule 15). A strip that silently returned `[]` would
 * make every census over it report an empty tree, and "no occurrences" is
 * exactly what an assertion of an absence cannot distinguish from a dead
 * instrument. So each consuming test proves this method still discriminates on
 * a fixture of its own BEFORE it trusts it on a real file, and the fixture has
 * to be one whose answer CHANGES when the strip goes — a comments-only source
 * asserting zero is satisfied by deleting the strip outright (rule 25, E228).
 */
trait DropsInsignificantTokensTrait
{
    /**
     * `$source` as a token list with whitespace, comments and doc-blocks
     * dropped, so that index arithmetic over it reads code neighbours.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function significantTokens(string $source): array
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)
                && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)
            ) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
    }
}
