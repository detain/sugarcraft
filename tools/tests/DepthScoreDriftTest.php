<?php

declare(strict_types=1);

/**
 * Guard for docs/DEPTH-SCORES.md (PD-P5-A).
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
    use TmpTree;

    private const DEMO_BODY = "<h2>Usage</h2>\n<p>Foo runs.</p>\n";

    private const DEMO_FOO = "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo\n{\n}\n";

    private const DEMO_BAR = "<?php\n\ndeclare(strict_types=1);\n\nfinal class Bar\n{\n    public function run(): void\n    {\n    }\n}\n";

    /** @var list<string> fixture directories removed in tearDown() */
    private array $fixtureDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->fixtureDirs = [];
        parent::tearDown();
    }

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

    public function testAStaleProseSentenceIsReportedAsHeader(): void
    {
        [$committed, $generated] = self::regenerated();
        self::assertStringContainsString('Grades: A at 85 or more', $committed);

        $stale = str_replace('Grades: A at 85 or more', 'Grades: A at 90 or more', $committed);
        self::assertNotSame($committed, $stale);
        self::assertSame(['header'], \DepthScore::staleRows($stale, $generated));
    }

    public function testAStaleSlugCountIsReportedAsHeader(): void
    {
        [$committed, $generated] = self::regenerated();

        $stale = (string) preg_replace('/Slugs: \d+/', 'Slugs: 999', $committed, 1);
        self::assertNotSame($committed, $stale);
        self::assertSame(['header'], \DepthScore::staleRows($stale, $generated));
    }

    public function testBareCheckFailsOnAStaleProseSentenceAtOut(): void
    {
        $stale = str_replace('Grades: A at 85 or more', 'Grades: A at 90 or more', self::committed());
        $dir = $this->makeTmpTree('depth-score-prose-');
        $this->fixtureDirs[] = $dir;
        file_put_contents("$dir/DEPTH-SCORES.md", $stale);

        [$code] = $this->runCli(['--check', '--out=' . "$dir/DEPTH-SCORES.md"]);

        self::assertSame(1, $code, 'a stale sentence must fail --check just as it fails the drift test');
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

    public function testNewlinesAreNormalizedLikePythonTextMode(): void
    {
        $normalize = new \ReflectionMethod(\DepthScore::class, 'normalizeNewlines');

        self::assertSame("a\nb\nc\n\nd", $normalize->invoke(null, "a\r\nb\rc\n\r\nd"));
    }

    public function testOnlyVisiblePhpUnderSrcIsScored(): void
    {
        $visible = new \ReflectionMethod(\DepthScore::class, 'isVisiblePhp');

        self::assertTrue($visible->invoke(null, 'sugar-x/src/Sub/Deep.php', 'sugar-x/src/'));
        self::assertFalse($visible->invoke(null, 'sugar-x/src/.hidden/Bar.php', 'sugar-x/src/'));
        self::assertFalse($visible->invoke(null, 'sugar-x/src/Dir/.git/Hook.php', 'sugar-x/src/'));
        self::assertFalse($visible->invoke(null, 'sugar-x/src/.Hidden.php', 'sugar-x/src/'));
        self::assertFalse($visible->invoke(null, 'sugar-x/src/Notes.txt', 'sugar-x/src/'));
    }

    public function testHiddenPhpFilesDoNotCountTowardClassCoverage(): void
    {
        $plain = $this->fixtureRepo(['docs/_data/sugar-demo.body.html' => self::DEMO_BODY, 'sugar-demo/src/Foo.php' => self::DEMO_FOO]);
        $hidden = $this->fixtureRepo([
            'docs/_data/sugar-demo.body.html' => self::DEMO_BODY,
            'sugar-demo/src/Foo.php' => self::DEMO_FOO,
            'sugar-demo/src/.hidden/Bar.php' => self::DEMO_BAR,
        ]);
        // Control: the same class as a visible file must change the row, or the equality below proves nothing.
        $visible = $this->fixtureRepo([
            'docs/_data/sugar-demo.body.html' => self::DEMO_BODY,
            'sugar-demo/src/Foo.php' => self::DEMO_FOO,
            'sugar-demo/src/Bar.php' => self::DEMO_BAR,
        ]);

        $plainRow = $this->rowFor($plain, 'sugar-demo');
        self::assertSame($plainRow, $this->rowFor($hidden, 'sugar-demo'));
        self::assertNotSame($plainRow, $this->rowFor($visible, 'sugar-demo'));
    }

    public function testAllDigitTopLevelFileNameDoesNotBreakTheScorer(): void
    {
        $plain = $this->fixtureRepo(['docs/_data/sugar-demo.body.html' => self::DEMO_BODY, 'sugar-demo/src/Foo.php' => self::DEMO_FOO]);
        // PHP stores the name "2026" as an int array key; the scorer must still treat it as a path.
        $numeric = $this->fixtureRepo([
            'docs/_data/sugar-demo.body.html' => self::DEMO_BODY,
            'sugar-demo/src/Foo.php' => self::DEMO_FOO,
            '2026' => "notes\n",
        ]);

        self::assertSame($this->rowFor($plain, 'sugar-demo'), $this->rowFor($numeric, 'sugar-demo'));
    }

    public function testBareCheckPassesOnTheCommittedTable(): void
    {
        [$code, $out] = $this->runCli(['--check']);

        self::assertSame(0, $code);
        self::assertStringContainsString('is current', $out);
    }

    public function testBareCheckFailsOnAStaleFileAtOut(): void
    {
        $stale = (string) preg_replace('/^(\| ([a-z0-9-]+) \| \w+ \| )([0-9.]+)/m', '${1}0.1', self::committed(), 1);
        self::assertNotSame(self::committed(), $stale);
        $dir = $this->makeTmpTree('depth-score-stale-');
        $this->fixtureDirs[] = $dir;
        file_put_contents("$dir/DEPTH-SCORES.md", $stale);

        [$code] = $this->runCli(['--check', '--out=' . "$dir/DEPTH-SCORES.md"]);

        self::assertSame(1, $code);
    }

    public function testStdoutPrintsTheTableForTheNamedHeader(): void
    {
        $committed = self::committed();
        $header = \DepthScore::parseHeader($committed);

        [$code, $out] = $this->runCli(['--stdout', '--run-date=' . $header['runDate'], '--tree=' . $header['tree']]);

        self::assertSame(0, $code);
        self::assertSame($committed, $out);
    }

    public function testBareWriteRefusesToRepinAFileToAnotherTree(): void
    {
        $committed = self::committed();
        $runDate = \DepthScore::parseHeader($committed)['runDate'];
        $dir = $this->makeTmpTree('depth-score-repin-');
        $this->fixtureDirs[] = $dir;
        $file = "$dir/DEPTH-SCORES.md";
        $pinned = (string) preg_replace('/Tree: `[0-9a-f]{7,40}`/', 'Tree: `0000000`', $committed, 1);
        file_put_contents($file, $pinned);

        [$code] = $this->runCli(['--run-date=' . $runDate, '--out=' . $file]);

        self::assertSame(2, $code);
        self::assertSame($pinned, file_get_contents($file));

        [$code] = $this->runCli(['--run-date=' . $runDate, '--tree=HEAD', '--out=' . $file]);

        self::assertSame(0, $code, 'an explicit --tree is the way to re-pin the file');
        self::assertNotSame($pinned, file_get_contents($file));
    }

    public function testBareWriteAcceptsAFileAlreadyPinnedToTheScoredTree(): void
    {
        $runDate = \DepthScore::parseHeader(self::committed())['runDate'];
        $head = \DepthScore::parseHeader(\DepthScore::generate(self::root(), 'HEAD', $runDate))['tree'];
        $dir = $this->makeTmpTree('depth-score-same-pin-');
        $this->fixtureDirs[] = $dir;
        $file = "$dir/DEPTH-SCORES.md";
        file_put_contents($file, (string) preg_replace('/Tree: `[0-9a-f]{7,40}`/', 'Tree: `' . $head . '`', self::committed(), 1));

        [$code] = $this->runCli(['--run-date=' . $runDate, '--out=' . $file]);

        self::assertSame(0, $code);
    }

    /**
     * @param list<string> $args
     * @return array{int, string} exit code and captured stdout
     */
    private function runCli(array $args): array
    {
        ob_start();
        try {
            $code = \DepthScore::main(array_merge(['depth-score.php'], $args));
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    /** The one table line for a slug from a scorer run over a fixture repository. */
    private function rowFor(string $repo, string $slug): string
    {
        $document = \DepthScore::generate($repo, 'HEAD', '2026-11-20');
        self::assertMatchesRegularExpression('/^\| ' . preg_quote($slug, '/') . ' \|/m', $document);
        preg_match('/^\| ' . preg_quote($slug, '/') . ' \|.*$/m', $document, $m);

        return $m[0];
    }

    /**
     * A one-commit git repository holding exactly $files, so the scorer reads it the way it reads the monorepo.
     *
     * @param array<string|int, string> $files path => content
     */
    private function fixtureRepo(array $files): string
    {
        $dir = $this->makeTmpTree('depth-score-fixture-');
        $this->fixtureDirs[] = $dir;
        foreach ($files as $path => $content) {
            $full = $dir . '/' . $path;
            if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0777, true)) {
                throw new \RuntimeException("cannot create the fixture directory for $path");
            }
            file_put_contents($full, $content);
        }
        $git = 'git -C ' . escapeshellarg($dir);
        $identity = '-c user.name=test -c user.email=test@example.invalid -c commit.gpgsign=false';
        exec("$git init -q && $git add -A && $git $identity commit -q -m fixture 2>&1", $lines, $code);
        if ($code !== 0) {
            throw new \RuntimeException("fixture git commands failed ($code): " . implode("\n", $lines));
        }

        return $dir;
    }
}
