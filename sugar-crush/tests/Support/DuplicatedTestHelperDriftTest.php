<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A HELPER COPIED FROM ONE TEST CLASS INTO ANOTHER AND THEN FIXED IN ONLY ONE
 * OF THEM IS INVISIBLE TO EVERY OTHER GUARD IN THIS SUITE.
 *
 * WHY THIS FILE EXISTS. `Support/ForkedChildTest::isRaw()` and
 * `Backend/EngineBackendTest::isRaw()` are the same helper: run `stty -a`
 * against a pty and substring-match `-icanon`/`-echo`. The device flag is `-F`
 * on GNU coreutils and `-f` on BSD, and the wrong one fails with an EMPTY
 * stdout - indistinguishable from "the terminal is cooked" - so the message on
 * fd 2 is the only thing telling those two apart, and it was going to
 * `/dev/null`. One copy was fixed. The other was in no lane's file list and
 * kept the bug for a full round, one directory away from its fixed twin.
 * Nothing in the tree could have said so: both suites were green, both helpers
 * were private, and no census looks at two files at once.
 *
 * THE LANE SPLIT MAKES THIS LIKELIER, NOT LESS LIKELY. Ownership is by
 * directory; a copied helper is not. A fix lands in whichever copy the round
 * happened to own, and the other copy's divergence is then indistinguishable
 * from a deliberate local difference - which is exactly what most of
 * {@see ACCEPTED_DIVERGENCE} really is.
 *
 * WHAT COUNTS AS DRIFT HERE, stated as a measurement and not as a feeling. Two
 * declarations of the same private method name, in DIFFERENT FILES, whose
 * normalised token bodies are not identical, and whose bodies agree except for
 * at most ONE token on each side after the common prefix and the common suffix
 * are trimmed. One token apart is the shape of the defect above: the whole
 * helper agrees and a single flag, literal or name does not.
 *
 * WHAT IT DELIBERATELY CANNOT SEE, because an alphabet is coverage and this
 * one was chosen rather than found:
 *
 *   * TWO TOKENS APART OR MORE. Every name inside the bound is a row of
 *     {@see ACCEPTED_DIVERGENCE} below, so that population is countable from
 *     this file and is not written into prose a merge invalidates. Relaxing
 *     {@see DRIFT_BOUND} to two or three tokens brings in further names;
 *     sampled on PHP 8.3.6 those are mostly `self::` against `$this->`, which
 *     is noise, but nothing here proves they all are. Widening the bound is a
 *     round of its own with rows to argue, not a constant to nudge.
 *   * SAME NAME, TWO CLASSES, ONE FILE. Comparison is cross-FILE, so two
 *     classes in one file are never compared with each other. Measured on PHP
 *     8.3.6 at the commit that added this file, no file in `tests/` declared
 *     one private name twice; the generator is the same
 *     {@see privateDeclarationsIn()} this guard runs, so a reader re-derives
 *     it rather than trusting the sentence.
 *   * PROTECTED AND PUBLIC HELPERS. A protected helper is usually inherited
 *     rather than copied, which is a different failure; public ones are the
 *     subject of tests rather than their machinery.
 *   * A COPY THAT WAS RENAMED. Nothing here matches bodies across different
 *     names, so a helper copied and renamed is out of reach by construction.
 */
final class DuplicatedTestHelperDriftTest extends TestCase
{
    /**
     * The largest per-side divergence, in tokens, that still reads as "the
     * same helper, one edit apart" rather than as two unrelated helpers that
     * happen to share a name.
     */
    private const DRIFT_BOUND = 1;

