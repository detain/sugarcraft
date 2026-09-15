<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;

/**
 * E726 (round 82) — the plugin stdin BLANK-LINE protocol contract.
 *
 * The wire is line-delimited JSON: ExternalModule::sendRequest() writes
 * exactly one toJson()."\n" per request, and readResponse() consumes exactly
 * one line per response. A blank line therefore carries NO protocol meaning
 * — it is not a delimiter, a flush, or a keepalive — so PluginSdk::run()
 * skips blank/whitespace-only reads silently: no parse, no error, no
 * response. Had blanks flowed through as events, each would have surfaced as
 * a Response::error line (Request::fromJson rejects empty input) and desynced
 * the host's strict one-request/one-response pairing.
 *
 * Verdict recorded honestly: the filter itself already shipped in the round-82
 * low-priority repair of PluginSdk::run() — this test is the missing pin that
 * makes the skip a POLICED contract instead of an incidental branch. It drives
 * the real child fixture (the same generic minimal SDK plugin the E723 census
 * owns) over a real pipe; run() consumes process STDIN, so no in-process
 * double could observe the loop at all.
 *
 * Determinism law (q9, round 82): after draining the child's pipes we poll
 * proc_get_status in bounded 10 ms ticks — the kernel closes stdio fds BEFORE
 * exit_notify() makes the task waitable, so a single-shot status read races.
 */
final class PluginSdkBlankLineContractTest extends TestCase
{
    public function testBlankAndWhitespaceOnlyStdinLinesProduceNoEventsAndNoDesync(): void
    {
        $child = \dirname(__DIR__, 2) . '/tests/Plugin/Support/sdk-eof-exit-child.php';
        $this->assertFileExists($child);

        $process = \proc_open([\PHP_BINARY, $child], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, \dirname(__DIR__, 2));
        $this->assertIsResource($process, 'child must spawn');

        \stream_set_timeout($pipes[1], 10);
        \stream_set_timeout($pipes[2], 10);

        // Five blank-ish lines interleaved around two real requests: leading
        // noise, a blank between responses, trailing whitespace. If the filter
        // regressed, each of the five would answer with an error row.
        \fwrite($pipes[0], "\n   \n\t \n");
        \fwrite($pipes[0], '{"type":"init","data":{}}' . "\n");
        \fwrite($pipes[0], "\n");
        \fwrite($pipes[0], '{"type":"view","data":{"width":40,"height":2}}' . "\n");
        \fwrite($pipes[0], "   \n");
        // The E366 door: closing fd 0 is the polite shutdown request.
        \fclose($pipes[0]);

        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);

        $deadline = \microtime(true) + 2.0;
        do {
            $stillRunning = (bool) (\proc_get_status($process)['running'] ?? false);
            if (!$stillRunning) {
                break;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);
        if ($stillRunning) {
            \proc_terminate($process, 9);
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        $this->assertFalse($stillRunning, 'the child must exit on stdin EOF, not need killing');
        $this->assertSame(0, $status, 'clean EOF terminus keeps the child on observable status 0');

        $lines = \array_values(\array_filter(\explode("\n", $stdout), static fn (string $l): bool => $l !== ''));
        $types = [];
        foreach ($lines as $line) {
            $decoded = \json_decode($line, true);
            $this->assertIsArray($decoded, "every response line must be JSON, got: {$line}");
            $types[] = $decoded['type'] ?? null;
        }

        $this->assertSame(
            ['init', 'view'],
            $types,
            'exactly the two real requests are answered, in order — the five blank lines '
            . 'produced no events, no error rows, and did not desync the pairing',
        );
        $this->assertStringNotContainsString('error', $stdout, 'blank input must never surface as an error response');
        $this->assertSame('', $stderr, 'the child must stay silent on stderr');
    }
}
