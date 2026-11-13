<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;

/**
 * SQL-C24 guard: citation provenance must stay truthful and greppable.
 *
 * (a) The real antecedent for the admin/dashboard cluster is
 *     `jorgerojas26/lazysql`; `charmbracelet/lazysql` never existed, so the
 *     blind org carry produced 404 provenance + an implied-license signal
 *     error. The whole tree pins the right org.
 * (b) README cites must name METHODS, not line numbers — `File.php:NN`
 *     cites rot one refactor later (they did), while `Class::method()`
 *     survives as a grep anchor.
 *
 * MySQL Workbench cites (`mysql-workbench …`, `MySQL Workbench …`,
 * `mysql_workbench_dash.md`) are the honest antecedent for the dashboard /
 * reports cluster and stay untouched by design.
 *
 * The guard file is excluded from its own scans: it must contain the bad
 * tokens verbatim to detect them (self-reference is the guard-test tax).
 */
final class CitationProvenanceGuardTest extends TestCase
{
    /** @var list<string> */
    private const SCAN_DIRS = ['src', 'tests', 'bin', 'lang'];

    public function testNoPhantomLazysqlOrgAnywhereInTheTree(): void
    {
        $offenders = [];
        foreach (self::treeFiles() as $file) {
            $lineNo = 0;
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $lineNo++;
                if (str_contains($line, 'charmbracelet/lazysql')) {
                    $offenders[] = basename($file) . ':' . $lineNo;
                }
            }
        }

        $this->assertSame([], $offenders, 'phantom org cite re-introduced: charmbracelet/lazysql');
    }

    /**
     * Every lazysql citation in src/ must carry the greppable
     * `jorgerojas26/lazysql` token (the fleet anchor form), not a bare
     * lowercase description with no org.
     */
    public function testEveryLazysqlCiteCarriesTheRealOrgToken(): void
    {
        $offenders = [];
        foreach (self::treeFiles() as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $lineNo = 0;
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $lineNo++;
                if (str_contains($line, 'lazysql') && !str_contains($line, 'jorgerojas26/lazysql')) {
                    $offenders[] = basename($file) . ':' . $lineNo;
                }
            }
        }

        $this->assertSame([], $offenders, 'lazysql mention without the jorgerojas26/lazysql anchor token');
    }

    public function testReadmeCarriesNoRottableLineNumberCites(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        $offenders = [];
        $lineNo = 0;
        foreach (explode("\n", $readme) as $line) {
            $lineNo++;
            // File.php:NN or bin/candy-sql:NN — line-number cites rot; use method names.
            if (preg_match('/([A-Za-z0-9_\/.\-]+\.(?:php|inc):[0-9]+|bin\/candy-sql:[0-9]+)/', $line, $m) === 1) {
                $offenders[] = 'README.md:' . $lineNo . ' ' . $m[1];
            }
        }

        $this->assertSame([], $offenders, 'stale file:line cite in README (cite Class::method() instead)');
    }

    /**
     * @return list<string>
     */
    private static function treeFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [$root . '/README.md'];
        foreach (self::SCAN_DIRS as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $f) {
                if ($f->isFile() && !str_contains($f->getPathname(), '/vendor/')) {
                    $files[] = $f->getPathname();
                }
            }
        }
        sort($files);

        // A guard must be allowed to write the crime it polices.
        return array_values(array_filter(
            $files,
            static fn(string $f): bool => basename($f) !== 'CitationProvenanceGuardTest.php',
        ));
    }
}
