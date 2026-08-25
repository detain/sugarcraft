<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Backend\Support\ScaledClockLoop;

/**
 * **E528 — the two `runOnScaledClock()` helpers are a SEAM, not a drift, and
 * the seam is now pinned instead of promised.**
 *
 * ## What the round asked for, and what the tree said
 *
 * E528 reads: `runOnScaledClock` now exists twice, the two DIFFER, consolidate
 * them into `Support/`; and it warns that
 * {@see \SugarCraft\Crush\Tests\Support\DuplicatedTestHelperDriftTest} "exists
 * to catch exactly this and did not", so find out why before fixing the
 * duplication. Both halves were checked against the tree first (rule 16), and
 * both come out differently.
 *
 * WHY THE DRIFT GUARD DID NOT FIRE: because this pair is outside its stated
 * alphabet, deliberately and in writing. That guard reports two same-named
 * private helpers whose bodies agree except for at most
 * {@see \SugarCraft\Crush\Tests\Support\DuplicatedTestHelperDriftTest::DRIFT_BOUND}
 * tokens per side — "the whole helper agrees and a single flag, literal or name
 * does not" — and its own docblock lists "TWO TOKENS APART OR MORE" first under
 * WHAT IT DELIBERATELY CANNOT SEE. MEASURED by running that guard's own
 * `driftReport()` at widening bounds on PHP 8.3.6: this pair's per-side
 * divergence cores are 165 and 128 tokens, so it first appears at a bound of
 * 165 and is still ABSENT at 128 - "past a hundred" was the first way this was
 * written, and it invites a reader to try 110, see nothing and conclude the
 * measurement was wrong. At 165 the report names the large majority of the
 * same-named private helpers in the suite and has stopped being a drift
 * detector. It is a DRIFT detector; these two are not a drifted copy, they are
 * two different helpers that share a name. Nothing failed.
 *
 * WHY THEY ARE NOT CONSOLIDATED: because {@see EngineBackendTest} already
 * argued it, and stated the trigger — that copy's docblock says the twin is
 * "deliberately not shared: that one collects a reasoning channel this test has
 * no use for", and "if a third caller appears, promote it to `Support/` rather
 * than growing a third copy". There is no third caller. Rule 6: an intentional
 * seam is documented and its dormancy pinned, not deleted; rule 7: a
 * justification is rewritten, not removed. What was missing is that the trigger
 * was a sentence — it fired only if a human happened to read the right
 * docblock at the right moment. It is a test now.
 *
 * ## What this file therefore asserts
 *
 * That the population is exactly the two known declarations, so a THIRD arrives
 * as a red test naming the file and the remedy rather than as a green suite;
 * and that the two really are different helpers rather than a drifted pair,
 * because the two situations have opposite fixes and only one of them is this
 * seam.
 */
final class ScaledClockHelperSeamTest extends TestCase
{
    /** The helper whose copies this file is about. */
    private const HELPER = 'runOnScaledClock';

    /**
     * The two declarations that are meant to exist, relative to `tests/`.
     *
     * Rule 18: this is not a cardinality over a directory, it is a named pair.
     * A count would merge clean and be arithmetically wrong; two paths cannot.
     *
     * @var list<string>
     */
    private const KNOWN = [
        'Backend/EngineBackendTest.php',
        'Backend/ReasoningProgressTest.php',
    ];

    public function testExactlyTheTwoKnownFilesDeclareTheScaledClockHelper(): void
    {
        $found = [];
        foreach (self::everyTestFile() as $relative => $path) {
            if (self::declaresHelper((string) file_get_contents($path), self::HELPER)) {
                $found[] = $relative;
            }
        }
        sort($found);

        $expected = self::KNOWN;
        sort($expected);

        $this->assertSame(
            $expected,
            $found,
            'the population of ' . self::HELPER . "() declarations has changed.\n\n"
                . "A THIRD COPY: EngineBackendTest::runOnScaledClock()'s docblock states the\n"
                . "trigger - \"if a third caller appears, promote it to Support/ rather than\n"
                . "growing a third copy\". Do that: one helper under\n"
                . "tests/Backend/Support/, taking the reasoning sink as an optional argument,\n"
                . "and delete both copies. Do NOT add a path here.\n\n"
                . "ONE FEWER: the seam has been consolidated after all. Delete this whole file\n"
                . "and the paragraph in EngineBackendTest that argues for it - a guard pinning\n"
                . 'a seam that no longer exists is worse than no guard.',
        );
    }

