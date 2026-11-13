<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the launcher's one irreplaceable job: hand a picked endpoint off
 * to the real `pcntl_exec()` with the exact argv the recorder tests only
 * simulate, arriving with a clean fd table.
 *
 * Covers -044 (the exec handoff was never proven in a subprocess — every
 * other test swaps the executor for a recorder) and -081 (fd hand-off
 * census: nothing long-lived may survive into ssh(1)). The ENOEXEC
 * failure leg (-080's exit-3 OS reason) already ships in
 * BinCliTest::testUnexecutableSshBinaryExitsThreeWithTheOsReason and is
 * deliberately not duplicated here.
 *
 * The success leg types Enter into a real picker on a pty (`script -qec`,
 * the BinCliTest shape) with `--ssh` pointed at a bash stub. pcntl_exec
 * replaces the PHP image; the stub records its received argv and its
 * inherited fd table to a sentinel file, then exits 0. The census pins
 * today's clean-by-construction state — config bytes are read through
 * file_get_contents (closed at read), and only the PHP main-script
 * handle survives the exec — so the next boot-path stream that forgets
 * to close reddens this suite instead of silently leaking a config path
 * into every ssh session.
 */
final class ExecHandoffTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-ssh-launcher';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    public function testHandoffDeliversTheRealArgvWithANoSecretsFdTable(): void
    {
        if (!is_dir('/proc/self/fd')) {
            // 2026-11-12: the fd census reads /proc/$$/fd inside the stub;
            // macOS has no /proc. The argv half would run there, but the
            // two halves are one observed handoff — keeping them together
            // avoids a second pty spawn for half a pin.
            $this->markTestSkipped('the fd hand-off census needs /proc (Linux)');
        }

        $dir = $this->makeTempDir();
        $cfg = $dir . '/wishlist.yml';
        file_put_contents($cfg, "- name: hand\n  host: h.test\n  port: 2200\n  user: ops\n");

        // The stub is what pcntl_exec becomes. It lists the fd table it
        // inherited (its OWN /proc/$$/fd — post-replacement that IS the
        // launcher's hand-off state) plus the argv the kernel delivered.
        $stub = $dir . '/stub.sh';
        // Nowdoc: every $ belongs to bash, not PHP.
        $stubBody = <<<'BASH'
#!/bin/bash
{ for f in /proc/$$/fd/*; do n=${f##*/}; t=$(readlink "$f" 2>/dev/null); printf 'FD %s %s\n' "$n" "$t"; done; printf 'ARG %s\n' "$0"; for a in "$@"; do printf 'ARG %s\n' "$a"; done; } > "$SENTINEL"
BASH;
        file_put_contents($stub, $stubBody . "\n");
        chmod($stub, 0755);
        $sentinel = $dir . '/sentinel.txt';

        [$code, $output] = $this->runUnderPty(
            ['--config', $cfg, '--ssh', $stub],
            $sentinel,
        );

        $this->assertSame(0, $code, 'pty output: ' . $output);
        $this->assertFileExists($sentinel, 'the replaced image must have run — pcntl_exec hand-off happened');

        $lines = file($sentinel, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $argv = [];
        $fds = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'ARG ')) {
                $argv[] = substr($line, 4);
            } elseif (preg_match('/^FD (\d+) (.*)$/', $line, $m) === 1) {
                $fds[(int) $m[1]] = $m[2];
            }
        }

        // (a) argv — exactly what toSshArgv builds, kernel-prepended with
        // the image path: the recorder pins proved the shape, this proves
        // the wire actually carries it through exec.
        $this->assertSame(
            [$stub, '-p', '2200', '--', 'ops@h.test'],
            $argv,
            'hand-off argv must equal the recorded argv',
        );

        // (b) fd census — the config file (and every scratch fixture that
        // could leak its name into ssh's inherited table) must be closed
        // before dispatch. The two scratch paths that legitimately appear
        // are the stub's own image fds, opened by bash AFTER the handoff:
        // the script handle (measured fd 255) and the sentinel redirect
        // (fd 1, replaced for the group). Anything else pointing at the
        // scratch dir is a boot-path leak.
        foreach ($fds as $num => $target) {
            if ($num === 1 || $target === $sentinel || $target === $stub) {
                continue;
            }
            $this->assertStringNotContainsString(
                $dir,
                $target,
                "fd {$num} leaked a boot-path resource into the hand-off: {$target}",
            );
        }
        $this->assertArrayHasKey(0, $fds);
        $this->assertArrayHasKey(2, $fds);

        // (c) the documented survivor — PHP keeps the main script open and
        // pcntl_exec inherits it (measured 2026-11-12: fd 5 -> the bin).
        // Pinned so the Launcher docblock invariant stays true-by-evidence:
        // a second handle at the bin (or the whole handle vanishing) means
        // the boot path changed what survives, and that deserves a look.
        $binHandles = array_keys(array_filter($fds, static fn (string $t): bool => $t === realpath(self::BIN)));
        $this->assertCount(1, $binHandles, 'exactly the PHP main-script handle may survive at the bin path');
    }

    /**
     * Run the bin on a pty of its own via util-linux `script -qec` (the
     * candy-tetris BinaryLaunchTest shape): -e propagates the child's exit
     * code and the typescript is discarded. Enter is typed once the first
     * picker frame is on screen — earlier keystrokes can be flushed by the
     * raw-mode switch — and re-sent until the child exits. SENTINEL is
     * exported so the exec-replacement stub can find where to record.
     *
     * @param list<string> $args
     * @return array{0:int,1:string} exit code and everything the pty showed
     */
    private function runUnderPty(array $args, string $sentinel): array
    {
        $binary = realpath(self::BIN);
        $this->assertNotFalse($binary);
        $inner = 'stty rows 24 cols 80 2>/dev/null; exec '
            . implode(' ', array_map('escapeshellarg', [PHP_BINARY, $binary, ...$args]));
        $process = proc_open(
            'exec script -qec ' . escapeshellarg($inner) . ' /dev/null',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            [
                'PATH' => '/usr/bin:/bin',
                'HOME' => \sys_get_temp_dir(),
                'TERM' => 'xterm-256color',
                'LC_ALL' => 'C',
                'LANG' => 'C',
                'SENTINEL' => $sentinel,
            ],
        );
        $this->assertIsResource($process, 'failed to spawn script(1) — is util-linux installed?');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 20.0;
        $nextKeyAt = null;
        $output = '';
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (str_contains($output, "\x1b[1;1H")) {
                $nextKeyAt ??= microtime(true) + 0.3;
                if (microtime(true) >= $nextKeyAt) {
                    fwrite($pipes[0], "\r");
                    $nextKeyAt = microtime(true) + 1.0;
                }
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                $this->fail('hand-off did not complete within the deadline: ' . $output);
            }
            usleep(20_000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);

        return [$exitCode, $output];
    }

    private function makeTempDir(): string
    {
        $dir = \sys_get_temp_dir() . '/sshl-exec-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