    /**
     * Same-named private helpers that ARE one token apart and are meant to be,
     * each with the reason.
     *
     * NOT AN EXEMPTION LIST AND NOT A COMMENT, for the same reason as
     * {@see ChildStderrCaptureTest::OUT_OF_SCOPE}: every row is checked against
     * the tree in both directions by the two tests below. A row whose pair has
     * been consolidated fails and must be deleted; a drifted pair with no row
     * fails and must be argued or fixed. A deferral is a claim about the tree,
     * so the tree is asked.
     *
     * THE ROWS ARE KEYED BY HELPER NAME AND NOT BY FILE PAIR. A name with three
     * copies has three pairs, and keying by pair would make a row go stale the
     * moment one of the three moved directory - which is a rename, not a drift.
     *
     * MOST OF THESE ARE NOT BUGS, AND THAT IS THE POINT. The value of the map
     * is not its current contents; it is that the THIRTEENTH name - the next
     * helper somebody fixes in one copy and not the other - arrives as a red
     * test naming both files, instead of as a green suite.
     *
     * @var array<string,string>
     */
    private const ACCEPTED_DIVERGENCE = [
        'askAboutHook' =>
            'A leading `\\` on a call to a global function against no leading `\\`. Purely how '
            . 'each file spells the same call; nothing about behaviour differs.',
        'awaitPromise' =>
            'THE ONE ROW HERE THAT IS A REAL SEMANTIC DIFFERENCE: the bound the helper waits '
            . 'to, 10.0 seconds against 15.0. Both are deliberate - a streaming wiring test '
            . 'legitimately needs longer than an engine unit test - but they are also exactly '
            . 'the shape a drifted copy takes, which is why the row says so rather than '
            . 'calling it style. If a third copy appears, give it the bound its own suite '
            . 'needs and say why here.',
        'body' =>
            'The parameter is named `$app` in one copy and `$chat` in the other, matching the '
            . 'model each file drives. A local name, not behaviour.',
        'createAskHook' =>
            'A fully-qualified interface name against the imported short name. Same class, two '
            . 'spellings, decided by whether the file already imports it.',
        'isRaw' =>
            'THE HELPER THIS WHOLE FILE EXISTS BECAUSE OF, and what is left of the divergence '
            . 'is now deliberate: the two copies differ only in the PREFIX of the temp file '
            . 'each uses for the stderr it captures. Those prefixes must NOT be shared - a '
            . 'process-unique temp name per call site is what keeps two suites running at once '
            . 'from reading each other\'s file. The `-F`/`-f` defect that made the copies '
            . 'differ in BEHAVIOUR was fixed in both, one round apart, which is the event this '
            . 'guard exists to make visible next time.',
        'readOrFail' =>
            'The text of the failure message differs; the read and the refusal are identical. '
            . 'Each message names what its own census is void without, which is worth more '
            . 'than one shared sentence.',
        'readme' =>
            'One copy calls a helper named `document()`, the other one named `repoFile()`. Two '
            . 'different accessors in two different classes, reached by helpers that happen to '
            . 'share a name.',
        'resetTaskListConnectionCache' =>
            'A fully-qualified class name against the imported short name, as with '
            . '`createAskHook` above.',
        'reviewerAgent' =>
            'One copy takes the active flag as a parameter and the other hard-codes `true`, '
            . 'because only one of the two files needs both states. A narrower copy, not a '
            . 'drifted one.',
        'rewriteHook' =>
            'A leading `\\` on a call to a global function, as with `askAboutHook` above.',
        'runBounded' =>
            'The timeout message names what each caller was waiting for - a worker stop in one '
            . 'file, a child reap in the other. The bound and the wait are identical.',
        'writeScript' =>
            'Different temp-name prefixes, and they must stay different for the same reason as '
            . '`isRaw` above.',
    ];

    /**
     * No same-named private helper has drifted without a row saying so.
     *
     * @return void
     */
    public function testNoCopiedTestHelperHasDriftedUnrecorded(): void
    {
        $this->assertTheScannerIsAlive();

        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        $this->assertNotSame([], $sources, 'no test file was read at all, so nothing was compared');

        [$drifted, $unparseable, $declarations] = self::driftReport($sources);

        // RULE: A GUARD MUST GO RED ON WHAT IT CANNOT PARSE. A private
        // declaration whose name or whose closing brace this scanner cannot
        // find is not "clean" - it is a hole shaped exactly like the next
        // copied helper.
        $this->assertSame(
            [],
            $unparseable,
            'this scanner could not read a private method declaration, so it cannot say whether '
                . 'that helper has a twin anywhere. It is reported rather than skipped: a '
                . 'declaration silently dropped is a helper this guard has stopped covering, '
                . 'which is indistinguishable from one it has cleared.',
        );

        // The scan has to have found helpers at all, or "nothing drifted" is a
        // statement about a dead walk rather than about the tree.
        $this->assertGreaterThan(
            0,
            $declarations,
            'no private test helper was found anywhere under tests/ - the walk is dead',
        );

        $unrecorded = [];
        foreach ($drifted as $name => $pairs) {
            if (isset(self::ACCEPTED_DIVERGENCE[$name])) {
                continue;
            }
            $unrecorded[] = $name . ': ' . implode('; ', $pairs);
        }

        $this->assertSame(
            [],
            $unrecorded,
            'two files declare a private helper of the same name whose bodies agree except for '
                . 'one token. That is what a copied helper fixed in only one place looks like: '
                . 'the whole method matches and a single flag, literal or name does not, and '
                . 'both suites stay green because a private helper has no other reader. Either '
                . 'make the two copies agree - or extract the helper - or add the name to '
                . 'ACCEPTED_DIVERGENCE with the reason the difference is deliberate. The row '
                . 'is checked back against the tree, so it cannot become a rubber stamp.',
        );
    }