    /**
     * Rule 15's known-positive, and rule 25's: `assertSame(self::KNOWN, ...)`
     * is satisfied just as well by a scanner that answers `false` for
     * everything and a `KNOWN` list that happens to be short — no, it is not,
     * and that is exactly the trap. It is satisfied by a scanner that answers
     * TRUE for those two paths and false elsewhere for the wrong reason, and by
     * one whose recogniser has silently narrowed. So the same recogniser is
     * pushed six sources it MUST accept and four it MUST reject, in this test.
     *
     * WHAT THIS SAID, AND WHAT IT COST: the recogniser was
     * `declaresPrivateHelper()` and required `T_PRIVATE`, and this list probed
     * a call, a string, a comment and a different name — four ways of writing
     * the name that are not declarations, and NOT ONE non-private declaration.
     * MEASURED with a matched control: a third `runOnScaledClock()` added to a
     * file in this directory as `private` is caught, as `protected` is NOT, and
     * as `public` is NOT. One keyword bought silence from a guard whose stated
     * purpose is that "a THIRD arrives as a red test". Rule 11 in its textbook
     * form — the alphabet was written from the two cases already known, and
     * both of those happen to be private.
     *
     * The visibility is now not part of the question at all, which is stronger
     * than adding two more token ids: a declaration with NO modifier (implicitly
     * public) and a plain top-level `function` are both declarations of the
     * name, and an alphabet of three keywords would still have missed them.
     */
    public function testTheDeclarationRecogniserWorksInBothPolarities(): void
    {
        $helper = self::HELPER;

        foreach ([
            'a private declaration' => "<?php\nfinal class T { private function {$helper}(\$p, array &\$t): array { return []; } }\n",
            'a PROTECTED declaration' => "<?php\nclass T { protected function {$helper}(\$p): void {} }\n",
            'a PUBLIC declaration' => "<?php\nclass T { public function {$helper}(\$p): void {} }\n",
            'a declaration with NO visibility modifier at all' => "<?php\nclass T { function {$helper}(\$p): void {} }\n",
            'a private STATIC declaration' => "<?php\nfinal class T { private static function {$helper}(\$p): void {} }\n",
            'a plain top-level function' => "<?php\nfunction {$helper}(\$p): void {}\n",
        ] as $label => $source) {
            $this->assertTrue(
                self::declaresHelper($source, $helper),
                "the recogniser no longer sees {$label} of the helper, so the census above is a "
                    . 'statement about the spellings it happens to accept rather than about the '
                    . 'population it names',
            );
        }

        foreach ([
            'a CALL rather than a declaration' => "<?php\nfinal class T { public function t(): void { \$this->{$helper}(1, \$x); } }\n",
            'the name inside a string' => "<?php\nfinal class T { public function t(): string { return '{$helper}'; } }\n",
            'the name in a comment' => "<?php\n// private function {$helper}()\nfinal class T {}\n",
            'a DIFFERENT private helper' => "<?php\nfinal class T { private function somethingElse(): void {} }\n",
        ] as $label => $source) {
            $this->assertFalse(
                self::declaresHelper($source, $helper),
                "the recogniser accepted {$label}, so the census counts appearances rather than "
                    . 'declarations and the population above is not the population it names',
            );
        }
    }

    /**
     * A declaration the recogniser must NOT be able to hide behind another one.
     *
     * The first draft `return`ed out of the whole function on the first name
     * match, so a non-matching declaration EARLIER in a file answered for a
     * matching one later. With the visibility test gone that particular hole
     * cannot recur, but the shape can — this pins the "keep looking" behaviour
     * directly rather than trusting that it fell out.
     */
    public function testALaterDeclarationIsNotHiddenByAnEarlierOne(): void
    {
        $helper = self::HELPER;

        $this->assertTrue(
            self::declaresHelper(
                "<?php\nclass T { public function somethingElse(): void {} "
                    . "private function {$helper}(): void {} }\n",
                $helper,
            ),
            'a declaration of the helper is invisible when another declaration precedes it, so '
                . 'the census reports whatever happens to come first in each file',
        );
    }

