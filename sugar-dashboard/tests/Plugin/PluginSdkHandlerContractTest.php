<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Plugin\Response;

/**
 * D-103 (findings sugar-dashboard-089 + 209): PluginSdk::run() honors the
 * handler it advertises, and its error path survives invalid UTF-8.
 *
 * The class docblock sold `MyPlugin::run(fn($req) => ...)` for its whole
 * life while the body called $sdk->handle() and left $handler dead; the
 * shipped house template (bin/tealdemo shape) passed a closure that could
 * never fire. These legs drive REAL children through the stdin/stdout wire:
 * a closure handler receives every request, an omitted handler keeps the
 * class template (the parameter went optional — nothing deleted), and a
 * throwing handler carrying raw bytes answers honest error JSON instead of
 * dying 255 (the JSON_INVALID_UTF8_SUBSTITUTE leg closes 209: the old
 * toJson threw INSIDE the catch, killing the reply channel at the one
 * moment the child owes the host an answer).
 */
final class PluginSdkHandlerContractTest extends TestCase
{
    /**
     * Spawn a plugin child, send one line, read one answer line, close the
     * stdin door (the grace-rung EOF), and report the kernel's verdict.
     *
     * @return array{0: ?string, 1: int, 2: string} response line (null on
     *     starvation/timeout), exit status, stderr
     */
    private function driveChild(string $fixture, string $requestLine): array
    {
        $child = \dirname(__DIR__, 2) . '/tests/Plugin/Support/' . $fixture;
        $this->assertFileExists($child);

        // Child-perspective modes, as every other proc_open in this lib.
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open([\PHP_BINARY, $child], $spec, $pipes, \dirname(__DIR__, 2));
        $this->assertIsResource($process, 'child must spawn');

        \stream_set_timeout($pipes[1], 10);
        \stream_set_timeout($pipes[2], 10);

        \fwrite($pipes[0], $requestLine . "\n");
        \fflush($pipes[0]);
        $line = \fgets($pipes[1]);
        \fclose($pipes[0]);
        $stdoutRest = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        $this->assertSame('', $stdoutRest, 'exactly one response line per request (no chatter)');

        return [$line === false ? null : $line, $status, $stderr];
    }

    public function testClosureHandlerReceivesEveryRequest(): void
    {
        [$line, $status, $stderr] = $this->driveChild(
            'sdk-handler-fire-child.php',
            '{"type":"update","data":{"marker":"42"}}',
        );

        $this->assertNotNull($line, 'the handler path must answer (never starve the host)');
        $decoded = \json_decode($line, true);
        $this->assertIsArray($decoded);
        $this->assertSame('view', $decoded['type']);
        // The marker arrives INSIDE the request object: proof the closure got
        // the decoded Request, not a canned reply.
        $this->assertSame('HANDLED:42', $decoded['data']['content']);
        $this->assertSame(0, $status, 'clean EOF terminus');
        $this->assertSame('', $stderr);
    }

    public function testOmittedHandlerKeepsTheClassTemplate(): void
    {
        [$line, $status] = $this->driveChild(
            'sdk-fallback-template-child.php',
            '{"type":"init","data":{}}',
        );

        $this->assertNotNull($line);
        $decoded = \json_decode($line, true);
        $this->assertSame('init', $decoded['type']);
        $this->assertSame('q10-fallback-child', $decoded['data']['name']);
        $this->assertSame(0, $status);
    }

    public function testInvalidUtf8ExceptionMessageStillAnswersErrorJson(): void
    {
        [$line, $status, $stderr] = $this->driveChild(
            'sdk-invalid-utf8-error-child.php',
            '{"type":"update","data":{}}',
        );

        $this->assertNotNull($line, 'the error path must not kill the reply channel');
        $decoded = \json_decode($line, true);
        $this->assertIsArray($decoded, 'the answer must be valid JSON');
        $this->assertSame('error', $decoded['type']);
        // Raw \xFF\xFE arrive as U+FFFD replacement chars — substituted, never fatal.
        $this->assertStringContainsString("\u{FFFD}", $decoded['data']['message']);
        $this->assertStringContainsString('bad ', $decoded['data']['message']);
        $this->assertStringContainsString(' message', $decoded['data']['message']);
        $this->assertSame('', $stderr, 'no uncaught throw may leak from the child');
        $this->assertSame(0, $status);
    }

    public function testInProcessErrorResponseEncodesRawBytes(): void
    {
        $json = Response::error('bad ' . "\xFF" . ' byte')->toJson();
        $decoded = \json_decode($json, true);
        $this->assertSame('error', $decoded['type']);
        $this->assertStringContainsString("\u{FFFD}", $decoded['data']['message']);
    }

    public function testRoundTripOfSubstitutedErrorMessage(): void
    {
        $wire = Response::error("poison \xFE")->toJson();
        $back = Response::fromJson($wire);
        $this->assertSame('error', $back->type);
        $this->assertStringContainsString("\u{FFFD}", $back->data['message']);
    }
}
