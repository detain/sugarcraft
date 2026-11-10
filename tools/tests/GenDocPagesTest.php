<?php

declare(strict_types=1);

/**
 * Guard for `tools/gen-doc-pages.php` (scsite-1110 L3, stage S3).
 *
 * The generator renders the five allow-listed Markdown documents into site
 * chrome whose nav block must stay byte-identical to the canonical one, and it
 * rewrites rendered hrefs so bodies point at site pages instead of repo paths.
 * Both behaviours are pinned here on a fixture tree — never by re-rendering the
 * real docs/ (that is the CI drift leg's job via --check). The repo-itself arm
 * runs --check against the shipped pages: if a Markdown source changes without
 * regeneration, or the generator's chrome drifts, this file reddens.
 *
 * HOW TO RUN: see the long note at the top of CheckPathReposTest.php —
 * `sugar-core/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
 * --no-configuration tools/tests` is the dev-box shape.
 */

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';

final class GenDocPagesTest extends TestCase
{
    use TmpTree;

    private const NAV_START = '<!--nav:start-->';
    private const NAV_END = '<!--nav:end-->';

    /** Memoised answer to "can the tool render in this environment?". */
    private static ?bool $commonmarkReady = null;

    /**
     * THE FIVE RENDERING ARMS NEED league/commonmark, and commonmark exists
     * ONLY inside a vendor autoload: sugar-markdown is the one package whose
     * manifest requires it, and the CI `tools/ guards` job runs
     * checkout + setup-php + a PHPUnit PHAR with no composer install at all.
     * There, the tool genuinely cannot render — the failing thing would be the
     * environment, not drift — so the arms skip LOUD (`Skipped: 5` in the
     * summary, never a silently-green guard). The probe asks the TOOL itself
     * via --can-render, which resolves through the very findAutoloader() the
     * render path uses, so the skip predicate cannot drift from reality. The
     * cure that makes these arms actually run in CI is a vendor step on the
     * job (`composer install -d sugar-markdown --no-progress`), which is a
     * .github/workflows change owned by another lane — see the LANE-D
     * handoff row. Help-text and skip-logic arms always run.
     */
    private function requireCommonmark(): void
    {
        if (self::$commonmarkReady === null) {
            $run = $this->runScript(\dirname(__DIR__, 2), ['--can-render']);
            self::$commonmarkReady = $run['exit'] === 0;
        }
        if (!self::$commonmarkReady) {
            self::markTestSkipped(
                'gen-doc-pages cannot render here: league/commonmark is not autoloadable'
                . ' (--can-render exited 1). The CI tools-guards job installs no vendor, so the'
                . ' rendering arms cannot distinguish drift from a missing dependency. Give the'
                . ' job `composer install -d sugar-markdown` to exercise this guard for real;'
                . ' until then it is SKIPPED loudly, never green by silence.',
            );
        }
    }

    /** @var list<string> */
    private const PAGES = [
        'GETTING-STARTED',
        'GLOSSARY',
        'KEYMAPS',
        'TRUST-BOUNDARY',
    ];

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('gen-doc-pages-');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testTheShippedPagesMatchTheirMarkdownSources(): void
    {
        $this->requireCommonmark();
        $run = $this->runScript(\dirname(__DIR__, 2), ['--check']);
        $this->assertSame(0, $run['exit'], 'generated pages drifted: ' . $run['output']);
        $this->assertStringContainsString('match their Markdown sources', $run['output']);
    }

    public function testTheHelpTextIsNotEmpty(): void
    {
        $run = $this->runScript($this->root(), ['--help']);
        $this->assertSame(0, $run['exit']);
        $this->assertNotSame('', \trim($run['output']));
        $this->assertStringContainsString('gen-doc-pages', $run['output']);
    }

    public function testTheFixtureRootRendersFiveChromeBearingPages(): void
    {
        $this->requireCommonmark();
        $root = $this->makeFixture();
        $run = $this->runScript($root, ['--root=' . $root]);
        $this->assertSame(0, $run['exit'], $run['output']);

        foreach (self::PAGES as $stem) {
            $this->assertFileExists("$root/docs/$stem.html");
        }
        $this->assertFileExists("$root/docs/cookbook/index.html");

        // The nav contract: whatever sits between the canonical markers in the
        // fixture index must appear byte-identical in every rendered page.
        $canonical = $this->between("$root/docs/index.html");
        $this->assertNotSame('', $canonical);
        foreach ([...array_map(fn (string $s): string => "$root/docs/$s.html", self::PAGES), "$root/docs/cookbook/index.html"] as $page) {
            $this->assertSame($canonical, $this->between($page), "nav drift in $page");
        }

        // Heading permalinks mint ids usable as fragment targets.
        $this->assertMatchesRegularExpression('/<h2[^>]*>\s*<a id="runtime-vocabulary"/', (string) \file_get_contents("$root/docs/GLOSSARY.html"));
    }

