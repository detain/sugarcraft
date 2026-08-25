<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

/**
 * Shared preamble for every guard under `tools/tests/`.
 *
 * WHY IT IS A SEPARATE FILE. `tools/` sits at the monorepo root, above every
 * lib's `phpunit.xml`, and CI runs these files as
 * `phpunit --no-configuration --colors=never tools/tests/` — a bare directory
 * suite with no bootstrap script and no composer autoloader for
 * `SugarCraft\Tools\Tests`. Each test file therefore has to find PHPUnit for
 * itself. That preamble lived inside CheckPathReposTest.php until a second
 * guard needed it, and copying thirty lines of it into the new file is exactly
 * the shape `DuplicatedTestHelperDriftTest` exists to catch. It is required by
 * name from the top of each test file rather than auto-discovered: PHPUnit's
 * directory suite matches `*Test.php`, so a `bootstrap.php` here would never
 * be loaded at all.
 *
 * The leading underscore keeps it out of that `*Test.php` glob deliberately.
 */

// ---------------------------------------------------------------------------
// Finding PHPUnit
// ---------------------------------------------------------------------------
//
// MOVED HERE VERBATIM FROM CheckPathReposTest.php, reasoning included, because
// deleting the reasoning is how the next reader deletes the guard:
//
//   Standalone script: the script under test is invoked as a subprocess, so no
//   SugarCraft lib classes are needed — only PHPUnit itself.
//
//   The root vendor/ carries no phpunit (root require-dev is phpstan only), so
//   borrow the first per-lib vendor that has one, then fall back to a global
//   composer install. The previous single hardcoded ~/.composer path made this
//   file unrunnable on any machine but its author's — it died in require_once
//   before PHPUnit could report anything.
//
//   AND NOT AT ALL WHEN PHPUNIT IS ALREADY HERE. A phpunit PHAR bundles its own
//   classes, so the hunt below finds nothing it needs and the `exit(2)` fired on
//   a runner that was perfectly capable of running this file — which is how a
//   guard ends up unrunnable in the one environment you want it in.

if (!\class_exists(\PHPUnit\Framework\TestCase::class, false)) {
    $autoloadCandidates = [];
    foreach (\glob(\dirname(__DIR__, 2) . '/*/vendor/autoload.php') ?: [] as $candidate) {
        if (\is_file(\dirname($candidate) . '/bin/phpunit')) {
            $autoloadCandidates[] = $candidate;
        }
    }
    $autoloadCandidates[] = ($_SERVER['HOME'] ?? '') . '/.composer/vendor/autoload.php';
    $autoloadCandidates[] = ($_SERVER['HOME'] ?? '') . '/.config/composer/vendor/autoload.php';

    $loaded = false;
    foreach ($autoloadCandidates as $candidate) {
        if (\is_file($candidate)) {
            require_once $candidate;
            $loaded = true;
            break;
        }
    }
    if (!$loaded) {
        \fwrite(\STDERR, "tools/tests/_bootstrap.php: no autoloader with PHPUnit found. Run `composer install` in any lib.\n");
        exit(2);
    }
}

/**
 * A throwaway directory tree, and the removal of it.
 *
 * Both guards here drive a `tools/` script as a subprocess against a FIXTURE
 * root rather than the real monorepo, so both need to build one and both need
 * to take it down again. `sys_get_temp_dir()` is shared with concurrent runs of
 * other suites, so the name carries the guard's own prefix and nothing here
 * ever glob-deletes.
 */
trait TmpTree
{
    /**
     * The fixture root, shared with the classes that use this trait.
     *
     * IT IS DECLARED HERE AND ASSIGNED THERE, WHICH LOOKS DEAD AND IS NOT. Both
     * consumers set it in `setUp()` and both read it back in `tearDown()`
     * through {@see removeDir()}. The `''` default is the load-bearing part:
     * PHPUnit runs `tearDown()` even when `setUp()` threw before the assignment,
     * and the `!== ''` check at each call site is what stops that path handing
     * `removeDir()` an uninitialised property.
     */
    private string $tmpDir = '';

    private function makeTmpTree(string $prefix): string
    {
        $dir = \sys_get_temp_dir() . '/' . $prefix . \uniqid('', true);
        // A GUARD MUST GO RED ON WHAT IT CANNOT DO, not carry on (rule 14). An
        // ignored return here hands every caller a path that does not exist,
        // and the failure then surfaces as a confusing assertion about page
        // content in whichever test happened to run first.
        if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw new \RuntimeException('could not create the fixture root ' . $dir);
        }
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }
        \rmdir($dir);
    }
}

/**
 * The significant tokens of one named function's body.
 *
 * WHY THIS IS SHARED RATHER THAN COPIED A THIRD TIME. `tools/tests/` now
 * carries TWO drift pins of the same shape — `StackedDocCommentTest` pins its
 * copy of `sugar-crush`'s stacked-doc-comment scanner, and `ToolsEnvRosterTest`
 * pins `candy-pty`'s copy of the environment scanner. Both answer "have these
 * two implementations diverged" by comparing token streams, and a divergence
 * detector that itself existed in two copies would be the joke writing itself.
 *
 * The extractor is checked against sources whose answers are already known by
 * {@see StackedDocCommentTest::testTheBodyExtractorReadsTheFunctionItIsAskedFor()}
 * — an extractor that always returned `[]`, or always the first function in the
 * file, satisfies every equality that uses it perfectly (rule 25).
 */
trait PhpFunctionBodyTokens
{
    /**
     * The significant tokens of one named function's body, or `[]`.
     *
     * Whitespace and comments are dropped so the comparison is about behaviour;
     * braces are matched so a nested block cannot end the body early.
     *
     * @return list<string>
     */
    private static function functionBodyTokens(string $source, string $name): array
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $named = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (\is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                $named = $j;

                break;
            }
            if ($named === null || !\is_array($tokens[$named]) || $tokens[$named][1] !== $name) {
                continue;
            }

            $depth = 0;
            $body = [];
            for ($j = $named; $j < $count; $j++) {
                $token = $tokens[$j];
                if ($token === '{') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($token === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $body;
                    }
                }
                if ($depth === 0) {
                    continue;
                }
                if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $body[] = \is_array($token) ? $token[1] : $token;
            }
        }

        return [];
    }
}
