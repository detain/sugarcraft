<?php

declare(strict_types=1);

/**
 * Guard for `tools/check-docs-assets.php` (docs-fix-1023 G4, maintainer
 * persona proposal).
 *
 * Same discipline as ChildLifetimesToolTest: the polarity that matters most is
 * RED. A site-asset gate that stopped matching would certify a broken homepage
 * forever, so every leg gets a planted-defect fixture that must report and
 * NAME the offender, plus a whole-fixture green arm proving the legs are not
 * simply accusing everything. The repo-wide arm is the drift pin: if a demo
 * GIF is deleted, an icon left behind, or a body link mis-relativised, this
 * file reddens the `tools/ guards` job instead of visitors finding a 404.
 *
 * HOW TO RUN: see the long note at the top of CheckPathReposTest.php —
 * `sugar-core/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
 * --no-configuration tools/tests` is the dev-box shape.
 */

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../check-docs-assets.php';

final class CheckDocsAssetsTest extends TestCase
{
    use TmpTree;

    private const RAW = 'https://raw.githubusercontent.com/detain/sugarcraft/master/';

    /** 1×1 transparent PNG (base64), reused for every placeholder asset. */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M+wAAQAAv9uwSUNAAAAAElFTkSuQmCC';

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('check-docs-assets-');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testTheRepositoryItselfPassesEveryLeg(): void
    {
        $this->assertSame([], \CheckDocsAssets::derive(\dirname(__DIR__, 2)), 'the shipped docs site must be asset-clean');
    }

    public function testACoherentFixtureCarriesNoFindings(): void
    {
        $this->assertSame([], \CheckDocsAssets::derive($this->makeCleanFixture()));
    }

    public function testAnIconMissingFromTheSiteTreeIsNamed(): void
    {
        $root = $this->makeCleanFixture();
        unlink("$root/docs/img/icons/alpha.png");

        // The links leg legitimately accuses the two pages that referenced the
        // deleted file; this leg must ALSO stand up on its own, named first.
        $iconFindings = $this->filter($root, 'media/icons/alpha.png has no copy in docs/img/icons/');
        $this->assertCount(1, $iconFindings);
    }

    public function testAnOrphanSiteIconIsNamed(): void
    {
        $root = $this->makeCleanFixture();
        copy("$root/media/icons/alpha.png", "$root/docs/img/icons/ghost.png");

        $findings = \CheckDocsAssets::derive($root);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('docs/img/icons/ghost.png has no source in media/icons/', $findings[0]);
    }

    public function testADeadDemoGifReferenceIsNamedWithItsFile(): void
    {
        $root = $this->makeCleanFixture();
        unlink("$root/alpha/.vhs/d.gif");

        $this->assertCount(1, $this->filter($root, 'docs/index.html references missing alpha/.vhs/d.gif'));
        // The stat leg sees the vanished gif too — both arms must fire, never one mask the other.
        $this->assertCount(1, $this->filter($root, 'advertises 1 VHS demos, tree carries 0'));
    }

    public function testABodyLinkThatDoublePrefixesLibIsNamed(): void
    {
        $root = $this->makeCleanFixture();
        // The exact 404 class: a page under docs/lib/ that still says href="lib/…".
        $this->put("$root/docs/lib/alpha.html", str_replace(
            'href="../index.html"',
            'href="../index.html" <a href="lib/alpha.html">self</a>',
            (string) file_get_contents("$root/docs/lib/alpha.html"),
        ));

        $findings = \CheckDocsAssets::derive($root);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString("docs/lib/alpha.html points at missing 'lib/alpha.html'", $findings[0]);
    }

    public function testAWrongVhsStatNumeralIsNamed(): void
    {
        $root = $this->makeCleanFixture();
        $this->put("$root/docs/index.html", str_replace(
            '<span class="num">1</span><span class="label">VHS demos</span>',
            '<span class="num">281</span><span class="label">VHS demos</span>',
            (string) file_get_contents("$root/docs/index.html"),
        ));

        $findings = \CheckDocsAssets::derive($root);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('advertises 281 VHS demos, tree carries 1', $findings[0]);
    }

