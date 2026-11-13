<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CODE-TEST-01: a fail-closed class-to-test coverage FLOOR over the whole
 * `src/` tree.
 *
 * Every census guard in this suite polices a named surface that already
 * exists; none of them notices a brand-new public class that ships with zero
 * tests. This one does. A class under `src/` that has public methods must
 * either
 *
 *  1. have a mirror test file (`<ClassName>Test.php`, in any directory), or
 *  2. be named by at least one test file other than this guard (the
 *     collaborator-style coverage most of this suite relies on), or
 *  3. carry a row in {@see UNCOVERED_ROSTER} with the reason it is accepted.
 *
 * WHAT THIS DOES NOT CLAIM. Rule 2 is a reference floor, not a coverage
 * measurement: a class mentioned in one test is not thereby well tested. It
 * moves the failure mode from "nobody noticed the new class had no test at
 * all" to "a reviewer sees the roster row grow". Line coverage stays the
 * coverage job's business.
 *
 * THE ROSTER IS BIDIRECTIONAL (the SuiteSkipRoster pattern): an uncovered
 * class without a row is red, and a row whose class has since gained a test
 * or been deleted is red too, so the list only ever shrinks.
 *
 * ADJUDICATION, 2026-11-13: the 64 rows seeded that day were all retired by
 * mirror tests; the roster is empty. New code does not get a row by default:
 * write the mirror test.
 */
final class ClassTestFloorCensusTest extends TestCase
{
    /**
     * `src/`-relative path (no `.php`) of every class accepted without a
     * mirror test or a naming reference, as of 2026-11-13.
     *
     * @var list<string>
     */
    private const UNCOVERED_ROSTER = [];

    public function testEveryClassWithPublicMethodsHasATestOrARosterRow(): void
    {
        [$uncovered] = $this->census();

        $new = array_values(array_diff($uncovered, self::UNCOVERED_ROSTER));
        sort($new);
        $this->assertSame([], $new, "CODE-TEST-01: these classes have no mirror test and no test names them — write one, or (with a reason) add a roster row:\n" . implode("\n", $new));
    }

    public function testTheRosterHoldsNoStaleRow(): void
    {
        [$uncovered, $all] = $this->census();

        $stale = [];
        foreach (self::UNCOVERED_ROSTER as $row) {
            if (!isset($all[$row])) {
                $stale[] = $row . ' (no such class with public methods any more)';
            } elseif (!in_array($row, $uncovered, true)) {
                $stale[] = $row . ' (a test now covers it)';
            }
        }

        $this->assertSame([], $stale, "CODE-TEST-01: delete these roster rows:\n" . implode("\n", $stale));
        $this->assertSame(self::UNCOVERED_ROSTER, array_values(array_unique(self::UNCOVERED_ROSTER)), 'no duplicate rows');
    }

    /**
     * @return array{0: list<string>, 1: array<string, true>} uncovered rows, every census row
     */
    private function census(): array
    {
        $root = dirname(__DIR__, 2);
        $self = __FILE__;

        $mirrors = [];
        $words = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || $file->getPathname() === $self) {
                continue;
            }
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $mirrors[substr($file->getFilename(), 0, -8)] = true;
            }
            preg_match_all('/\b[A-Z][A-Za-z0-9_]+\b/', (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[0] as $word) {
                $words[$word] = true;
            }
        }

        $uncovered = [];
        $all = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class)
                || !preg_match('/public (?:static )?function (?!__construct)/', $source)) {
                continue;
            }
            $row = substr($file->getPathname(), strlen($root . '/src/'), -4);
            $all[$row] = true;
            if (!isset($mirrors[$class[1]]) && !isset($words[$class[1]])) {
                $uncovered[] = $row;
            }
        }

        return [$uncovered, $all];
    }
}
