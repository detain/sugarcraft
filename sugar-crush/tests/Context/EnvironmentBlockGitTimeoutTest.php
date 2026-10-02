<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\EnvironmentBlock;

/**
 * A git that does not answer must not hold the prompt hostage (audit 15d-14).
 *
 * render() runs its git reads synchronously before every request. The
 * fixture puts a `git` shim first on PATH so the slowness is deterministic:
 * the shim records its pid and sleeps 30 s for the commands it is told to
 * stall on, standing in for a huge or network-mounted repository or a slow
 * `core.fsmonitor` hook.
 */
final class EnvironmentBlockGitTimeoutTest extends TestCase
{
    private const TIMEOUT_TEXT = 'unavailable (git timed out after 2s)';

    private string $dir;

    private string $shimDir;

    private string|false $originalPath;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/env_git_timeout_' . uniqid((string) getmypid(), true);
        $this->dir = $base . '/repo';
        $this->shimDir = $base . '/bin';
        mkdir($this->dir . '/.git', 0777, true);
        mkdir($this->shimDir, 0777, true);
        $this->originalPath = getenv('PATH');
    }

    protected function tearDown(): void
    {
        if ($this->originalPath === false) {
            putenv('PATH');
        } else {
            putenv('PATH=' . $this->originalPath);
        }

        $base = \dirname($this->dir);
        foreach ([$this->dir . '/.git', $this->dir, $this->shimDir . '/git', $this->shimDir . '/pids', $this->shimDir, $base] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    /**
     * @param string|null $stallOn the git subcommand that sleeps (null: every
     *                             one); the rest answer `main`
     */
    private function installShim(?string $stallOn): void
    {
        $stallPattern = $stallOn === null ? '*' : "*{$stallOn}*";
        $shim = $this->shimDir . '/git';
        file_put_contents($shim, "#!/bin/sh\n"
            . 'echo $$ >> ' . escapeshellarg($this->shimDir . '/pids') . "\n"
            . "case \"\$*\" in\n"
            . "  {$stallPattern}) sleep 30 ;;\n"
            . "  *) echo main ;;\n"
            . "esac\n");
        chmod($shim, 0755);
        putenv('PATH=' . $this->shimDir . ':' . ($this->originalPath === false ? '/usr/bin:/bin' : $this->originalPath));
    }

    /** @return list<int> */
    private function shimPids(): array
    {
        $raw = (string) @file_get_contents($this->shimDir . '/pids');

        return array_map('intval', array_values(array_filter(explode("\n", $raw))));
    }

    public function testAHungGitIsCutOffAndEveryGitFieldSaysSo(): void
    {
        $this->installShim(null);

        $start = microtime(true);
        $out = (new EnvironmentBlock($this->dir, 'm'))->render();
        $elapsed = microtime(true) - $start;

        self::assertLessThan(3.0, $elapsed, "render() waited on git for {$elapsed}s");
        self::assertStringContainsString("Current branch: " . self::TIMEOUT_TEXT . "\n", $out);
        self::assertStringContainsString("Status:\n" . self::TIMEOUT_TEXT . "\n", $out);
        self::assertStringContainsString("Recent commits:\n" . self::TIMEOUT_TEXT, $out);
        self::assertStringContainsString('(git diff --cached, index vs HEAD): ' . self::TIMEOUT_TEXT, $out);
        self::assertStringContainsString('(git diff, working tree vs index): ' . self::TIMEOUT_TEXT, $out);
        self::assertSame(5, substr_count($out, self::TIMEOUT_TEXT));
        self::assertStringNotContainsString('git exited', $out);

        // One expiry ends the render's git work: the later reads never ran.
        self::assertCount(1, $this->shimPids(), 'git was re-run after it had already timed out');
        foreach ($this->shimPids() as $pid) {
            self::assertTrue(self::gone($pid), "the timed-out git {$pid} was left running");
        }
    }

    public function testAFastBranchStillRendersWhenALaterReadTimesOut(): void
    {
        $this->installShim('status');

        $start = microtime(true);
        $out = (new EnvironmentBlock($this->dir, 'm'))->render();

        self::assertLessThan(3.0, microtime(true) - $start);
        self::assertStringContainsString("Current branch: main\n", $out);
        self::assertStringContainsString("Status:\n" . self::TIMEOUT_TEXT . "\n", $out);
        self::assertStringContainsString("Recent commits:\n" . self::TIMEOUT_TEXT, $out);
        self::assertSame(4, substr_count($out, self::TIMEOUT_TEXT));
        self::assertCount(2, $this->shimPids(), 'only branch and status should have run');
    }

    public function testAGitThatAnswersInTimeIsUnaffected(): void
    {
        $this->installShim('no-such-subcommand');

        $out = (new EnvironmentBlock($this->dir, 'm'))->render();

        self::assertStringNotContainsString('timed out', $out);
        self::assertStringContainsString("Current branch: main\n", $out);
        self::assertStringContainsString("Status:\nmain\n", $out);
        self::assertCount(5, $this->shimPids());
    }

    /** Gone, or a zombie awaiting a reaper this test does not own. */
    private static function gone(int $pid): bool
    {
        $deadline = microtime(true) + 3.0;
        do {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if ($stat === false ? !posix_kill($pid, 0) : preg_match('/\) Z /', $stat) === 1) {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        @posix_kill($pid, 9);

        return false;
    }
}
