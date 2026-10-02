<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\PromptFence;

/**
 * A launch from a repository SUBDIRECTORY is a launch inside the repository
 * (audit 15d-13a).
 *
 * `file_exists(cwd/.git)` alone rendered `cd repo/src && sugarcrush` as
 * "Is directory a git repo: No" and dropped the whole git section. The fix
 * asks `git rev-parse --show-toplevel` — but only when the cwd has no `.git`
 * of its own, and only once per block — so these tests also count git
 * invocations through a PATH shim that logs each one and then runs the real
 * git.
 *
 * GIT_CEILING_DIRECTORIES is pinned to the temp root so git's discovery can
 * never walk out of the fixture into some repository that happens to enclose
 * the temp directory, which would make the "outside any repo" cases lie.
 */
final class EnvironmentBlockSubdirectoryTest extends TestCase
{
    private string $base;

    private string $shimDir;

    private string $realGit;

    private string|false $originalPath;

    private string|false $originalCeiling;

    protected function setUp(): void
    {
        $realGit = trim((string) shell_exec('command -v git 2>/dev/null'));
        if ($realGit === '') {
            self::markTestSkipped('git is unavailable in this environment');
        }
        $this->realGit = $realGit;

        $tmp = (string) realpath(sys_get_temp_dir());
        $this->base = $tmp . '/env_subdir_' . uniqid((string) getmypid(), true);
        $this->shimDir = $this->base . '/bin';
        mkdir($this->shimDir, 0777, true);

        $this->originalPath = getenv('PATH');
        $this->originalCeiling = getenv('GIT_CEILING_DIRECTORIES');
        putenv('GIT_CEILING_DIRECTORIES=' . $tmp);
    }

