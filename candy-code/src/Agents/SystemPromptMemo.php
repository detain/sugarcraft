<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Context\EnvironmentBlock;
use SugarCraft\Code\Workspace\GitRunner;

/**
 * Process-wide memo of a rendered `<env>` block (S-O06), so back-to-back stage
 * dispatches over an unchanged repository pay one fingerprint read instead of
 * the full branch/status/log render.
 *
 * Only a render whose bytes the fingerprint fully covers is ever served from
 * the memo: the git section must be present and the two diff sections must be
 * withheld (no write since the last render, or diffs disabled). A render that
 * carries diffs always re-polls — the freshness law forbids a stale diff, and
 * `status --porcelain=v2` cannot see a second edit to an already-modified file.
 *
 * The key is the block's non-git identity + cwd + a hash of
 * `git status --porcelain=v2 --branch` (HEAD oid, branch, every changed path)
 * + the `.git/index` mtime. A fingerprint that cannot be read cleanly (no
 * proc_open, timeout, non-zero exit, truncated output) bypasses the memo. A
 * short TTL bounds anything the key cannot see.
 */
final class SystemPromptMemo
{
    public const TTL_SECONDS = 30;
    private const MAX_ENTRIES = 16;
    private const FINGERPRINT_MAX_BYTES = 262144;

    /** @var array<string, array{at: int, rendered: string}> */
    private static array $entries = [];
    private static int $renders = 0;
    private static int $hits = 0;

    public static function render(EnvironmentBlock $env): string
    {
        $key = self::keyFor($env);
        if ($key === null) {
            self::$renders++;

            return $env->render();
        }

        $now = time();
        $hit = self::$entries[$key] ?? null;
        if ($hit !== null && $now - $hit['at'] <= self::TTL_SECONDS) {
            self::$hits++;

            return $hit['rendered'];
        }

        self::$renders++;
        $rendered = $env->render();
        self::$entries[$key] = ['at' => $now, 'rendered' => $rendered];
        if (\count(self::$entries) > self::MAX_ENTRIES) {
            array_shift(self::$entries);
        }

        return $rendered;
    }

    /** @return array{renders: int, hits: int} full render passes vs memo hits since the last reset */
    public static function stats(): array
    {
        return ['renders' => self::$renders, 'hits' => self::$hits];
    }

    public static function reset(): void
    {
        self::$entries = [];
        self::$renders = 0;
        self::$hits = 0;
    }

    private static function keyFor(EnvironmentBlock $env): ?string
    {
        if (!$env->includesVolatile() || ($env->writeSinceLastRender() && $env->diffsAfterWrites())) {
            return null;
        }
        if (!\function_exists('proc_open')) {
            return null;
        }

        $cwd = $env->cwd();
        $captured = GitRunner::new($cwd)->withInheritedGitEnv()->withTimeout(2.0)
            ->capture(self::FINGERPRINT_MAX_BYTES, 'status', '--porcelain=v2', '--branch');
        if ($captured['timedOut'] || $captured['exitCode'] !== 0 || $captured['truncatedBytes'] > 0) {
            return null;
        }

        clearstatcache(true, $cwd . '/.git/index');
        $indexMtime = @filemtime($cwd . '/.git/index') ?: 0;

        return hash('sha256', $env->memoIdentity() . "\0" . $captured['stdout'] . "\0" . $indexMtime);
    }
}
