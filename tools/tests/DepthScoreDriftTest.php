<?php

declare(strict_types=1);

/**
 * Guard for docs/DEPTH-SCORES.md (plan_doc.md section 3.1, PD-P5-A).
 *
 * THE TABLE MUST REPRODUCE FROM ITS OWN HEADER. The header records the run date
 * and the tree the scores came from, so this guard reads both values from the
 * committed file and regenerates the table from them. It never reads today's
 * date or the working tree: a table is current when the scorer, run on the
 * commit it names, writes exactly this file. Editing a body file, adding a lib
 * or changing the scorer makes the table stale, and this guard names the rows
 * that no longer match.
 *
 * The regeneration reads the header commit through git, so the commit must be
 * present in the clone. A shallow checkout that lacks it fails here, loudly,
 * rather than passing on a table it could not check.
 *
 * HOW TO RUN: `phpunit --no-configuration tools/tests/` (CI), or locally
 * `sugar-core/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
 * --no-configuration tools/tests/DepthScoreDriftTest.php`.
 */

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../depth-score.php';

final class DepthScoreDriftTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function committed(): string
    {
        $path = self::root() . '/docs/DEPTH-SCORES.md';
        self::assertFileExists($path, 'docs/DEPTH-SCORES.md is missing');

        return (string) file_get_contents($path);
    }

    /** @return array{0: string, 1: string} committed text, regenerated text from its header */
    private static function regenerated(): array
    {
        $committed = self::committed();
        $header = \DepthScore::parseHeader($committed);
        $sha = \DepthScore::resolveCommit(self::root(), $header['tree']);

        return [$committed, \DepthScore::generate(self::root(), $sha, $header['runDate'])];
    }

    /** @return list<string> slugs with a docs/_data body at the header commit, minus candy-code */
    private static function gradedSlugsAtHeaderTree(string $tree): array
    {
        $cmd = sprintf(
            'git -C %s ls-tree --name-only --full-tree %s docs/_data/',
            escapeshellarg(self::root()),
            escapeshellarg($tree),
        );
        exec($cmd, $lines, $code);
        self::assertSame(0, $code, 'git ls-tree failed for the header tree');

        $slugs = [];
        foreach ($lines as $line) {
            if (preg_match('#^docs/_data/((?:sugar|candy)-[a-z0-9-]+)\.body\.html$#', $line, $m) === 1
                && $m[1] !== 'candy-code') {
                $slugs[] = $m[1];
            }
        }
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    public function testCommittedTableIsTheScorerOutputForItsHeader(): void
    {
        [$committed, $generated] = self::regenerated();

        self::assertSame(
            [],
            \DepthScore::staleRows($committed, $generated),
            'docs/DEPTH-SCORES.md is stale. Regenerate with: php tools/depth-score.php '
            . '--run-date=<the header run date> --tree=<the header tree>',
        );
        self::assertSame($generated, $committed);
    }

    public function testTableRowsAreTheGradedSlugsAtTheHeaderTree(): void
    {
        $committed = self::committed();
        $header = \DepthScore::parseHeader($committed);
        $sha = \DepthScore::resolveCommit(self::root(), $header['tree']);

        $rowSlugs = [];
        foreach (explode("\n", $committed) as $line) {
            if (preg_match('/^\| ([a-z0-9-]+) \| (?:library|app) \|/', $line, $m) === 1) {
                $rowSlugs[] = $m[1];
            }
        }
        sort($rowSlugs, SORT_STRING);

        self::assertSame(
            self::gradedSlugsAtHeaderTree($sha),
            $rowSlugs,
            'the table rows must be every sugar-* and candy-* slug with a docs/_data body, minus candy-code',
        );
        self::assertMatchesRegularExpression(
            '/Slugs: ' . count($rowSlugs) . ' \(/',
            $committed,
            'the header slug count must match the number of rows',
        );
    }

    public function testAStaleRowIsReportedByItsSlug(): void
    {
        [$committed, $generated] = self::regenerated();

        $slug = '';
        $stale = (string) preg_replace_callback(
            '/^(\| ([a-z0-9-]+) \| \w+ \| )([0-9.]+)/m',
            static function (array $m) use (&$slug): string {
                $slug = $m[2];

                return $m[1] . '0.1';
            },
            $committed,
            1,
        );
        self::assertNotSame('', $slug, 'the fixture needs a table row to make stale');
        self::assertNotSame($generated, $stale);
        self::assertSame([$slug], \DepthScore::staleRows($stale, $generated));
    }

    public function testAStaleHeaderIsReported(): void
    {
        [$committed, $generated] = self::regenerated();

        $stale = str_replace('Run date: ', 'Run date: 1999-01-01 ', $committed);
        self::assertContains('header', \DepthScore::staleRows($stale, $generated));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function halfEvenCases(): iterable
    {
        // Python's round(x, 1) takes the exact binary value and breaks exact ties to even.
        yield 'exact tie to even (0.25)' => [0.25, 0.2];
        yield 'exact tie to even (0.75)' => [0.75, 0.8];
        yield 'binary below the tie (1.15)' => [1.15, 1.1];
        yield 'binary above the tie (0.05)' => [0.05, 0.1];
        yield 'binary below the tie (0.35)' => [0.35, 0.3];
        yield 'already one decimal' => [62.5, 62.5];
        yield 'integer' => [100.0, 100.0];
        yield 'zero' => [0.0, 0.0];
        yield 'tie to odd rounds up (2.75)' => [2.75, 2.8];
    }

    /** @dataProvider halfEvenCases */
    public function testRoundingMatchesPythonHalfToEven(float $input, float $expected): void
    {
        self::assertSame($expected, \DepthScore::roundOneDecimal($input));
    }

    public function testParseHeaderRejectsADocumentWithoutTheHeaderLine(): void
    {
        $this->expectException(\RuntimeException::class);
        \DepthScore::parseHeader("# Depth scores\n\nno header here\n");
    }
}
