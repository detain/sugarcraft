<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Roster-drift guard for the README namespace tables (audit C-1, plan_review step 22).
 *
 * Every `## X Namespace` table row claims "type T is an X (or X\Y) member". This
 * guard re-derives the truth from src/ (class/interface/trait/enum declarations,
 * plus the class_alias shim files as documented re-exports) and fails, per
 * section, on any row whose col1 token does not resolve under the namespace the
 * enclosing table heading asserts:
 *
 *  - unqualified token  -> a type whose namespace == base or starts with base\
 *    (or the base-relative class_alias shim, e.g. Foundation\Color);
 *  - backslash token    -> exact membership of the token itself OR of
 *    base\token (the btop rows spell from the library root, the Modules rows
 *    spell relative to their section).
 *
 * The sweep is one-directional (placement truth, not completeness): many value
 * parts stay undocumented prose-side on purpose — C-1 is about wrong homes.
 * Scans src/ namespace lines only; no coupling to scripts/parallel-tests-* or
 * any CI manifest (lib-local suite).
 */
final class ReadmeNamespaceRosterTest extends TestCase
{
    private const ROOT = 'SugarCraft\\Dashboard\\';

    /** Top-level namespaces whose README tables are policed. */
    private const POLICED = [
        'Foundation', 'Layout', 'Plot', 'Components', 'Keys', 'State',
        'Position', 'Output', 'Module', 'Modules', 'Registry', 'Plugin',
    ];

    /** Floor: proves the parser sees the shipped tables (mutation discriminator). */
    private const MIN_POLICED_ROWS = 280;

    public function testTheSweepIsNotVacuous(): void
    {
        $rows = self::rosterRows();
        self::assertGreaterThanOrEqual(
            self::MIN_POLICED_ROWS,
            $rows[0],
            'README roster parser found too few policed rows — headings or row shape changed'
        );
        self::assertGreaterThanOrEqual(350, count(self::truthTypes()), 'src type census imploded');
    }

    /**
     * @return array<string, array{string, array<int, array{string,int}>}>
     */
    public static function sectionProvider(): array
    {
        [$rows, $bySection] = self::rosterRows();
        unset($rows);
        $cases = [];
        foreach ($bySection as $section => $entries) {
            $cases[$section] = [$section, $entries];
        }

        return $cases;
    }

    /**
     * @param array<int, array{string,int}> $entries
     * @dataProvider sectionProvider
     */
    public function testEveryDocumentedTypeLivesInTheNamespaceItsTableClaims(string $section, array $entries): void
    {
        $truth = self::truthTypes();
        $aliases = self::aliasShims();
        $shortIndex = [];
        foreach ($truth as $fqn) {
            $shortIndex[substr($fqn, (int) strrpos($fqn, '\\') + 1)][] = $fqn;
        }

        $failures = [];
        foreach ($entries as [$token, $line]) {
            $resolved = false;
            if (str_contains($token, '\\')) {
                foreach ([$token, $section . '\\' . $token] as $candidate) {
                    if (in_array($candidate, $truth, true)) {
                        $resolved = true;
                        break;
                    }
                }
            } else {
                $expected = $section . '\\' . $token;
                if (in_array($expected, $truth, true) || in_array($expected, $aliases, true)) {
                    $resolved = true;
                } else {
                    foreach ($truth as $fqn) {
                        if (($fqn === $token || str_starts_with($fqn, $section . '\\'))
                            && substr($fqn, (int) strrpos($fqn, '\\') + 1) === $token) {
                            $resolved = true;
                            break;
                        }
                    }
                }
            }
            if (!$resolved) {
                $homes = $shortIndex[$token] ?? ['(no such type in src/)'];
                $failures[] = "line {$line}: `{$token}` claimed under {$section}, actual: " . implode(', ', $homes);
            }
        }
        self::assertSame([], $failures, "README namespace-table drift in [{$section}]:\n" . implode("\n", $failures));
    }

    /**
     * @return array{0: int, 1: array<string, array<int, array{string,int}>>}
     */
    private static function rosterRows(): array
    {
        $readme = file(__DIR__ . '/../README.md');
        if ($readme === false) {
            throw new \RuntimeException('README.md unreadable — namespace roster guard cannot run');
        }
        $section = null;
        $bySection = [];
        $count = 0;
        $policed = implode('|', self::POLICED);
        foreach ($readme as $index => $line) {
            $top = preg_match('/^## (' . $policed . ') Namespace\b/', (string) $line, $m);
            if ($top === 1) {
                $section = $m[1];
                $bySection[$section] ??= [];
                continue;
            }
            if (preg_match('/^## /', (string) $line) === 1) {
                $section = null; // non-namespace section (Usage Example, Example Demos, …)
                continue;
            }
            if ($section === null) {
                continue;
            }
            if (preg_match('/^### ((?:' . $policed . ')(?:\\\\[A-Za-z]+)+)/', (string) $line, $m) === 1) {
                $bySection[$m[1]] ??= [];
                $section = $m[1];
                continue;
            }
            if (preg_match('/^### /', (string) $line) === 1) {
                continue; // plain prose subsection, same namespace
            }
            if (preg_match('/^\|\s*`/', (string) $line) !== 1) {
                continue;
            }
            $firstCell = explode('|', rtrim((string) $line, "\n"), 3)[1] ?? '';
            if (preg_match_all('/`([^`]+)`/', $firstCell, $tokens) < 1) {
                continue;
            }
            foreach ($tokens[1] as $token) {
                if (preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/', $token) !== 1) {
                    continue; // method prose, sentences, lowercase fragments
                }
                $bySection[$section][] = [$token, $index + 1];
                $count++;
            }
        }

        return [$count, $bySection];
    }

    /**
     * Every declared type in src/, relative to the library root namespace.
     *
     * @return list<string>
     */
    private static function truthTypes(): array
    {
        $types = [];
        $root = (string) realpath(__DIR__ . '/../src');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $decl = '/^[ \t]*(?:final[ \t]+|abstract[ \t]+|readonly[ \t]+)*(?:class|interface|trait|enum)[ \t]+([A-Za-z0-9_]+)/m';
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $code, $m) !== 1) {
                continue;
            }
            $ns = trim($m[1]);
            if (!str_starts_with($ns, self::ROOT)) {
                continue;
            }
            $rel = substr($ns, strlen(self::ROOT));
            if (preg_match_all($decl, $code, $decls) >= 1) {
                foreach ($decls[1] as $name) {
                    $types[] = ($rel === '' ? '' : $rel . '\\') . $name;
                }
            }
        }
        sort($types);

        return array_values(array_unique($types));
    }

    /**
     * class_alias shim files (Foundation/Color.php, State/* re-exports) define a
     * namespace-visible name without declaring it — directory path spells it.
     *
     * @return list<string>
     */
    private static function aliasShims(): array
    {
        $shims = [];
        $root = (string) realpath(__DIR__ . '/../src');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (!str_contains((string) file_get_contents($file->getPathname()), 'class_alias(')) {
                continue;
            }
            $relDir = trim(substr(dirname($file->getPathname()), strlen($root . '/')), '/');
            $name = basename($file->getPathname(), '.php');
            $shims[] = ($relDir === '' || $relDir === '.' ? '' : $relDir . '\\') . $name;
        }

        return $shims;
    }
}
