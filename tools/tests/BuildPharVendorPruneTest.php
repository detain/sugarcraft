<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Fleet pin for the vendor prune (CG-43) copied into every app's
 * `tools/build-phar.php`.
 *
 * WHY A FLEET TEST. The walk is the same ~20 lines pasted into each app, so a
 * respell in one app silently re-ships dependency tests/examples/docs inside
 * that app's archive. Each script is run with `--list-files` against a
 * synthetic package (its own copy under a temp `tools/`, since the script
 * resolves the package as `dirname(__DIR__)`), so no git, composer or real
 * vendor/ is involved and the check is hermetic.
 */
final class BuildPharVendorPruneTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scripts(): iterable
    {
        foreach (glob(__DIR__ . '/../../*/tools/build-phar.php') ?: [] as $script) {
            yield basename(dirname($script, 2)) => [realpath($script)];
        }
    }

    public function testTheFleetIsNotEmpty(): void
    {
        self::assertGreaterThanOrEqual(20, iterator_count(self::scripts()));
    }

    /** @dataProvider scripts */
    public function testVendorDevWeightIsPrunedAndRuntimeCodeSurvives(string $script): void
    {
        $listed = self::list($script);

        foreach ($listed as $path) {
            self::assertDoesNotMatchRegularExpression(
                '#^vendor/[^/]+/[^/]+/(?!src/)(?:[^/]+/)*(?:tests|Tests|test|examples|\.vhs|docs|doc)/#',
                $path,
                "vendor dev-weight leaked into the archive: {$path}",
            );
            self::assertDoesNotMatchRegularExpression('#^vendor/[^/]+/[^/]+/phpunit\.xml#', $path);
            self::assertDoesNotMatchRegularExpression('#^vendor/[^/]+/[^/]+/[^/]+\.md$#', $path);
        }
        self::assertContains('vendor/autoload.php', $listed);
        self::assertContains('vendor/sugarcraft/pkg/src/Keep.php', $listed);
        self::assertContains('vendor/sugarcraft/pkg/src/Tests/Keep.php', $listed, 'src/ is never pruned');
        self::assertContains('vendor/sugarcraft/pkg/composer.json', $listed);
        self::assertContains('vendor/composer/installed.json', $listed);
        self::assertNotContains('vendor/sugarcraft/pkg/tests/FooTest.php', $listed);
        self::assertNotContains('vendor/sugarcraft/pkg/README.md', $listed);
        self::assertContains('src/tests/Own.php', $listed, 'the app\'s own roots are never pruned');
    }

    /** @return list<string> */
    private static function list(string $script): array
    {
        $source = (string) file_get_contents($script);
        preg_match("/^const INCLUDE_ROOTS = \\[(.*?)\\];/m", $source, $m);
        self::assertNotEmpty($m, 'INCLUDE_ROOTS not found in ' . $script);
        preg_match_all("/'([^']+)'/", $m[1], $roots);

        $pkg = sys_get_temp_dir() . '/phar-prune-' . bin2hex(random_bytes(4));
        mkdir($pkg . '/tools', 0o755, true);
        copy($script, $pkg . '/tools/build-phar.php');
        $put = static function (string $rel) use ($pkg): void {
            @mkdir(dirname($pkg . '/' . $rel), 0o755, true);
            file_put_contents($pkg . '/' . $rel, "x\n");
        };
        foreach ($roots[1] as $root) {
            @mkdir($pkg . '/' . $root, 0o755, true);
        }
        foreach ([
            'src/tests/Own.php',
            'vendor/autoload.php',
            'vendor/composer/installed.json',
            'vendor/sugarcraft/pkg/composer.json',
            'vendor/sugarcraft/pkg/README.md',
            'vendor/sugarcraft/pkg/phpunit.xml.dist',
            'vendor/sugarcraft/pkg/src/Keep.php',
            'vendor/sugarcraft/pkg/src/Tests/Keep.php',
            'vendor/sugarcraft/pkg/tests/FooTest.php',
            'vendor/sugarcraft/pkg/Tests/BarTest.php',
            'vendor/sugarcraft/pkg/examples/demo.php',
            'vendor/sugarcraft/pkg/.vhs/x.tape',
            'vendor/sugarcraft/pkg/docs/a.html',
            'vendor/sugarcraft/pkg/doc/a.html',
            'vendor/sugarcraft/pkg/test/a.php',
        ] as $rel) {
            $put($rel);
        }

        try {
            $proc = proc_open(
                [PHP_BINARY, '-d', 'phar.readonly=0', $pkg . '/tools/build-phar.php', '--vendor=monorepo', '--list-files'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc), $script . ' --list-files failed: ' . $err);
        } finally {
            exec('rm -rf ' . escapeshellarg($pkg));
        }

        return array_values(array_filter(explode("\n", $out), static fn (string $l): bool => $l !== ''));
    }
}
