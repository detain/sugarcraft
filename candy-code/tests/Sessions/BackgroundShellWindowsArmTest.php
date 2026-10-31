<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\WindowsShell;

/**
 * C-TUI-6.a: the background registry's Windows arm, EXEC-tested as data.
 *
 * On an injected Windows family `start()` must (a) refuse cleanly when no
 * bash.exe resolves — before ANY sink file or row exists — and (b) spawn
 * the wrapper as the ARRAY argv [bash.exe, '-c', wrapped], which the
 * recording fixture proves byte-intact through `SHELL_INNER=` including the
 * `%PATH%` and `!x` that cmd.exe-style quoting would have blanked. The
 * `grouped` flag stays false on that host (no setsid there — the honest
 * degradation the design calls for), and the POSIX leg re-proves the
 * historic spawn path moved nothing.
 *
 * Liveness is read by polling `status()`, never single-shot after a pipe
 * drains (the documented flake family, inherited discipline from
 * BackgroundShellRegistryTest).
 */
final class BackgroundShellWindowsArmTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/w6a-bgreg-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        WindowsShell::setOsFamilyForTesting(null);
        WindowsShell::setEnvSeamForTesting(null);
        $this->clearTree($this->root);
        @rmdir($this->root);
    }

    public function testWindowsStartSpawnsTheWrapperThroughTheBashArgvArrayIntact(): void
    {
        $marker = $this->root . '/argv.marker';
        $bin = $this->root . '/gitbin';
        mkdir($bin, 0o700, true);
        $bash = $bin . '/bash.exe';
        // The recording fixture: it prints its argc and every argument into
        // the marker and exits — it does NOT run the wrapper, so no .exit
        // file ever appears, and the state stays honestly 'running' until
        // teardown reaps. The point is what the exec carried, not what ran.
        file_put_contents(
            $bash,
            "#!/bin/bash\n"
            . '{ printf "argc=%s\n" "$#"; for a in "$@"; do printf "arg=[%s]\n" "$a"; done; } >> "$W6A_ARGV_MARKER"' . "\n"
            . "exit 0\n"
        );
        chmod($bash, 0o755);
        $this->armWindowsPath($bin);

        $registry = BackgroundShellRegistry::forSession('w6a-windows-arm', $this->root);
        $started = $registry->start('echo %PATH% !x', 'arm-label', ['W6A_ARGV_MARKER' => $marker]);

        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];
        self::assertGreaterThan(0, (int) $started['shell']['pid']);
        // The grouped flag records what the SPAWN got, from the live detach
        // probe — which on this POSIX runner wraps even an injected-Windows
        // array spawn, so the honest value here is groupsSpawns(); the
        // Windows-host leg of that same flag (prefix [], grouped false) is
        // pinned in ProcessContainmentPhpSetsidTest, where the family guard
        // skips the probe outright. Never a derived guess.
        self::assertSame(ProcessContainment::groupsSpawns(), $started['shell']['grouped'], 'the row records the kernel truth at spawn time');

        self::assertTrue($this->pollRegistry($registry, fn (): bool => is_file($marker)), 'the fixture never recorded its argv');
        $recorded = (string) file_get_contents($marker);
        self::assertStringContainsString('argc=2', $recorded, 'the spawn was [bash.exe, -c, script] — three tokens, two arguments');
        self::assertStringContainsString('arg=[-c]', $recorded);
        // The wrapper rode the argv whole: the SHELL_INNER assignment with
        // its single-quoted payload — `%` and `!` still there because the
        // quoting is the RECEIVER's now, and the user command visible
        // inside it — the exact bytes cmd.exe would have blanked.
        self::assertStringContainsString('SHELL_INNER=', $recorded);
        self::assertStringContainsString('%PATH%', $recorded);
        self::assertStringContainsString('!x', $recorded);

        // Teardown kills+reaps the fixture (already exited; the reap is the
        // point) and takes its rows with it.
        $registry->teardown();
    }

    public function testWindowsStartWithNoBashRefusesBeforeAnySinkFileOrRowExists(): void
    {
        $this->armWindowsPath($this->root . '/no-such-bin');

        $registry = BackgroundShellRegistry::forSession('w6a-windows-none', $this->root);
        $started = $registry->start('echo hi', 'missing-bash');

        self::assertFalse($started['ok']);
        self::assertStringContainsString('Git for Windows', (string) $started['error']);
        self::assertCount(0, $registry->ids(), 'the refusal lands before any row is minted');
        // The sink directory is minted by path preparation, which the
        // refusal precedes — so the honest assertion is that the directory
        // never even appears (and scandir on a missing path would itself
        // trigger a PHP warning, which failOnWarning rightly forbids).
        self::assertFileDoesNotExist($registry->directoryPath());
    }

    public function testPosixLegSettlesWithItsHistoricBytes(): void
    {
        // Family unset: the plain string spawnSpec path, unchanged by every
        // edit in this step — the real wrapper runs, records its exit code,
        // and its stdout reads back exactly as promised.
        WindowsShell::setOsFamilyForTesting(null);

        $registry = BackgroundShellRegistry::forSession('w6a-posix-leg', $this->root);
        $started = $registry->start('printf bg-posix-ok', 'posix');
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $settled = $this->pollRegistry($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished');
        self::assertTrue($settled, 'the POSIX shell never settled within the budget');
        self::assertSame(0, (int) ($registry->status($id)['exitCode'] ?? -1));
        $tail = $registry->read($id);
        self::assertStringContainsString('bg-posix-ok', (string) $tail['stdout']);

        $registry->teardown();
    }

    private function armWindowsPath(string $pathDir): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');
        $vars = [
            'PATH' => $pathDir,
            'PATHEXT' => '.exe',
            'ProgramFiles' => $this->root . '/no-pf',
        ];
        WindowsShell::setEnvSeamForTesting(static fn (string $name): ?string => $vars[$name] ?? null);
    }

    /**
     * @param callable(BackgroundShellRegistry): bool $predicate
     */
    private function pollRegistry(BackgroundShellRegistry $registry, callable $predicate, float $budgetSeconds = 8.0): bool
    {
        $deadline = microtime(true) + $budgetSeconds;
        while (true) {
            if ($predicate($registry)) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(20_000);
        }
    }

    private function clearTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->clearTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}
