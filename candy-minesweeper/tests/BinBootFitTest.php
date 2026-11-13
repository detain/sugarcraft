<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Boot-fit contract (candy-minesweeper-001, critical) on the REAL binary,
 * under a `script -qec` pty of a known size (the candy-tetris
 * BinaryLaunchTest shape). The refusal must come before the alt screen is
 * entered: a too-small launch exits 1 with a plain message and paints no
 * frame; a fitting launch boots and quits cleanly.
 */
final class BinBootFitTest extends TestCase
{
    private const DEADLINE_SECONDS = 15.0;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/msw-binfit-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home . '/.config/candy-minesweeper/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->home . '/.config/candy-minesweeper');
        @rmdir($this->home . '/.config');
        @rmdir($this->home);
    }

    /**
     * @param list<string> $args
     * @return array{0:int, 1:string}
     */
    private function runUnderPty(array $args, int $rows, int $cols, ?string $quitKey = null): array
    {
        $binary = realpath(__DIR__ . '/../bin/candy-minesweeper');
        self::assertNotFalse($binary);
        $inner = "stty rows {$rows} cols {$cols} 2>/dev/null; exec "
            . implode(' ', array_map('escapeshellarg', [\PHP_BINARY, $binary, ...$args]));
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => $this->home,
            'TERM' => 'xterm-256color',
            'LC_ALL' => 'C',
            'LANG' => 'C',
        ];
        $process = proc_open(
            'exec script -qec ' . escapeshellarg($inner) . ' /dev/null',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            $environment,
        );
        self::assertIsResource($process, 'failed to spawn script(1) — is util-linux installed?');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        $nextKeyAt = null;
        $exitCode = -1;
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if ($quitKey !== null && str_contains($output, "\x1b[?1049h")) {
                $nextKeyAt ??= microtime(true) + 0.3;
                if (microtime(true) >= $nextKeyAt) {
                    fwrite($pipes[0], $quitKey);
                    $nextKeyAt = microtime(true) + 1.0;
                }
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                self::fail('candy-minesweeper under a pty did not exit within the deadline: ' . $this->printable($output));
            }
            usleep(20_000);
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$exitCode, $output];
    }

    private function printable(string $raw): string
    {
        return preg_replace('/\x1b\[[0-9;?]*[a-zA-Z]/', '', $raw) ?? $raw;
    }

    public function testTooSmallTerminalRefusesBootWithPlainExitOne(): void
    {
        // EASY needs 17 rows; 12 offered → refuse BEFORE the alt screen.
        [$code, $output] = $this->runUnderPty(['--easy'], 12, 60);
        self::assertSame(1, $code, 'output was: ' . $this->printable($output));
        self::assertStringContainsString('needs a terminal', $output);
        self::assertStringContainsString('17', $output);
        self::assertStringNotContainsString("\x1b[?1049h", $output, 'refusal must not enter the alt screen');
    }

    public function testFittingTerminalBootsAndQuits(): void
    {
        [$code, $output] = $this->runUnderPty(['--easy'], 30, 80, 'q');
        self::assertStringNotContainsString('needs a terminal', $output);
        self::assertStringContainsString("\x1b[?1049h", $output, 'fitting boot must enter the alt screen');
        self::assertSame(0, $code, 'output was: ' . $this->printable($output));
    }
}
