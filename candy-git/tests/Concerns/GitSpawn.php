<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests\Concerns;

/**
 * Shell-free git fixture spawns (candy-git-123).
 *
 * Fixture setup used to concatenate `exec('git -C … 2>/dev/null')` strings:
 * POSIX-shaped, cmd.exe-hostile (single quotes survive, `2>/dev/null` writes
 * a file named `dev/null`), and already half-mothballed by the F12b
 * `nullSink()` shim. Spawning `git` through a raw argv vector skips the
 * shell entirely, so quoting semantics are identical on every runner —
 * the fixture either works on Windows CI or fails LOUD, never silently
 * no-ops the way a swallowed cmd error did.
 */
trait GitSpawn
{
    /**
     * Run git with the given argv tail. Never throws: the exit code and both
     * streams come back for the caller to assert on (or ignore).
     *
     * @param list<string> $args
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function gitSpawn(array $args): array
    {
        $stdout = '';
        $stderr = '';
        $proc   = proc_open(
            ['git', ...array_values($args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        \assert(\is_resource($proc));

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Run git and REQUIRE success: a fixture step that silently failed is the
     * exact disease this trait was born to cure, so a non-zero exit raises
     * with the captured stderr instead of let the later assertion confuse it.
     *
     * @param list<string> $args
     */
    private static function gitMust(array $args): void
    {
        $r = self::gitSpawn($args);
        if ($r['exit'] !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . " failed ({$r['exit']}): {$r['stderr']}");
        }
    }

    /**
     * Run git and hand back the trimmed stdout (stderr ignored — success is
     * asserted, same contract as {@see self::gitMust()}).
     *
     * @param list<string> $args
     */
    private static function gitStdout(array $args): string
    {
        $r = self::gitSpawn($args);
        if ($r['exit'] !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . " failed ({$r['exit']}): {$r['stderr']}");
        }

        return trim($r['stdout']);
    }

    /** Skip when even a bare `git --version` cannot spawn (identical semantics to the exec() probe it replaces). */
    private function requireGitSpawnable(): void
    {
        if (self::gitSpawn(['--version'])['exit'] !== 0) {
            $this->markTestSkipped('git not available');
        }
    }
}
