<?php

declare(strict_types=1);

/**
 * Guard for `tools/check-lib-licenses.php` (ISS-120, sugar_issues.md).
 *
 * THE SHAPE OF THIS FILE follows ChildLifetimesToolTest: the green polarities
 * call the guard's derive()/inspect() on the REAL tree — a throwaway fixture
 * under /tmp can never satisfy the repository's own package roster, so
 * asserting exit 0 there would certify the wrong contract — while the red
 * polarities drive the CLI against fixture trees whose only job is to carry a
 * planted violation and prove it gets named with its exit code.
 *
 * The fixtures copy the REAL scripts/affected-libs.php into the temp tree on
 * purpose: the guard derives its package set with the same rule the sync
 * workflow uses, and a fixture that faked that enumerator would stop proving
 * the guard follows the workflow's roster.
 *
 * HOW TO RUN: `phpunit --no-configuration tools/tests/` (CI), or locally
 * `sugar-core/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
 * --no-configuration tools/tests`.
 */

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../check-lib-licenses.php';

final class CheckLibLicensesTest extends TestCase
{
    use TmpTree;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('lib_licenses_tool_');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * @param  list<string> $flags
     * @return array{0: string, 1: string, 2: int} stdout, stderr, exit code
     */
    private function runTool(string $root, array $flags = []): array
    {
        $handle = \proc_open(
            [PHP_BINARY, \dirname(__DIR__) . '/check-lib-licenses.php', '--root=' . $root, ...$flags],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root(),
        );
        $this->assertIsResource($handle);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [$stdout, $stderr, \proc_close($handle)];
    }

    /**
     * A fixture repo carrying the workflow's real enumerator, a root LICENSE,
     * and the named package dirs (composer.json + phpunit.xml = the discovery
     * rule). Nothing else — every caller plants its own violation.
     *
     * @param  list<string> $packages
     */
    private function fixtureTree(array $packages): string
    {
        $root = $this->tmpDir;
        $this->assertNotFalse(\mkdir($root . '/scripts'));
        $this->assertNotFalse(\copy(self::root() . '/scripts/affected-libs.php', $root . '/scripts/affected-libs.php'));
        $this->assertNotFalse(\copy(self::root() . '/LICENSE', $root . '/LICENSE'));
        foreach ($packages as $pkg) {
            $this->assertNotFalse(\mkdir($root . '/' . $pkg));
            \file_put_contents(
                $root . '/' . $pkg . '/composer.json',
                (string) \json_encode(['name' => 'sugarcraft/' . $pkg]),
            );
            \touch($root . '/' . $pkg . '/phpunit.xml');
        }

        return $root;
    }

    public function testTheRealTreeCarriesTheRootLicenseInEveryPackage(): void
    {
        $packages = \CheckLibLicenses::derivePackages(self::root());
        // A census that iterates nothing reports perfect compliance; the
        // floor keeps a broken enumeration from passing this arm vacuously.
        self::assertGreaterThanOrEqual(50, count($packages), 'the derived package set is implausibly small');
        self::assertSame([], \CheckLibLicenses::verdict(\CheckLibLicenses::inspect(self::root(), $packages)));
    }

    public function testTheCommandLinePassesOnTheRealRepo(): void
    {
        [$stdout, $stderr, $rc] = $this->runTool(self::root());
        self::assertSame(0, $rc, $stdout . $stderr);
        self::assertStringContainsString('0 problems', $stdout);
    }

    public function testAPackageWithoutALicenseIsNamedAndOnlyThatPackage(): void
    {
        $root = $this->fixtureTree(['lib-a', 'lib-b']);
        \copy($root . '/LICENSE', $root . '/lib-a/LICENSE');
        \copy($root . '/LICENSE', $root . '/lib-b/LICENSE');
        $this->assertNotFalse(\unlink($root . '/lib-a/LICENSE'));

        [$stdout, $stderr, $rc] = $this->runTool($root);
        self::assertSame(1, $rc, $stdout . $stderr);
        self::assertStringContainsString('MISSING lib-a/LICENSE', $stderr);
        self::assertStringNotContainsString('MISSING lib-b', $stderr);
    }

    public function testAPackageLicenseThatDriftedFromTheRootIsNamed(): void
    {
        $root = $this->fixtureTree(['lib-a']);
        \file_put_contents($root . '/lib-a/LICENSE', "Not the root license\n");

        [$stdout, $stderr, $rc] = $this->runTool($root);
        self::assertSame(1, $rc, $stdout . $stderr);
        self::assertStringContainsString('DRIFTED lib-a/LICENSE', $stderr);
    }

