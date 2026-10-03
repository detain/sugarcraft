<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Program;

/**
 * candy-testing is the harness every other lib drives its Programs through,
 * so it must not lean on a candy-core name that candy-core only keeps alive
 * as a delegating alias. The aliases stay (external consumers use them), but
 * a harness call is what pins them in place: candy-core's
 * `Program::getModel()` docblock cited "candy-testing's ProgramSimulator still
 * calls this name" as its reason to exist.
 *
 * The deprecated roster is re-derived from candy-core's own `@deprecated`
 * docblocks on every run, so a newly deprecated method is covered without
 * editing this test.
 */
final class DeprecatedCoreApiUsageTest extends TestCase
{
    public function testSourceCallsNoDeprecatedCandyCoreMethod(): void
    {
        $deprecated = [];
        foreach (self::phpFiles(\dirname((string) (new \ReflectionClass(Program::class))->getFileName())) as $file) {
            foreach (self::deprecatedMethods((string) file_get_contents($file)) as $name) {
                $deprecated[strtolower($name)] = $name . '() in ' . basename($file);
            }
        }

        $hits = [];
        foreach (self::phpFiles(\dirname(__DIR__) . '/src') as $file) {
            foreach (self::calledMethods((string) file_get_contents($file)) as [$name, $line]) {
                if (isset($deprecated[strtolower($name)])) {
                    $hits[] = sprintf('%s:%d calls deprecated %s', basename($file), $line, $deprecated[strtolower($name)]);
                }
            }
        }

        $this->assertSame([], $hits);
    }

    /**
     * Positive control: without it the real scan could pass vacuously if
     * either regex/tokenizer half silently stopped matching.
     */
    public function testScannerDetectsADeprecatedCall(): void
    {
        $core = <<<'PHP'
            <?php
            final class P {
                /** Current. */
                public function model(): int { return 1; }
                /**
                 * @deprecated use model()
                 */
                public function getModel(): int { return $this->model(); }
                /** @deprecated */
                public static function legacy(): void {}
            }
            PHP;
        $this->assertSame(['getModel', 'legacy'], self::deprecatedMethods($core));

        $caller = <<<'PHP'
            <?php
            // $p->getModel() in a comment is not a call
            $a = $p->model();
            $b = $p?->getModel();
            P::legacy();
            PHP;
        $this->assertSame([['model', 3], ['getModel', 4], ['legacy', 5]], self::calledMethods($caller));
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<string> method names whose own docblock carries @deprecated */
    private static function deprecatedMethods(string $source): array
    {
        preg_match_all(
            '~/\*\*(?:(?!\*/).)*?@deprecated\b(?:(?!\*/).)*?\*/\s*(?:#\[[^\]]*\]\s*)*'
            . '(?:(?:final|abstract|public|static)\s+)*function\s+(\w+)\s*\(~s',
            $source,
            $m,
        );
        return $m[1];
    }

    /** @return list<array{0: string, 1: int}> method calls (`->x(`, `?->x(`, `::x(`) outside comments */
    private static function calledMethods(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !\is_array($t) || !\in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $calls = [];
        $n = \count($tokens);
        for ($i = 0; $i + 2 < $n; $i++) {
            $op = $tokens[$i];
            if (!\is_array($op) || !\in_array($op[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            $name = $tokens[$i + 1];
            if (\is_array($name) && $name[0] === T_STRING && $tokens[$i + 2] === '(') {
                $calls[] = [$name[1], $name[2]];
            }
        }
        return $calls;
    }
}