    /**
     * **They are two helpers, not one helper that drifted — structurally.**
     *
     * This is the assertion that decides which remedy applies, and it is keyed
     * on the SIGNATURE rather than on either docblock's prose (rule 40: an
     * exemption keyed on text can be bought with a sentence, and the sentence
     * that buys it is usually the one explaining the exemption).
     *
     * If the two ever come to take the same parameters they are no longer a
     * seam with a reason; they are a copy, {@see
     * \SugarCraft\Crush\Tests\Support\DuplicatedTestHelperDriftTest} becomes the
     * right instrument for them, and the argument in {@see EngineBackendTest}
     * has expired.
     */
    public function testTheTwoCopiesAreDifferentHelpersAndNotADriftedPair(): void
    {
        $narrow = new \ReflectionMethod(EngineBackendTest::class, self::HELPER);
        $wide = new \ReflectionMethod(ReasoningProgressTest::class, self::HELPER);

        $this->assertNotSame(
            $narrow->getNumberOfParameters(),
            $wide->getNumberOfParameters(),
            'the two ' . self::HELPER . '() helpers now declare the same parameters. The reason '
                . 'EngineBackendTest gives for not sharing them is that the other "collects a '
                . 'reasoning channel this test has no use for"; if that difference has gone, the '
                . 'seam has gone with it. Consolidate into tests/Backend/Support/ and delete '
                . 'this file.',
        );

        $extra = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            \array_slice($wide->getParameters(), $narrow->getNumberOfParameters()),
        );
        $this->assertSame(
            ['thoughts'],
            $extra,
            'the difference between the two is no longer the reasoning channel alone, so the '
                . 'stated reason for the seam no longer describes it',
        );
    }

    /**
     * The shared double both copies drive is genuinely shared, which is the
     * half of the consolidation that DID happen and is worth not losing.
     *
     * WHAT THIS SAID: «Without this, "they share Support/ScaledClockLoop" is
     * another sentence.» WHAT IS TRUE NOW: the first version of it WAS bought
     * by a sentence - rule 40 landing two methods after the sibling below cites
     * rule 40 for avoiding exactly this. It asserted that the string
     * `Support\ScaledClockLoop` occurred somewhere in the file, and MEASURED by
     * pointing it at a class whose only occurrence of that string was a comment
     * DENYING the fact - the words "deliberately not using" followed by the
     * name - the test was GREEN. WHY THE CLAIM STILL EARNS ITS PLACE: the half
     * worth keeping is real - the consolidation of the LOOP happened even
     * though the consolidation of the helper did not - and only the instrument
     * was prose-keyed. It is now keyed on structure: a `use` statement that
     * resolves to {@see ScaledClockLoop}, which is a token-stream fact no
     * comment can forge.
     */
    public function testBothCopiesDriveTheOneSharedScaledClockLoop(): void
    {
        $this->assertTrue(
            class_exists(ScaledClockLoop::class),
            'the shared loop double is gone, so each copy is about to grow its own',
        );

        foreach ([EngineBackendTest::class, ReasoningProgressTest::class] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $this->assertIsString($file);
            $this->assertContains(
                ScaledClockLoop::class,
                self::importedClassNames((string) file_get_contents($file)),
                $class . ' no longer IMPORTS the shared ScaledClockLoop. Note what this asks for: '
                    . 'a use statement, not a mention. An earlier revision matched the name '
                    . 'anywhere in the file and was satisfied by a comment saying the class was '
                    . 'NOT used, so do not close this by writing the name into a comment.',
            );
        }
    }

    /**
     * Rule 15's known-positive for the recogniser above, and rule 40's: the
     * same function is pushed two sources it MUST accept and four it MUST
     * reject, one of which is the comment that bought the old assertion.
     */
    public function testTheImportRecogniserCannotBeBoughtWithASentence(): void
    {
        $fqn = ScaledClockLoop::class;
        $short = 'ScaledClockLoop';

        $this->assertContains(
            $fqn,
            self::importedClassNames("<?php\nnamespace X;\nuse {$fqn};\nfinal class T {}\n"),
            'the recogniser no longer sees a plain import, so the assertion above is a statement '
                . 'about nothing',
        );
        $this->assertContains(
            $fqn,
            self::importedClassNames("<?php\nnamespace X;\nuse {$fqn} as Aliased;\nfinal class T {}\n"),
            'an aliased import is still an import of the same class',
        );

        foreach ([
            'a comment DENYING the import' => "<?php\nnamespace X;\n// Deliberately NOT using Support\\{$short} here.\nfinal class T {}\n",
            'the name in a doc-block cross-reference' => "<?php\nnamespace X;\n/** {@see \\{$fqn}} */\nfinal class T {}\n",
            'the name inside a string' => "<?php\nnamespace X;\nfinal class T { public function t(): string { return '{$fqn}'; } }\n",
            'a trait use inside a class body' => "<?php\nnamespace X;\nfinal class T { use \\{$fqn}; }\n",
            // The interpolation-opener case: without both openers counted, the
            // brace depth is one too shallow from the interpolated string
            // onwards and the trait use below reads as a top-level import.
            'a trait use AFTER an interpolated string' => "<?php\nnamespace X;\nfinal class T { public function t(\$x): string { return \"a{\$x}b\"; } use \\{$fqn}; }\n",
        ] as $label => $source) {
            $this->assertNotContains(
                $fqn,
                self::importedClassNames($source),
                "the recogniser accepted {$label} as an import, so it is keyed on text after all",
            );
        }
    }

    /**
     * The fully-qualified class names `$source` IMPORTS, aliases resolved back
     * to the class they name.
     *
     * Namespace-level only, so a trait `use` inside a class body and a
     * closure's `use (...)` are both outside it - the first by brace depth, the
     * second because a closure lives inside a function body.
     *
     * @return list<string>
     */
    private static function importedClassNames(string $source): array
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);
        $imports = [];
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            // BOTH INTERPOLATION OPENERS COUNT, and this is not defensive
            // padding - `"{$x}"` opens with T_CURLY_OPEN, an ARRAY token, and
            // closes with a bare `}`. A walk that counts only the one-byte
            // brace therefore decrements on a level it never incremented, and
            // from the first interpolated string onwards it believes it is one
            // level further out than it is - at which point a trait `use`
            // inside a class body reads as a namespace-level import.
            // {@see \SugarCraft\Crush\Tests\Support\InterpolationOpenerTokenTest}
            // caught this in the first revision of this method; the fix is its
            // own prescription, and its polarity fixture is below.
            if ($token === '{'
                || (\is_array($token) && \in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $depth++;

                continue;
            }
            if ($token === '}') {
                $depth--;

                continue;
            }
            if ($depth !== 0 || !\is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $name = '';
            $afterAs = false;
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (\is_array($next) && \in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === ';' || $next === '(' || $next === '{' || $next === ',') {
                    break;
                }
                if (\is_array($next) && $next[0] === T_AS) {
                    $afterAs = true;

                    continue;
                }
                if ($afterAs) {
                    continue;
                }
                $name .= \is_array($next) ? $next[1] : $next;
            }

            $name = ltrim($name, '\\');
            if ($name !== '') {
                $imports[] = $name;
            }
        }

        return $imports;
    }

    /**
     * Does `$source` DECLARE a function or method called `$name`?
     *
     * Token-based, not a substring match: a call, a mention in a string and a
     * mention in a comment all contain the name, and counting appearances
     * instead of declarations would make the census above answer a different
     * question than the one it asks. {@see
     * testTheDeclarationRecogniserWorksInBothPolarities()} pushes ten sources
     * through it, six it must accept and four it must reject.
     *
     * VISIBILITY IS DELIBERATELY NOT PART OF THE QUESTION, and that is a
     * correction rather than a preference — see the docblock of the polarity
     * test for the measurement. A closure and an arrow function are excluded
     * for free: neither has a name for the `T_STRING` test to match.
     */
    private static function declaresHelper(string $source, string $name): bool
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            // The name is the next significant token; anything skipped here is
            // whitespace, a comment or a by-reference marker. Anything else -
            // a `(`, for a closure - means this `function` declares no name,
            // and the scan continues with the next one rather than returning.
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (\is_array($next) && \in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === '&') {
                    continue;
                }
                if (\is_array($next) && $next[0] === T_STRING && $next[1] === $name) {
                    return true;
                }

                break;
            }
        }

        return false;
    }

    /**
     * Every `.php` file under `tests/`, keyed by its path relative to `tests/`.
     *
     * NOT `Support/TestFileWalkTrait`, and the reason is this round rather than
     * a preference: that trait is another lane's file this round, and a `use`
     * of it here would put an edit of theirs and a guard of mine in one
     * inheritance chain during a concurrent merge. The walk is four lines.
     * If the two are still separate next round, fold this one into the trait.
     *
     * @return array<string, string>
     */
    private static function everyTestFile(): array
    {
        $root = \dirname(__DIR__);
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $found[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
        }
        ksort($found);

        return $found;
    }
}
