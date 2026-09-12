<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Every PHP file under chosen roots of the package, keyed by its path relative
 * to the package root and sorted.
 *
 * ONE COPY, for the reason {@see \SugarCraft\Crush\Tests\Support\TestFileWalkTrait}
 * records for the tests/ walk: `DuplicatedDocBlockLineTest` and
 * `BackendSignatureNullabilityTest` each carried a private `everySourceFile()`,
 * and the two had already diverged past the bound their own sibling guard
 * reports at — one walked four named roots with a shebang-aware file test, the
 * other walked `src/` with a plain extension test — so the guard whose whole
 * subject is duplicated helpers could not see this pair while the duplication
 * was real (E609). Extracting it is the fix that does not depend on anybody
 * noticing next time; what genuinely differs between the two consumers — the
 * roots, and the claims each file makes about their population — stays with the
 * files, which now pass roots in.
 *
 * The file test is {@see SourceFileWalkTrait::isPhp()}, the shebang-aware
 * shape the doc-block census grew for `bin/sugarcrush`. It is a superset of the
 * `.php` extension test the Backend census carried, and the widening is honest
 * in one direction only: a PHP executable without an extension under `src/`
 * would join that census too, which is what a census of shipped source means.
 *
 * A TRAIT RATHER THAN A BASE CLASS: both consumers are `final` TestCases with
 * no other reason to share an ancestor — the identical argument
 * {@see \SugarCraft\Crush\Tests\Support\TestFileWalkTrait} gives.
 */
trait SourceFileWalkTrait
{
    /**
     * @param string $package the package root paths are keyed against
     * @param list<string> $roots package-relative directories to walk
     *
     * @return array<string,string> path relative to the package => absolute path, sorted
     */
    private static function everySourceFileIn(string $package, array $roots): array
    {
        $found = [];

        foreach ($roots as $root) {
            $directory = $package . '/' . $root;
            if (!is_dir($directory)) {
                // SILENT HERE AND CAUGHT THERE, deliberately. A root that has
                // been renamed away contributes nothing, and nothing is what
                // the per-root assertion in
                // {@see DuplicatedDocBlockLineTest::testNoDocBlockInThisPackageRepeatsALineUnderItself()}
                // reds on. Throwing here would move the diagnosis into the
                // walker and leave the test that makes the claim unable to
                // state which root went missing.
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || !self::isPhp($file->getPathname())) {
                    continue;
                }
                $found[substr($file->getPathname(), \strlen($package) + 1)] = $file->getPathname();
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Whether $path holds PHP source — asked of the FILE, not of its name.
     *
     * WHY NOT `str_ends_with($path, '.php')`, which is what one of the two
     * extracted copies tested. That test reported ZERO files for the `bin/`
     * root while the doc-block above it claimed the census covered it:
     * measured on PHP 8.3.6, `bin/` holds exactly one entry, `bin/sugarcrush`,
     * and it is 431 lines of PHP behind a `#!/usr/bin/env php` line with no
     * extension at all. The root was in the walk, the walk was in the prose,
     * and the file was in neither — rule 11 at its plainest, the alphabet here
     * being the file extension and the one file it could not express being the
     * package's own executable.
     *
     * The shebang is read from the file rather than guessed from the path, so
     * a second extensionless entry point arrives covered instead of arriving
     * uncounted.
     */
    private static function isPhp(string $path): bool
    {
        if (str_ends_with($path, '.php')) {
            return true;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $first = (string) fgets($handle, 256);
        fclose($handle);

        return str_starts_with($first, '#!') && str_contains($first, 'php');
    }
}