    public function testRenderedBodiesPointAtSitePagesNotRepoPaths(): void
    {
        $this->requireCommonmark();
        $root = $this->makeFixture();
        $this->assertSame(0, $this->runScript($root, ['--root=' . $root])['exit']);
        $body = (string) \file_get_contents("$root/docs/GETTING-STARTED.html");

        $this->assertStringContainsString('href="/lib/alpha.html"', $body);
        // A README anchor carried over to a generated lib page is inert (those
        // pages mint no heading ids), so the fragment must be dropped.
        $this->assertStringNotContainsString('/lib/alpha.html#', $body);
        $this->assertStringContainsString('href="https://github.com/detain/sugarcraft/blob/master/alpha/src/X.php"', $body);
        $this->assertStringContainsString('href="https://github.com/detain/sugarcraft/blob/master/nope/x"', $body);
        $this->assertStringContainsString('href="https://github.com/detain/sugarcraft/blob/master/AGENTS.md"', $body);
        $this->assertStringContainsString('href="https://github.com/detain/sugarcraft/blob/master/docs/MATCHUPS.md"', $body);
        // Rendered siblings keep their fragments; they DO carry heading ids.
        $this->assertStringContainsString('href="/GLOSSARY.html#runtime-vocabulary"', $body);
        $this->assertStringContainsString('href="/cookbook/index.html"', $body);
        // Untouched shapes: remote schemes, same-page anchors, docs-local files.
        $this->assertStringContainsString('href="https://example.com/docs"', $body);
        $this->assertStringContainsString('href="#top"', $body);
        $this->assertStringContainsString('href="./x.php"', $body);
        // No repo-escape href survives a rewrite.
        $this->assertStringNotContainsString('href="../', $body);
    }

    public function testCheckIsCleanThenOneByteOfTamperTurnsItRed(): void
    {
        $this->requireCommonmark();
        $root = $this->makeFixture();
        $this->assertSame(0, $this->runScript($root, ['--root=' . $root])['exit']);

        $first = $this->runScript($root, ['--root=' . $root, '--check']);
        $this->assertSame(0, $first['exit'], $first['output']);

        \file_put_contents("$root/docs/GLOSSARY.html", 'x', FILE_APPEND);
        $second = $this->runScript($root, ['--root=' . $root, '--check']);
        $this->assertSame(1, $second['exit']);
        $this->assertStringContainsString('out of date', $second['output']);
        $this->assertStringContainsString('GLOSSARY.html', $second['output']);
    }

    public function testAMissingSourceFailsLoudInsteadOfSilentlySkipping(): void
    {
        $this->requireCommonmark();
        $root = $this->makeFixture();
        unlink("$root/docs/KEYMAPS.md");
        $run = $this->runScript($root, ['--root=' . $root]);
        $this->assertSame(1, $run['exit']);
        $this->assertStringContainsString('KEYMAPS', $run['output']);
    }

    private function root(): string
    {
        return $this->tmpDir;
    }

    private function makeFixture(): string
    {
        $root = $this->root();
        \mkdir("$root/docs/_data", 0777, true);
        \mkdir("$root/docs/cookbook", 0777, true);
        \mkdir("$root/docs/css", 0777, true);
        \mkdir("$root/docs/js", 0777, true);

        \file_put_contents("$root/docs/index.html", <<<'HTML'
            <link rel="stylesheet" href="css/style.css">
            <!--nav:start-->
            <nav class="nav"><div class="nav-links"><a href="/lib/alpha.html">alpha</a></div></nav>
            <!--nav:end-->
            HTML);

        \file_put_contents("$root/docs/_data/alpha.json", '{"title":"Alpha","description":"a library","category":"library"}');

        \file_put_contents("$root/docs/GETTING-STARTED.md", <<<'MD'
            # Getting started

            <a id="top"></a>

            [lib](../alpha/)
            [readme](../alpha/README.md#keys)
            [source](../alpha/src/X.php)
            [nope](../nope/x)
            [agents](../AGENTS.md)
            [matchups](MATCHUPS.md)
            [glossary](GLOSSARY.md#runtime-vocabulary)
            [cookbook](/cookbook/README.md)
            [remote](https://example.com/docs)
            [same-page](#top)
            [recipe](./x.php)
            MD);
        \file_put_contents("$root/docs/GLOSSARY.md", "# Glossary\n\n## Runtime vocabulary\n\nterms\n");
        \file_put_contents("$root/docs/KEYMAPS.md", "# Keymaps\n\nkeys\n");
        \file_put_contents("$root/docs/TRUST-BOUNDARY.md", "# Trust\n\nboundaries\n");
        \file_put_contents("$root/docs/cookbook/README.md", "# Cookbook\n\n[a](./spinner.php)\n");
        \file_put_contents("$root/docs/css/style.css", 'body{}');

        return $root;
    }

    /** Bytes between the canonical markers (exclusive), of a real file. */
    private function between(string $file): string
    {
        $html = (string) \file_get_contents($file);
        $start = \strpos($html, self::NAV_START);
        $end = \strpos($html, self::NAV_END);
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        return \substr($html, $start + \strlen(self::NAV_START), $end - $start - \strlen(self::NAV_START));
    }

    /**
     * @param list<string> $args
     * @return array{exit:int,output:string}
     */
    private function runScript(string $root, array $args = []): array
    {
        $cmd = \escapeshellarg(\PHP_BINARY)
            // escapeshellarg, not escapeshellcmd: an interpreter installed
            // under a path with a space must stay one argv entry (the same
            // trap GenDocsTest documents).
            . ' ' . \escapeshellarg(__DIR__ . '/../gen-doc-pages.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . \escapeshellarg($arg);
        }
        $output = [];
        $exitCode = 0;
        \exec($cmd . ' 2>&1', $output, $exitCode);
        return ['exit' => $exitCode, 'output' => \implode("\n", $output)];
    }
}
