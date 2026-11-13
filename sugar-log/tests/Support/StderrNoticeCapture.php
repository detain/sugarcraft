<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests\Support;

/**
 * [TEST-SL-01] Runs a child PHP process and captures the bytes it actually
 * writes to its process-bound STDOUT/STDERR.
 *
 * The lib's honesty contract — "a record that cannot land must be announced"
 * — is delivered through fwrite() to the process STDERR constant, so the only
 * way to assert the notice is to observe a real process STDERR. In-process
 * assertions cannot: redefining the STDERR constant is impossible, and the
 * phpunit runner's own stream is shared. A proc_open child with piped
 * descriptors gives each notice shape a hermetic byte-level pin
 * (house precedents: candy-vcr SrcExitCensusTest, sugar-dash golden child).
 */
final class StderrNoticeCapture
{
    /**
     * Execute $body inside a child that has autoloaded this library.
     *
     * @param string $body PHP statements (no opening tag) using fully
     *                     qualified class names; run after the require.
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function run(string $body): array
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = \sys_get_temp_dir() . '/sugar-log-notice-' . \uniqid('sl-', true) . '.php';
        \file_put_contents(
            $script,
            "<?php\ndeclare(strict_types=1);\nrequire " . \var_export($autoload, true) . ";\n" . $body . "\n",
        );

        try {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = \proc_open([\PHP_BINARY, $script], $descriptors, $pipes);
            if (!\is_resource($process)) {
                throw new \RuntimeException('proc_open failed to start the notice-capture child.');
            }

            \fclose($pipes[0]);
            $stdout = (string) \stream_get_contents($pipes[1]);
            \fclose($pipes[1]);
            $stderr = (string) \stream_get_contents($pipes[2]);
            \fclose($pipes[2]);
            $exitCode = \proc_close($process);

            return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        } finally {
            @\unlink($script);
        }
    }

    /**
     * Number of complete lines in $bytes ("\n"-terminated record count).
     */
    public static function lineCount(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }

        return \substr_count($bytes, "\n") + (str_ends_with($bytes, "\n") ? 0 : 1);
    }
}
