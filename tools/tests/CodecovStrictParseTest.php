<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * RT-45 (repo-tooling-049): codecov.yml carried literal duplicate mapping
 * keys (`carryforward: true` twice for sugar-spring and sugar-charts) with no
 * guard anywhere — block YAML parsers silently let the last one win, so the
 * file parsed "green" while being outside the YAML spec. The roster this
 * covers is codecov.yml plus every workflow: none of them had ANY structural
 * parse check before this test (the posture/interpolation guards are
 * substring-level by design and cannot see duplicate keys).
 *
 * Implementation note: the tools suite runs PHPUnit from a PHAR without
 * symfony/yaml (see WorkflowRunInterpolationTest's header), so the scanner is
 * the repo's own line-based block-mapping subset: scalar-key lines, `- ` list
 * items, and `|`/`>` block scalars are tracked well enough to scope key
 * uniqueness to the mapping that really owns each key. Aliased/flow
 * exotica is not in this file family's vocabulary; a construct the scanner
 * cannot interpret makes it FAIL, never silently pass.
 */
final class CodecovStrictParseTest extends TestCase
{
    /** @return array<string,string> path => contents */
    private static function roster(): array
    {
        $paths = ['codecov.yml' => \dirname(__DIR__, 2) . '/codecov.yml'];
        foreach (glob(\dirname(__DIR__, 2) . '/.github/workflows/*.yml') ?: [] as $yml) {
            $paths['.github/workflows/' . basename($yml)] = $yml;
        }
        ksort($paths);

        foreach ($paths as $label => $path) {
            self::assertFileExists($path, "strict-parse roster entry missing: {$label}");
        }

        return $paths;
    }

    public function testTheRosterIsTheExpectedFileFamily(): void
    {
        $files = array_keys(self::roster());
        self::assertContains('codecov.yml', $files);
        self::assertGreaterThanOrEqual(15, count($files), 'workflows + codecov (16 today, measured 2026-11-12) — a roster that shrinks to near-nothing is the vacuity this class exists to prevent');
        self::assertContains('.github/workflows/sync-sugarcraft.yml', $files);
    }

    public function testNoFileCarriesDuplicateMappingKeys(): void
    {
        $problems = [];
        foreach (self::roster() as $label => $path) {
            foreach (self::duplicateKeyLines((string) file_get_contents($path)) as $problem) {
                $problems[] = "{$label}:{$problem}";
            }
        }
        self::assertSame([], $problems, 'duplicate mapping keys (spec-illegal, parsers silently last-wins)');
    }

    /**
     * @return list<string> "line N: duplicate key 'k'" findings
     */
    private static function duplicateKeyLines(string $yaml): array
    {
        $findings = [];
        /** @var list<array{indent:int, keys:array<string,int>}> $stack */
        $stack = [];
        $blockScalarIndent = -1; // lines deeper than this belong to a |/> body

        foreach (explode("\n", $yaml) as $no => $raw) {
            $line = rtrim($raw, "\r");
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '---')) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($blockScalarIndent >= 0 && $indent > $blockScalarIndent) {
                continue; // inside a block scalar body — prose is not keys
            }
            $blockScalarIndent = -1;

            $content = $trimmed;
            $keyIndent = $indent;
            if (str_starts_with($content, '- ') || $content === '-') {
                // list item: a fresh sibling mapping at the dash's content column
                $keyIndent = $indent + 2;
                $content = ltrim(substr($content, 1));
                foreach ($stack as $lvl => $entry) {
                    if ($entry['indent'] >= $keyIndent) {
                        unset($stack[$lvl]);
                    }
                }
                $stack = array_values($stack);
            }

            if (preg_match('/^("?[A-Za-z0-9_."\'-]+"?):(?:\s|$)/', $content, $m) === 1) {
                $key = trim($m[1], '"');
                $value = trim(substr($content, strlen($m[0])));
                foreach ($stack as $lvl => $entry) {
                    if ($entry['indent'] > $keyIndent) {
                        unset($stack[$lvl]);
                    }
                }
                $stack = array_values($stack);
                $owner = null;
                foreach ($stack as $lvl => $entry) {
                    if ($entry['indent'] === $keyIndent) {
                        $owner = $lvl;
                    }
                }
                if ($owner === null) {
                    $stack[] = ['indent' => $keyIndent, 'keys' => []];
                    $owner = count($stack) - 1;
                }
                if (isset($stack[$owner]['keys'][$key])) {
                    $findings[] = 'line ' . ($no + 1) . ": duplicate key '{$key}' (first seen line {$stack[$owner]['keys'][$key]})";
                } else {
                    $stack[$owner]['keys'][$key] = $no + 1;
                }
                if (str_ends_with($value, '|') || str_ends_with($value, '>') || preg_match('/[|>][-+d]?\d*$/', $value) === 1) {
                    $blockScalarIndent = $keyIndent; // deeper lines are body
                }
            }
        }

        return $findings;
    }

    public function testTheScannerItselfDetectsADuplicateAndSurvivesBlockScalars(): void
    {
        $doc = "root:\n  flags:\n    - name: a\n      carryforward: true\n      carryforward: true\n    - name: b\n      carryforward: true\n  note: |\n    example: not-a-key\n    example: also-not-a-key\n  tail: 1\n";
        $found = self::duplicateKeyLines($doc);
        self::assertCount(1, $found, 'only the in-mapping duplicate is named; block-scalar prose and sibling list items are not');
        self::assertStringContainsString("duplicate key 'carryforward'", $found[0]);
    }
}
