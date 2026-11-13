<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests\Support;

/**
 * Shared child-pipe plumbing for the suites that spawn CLI children.
 *
 * Two harness laws (findings candy-codetime-086/087):
 * - Descriptor 0 must be wired explicitly. An unmapped stdin is inherited,
 *   so a child that reads would steal PHPUnit's own input (and under a
 *   TTY runner, the console).
 * - stdout and stderr must be drained CONCURRENTLY. Sequential drains are
 *   the scriptui #45 deadlock shape: a child blocked on a full 64 KiB
 *   stderr pipe while the parent waits for stdout EOF hangs the suite.
 *   A stream_select loop is shape-proof however chatty an error path gets.
 */
trait DrainPipes
{
    /**
     * @param resource $out stdout pipe
     * @param resource $err stderr pipe
     * @return array{0:string,1:string}
     */
    private static function drainPair($out, $err): array
    {
        $stdout = '';
        $stderr = '';
        $streams = ['out' => $out, 'err' => $err];
        while ($streams !== []) {
            $read = array_values($streams);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 60);
            if ($ready === false || $ready === 0) {
                fclose($out);
                fclose($err);
                throw new \RuntimeException(
                    'child pipe stalled for 60s; partial stdout: ' . strlen($stdout)
                    . ' bytes, stderr: ' . strlen($stderr) . ' bytes',
                );
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                foreach ($streams as $key => $candidate) {
                    if ($candidate !== $stream) {
                        continue;
                    }
                    if ($chunk === '' || $chunk === false) {
                        fclose($stream);
                        unset($streams[$key]);
                    } elseif ($key === 'out') {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                    break;
                }
            }
        }
        return [$stdout, $stderr];
    }
}
