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
 * `driftReport()` at widening bounds on PHP 8.3.6: this pair appears only once
 * the bound is raised past a hundred, at which point the report names most
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
            if (self::declaresPrivateHelper((string) file_get_contents($path), self::HELPER)) {
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
     * pushed a source it MUST accept and four it MUST reject, in this test.
     */
    public function testTheDeclarationRecogniserWorksInBothPolarities(): void
    {
        $helper = self::HELPER;

        $this->assertTrue(
            self::declaresPrivateHelper(
                "<?php\nfinal class T { private function {$helper}(\$p, array &\$t): array { return []; } }\n",
                $helper,
            ),
            'the recogniser no longer sees a private declaration of the helper, so the census '
                . 'above is a statement about nothing',
        );

        foreach ([
            'a CALL rather than a declaration' => "<?php\nfinal class T { public function t(): void { \$this->{$helper}(1, \$x); } }\n",
            'the name inside a string' => "<?php\nfinal class T { public function t(): string { return '{$helper}'; } }\n",
            'the name in a comment' => "<?php\n// private function {$helper}()\nfinal class T {}\n",
            'a DIFFERENT private helper' => "<?php\nfinal class T { private function somethingElse(): void {} }\n",
        ] as $label => $source) {
            $this->assertFalse(
                self::declaresPrivateHelper($source, $helper),
                "the recogniser accepted {$label}, so the census counts appearances rather than "
                    . 'declarations and the population above is not the population it names',
            );
        }
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
     * Without this, "they share Support/ScaledClockLoop" is another sentence.
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
            $this->assertStringContainsString(
                'Support\\ScaledClockLoop',
                (string) file_get_contents($file),
                $class . ' no longer imports the shared ScaledClockLoop',
            );
        }
    }

    /**
     * Does `$source` DECLARE a private method called `$name`?
     *
     * Token-based, not a substring match: a call, a mention in a string and a
     * mention in a comment all contain the name, and counting appearances
     * instead of declarations would make the census above answer a different
     * question than the one it asks. {@see
     * testTheDeclarationRecogniserWorksInBothPolarities()} pushes all four
     * through it.
     */
    private static function declaresPrivateHelper(string $source, string $name): bool
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            // The name is the next T_STRING; anything else between is a
            // by-reference marker or whitespace.
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (\is_array($next) && \in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($next === '&') {
                    continue;
                }
                if (\is_array($next) && $next[0] === T_STRING && $next[1] === $name) {
                    // Look backwards for the visibility modifier. A closure or
                    // an arrow function has none and is not a declaration of a
                    // named helper anyway.
                    for ($k = $i - 1; $k >= 0; $k--) {
                        $previous = $tokens[$k];
                        if (\is_array($previous) && \in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        if (\is_array($previous) && \in_array($previous[0], [T_STATIC, T_FINAL, T_ABSTRACT], true)) {
                            continue;
                        }

                        return \is_array($previous) && $previous[0] === T_PRIVATE;
                    }
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
