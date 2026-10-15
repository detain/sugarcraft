<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Behaviour pins for scripts/refresh-deps.php's scratch-manifest restore.
 *
 * WHY THIS EXISTS (honey-flap pilot, rename plan Procedure R, 2026-10-15). <!-- @rename-keep -->
 * Linked mode injects path-repo `repositories[]` blocks into lib manifests as
 * scratch and then "discards" them. The discard used to be a
 * `git checkout --` over every first-level lib composer.json — a restore from
 * the INDEX. That equals "discard the scratch" only while no manifest carries
 * other uncommitted bytes.
 * During a rename it does not: R3's `git mv` stages the OLD manifest bytes at
 * the NEW path, the rewriter's edits sit unstaged on top, and R5's refresh-deps
 * checkout rolled those edits back mid-flight — the pilot's
 * candy-flappy/composer.json came out of R5 as pre-rename honey-flap bytes <!-- @rename-keep -->
 * despite the tool having exited 0 with the rewrite verified beforehand.
 *
 * The fix is a byte snapshot taken before injection and written back in the
 * `finally`. This test drives the REAL script (via the SUGARCRAFT_REFRESH_ROOT
 * hook, mirroring RenameLibTest's SUGARCRAFT_RENAME_ROOT idiom) against a
 * throwaway mini-repo that reproduces the exact shape: a committed manifest, a
 * rename-style uncommitted rewrite on top, and a stubbed `composer` on PATH so
 * no network or real dependency resolution is involved. The assertion that
 * goes red against the old restore is the preserved rewrite — a checkout from
 * the index would replace it with the committed bytes.
 */
final class RefreshDepsScratchRestoreTest extends TestCase
{
    use TmpTree;

    /** @var list<string> every temp dir made, all removed in tearDown */
    private array $dirs = [];

    private const RENAMED_MARKER = 'sugarcraft/renamed-uncommitted';

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeDir($dir);
        }
        $this->dirs = [];
    }

    public function testLinkedModeScratchDiscardKeepsUncommittedManifestEdits(): void
    {
        $root = $this->miniRepo();

        // The Procedure R shape: the tool rewrote the manifest in the working
        // tree after committing the move; the edit is UNCOMMITTED.
        $committed = (string) \file_get_contents("{$root}/foo/composer.json");
        $this->assertStringNotContainsString(self::RENAMED_MARKER, $committed, 'fixture sanity');
        \file_put_contents(
            "{$root}/foo/composer.json",
            \str_replace('"name": "sugarcraft/foo"', '"name": "' . self::RENAMED_MARKER . '"', $committed)
        );

        $run = $this->runRefresh($root);
        $this->assertSame(0, $run['rc'], $run['out']);

        $after = (string) \file_get_contents("{$root}/foo/composer.json");

        // PRIMARY PIN: the uncommitted rename survives the scratch discard.
        // The old `git checkout --` restore produced the committed bytes here
        // and this assert goes red.
        $this->assertStringContainsString(
            self::RENAMED_MARKER,
            $after,
            'linked mode clobbered an uncommitted composer.json edit — scratch restore '
            . 'must write back the pre-injection bytes, never check out from the index'
        );

        // The scratch injections themselves ARE discarded — that half of the
        // contract still holds (a published lib manifest carrying ../<dep> is
        // a hard fatal in a split-repo clone).
        $this->assertStringNotContainsString('"repositories"', $after);

        // Positive control against vacuity: the stub composer must have RUN
        // against an injected manifest, or nothing above tested anything.
        $seen = (string) @\file_get_contents("{$root}/composer-stub-saw.json");
        $this->assertStringContainsString('"repositories"', $seen, 'injection never happened — test would pass vacuously');
        $this->assertStringContainsString(self::RENAMED_MARKER, $seen);

        // Linked state ended on disk: the vendored sibling is a symlink.
        $this->assertDirectoryExists("{$root}/foo/vendor/sugarcraft/bar");
        $this->assertTrue(\is_link("{$root}/foo/vendor/sugarcraft/bar"));
    }

    public function testRefreshRootEnvRefusesNonDirectory(): void
    {
        $root = $this->miniRepo();
        $cmd = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg("{$root}/scripts/refresh-deps.php")
            . " --mode=linked --libs=foo 2>&1";
        $process = \proc_open(
            ['/bin/sh', '-c', $cmd],
            [1 => ['pipe', 'w']],
            $pipes,
            $root,
            $this->env($root, $root . '/nope-not-a-dir')
        );
        $this->assertIsResource($process);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $rc = \proc_close($process);

        $this->assertSame(2, $rc, $out);
        $this->assertStringContainsString('root is not a directory', $out);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    /**
     * Mini-repo: root manifest + two libs, `foo` requiring sibling `bar` at
     * dev-master. Committed clean, so any later `git checkout` of manifests
     * would visibly revert working-tree edits.
     */
    private function miniRepo(): string
    {
        $root = $this->makeTmpTree('refresh-deps-');
        $this->dirs[] = $root;

        $this->writeFile($root, 'composer.json', <<<'JSON'
            {"name":"sugarcraft/monorepo-fixture","require":{"sugarcraft/foo":"@dev","sugarcraft/bar":"@dev"},"repositories":[{"type":"path","url":"foo"},{"type":"path","url":"bar"}]}
            JSON . "\n");
        $this->writeFile($root, 'foo/composer.json', <<<'JSON'
            {
                "name": "sugarcraft/foo",
                "description": "fixture",
                "require": {
                    "php": "^8.3",
                    "sugarcraft/bar": "dev-master"
                }
            }
            JSON . "\n");
        $this->writeFile($root, 'bar/composer.json', <<<'JSON'
            {
                "name": "sugarcraft/bar",
                "description": "fixture"
            }
            JSON . "\n");

        // The real scripts, so the test pins shipped behaviour, not a mock.
        $this->writeFile($root, 'scripts/refresh-deps.php', (string) \file_get_contents(\dirname(__DIR__, 2) . '/scripts/refresh-deps.php'));
        $this->writeFile($root, 'tools/check-path-repos.php', (string) \file_get_contents(\dirname(__DIR__) . '/check-path-repos.php'));

        // Stub composer: no dependency resolution, but it MUST leave the lib
        // in the shape report() recognises as linked — vendor/sugarcraft/<dep>
        // symlinks — or the run ends PROBLEMS. It also records the manifest it
        // was invoked with, which is the injection positive control.
        $this->writeFile($root, 'stub/composer', <<<'SH'
            #!/bin/sh
            here="$PWD"
            if [ "$1" = "update" ]; then
                cp "$here/composer.json" "$here/../composer-stub-saw.json"
                php -r '
                    $m = json_decode(file_get_contents($argv[1]), true) ?: [];
                    foreach ($m["repositories"] ?? [] as $r) {
                        if (($r["type"] ?? "") !== "path" || !isset($r["url"])) { continue; }
                        $dep = basename($r["url"]);
                        $dir = dirname($argv[1]) . "/vendor/sugarcraft";
                        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
                        $link = $dir . "/" . $dep;
                        if (!is_link($link)) { @symlink("../../../" . $dep, $link); }
                    }
                ' "$here/composer.json"
                echo "stub composer ok\n"
                exit 0
            fi
            echo "stub composer: unsupported subcommand $1\n" >&2
            exit 1
            SH . "\n");
        \chmod("{$root}/stub/composer", 0o755);

        $this->git($root, 'init -q');
        $this->git($root, 'add -A');
        $this->git($root, 'commit -qm base');
        \copy("{$root}/foo/composer.json", "{$root}/foo/composer.json.orig");

        return $root;
    }

    /** @return array{rc:int, out:string} */
    private function runRefresh(string $root): array
    {
        $cmd = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg("{$root}/scripts/refresh-deps.php")
            . ' --mode=linked --libs=foo 2>&1';

        $process = \proc_open(
            ['/bin/sh', '-c', $cmd],
            [1 => ['pipe', 'w']],
            $pipes,
            $root,
            $this->env($root, $root)
        );
        $this->assertIsResource($process);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $rc = \proc_close($process);

        return ['rc' => $rc, 'out' => $out];
    }

    /** @return array<string, string> */
    private function env(string $root, string $refreshRoot): array
    {
        return [
            'PATH' => $root . '/stub:' . (string) \getenv('PATH') . ':/usr/local/bin:/usr/bin:/bin',
            'HOME' => $root,
            'LANG' => 'C',
            'SUGARCRAFT_REFRESH_ROOT' => $refreshRoot,
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
        ];
    }

    private function writeFile(string $root, string $rel, string $contents): void
    {
        $path = $root . '/' . $rel;
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o700, true);
        }
        \file_put_contents($path, $contents);
    }

    private function git(string $root, string $args): void
    {
        \exec(
            'git -c user.name=fixture -c user.email=fixture@example.com -C '
            . \escapeshellarg($root) . ' ' . $args . ' 2>&1',
            $output,
            $rc
        );
        $this->assertSame(0, $rc, 'git ' . $args . ' failed: ' . \implode("\n", $output));
    }
}