    protected function tearDown(): void
    {
        foreach (['PATH' => $this->originalPath, 'GIT_CEILING_DIRECTORIES' => $this->originalCeiling] as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        if (isset($this->base) && is_dir($this->base)) {
            shell_exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    public function testASubdirectoryOfARepoIsReportedAsInsideTheRepo(): void
    {
        $root = $this->committedRepo($this->base . '/repo');
        mkdir($root . '/sub');
        file_put_contents($root . '/tracked.txt', "changed\n");

        $out = (new EnvironmentBlock($root . '/sub', 'm'))->render();

        self::assertStringContainsString(
            "Working directory: {$root}/sub (repo root: {$root})\nIs directory a git repo: Yes\n",
            $out,
        );
        self::assertStringContainsString("Current branch: main\n", $out);
        self::assertStringContainsString("Status:\n M tracked.txt\n", $out);
        self::assertStringContainsString("Recent commits:\n", $out);
        self::assertStringContainsString(' initial', $out);
        self::assertStringContainsString('Unstaged changes (git diff, working tree vs index):', $out);
        self::assertStringContainsString('+changed', $out);
    }

    public function testTheRepoRootRendersWithoutARootSuffixAndRunsNoExtraGit(): void
    {
        $root = $this->committedRepo($this->base . '/repo');
        $this->installLoggingShim();

        $out = (new EnvironmentBlock($root, 'm'))->withWriteSinceLastRender(false)->render();

        self::assertStringStartsWith("<env>\nWorking directory: {$root}\nIs directory a git repo: Yes\n", $out);
        self::assertStringNotContainsString('repo root', $out);
        // branch, status, log — the three reads the suppressed-diff render
        // always made; the `.git` fast path adds no `rev-parse`.
        self::assertSame(3, \count($this->gitCalls()));
        self::assertSame([], $this->gitCalls('rev-parse'));
    }

    public function testADirectoryOutsideAnyRepoStillSaysNoAndProbesOnlyOnce(): void
    {
        $plain = $this->base . '/plain';
        mkdir($plain);
        $this->installLoggingShim();

        $block = new EnvironmentBlock($plain, 'm');
        $first = $block->render();
        $second = $block->render();
        // The Runtime derives a fresh copy per step through this wither, so
        // the memo must travel with it or every step would probe again.
        $third = $block->withWriteSinceLastRender(false)->render();

        foreach ([$first, $second, $third] as $out) {
            self::assertStringContainsString("Working directory: {$plain}\nIs directory a git repo: No\n", $out);
            self::assertStringNotContainsString('Current branch:', $out);
        }
        self::assertCount(1, $this->gitCalls());
        self::assertCount(1, $this->gitCalls('rev-parse --show-toplevel'));
    }

    public function testAFenceClosingRepoRootNameArrivesEscaped(): void
    {
        // Two legal components, `x<` and `env>y`, whose join carries `</env>`.
        $root = $this->committedRepo($this->base . '/x</env>y');
        mkdir($root . '/sub');

        $out = (new EnvironmentBlock($root . '/sub', 'm'))->withWriteSinceLastRender(false)->render();

        self::assertStringContainsString('(repo root: ' . PromptFence::escape($root) . ")\n", $out);
        self::assertStringContainsString('x&lt;/env>y', $out);
        self::assertSame(1, substr_count($out, '</env>'), 'the repo root closed the fence');
        self::assertStringContainsString("Is directory a git repo: Yes\n", $out);
    }

    public function testATimedOutProbeIsReportedAsSuchAndNotRetried(): void
    {
        $plain = $this->base . '/plain';
        mkdir($plain);
        $shim = $this->shimDir . '/git';
        file_put_contents($shim, "#!/bin/sh\n"
            . 'printf \'%s\n\' "$*" >> ' . escapeshellarg($this->shimDir . '/calls') . "\n"
            . "sleep 30\n");
        chmod($shim, 0755);
        $this->prependShimToPath();

        $block = new EnvironmentBlock($plain, 'm');
        $start = microtime(true);
        $out = $block->render();
        $block->render();

        self::assertLessThan(3.0, microtime(true) - $start, 'the probe was not bounded, or was retried');
        self::assertStringContainsString("Is directory a git repo: unavailable (git timed out after 2s)\n", $out);
        self::assertStringNotContainsString('Current branch:', $out);
        self::assertCount(1, $this->gitCalls());
    }

    /** A real repository on branch `main` with one commit; returns its path. */
    private function committedRepo(string $dir): string
    {
        mkdir($dir, 0777, true);
        $git = escapeshellarg($this->realGit) . ' -C ' . escapeshellarg($dir)
            . ' -c user.email=crush@example.test -c user.name=crush';
        shell_exec("{$git} init -q -b main 2>/dev/null");
        file_put_contents($dir . '/tracked.txt', "original\n");
        shell_exec("{$git} add tracked.txt 2>/dev/null");
        shell_exec("{$git} commit -q -m initial 2>/dev/null");

        if (trim((string) shell_exec("{$git} rev-parse --verify -q HEAD 2>/dev/null")) === '') {
            self::markTestSkipped('could not build a git fixture repository');
        }

        return $dir;
    }

    /** A `git` first on PATH that logs its argv, then runs the real one. */
    private function installLoggingShim(): void
    {
        $shim = $this->shimDir . '/git';
        file_put_contents($shim, "#!/bin/sh\n"
            . 'printf \'%s\n\' "$*" >> ' . escapeshellarg($this->shimDir . '/calls') . "\n"
            . 'exec ' . escapeshellarg($this->realGit) . " \"\$@\"\n");
        chmod($shim, 0755);
        $this->prependShimToPath();
    }

    private function prependShimToPath(): void
    {
        putenv('PATH=' . $this->shimDir . ':' . ($this->originalPath === false ? '/usr/bin:/bin' : $this->originalPath));
    }

    /** @return list<string> logged git argv lines, optionally only those containing $needle */
    private function gitCalls(string $needle = ''): array
    {
        $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($this->shimDir . '/calls'))));

        return array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, $needle)));
    }
}