    public function testAnUpwardLinkInAPackageDocumentIsNamed(): void
    {
        $root = $this->fixtureTree(['lib-a']);
        \copy($root . '/LICENSE', $root . '/lib-a/LICENSE');
        // The exact sugar-pty shape: a badge that pointed one level up, which
        // the single-prefix split ships as a dangling reference.
        \file_put_contents($root . '/lib-a/README.md', "# lib-a\n\n[MIT](../LICENSE)\n");

        [$stdout, $stderr, $rc] = $this->runTool($root);
        self::assertSame(1, $rc, $stdout . $stderr);
        self::assertStringContainsString('UPWARD-LINK lib-a/README.md', $stderr);
    }

    public function testFixCopiesTheRootLicenseDownUntilTheTreeIsClean(): void
    {
        $root = $this->fixtureTree(['lib-a', 'lib-b']);
        \file_put_contents($root . '/lib-b/LICENSE', "stale bytes\n");

        [$stdout, $stderr, $rc] = $this->runTool($root, ['--fix']);
        self::assertSame(0, $rc, $stdout . $stderr);
        self::assertStringContainsString('into 2 package(s)', $stdout);
        $rootDigest = \md5_file($root . '/LICENSE');
        self::assertSame($rootDigest, \md5_file($root . '/lib-a/LICENSE'));
        self::assertSame($rootDigest, \md5_file($root . '/lib-b/LICENSE'));
    }

    public function testAnEmptyPackageSetRefusesToCertifyNothing(): void
    {
        $root = $this->fixtureTree([]);

        [, $stderr, $rc] = $this->runTool($root);
        self::assertSame(2, $rc, $stderr);
        self::assertStringContainsString('empty set', $stderr);
    }

    public function testAMissingEnumeratorRefusesToGuessTheRoster(): void
    {
        $root = $this->fixtureTree([]);
        $this->assertNotFalse(\unlink($root . '/scripts/affected-libs.php'));

        [, $stderr, $rc] = $this->runTool($root);
        self::assertSame(2, $rc, $stderr);
        self::assertStringContainsString('enumerator', $stderr);
    }

    public function testAMissingRootLicenseRefusesToCompareAgainstAbsence(): void
    {
        $root = $this->fixtureTree(['lib-a']);
        $this->assertNotFalse(\unlink($root . '/LICENSE'));

        [, $stderr, $rc] = $this->runTool($root);
        self::assertSame(2, $rc, $stderr);
        self::assertStringContainsString('root LICENSE', $stderr);
    }

    /**
     * Repo-wide sweep: no tracked markdown file anywhere in the monorepo may
     * link `](../LICENSE)` — that is the class of reference the split repos
     * inherit broken, and sugar-pty/README.md was the last one standing.
     *
     * The finder is proven non-blind with a known-positive: the same predicate
     * over a planted temp file must report it, so a broken read cannot pass
     * this arm vacuously.
     */
    public function testNoTrackedMarkdownCarriesAnUpwardLicenseLink(): void
    {
        $handle = \proc_open(
            ['git', 'ls-files', '-z'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root(),
        );
        $this->assertIsResource($handle);
        $listing = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        self::assertSame(0, \proc_close($handle), 'git ls-files must enumerate the tracked tree');

        $needle = \CheckLibLicenses::UPWARD_LINK;
        $scanned = 0;
        $offenders = [];
        foreach (explode("\0", $listing) as $path) {
            if ($path === '' || !str_ends_with($path, '.md')) {
                continue;
            }
            $text = @\file_get_contents(self::root() . '/' . $path);
            if (!\is_string($text)) {
                continue;
            }
            ++$scanned;
            if (str_contains($text, $needle)) {
                $offenders[] = $path;
            }
        }
        self::assertGreaterThan(100, $scanned, 'the sweep found almost no markdown to read — the finder is blind');
        self::assertSame([], $offenders, 'tracked docs still reference the parent LICENSE');

        // Known-positive: the predicate sees a planted link, so an empty
        // $offenders above means clean, not deaf.
        $probe = $this->tmpDir . '/probe.md';
        \file_put_contents($probe, "badge " . $needle . " here\n");
        self::assertStringContainsString($needle, (string) \file_get_contents($probe));
    }

    public function testTheHelpTextDescribesTheGuardAndExitsClean(): void
    {
        $handle = \proc_open(
            [PHP_BINARY, \dirname(__DIR__) . '/check-lib-licenses.php', '--help'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root(),
        );
        $this->assertIsResource($handle);
        $stdout = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        self::assertSame(0, \proc_close($handle));
        self::assertStringContainsString('check-lib-licenses', $stdout);
        self::assertStringContainsString('--fix', $stdout);
    }
}
