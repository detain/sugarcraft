<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests\Support;

/**
 * Run the real binary on a pty of its own via util-linux `script -qec`
 * (the candy-tetris BinaryLaunchTest / candy-code BinInteractiveTtyGuard
 * shape). Since the no-TTY boot gate (candy-minesweeper-002) refuses
 * piped launches, every leg that must actually BOOT the Program runs
 * here; the pty is sized so both the fit budget and the alt-screen
 * contract hold. When $quitKey is given it is typed once the first
 * frame is on screen — earlier keystrokes could be flushed by the
 * switch to raw mode — and re-sent until the child exits.
 */
final class PtyLaunch
{
    public const DEADLINE_SECONDS = 15.0;

    /**
     * @param list<string>         $args
     * @param array<string,string> $extraEnv merged over the hermetic base env
     *
     * @return array{0:int, 1:string} exit code and everything the pty showed
     */
    public static function run(
        array $args,
        int $rows = 40,
        int $cols = 100,
        ?string $quitKey = null,
        array $extraEnv = [],
    ): array {
        $libRoot = \dirname(__DIR__, 2);
        $binary = realpath($libRoot . '/bin/candy-minesweeper');
        if ($binary === false) {
            throw new \RuntimeException('sanity: binary missing at ' . $libRoot . '/bin/candy-minesweeper');
        }
        $inner = "stty rows {$rows} cols {$cols} 2>/dev/null; exec "
            . implode(' ', array_map('escapeshellarg', [\PHP_BINARY, $binary, ...$args]));
        // Hermetic base: no LANG/LC_ALL reaches the child except the pinned
        // C locale (frame-copy assertions stay English), and HOME points at
        // the temp dir unless the leg overrides it.
        $environment = array_merge(
            [
                'PATH' => (string) getenv('PATH'),
                'HOME' => sys_get_temp_dir(),
                'TERM' => 'xterm-256color',
                'LC_ALL' => 'C',
                'LANG' => 'C',
            ],
            $extraEnv,
        );
        $process = proc_open(
            'exec script -qec ' . escapeshellarg($inner) . ' /dev/null',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $libRoot,
            $environment,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('failed to spawn script(1) — is util-linux installed?');
        }
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
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new \RuntimeException(
                    'candy-minesweeper under a pty did not exit within the deadline: ' . self::printable($output),
                );
            }
            usleep(20_000);
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$exitCode, $output];
    }

    /** Strip SGR/csi bytes so captured output is safe to embed in failures. */
    public static function printable(string $raw): string
    {
        return preg_replace('/\x1b\[[0-9;?]*[a-zA-Z]/', '', $raw) ?? $raw;
    }
}