    public function testTheCliExitsOneAndPrintsEachFinding(): void
    {
        $root = $this->makeCleanFixture();
        unlink("$root/docs/img/icons/alpha.png");

        [$out, $err, $code] = $this->runTool($root);
        $this->assertSame(1, $code, "expected exit 1; stderr: $err");
        $this->assertStringContainsString('media/icons/alpha.png has no copy', $out);
        $this->assertStringContainsString('finding(s)', $out);
    }

    public function testTheCliExitsZeroOnACleanFixture(): void
    {
        [$out, $err, $code] = $this->runTool($this->makeCleanFixture());
        $this->assertSame(0, $code, "expected exit 0; stderr: $err");
        $this->assertStringContainsString('all in sync', $out);
    }

    public function testTheCliExitsTwoOnABogusRoot(): void
    {
        [, $err, $code] = $this->runTool('/nonexistent/docs-assets-fixture');
        $this->assertSame(2, $code);
        $this->assertStringContainsString('not a directory', $err);
    }

    /**
     * The classification arm of localTarget(): what is NOT a local file
     * reference must never be accused.
     */
    public function testNonLocalLinkShapesAreIgnored(): void
    {
        foreach ([
            'https://example.com/x.gif',
            'http://example.com/x.gif',
            '//cdn.example.com/x.gif',
            'mailto:a@b.c',
            'javascript:void(0)',
            'data:image/png;base64,AAA',
            '#section',
            '',
            '?v=2',
        ] as $raw) {
            $this->assertNull(\CheckDocsAssets::localTarget($raw), "must be ignored: $raw");
        }

        $this->assertSame('img/i.png', \CheckDocsAssets::localTarget('img/i.png?v=2#frag'));
        $this->assertSame('..', \CheckDocsAssets::localTarget('../'));
        $this->assertSame('css/style.css', \CheckDocsAssets::localTarget('css/style.css'));
    }

    private static function png(): string
    {
        $bytes = base64_decode(self::PNG_B64, true);
        \assert(\is_string($bytes));

        return $bytes;
    }

    /**
     * @return list<string> findings from $root containing $needle
     */
    private function filter(string $root, string $needle): array
    {
        return array_values(array_filter(
            \CheckDocsAssets::derive($root),
            static fn (string $f): bool => str_contains($f, $needle),
        ));
    }

    /**
     * A minimal but fully coherent site tree: one package `alpha`, matching
     * icons, one real demo gif referenced once, resolvable links, and a
     * truthful VHS stat.
     */
    private function makeCleanFixture(): string
    {
        $root = $this->tmpDir;
        foreach (['/media/icons', '/docs/img/icons', '/docs/lib', '/docs/_data', '/alpha/.vhs'] as $dir) {
            $this->put("$root$dir/index.keep", 'x');
        }

        $this->put("$root/media/icons/alpha.png", self::png());
        $this->put("$root/docs/img/icons/alpha.png", self::png());
        $this->put("$root/alpha/.vhs/d.gif", self::png());

        $this->put("$root/docs/index.html", '<html><body>'
            . '<span class="num">1</span><span class="label">VHS demos</span>'
            . '<a href="lib/alpha.html">x</a>'
            . '<img src="img/icons/alpha.png" alt="">'
            . '<img loading="lazy" src="https://sugarcraft.invalid/static/chrome.gif" alt="">'
            . '<img src="' . self::RAW . 'alpha/.vhs/d.gif" alt="">'
            . '</body></html>');
        $this->put("$root/docs/lib/alpha.html", '<html><body>'
            . '<img src="../img/icons/alpha.png" alt="">'
            . '<a href="../index.html">home</a>'
            . '</body></html>');

        return $root;
    }

    private function put(string $path, string $contents): void
    {
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException("could not create directory for $path");
        }
        file_put_contents($path, $contents);
    }

    private function runTool(string $root): array
    {
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__) . '/check-docs-assets.php', '--root=' . $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [$out, $err, \proc_close($process)];
    }
}