    /**
     * A row cannot outlive the divergence it was written for.
     *
     * Without this, {@see ACCEPTED_DIVERGENCE} decays into a list of helpers
     * somebody once looked at, and the check above keeps passing because a
     * stale row still matches the name. A row whose pair has been consolidated
     * means the copies are one helper now, and this is the one moment anybody
     * is likely to notice.
     */
    public function testEveryAcceptedDivergenceStillDescribesADriftedPair(): void
    {
        $this->assertTheScannerIsAlive();

        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        [$drifted] = self::driftReport($sources);

        $overtaken = [];
        foreach (self::ACCEPTED_DIVERGENCE as $name => $reason) {
            $this->assertNotSame('', trim($reason), $name . ' is accepted without a reason');

            if (!isset($drifted[$name])) {
                $overtaken[] = $name;
            }
        }

        $this->assertSame(
            [],
            $overtaken,
            'this name is recorded in ACCEPTED_DIVERGENCE as a helper whose copies differ by '
                . 'one token, and they no longer do - the copies were consolidated, one was '
                . 'deleted, or the divergence grew past the bound. Delete the row. An accepted '
                . 'divergence that has been overtaken is how a helper silently stops being '
                . 'compared.',
        );
    }

    /**
     * THE SCANNER IS PUSHED THROUGH INPUTS WHOSE ANSWER IS KNOWN, in the same
     * test that uses it to assert an absence.
     *
     * WHY. Both real-tree assertions above are absences - "nothing drifted that
     * is not recorded", "no row is stale" - and an absence is satisfied
     * perfectly by an instrument that has been deleted. It is satisfied just as
     * perfectly by a fixture whose expected value is what a DEAD instrument
     * returns, which is why the load-bearing fixture here is the POSITIVE one:
     * with {@see driftReport()} stubbed to return nothing, the first fixture
     * below fails and the two negatives do not.
     *
     * THE FIXTURES ARE NOWDOCS, so their `private function` declarations are a
     * single `T_ENCAPSED_AND_WHITESPACE` token in THIS file and contribute
     * nothing to the real scan. That is the same discipline the file argues
     * for: a fixture that spelled its offender as real code would put this
     * guard's own test doubles into the population it measures.
     */
    private function assertTheScannerIsAlive(): void
    {
        $original = <<<'PHP'
            <?php
            final class A
            {
                private function probe(string $device): bool
                {
                    return str_contains((string) shell_exec('stty -F ' . $device), '-icanon');
                }
            }
            PHP;

        // ONE TOKEN APART, inside a string literal: the exact shape of the
        // defect this file exists for, and the fixture that fails if the
        // scanner is dead.
        $drifted = str_replace("'stty -F '", "'stty -f '", $original);
        [$report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $drifted]);
        $this->assertArrayHasKey(
            'probe',
            $report,
            'two copies of one helper differing by a single string literal were not reported. '
                . 'Until this passes, every "nothing drifted" answer above is a statement about '
                . 'a dead instrument.',
        );

