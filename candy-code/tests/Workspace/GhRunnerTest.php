<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workspace\GhRunner;

/**
 * C-AGT-6.d: the read-only host runner behind `@pr:<n>`. A fake `gh` shim on
 * PATH makes every answer deterministic — the recorded argv, the retained
 * bytes, the expiry and the environment grant are each pinned against it.
 * The shim idiom follows {@see \SugarCraft\Code\Tests\Context\EnvironmentBlockGitTimeoutTest}:
 * a directory first on PATH holding a `#!/bin/sh` script.
 */
final class GhRunnerTest extends TestCase
{
    private string $dir;

    private string $shimDir;

    private string|false $originalPath;

    /** @var list<string> */
    private array $putEnvKeys = [];

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/crush_gh_runner_' . uniqid((string) getmypid(), true);
        $this->dir = $base . '/repo';
        $this->shimDir = $base . '/bin';
        mkdir($this->dir, 0777, true);
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
        foreach ($this->putEnvKeys as $name) {
            putenv($name);
        }

        $base = \dirname($this->dir);
        foreach ([$this->shimDir . '/gh', $this->shimDir . '/args', $this->dir, $this->shimDir, $base] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    /**
     * A `gh` that records each argument on its own line and answers with the
     * pull-request diff. $stallOn makes the call whose joined arguments contain
     * it sleep instead of exiting 1; $envEcho answers with the two named
     * environment probes instead. (The stall marker must be one whitespace-free
     * token: it lands unquoted in a POSIX `case` pattern.)
     *
     * @param ?string $stallOn
     * @param ?array{0: string, 1: string} $envEcho
     */
    private function installGhShim(?string $stallOn = null, ?array $envEcho = null): void
    {
        $script = "#!/bin/sh\n"
            . 'printf "%s\n" "$@" >> ' . escapeshellarg($this->shimDir . '/args') . "\n";
        if ($envEcho !== null) {
            [$granted, $stripped] = $envEcho;
            $script .= 'echo "G=' . $granted . '=[${' . $granted . '-none}] S=' . $stripped . '=[${' . $stripped . '-none}]"\n';
        } elseif ($stallOn !== null) {
            $script .= "case \"\$*\" in\n"
                . "  *{$stallOn}*) sleep 30 ;;\n"
                . "  *) exit 1 ;;\n"
                . "esac\n";
        } else {
            $script .= "printf 'diff --git a/x b/x\\n+x\\n'\n";
        }
        file_put_contents($this->shimDir . '/gh', $script);
        chmod($this->shimDir . '/gh', 0755);
        putenv('PATH=' . $this->shimDir . ':' . ($this->originalPath === false ? '/usr/bin:/bin' : $this->originalPath));
    }

    private function recordedArgv(): string
    {
        return (string) @file_get_contents($this->shimDir . '/args');
    }

    public function testAvailabilityIsAPathWalkNotASpawn(): void
    {
        putenv('PATH=' . $this->shimDir);
        self::assertFalse(GhRunner::available(), 'a directory holding no gh must not read as one');

        $this->installGhShim();
        self::assertTrue(GhRunner::available());
        self::assertFileDoesNotExist($this->shimDir . '/args', 'available() spawned the shim');
    }

    public function testCaptureRunsTheWordsItIsGivenAndQuotesEachOne(): void
    {
        $this->installGhShim();

        $captured = GhRunner::new($this->dir)->capture(null, 'pr', 'diff', '12', '--color', 'never');

        self::assertSame(0, $captured['exitCode']);
        self::assertFalse($captured['timedOut']);
        self::assertStringContainsString('diff --git a/x b/x', $captured['stdout']);
        self::assertSame(
            "pr\ndiff\n12\n--color\nnever\n",
            $this->recordedArgv(),
            'the argv the runner assembles is the argv gh sees, one word per line'
        );

        $line = GhRunner::new($this->dir)->shellCommand('pr', 'diff', '12');
        self::assertStringStartsWith('exec gh ', $line);
        self::assertStringContainsString("'pr' 'diff' '12'", $line, 'every word is shell-quoted');
    }

    public function testABoundKeepsTheHeadOfTheOutputAndAccountsForTheRest(): void
    {
        $this->installGhShim();

        $captured = GhRunner::new($this->dir)->capture(10, 'pr', 'diff', '12', '--color', 'never');

        self::assertSame(0, $captured['exitCode']);
        self::assertLessThanOrEqual(10, \strlen($captured['stdout']));
        self::assertGreaterThan(0, $captured['stdoutDropped'], 'the cut must be reported, never silent');
    }

    public function testASlowHostIsCutOffAtTheBound(): void
    {
        $this->installGhShim(stallOn: '900');

        $start = microtime(true);
        $captured = GhRunner::new($this->dir)->withTimeout(0.2)->capture(null, 'pr', 'diff', '900');
        $elapsed = microtime(true) - $start;

        self::assertTrue($captured['timedOut']);
        self::assertSame(124, $captured['exitCode']);
        self::assertLessThan(5.0, $elapsed, "the bound did not cut the hung gh: {$elapsed}s");
    }

    public function testTheGrantCarriesTheGithubPairAndNothingElse(): void
    {
        $this->installGhShim(envEcho: ['GITHUB_TOKEN', 'ACME_SECRET']);
        $this->putEnvKeys = ['GITHUB_TOKEN', 'ACME_SECRET'];
        putenv('GITHUB_TOKEN=gh-session-token');
        putenv('ACME_SECRET=topsecret-value');

        $captured = GhRunner::new($this->dir)->capture(null, 'pr', 'diff', '7');

        self::assertSame(0, $captured['exitCode']);
        self::assertStringContainsString('GITHUB_TOKEN=[gh-session-token]', $captured['stdout'], 'R13: gh cannot reach the host without its own pair; the declaration is the grant');
        self::assertStringContainsString('ACME_SECRET=[none]', $captured['stdout'], 'every other credential-shaped name stays scrubbed');
    }

    public function testAnAbsentGhIsRefusedBeforeAnySpawn(): void
    {
        putenv('PATH=' . $this->shimDir);

        $runner = GhRunner::new($this->dir);
        self::assertFalse($runner->available());

        // The command line still exists to be inspected — building it never
        // resolves or runs anything.
        self::assertStringContainsString("'pr' 'diff'", $runner->shellCommand('pr', 'diff', '3'));
        self::assertFileDoesNotExist($this->shimDir . '/args');
    }
}
