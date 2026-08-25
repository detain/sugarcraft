<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Every `.php` file under `tests/`, keyed by its path relative to `tests/`.
 *
 * ONE COPY, because the guards that use it are the guards that hunt copies.
 * {@see DuplicatedTestHelperDriftTest} and {@see OneSidedHomeSandboxTest} both
 * censused the tree by walking it, and both carried their own walker. The two
 * had already diverged -- one kept an intermediate variable the other did not --
 * by twenty tokens, which is well past the divergence bound that guard reports
 * at, so the file whose whole subject is duplicated test helpers could not see
 * its own duplicate. Extracting it is the fix that does not depend on anybody
 * noticing next time.
 *
 * A TRAIT RATHER THAN A BASE CLASS: one of the two consumers is a `final`
 * TestCase and the other is too, and neither has any other reason to share an
 * ancestor.
 */
trait TestFileWalkTrait
{
    /**
     * @return array<string,string> relative path => absolute path, sorted
     */
    private static function everyTestFile(): array
    {
        $root = \dirname(__DIR__);
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
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