        // IDENTICAL COPIES ARE NOT DRIFT. Without this the predicate could be
        // stuck at "every shared name is drift", which would put every
        // legitimately duplicated helper on the hook and be answered with an
        // exemption - which is where the next real drift would hide.
        [$report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $original]);
        $this->assertSame(
            [],
            $report,
            'two byte-identical copies of a helper were reported as drifted',
        );

        // A SHARED NAME OVER TWO UNRELATED BODIES IS NOT DRIFT EITHER. This is
        // the other way the predicate rots, and it is the common case rather
        // than the exotic one: most private names declared in more than one
        // file under `tests/` belong to unrelated helpers that merely agree on
        // a word - chat(), app(), context() - and a predicate stuck at "a
        // shared name means drift" would put every one of them on the hook.
        $unrelated = <<<'PHP'
            <?php
            final class B
            {
                private function probe(string $device): bool
                {
                    $rows = [];
                    foreach (explode("\n", $device) as $line) {
                        $rows[] = trim($line);
                    }

                    return $rows !== [];
                }
            }
            PHP;
        [$report] = self::driftReport(['a/A.php' => $original, 'b/B.php' => $unrelated]);
        $this->assertSame(
            [],
            $report,
            'two unrelated helpers that happen to share a name were reported as drifted copies',
        );

        // AND THE SCANNER SAYS SO WHEN IT CANNOT PARSE. A declaration whose
        // body never closes must reach the caller as a problem, not be dropped.
        $truncated = <<<'PHP'
            <?php
            final class C
            {
                private function probe(): bool
                {
                    return true;
            PHP;
        [, $unparseable] = self::driftReport(['c/C.php' => $truncated]);
        $this->assertNotSame(
            [],
            $unparseable,
            'a private declaration whose body never closes was silently dropped instead of '
                . 'being reported - a guard that quietly ignores the unparseable has a hole '
                . 'shaped exactly like the next defect',
        );
    }

    /**
     * The drift report over a map of path => source.
     *
     * Takes SOURCES rather than reading the tree itself, so the fixtures above
     * and the two real-tree tests go through exactly this code. A second
     * definition of "drifted" is the seam a copied helper would slip through.
     *
     * @param array<string,string> $sources
     *
     * @return array{array<string,list<string>>, list<string>, int}
     *         name => pair descriptions, unparseable declarations, declarations read
     */
    private static function driftReport(array $sources): array
    {
        $declarations = [];
        $unparseable = [];
        $read = 0;

        foreach ($sources as $relative => $source) {
            foreach (self::privateDeclarationsIn($source) as $declaration) {
                if ($declaration['body'] === null) {
                    $unparseable[] = $relative . ': ' . $declaration['problem'];

                    continue;
                }
                $read++;
                $declarations[$declaration['name']][$relative] = $declaration['body'];
            }
        }

        ksort($declarations);
        $drifted = [];

        foreach ($declarations as $name => $byFile) {
            $files = array_keys($byFile);
            $count = \count($files);

            for ($a = 0; $a < $count; $a++) {
                for ($b = $a + 1; $b < $count; $b++) {
                    $left = $byFile[$files[$a]];
                    $right = $byFile[$files[$b]];
                    if ($left === $right) {
                        continue;
                    }

                    [$leftCore, $rightCore] = self::divergenceCore($left, $right);
                    if (\count($leftCore) > self::DRIFT_BOUND || \count($rightCore) > self::DRIFT_BOUND) {
                        continue;
                    }

                    $drifted[$name][] = $files[$a] . ' [' . implode(' ', $leftCore) . '] vs '
                        . $files[$b] . ' [' . implode(' ', $rightCore) . ']';
                }
            }
        }

        return [$drifted, $unparseable, $read];
    }

    /**
     * What is left of two token bodies once the common prefix and the common
     * suffix are trimmed.
     *
     * WHY NOT `similar_text()` OR A PERCENTAGE. A percentage answers "how
     * alike are these" and the question here is "how many edits apart are
     * they" - and the two ORDER DIFFERENTLY, which is the part that matters.
     * Measured on PHP 8.3.6 over `tests/` at the commit that added this file:
     * the `isRaw()` pair, ONE token apart out of a hundred, scores 98.90%. A
     * `removeDirectory()` pair whose bodies share only their opening and
     * closing - 66 of 74 tokens in the divergence core, two different helpers
     * by any reading - scores 98.80%, one tenth of a point below it. And an
     * `unshrinkablePairs()` pair 22 tokens apart scores 99.42%, HIGHER than
     * the one-token pair. A threshold on the percentage would admit both and
     * a stricter one would exclude `isRaw()` itself. Trimming both ends
     * answers in tokens, is O(n), and is exactly the quantity
     * {@see DRIFT_BOUND} is stated in. Generator: the same
     * {@see divergenceCore()} this guard calls.
     *
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return array{list<string>,list<string>}
     */
    private static function divergenceCore(array $left, array $right): array
    {
        $leftCount = \count($left);
        $rightCount = \count($right);
        $shortest = min($leftCount, $rightCount);

        $prefix = 0;
        while ($prefix < $shortest && $left[$prefix] === $right[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while ($suffix < $shortest - $prefix
            && $left[$leftCount - 1 - $suffix] === $right[$rightCount - 1 - $suffix]) {
            $suffix++;
        }

        return [
            array_values(\array_slice($left, $prefix, $leftCount - $prefix - $suffix)),
            array_values(\array_slice($right, $prefix, $rightCount - $prefix - $suffix)),
        ];
    }

    /**
     * Every `private` (including `private static`) method a source declares.
     *
     * The body is normalised to `id:text` per significant token - whitespace
     * and every kind of comment dropped - so that reindenting a helper, or
     * documenting one copy and not the other, is not drift. The token ID is
     * kept alongside the text because `'{'` the string and `{` the
     * interpolation opener are different tokens with the same text, and a
     * comparison on text alone would call them equal.
     *
     * @return list<array{name:string, body:list<string>|null, problem:string}>
     */
    private static function privateDeclarationsIn(string $source): array
    {
        $tokens = \token_get_all($source);
        $count = \count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }

            if (!self::isPrivate($tokens, $i)) {
                continue;
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $tokens[$j];
                if (\is_array($candidate) && $candidate[0] === \T_WHITESPACE) {
                    continue;
                }
                if (\is_array($candidate) && $candidate[0] === \T_STRING) {
                    $name = $candidate[1];
                }

                break;
            }

            if ($name === null) {
                $found[] = [
                    'name' => '',
                    'body' => null,
                    'problem' => 'a private function declaration whose name this scanner cannot read',
                ];

                continue;
            }

            $body = self::bodyOf($tokens, $i);
            $found[] = [
                'name' => $name,
                'body' => $body,
                'problem' => $body === null
                    ? $name . '(): the scanner found no closing brace for this body'
                    : '',
            ];
        }

        return $found;
    }

    /**
     * Whether the `function` token at $at carries the `private` modifier.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function isPrivate(array $tokens, int $at): bool
    {
        $skippable = [
            \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT,
            \T_STATIC, \T_FINAL, \T_ABSTRACT, \T_READONLY,
        ];

        for ($j = $at - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (!\is_array($token)) {
                return false;
            }
            if (\in_array($token[0], $skippable, true)) {
                continue;
            }

            return $token[0] === \T_PRIVATE;
        }

        return false;
    }

    /**
     * The normalised body of the method whose `function` token is at $at, or
     * null when this scanner cannot find where the body ends.
     *
     * THE BRACE WALK NAMES EVERY OPENER THE RUNNING PHP PRODUCES, not just
     * `{`: an interpolation opens with an ARRAY token and closes with a bare
     * `}`, so a depth count matching only the bare string goes one closer over
     * and ends the body early - inside the helper rather than at its end,
     * which would make two identical helpers look different. The roster is
     * enforced across the whole suite by
     * {@see InterpolationOpenerTokenTest::testEveryBraceWalkingScannerNamesEveryOpener()}.
     *
     * A `;` at parameter-list depth zero before any `{` means the declaration
     * has no body. PHP does not allow a private abstract method, so in this
     * tree that is a shape the scanner does not understand rather than a real
     * answer, and it is reported as such.
     *
     * @param list<array{int,string,int}|string> $tokens
     *
     * @return list<string>|null
     */
    private static function bodyOf(array $tokens, int $at): ?array
    {
        $count = \count($tokens);
        $parentheses = 0;
        $i = $at;

        for (; $i < $count; $i++) {
            $text = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(') {
                $parentheses++;
            } elseif ($text === ')') {
                $parentheses--;
            } elseif ($parentheses === 0 && $text === '{') {
                break;
            } elseif ($parentheses === 0 && $text === ';') {
                return null;
            }
        }

        if ($i >= $count) {
            return null;
        }

        $depth = 0;
        $body = [];

        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;

            if ($text === '{'
                || $id === \T_CURLY_OPEN
                || $id === \T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }

            if ($id !== null && \in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $body[] = $id === null ? $text : $id . ':' . $text;
        }

        return null;
    }

    /**
     * Every `.php` file under `tests/`, keyed by its path relative to `tests/`.
     *
     * @return array<string,string> relative path => absolute path
     */
    private static function everyTestFile(): array
    {
        $root = \dirname(__DIR__);
        $found = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $found[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
        }

        ksort($found);

        return $found;
    }
}
