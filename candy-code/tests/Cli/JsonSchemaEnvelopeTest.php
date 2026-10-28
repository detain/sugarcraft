<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Message;

/**
 * rv-tui-5 (fix 4): every `--output-format json` envelope carries a
 * top-level `"schema": 1` as its FIRST key, so a machine consumer can pin
 * the contract version without sniffing key presence.
 *
 * Four emitters, one version: {@see NonInteractive::format()}'s success
 * document, {@see NonInteractive::emitErrorDocument()}'s error document
 * (which `failUsage()` and the provider refusals route through),
 * Subcommands' `emitDocument()` — which prepends the key at its single
 * encode site so every subcommand document rides the same version — and the
 * two last-resort literals that exist precisely because the encoders may be
 * the thing that failed (NonInteractive's `sprintf` fallback, Subcommands'
 * encoding fallback, and bin's pre-autoload guard). The literals are pinned
 * by source scan because they must stay encodable by construction; the live
 * documents are pinned by decoding an actual emit.
 */
final class JsonSchemaEnvelopeTest extends TestCase
{
    public function testTheSuccessDocumentCarriesSchemaFirst(): void
    {
        $json = NonInteractive::format(Message::assistant('the answer'), NonInteractive::FORMAT_JSON);
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['schema', 'result'], array_keys($document));
        $this->assertSame(NonInteractive::SCHEMA, $document['schema']);
        $this->assertSame('the answer', $document['result']);
    }

    public function testTheSuccessDocumentKeepsRefusalsAfterSchemaAndResult(): void
    {
        $json = NonInteractive::format(
            Message::assistant('the answer'),
            NonInteractive::FORMAT_JSON,
            [['tool' => 'Bash', 'reason' => 'hook']],
        );
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['schema', 'result', 'refusals'], array_keys($document));
    }

    public function testTheUsageErrorDocumentCarriesSchemaFirst(): void
    {
        ob_start();
        $exitCode = NonInteractive::failUsage('bad flag frob', NonInteractive::FORMAT_JSON);
        $output = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_CONFIG, $exitCode);
        $document = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['schema', 'result', 'error'], array_keys($document));
        $this->assertSame(NonInteractive::SCHEMA, $document['schema']);
        $this->assertNull($document['result']);
        $this->assertSame('usage', $document['error']['type']);
    }

    public function testTheConstantIsOne(): void
    {
        $this->assertSame(1, NonInteractive::SCHEMA);
    }

    /**
     * The three by-construction literals — the two encode-failure fallbacks
     * and bin's autoload guard — must open with the schema key too, or the
     * documents a broken-encoder run emits are exactly the ones a schema
     * check would reject.
     */
    public function testEveryFallbackLiteralOpensWithTheSchemaKey(): void
    {
        $root = \dirname(__DIR__, 2);

        $nonInteractive = (string) file_get_contents($root . '/src/Cli/NonInteractive.php');
        $this->assertSame(
            1,
            substr_count($nonInteractive, "'{\"schema\":%d,\"result\":null,\"error\""),
            'the JsonException fallback sprintf must open with the schema key',
        );

        $subcommands = (string) file_get_contents($root . '/src/Cli/Subcommands.php');
        $emitFallback = <<<'NEEDLE'
'{"schema":' . NonInteractive::SCHEMA . ',"result":null,"error":{"type":"encoding"
NEEDLE;
        $this->assertStringContainsString(
            $emitFallback,
            $subcommands,
            'the emitDocument encoding fallback must open with the schema key',
        );

        $bin = (string) file_get_contents($root . '/bin/candy-code');
        $this->assertStringContainsString('{"schema":1,"result":null,"error":{"type":"installation"', $bin);
    }

    /**
     * The success document's `refusals` key must stay AFTER `result` even
     * with schema prepended — the guard test on the bin twin compares
     * `array_keys()` positionally, and this pins the ordering doctrine the
     * seam relies on: schema FIRST, every pre-existing key in its old order
     * after it.
     */
    public function testTheSchemaKeyNeverReordersTheLegacyKeys(): void
    {
        $legacy = ['result', 'refusals'];
        $withSchema = array_keys(json_decode(
            NonInteractive::format(
                Message::assistant('x'),
                NonInteractive::FORMAT_JSON,
                [['tool' => 'Bash', 'reason' => 'hook']],
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));

        $this->assertSame($legacy, array_values(array_slice($withSchema, 1)));
    }

    /**
     * C-AGT-7.b, format layer: `json_schema_applied` joins the conditional
     * set on the report-what-happened polarity the stop trio already uses —
     * it EXISTS only when the renderer was told the schema demonstrably did
     * not constrain the answering step, it carries the single value `false`,
     * and it sits AFTER every legacy key so an older `array_keys` reader's
     * prefix is untouched. A clean document never grows a `true`.
     */
    public function testTheSchemaAppliedKeyAppearsOnlyToSayFalseAndOnlyLast(): void
    {
        $default = json_decode(
            NonInteractive::format(Message::assistant('x'), NonInteractive::FORMAT_JSON, [], 's1', null, []),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertArrayNotHasKey(
            'json_schema_applied',
            $default,
            'a run without the flag must not answer for the schema — the key is absent, never a hard true',
        );

        $withAll = json_decode(
            NonInteractive::format(
                Message::assistant('x'),
                NonInteractive::FORMAT_JSON,
                [['tool' => 'Bash', 'reason' => 'hook']],
                's1',
                \SugarCraft\Code\Usage::new(totalTokens: 5)->toArray(),
                ['steps_truncated' => true, 'length_stopped' => true, 'loop_guard_stopped_by' => 'Bash'],
                true,
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $keys = array_keys($withAll);
        $this->assertSame('json_schema_applied', $keys[count($keys) - 1], 'the new key lands AFTER every legacy key');
        $this->assertFalse($withAll['json_schema_applied']);
        $this->assertSame(
            ['schema', 'result', 'refusals', 'session_id', 'usage', 'steps_truncated', 'length_stopped', 'loop_guard_stopped_by', 'json_schema_applied'],
            $keys,
        );

        $text = NonInteractive::format(Message::assistant('plain answer'), NonInteractive::FORMAT_TEXT, [], null, null, [], true);
        $this->assertSame("plain answer", $text, 'the text arm ignores the flag — its stderr notice is run()\'s, and its stdout stays the answer alone');
    }

    /**
     * C-AGT-7.b, end-to-end: a real `--json-schema` one-shot whose turn RAN A
     * TOOL must not hand the caller an envelope that reads as though the
     * schema governed the answer. The engine constrains only the turn's first
     * request (Runtime::jsonSchemaFor — unchanged here by design); when a tool
     * result lands before the settled answer, that answer was unconstrained.
     *
     * Both witnesses run in a child because the honest half of this claim is
     * the STDERR line, and `fwrite(STDERR,...)` cannot be captured in-process:
     * splitting the two channels across separate assertions is exactly how a
     * key present-but-unannounced (or an announcement without a key) would
     * pass. The child therefore pins stdout and stderr TOGETHER, per mode:
     *
     *   tools:  envelope carries json_schema_applied:false, one stderr notice;
     *   clean:  no key, no notice — the schema rode the only request there was.
     *
     * The tool call names a tool the launch does not carry. That is
     * deliberate: "the turn ran a tool" is the SIGNAL, and an unknown-name call
     * still lands its result row in the history the next request is built
     * from (ToolFinished's own contract covers every call end alike) — so the
     * probe proves the rule without executing anything, and a mutation that
     * read the signal off only SUCCESSFUL tool runs goes red right here.
     */
    public function testAToolUsingSchemaRunSaysFalseOnBothChannels(): void
    {
        $root = \dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/crush-agt7b-envelope-' . bin2hex(random_bytes(6));
        mkdir($dir . '/home', 0o700, true);

        $script = <<<'PHP'
            <?php
            require $argv[2] . '/vendor/autoload.php';

            use SugarCraft\Code\Backend\EngineBackend;
            use SugarCraft\Code\Cli\ArgvParser;
            use SugarCraft\Code\Cli\NonInteractive;
            use SugarCraft\Code\Providers\CompleteRequest;
            use SugarCraft\Code\Providers\CompleteResponse;
            use SugarCraft\Code\Providers\EmbeddingsRequest;
            use SugarCraft\Code\Providers\EmbeddingsResponse;
            use SugarCraft\Code\Providers\ProviderInterface;
            use SugarCraft\Code\Tools\ToolCall;

            $provider = new class ($argv[1] === 'tools') implements ProviderInterface {
                private int $calls = 0;

                public function __construct(private readonly bool $useTool)
                {
                }

                public function name(): string
                {
                    return 'agt7b-probe';
                }

                public function supportsStreaming(): bool
                {
                    return false;
                }

                public function supportsFunctionCalling(): bool
                {
                    return $this->useTool;
                }

                public function supportsVision(): bool
                {
                    return false;
                }

                public function supportsDocuments(): bool
                {
                    return false;
                }

                public function supportsJsonSchema(): bool
                {
                    return true;
                }

                public function contextWindow(): int
                {
                    return 1000;
                }

                public function costPer1kTokens(string $model, string $direction): float
                {
                    return 0.0;
                }

                public function complete(CompleteRequest $request): CompleteResponse
                {
                    $this->calls++;
                    if ($this->useTool && $this->calls === 1) {
                        return new CompleteResponse(
                            content: 'let me look',
                            toolCalls: [new ToolCall('agt7b-c1', 'Agt7bNoSuchTool', [])],
                        );
                    }

                    return new CompleteResponse(content: 'settled answer');
                }

                public function completeStream(CompleteRequest $request): \Generator
                {
                    yield new CompleteResponse(content: 'settled answer');
                }

                public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
                {
                    return new EmbeddingsResponse(embeddings: []);
                }
            };

            $args = ArgvParser::parse([
                'candy-code', '--root', $argv[3], '-p', 'extract',
                '--json-schema', '{"type":"object"}', '--output-format', 'json',
            ]);
            if ($args->usageError !== null) {
                fwrite(STDERR, 'USAGE-ERROR ' . $args->usageError . "\n");
                exit(9);
            }

            exit(NonInteractive::run($args, new EngineBackend($provider, 'agt7b-probe'), NonInteractive::FORMAT_JSON));
            PHP;

        $file = $dir . '/agt7b-envelope-child.php';
        self::assertIsInt(file_put_contents($file, $script));

        try {
            foreach (['tools', 'clean'] as $mode) {
                $process = proc_open([PHP_BINARY, $file, $mode, $root, $dir], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, $root, ['HOME' => $dir . '/home', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
                self::assertIsResource($process, "the {$mode} child did not start");
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 30);
                stream_set_timeout($pipes[2], 30);
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, \defined('SIGTERM') ? SIGTERM : 15);
                }
                self::assertSame(0, proc_close($process), "{$mode} child exited nonzero; stderr: {$stderr}");

                $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('settled answer', $document['result'], "{$mode}: the answer is intact on both modes");

                if ($mode === 'tools') {
                    self::assertArrayHasKey(
                        'json_schema_applied',
                        $document,
                        "a tool-using schema turn is exactly the case the key exists to name; stderr was:\n{$stderr}",
                    );
                    self::assertFalse($document['json_schema_applied']);
                    self::assertStringContainsString(
                        'could not constrain the settled answer',
                        $stderr,
                        'the envelope says it, and stderr says it too — a JSON-blind reader (the text format shares this computation) must learn it as well',
                    );
                    self::assertSame(
                        1,
                        substr_count($stderr, 'could not constrain the settled answer'),
                        'ONE notice, not a notice per tool row',
                    );
                } else {
                    self::assertArrayNotHasKey(
                        'json_schema_applied',
                        $document,
                        "no tool ran, so the schema rode the answering request; a `true` here would be the fabricated half of the claim; stderr was:\n{$stderr}",
                    );
                    self::assertStringNotContainsString('could not constrain', $stderr);
                }
            }
        } finally {
            @unlink($file);
            @rmdir($dir . '/home');
            @rmdir($dir);
        }
    }

    /**
     * C-AGT-7.b round 2 (rv-1 MAJOR): a resume whose CARRIED history ends on
     * a tool result is refused the schema by the engine — on the FIRST
     * request of the new run — exactly as mid-turn: Runtime::jsonSchemaFor
     * scans the carried rows too, and a tool-result row at or after the last
     * tool-call row means the answer being asked for is not schema-bound.
     * But no ToolFinished event fires before that answer, so a report that
     * read only this run's observer would hand the caller a keyless, silent,
     * unconstrained envelope over a tool-ended transcript. run() now ORs the
     * carried state in, and this pins both halves: resuming a tool-ended
     * transcript carries `json_schema_applied:false` plus exactly one stderr
     * notice (revert the carried leg of the OR and THIS leg reddens — with no
     * tool events this run the key vanishes and stderr goes silent), while
     * resuming an ordinary transcript stays keyless and silent (an
     * over-eager carried leg reddens right here).
     *
     * The provider here is no double: a resume seeds $history only on a
     * funnel-owned run ($opened is minted when no backend was injected), so
     * the child selects the REAL sglang provider through the same two
     * environment variables an operator uses and points it at a throwaway
     * loopback endpoint. The endpoint routes the chat completion to an
     * OpenAI-shaped SSE answer and everything else — sglang's server-info
     * probes included, which fail safe by construction — to an empty JSON
     * object, so the answer never depends on a discovery shape. Both
     * channels are read TOGETHER from the child, per mode, exactly like the
     * live-tools witness above: the stderr line is the half an in-process
     * capture could not see.
     */
    public function testAResumedSchemaRunOverAToolEndedTranscriptSaysFalseOnBothChannels(): void
    {
        $root = \dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/crush-agt7b-resume-' . bin2hex(random_bytes(6));
        mkdir($dir . '/home/.candy-code', 0o700, true);
        [$endpoint, $endpointPipes, $address] = $this->startCarriedEndpoint($dir);

        $script = <<<'PHP'
            <?php
            require $argv[2] . '/vendor/autoload.php';

            use SugarCraft\Code\Cli\ArgvParser;
            use SugarCraft\Code\Cli\NonInteractive;
            use SugarCraft\Code\Message;
            use SugarCraft\Code\Session\EnhancedSessionStore;
            use SugarCraft\Code\ToolCall;
            use SugarCraft\Code\ToolResult;

            $rows = [Message::user('earlier question')];
            if ($argv[1] === 'carried') {
                // A step whose RESULT row is last: the next request owes that
                // result its answer — the position the engine withholds the
                // schema at, reached through the transcript, not this run.
                // These are the Chat-side DTO pair (the transcript stores
                // THESE types), not the engine-side Tools\ pair.
                $rows[] = Message::assistant('looking')
                    ->withToolCalls([new ToolCall(name: 'Agt7bNoSuchTool', arguments: [], id: 'agt7b-c1')])
                    ->withStepId('agt7b-s1');
                $rows[] = Message::assistant('')
                    ->withToolResults([new ToolResult(name: 'Agt7bNoSuchTool', result: 'done', id: 'agt7b-c1')])
                    ->withStepId('agt7b-s1');
            } else {
                $rows[] = Message::assistant('an ordinary answer');
            }

            $store = new EnhancedSessionStore((string) getenv('HOME') . '/.candy-code/session.db');
            // saveTranscript is the production save path AND auto-creates the
            // session row; a transcript saved without one never resolves as a
            // resume target and the launch would silently mint a fresh id.
            $store->saveTranscript($argv[4], $rows);

            $args = ArgvParser::parse([
                'candy-code', '--root', $argv[2], '--resume', $argv[4], '-p', 'follow up',
                '--json-schema', '{"type":"object"}', '--output-format', 'json',
            ]);
            if ($args->usageError !== null) {
                fwrite(STDERR, 'USAGE-ERROR ' . $args->usageError . "\n");
                exit(9);
            }

            exit(NonInteractive::run($args, null, NonInteractive::FORMAT_JSON));
            PHP;

        $file = $dir . '/agt7b-resume-child.php';
        self::assertIsInt(file_put_contents($file, $script));

        try {
            foreach (['carried', 'clean'] as $mode) {
                $process = proc_open([PHP_BINARY, $file, $mode, $root, $dir, 'agt7b-' . $mode . '-session'], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, $root, [
                    'HOME' => $dir . '/home',
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'CANDY_CODE_PROVIDER' => 'sglang',
                    'CANDY_CODE_MODEL' => 'agt7b-probe-model',
                    'OPENAI_BASE_URL' => 'http://' . $address . '/v1',
                ]);
                self::assertIsResource($process, "the {$mode} resume child did not start");
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 90);
                stream_set_timeout($pipes[2], 90);
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, \defined('SIGTERM') ? SIGTERM : 15);
                }
                self::assertSame(0, proc_close($process), "{$mode} resume child exited nonzero; stderr:\n{$stderr}");

                $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('carried answer', $document['result'], "{$mode}: the loopback's answer must survive the resume intact");

                if ($mode === 'carried') {
                    self::assertArrayHasKey(
                        'json_schema_applied',
                        $document,
                        "a carried transcript ending on a tool result means the answering request never carried the schema; the key exists to say so; stderr was:\n{$stderr}",
                    );
                    self::assertFalse($document['json_schema_applied']);
                    self::assertStringContainsString(
                        'could not constrain the settled answer',
                        $stderr,
                        'the envelope says it, and stderr says it too — the same pairing the live-tools witness pins',
                    );
                    self::assertSame(
                        1,
                        substr_count($stderr, 'could not constrain the settled answer'),
                        'ONE notice, not a notice per carried row',
                    );
                } else {
                    self::assertArrayNotHasKey(
                        'json_schema_applied',
                        $document,
                        "a tool-less resume leaves the schema riding the answering request; the key must not appear — that half would be the fabricated claim; stderr was:\n{$stderr}",
                    );
                    self::assertStringNotContainsString('could not constrain', $stderr);
                }
            }
        } finally {
            $this->removeCarriedTree($dir);
            foreach ($endpointPipes as $pipe) {
                @fclose($pipe);
            }
            $endpointStatus = proc_get_status($endpoint);
            if ($endpointStatus['running']) {
                proc_terminate($endpoint, \defined('SIGTERM') ? SIGTERM : 15);
            }
            proc_close($endpoint);
        }
    }

    /**
     * Throwaway loopback for the resume witness: answers the chat completion
     * with an OpenAI-shaped SSE body and every other path (sglang's info
     * probes ride those) with a bare JSON object. Announces its port-0
     * address on a LISTEN line and self-expires after 40 accepts or 20 idle
     * seconds, so a crashed parent leaves no listener behind.
     *
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    private function startCarriedEndpoint(string $dir): array
    {
        $script = <<<'PHP'
            <?php
            $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($server === false) {
                fwrite(STDERR, "bind failed: {$errstr}\n");
                exit(1);
            }
            fwrite(STDOUT, 'LISTEN ' . stream_socket_get_name($server, false) . "\n");
            fflush(STDOUT);

            $sse = 'data: ' . json_encode(['choices' => [[
                'index' => 0,
                'delta' => ['role' => 'assistant', 'content' => 'carried answer'],
                'finish_reason' => 'stop',
            ]]]) . "\n\n" . "data: [DONE]\n\n";

            for ($seen = 0; $seen < 40; $seen++) {
                $conn = @stream_socket_accept($server, 20);
                if ($conn === false) {
                    break;
                }
                stream_set_timeout($conn, 5);

                $head = '';
                while (!feof($conn)) {
                    $line = fgets($conn, 8192);
                    if (!is_string($line)) {
                        break;
                    }
                    $head .= $line;
                    if (str_ends_with($head, "\r\n\r\n")) {
                        break;
                    }
                }
                if (preg_match('/Content-Length:\s*(\d+)/i', $head, $match) === 1) {
                    if (stripos($head, 'expect: 100-continue') !== false) {
                        fwrite($conn, "HTTP/1.1 100 Continue\r\n\r\n");
                    }
                    $left = (int) $match[1];
                    while ($left > 0 && !feof($conn)) {
                        $chunk = fread($conn, min(8192, $left));
                        if (!is_string($chunk) || $chunk === '') {
                            break;
                        }
                        $left -= strlen($chunk);
                    }
                }
                if (stripos($head, 'chat') !== false) {
                    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\nContent-Length: " . strlen($sse) . "\r\n\r\n" . $sse);
                } else {
                    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: 2\r\n\r\n{}");
                }
                fclose($conn);
            }
            fclose($server);
            PHP;

        $file = $dir . '/agt7b-carried-endpoint.php';
        self::assertIsInt(file_put_contents($file, $script));

        $process = proc_open([PHP_BINARY, $file], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process, 'the carried endpoint child did not start');
        stream_set_timeout($pipes[1], 15);

        $announced = fgets($pipes[1]);
        self::assertIsString($announced, 'the carried endpoint never announced its address');
        self::assertMatchesRegularExpression('/^LISTEN 127\.0\.0\.1:\d+\R$/', $announced);

        return [$process, $pipes, substr(trim($announced), 7)];
    }

    /**
     * Remove the whole scratch tree the resume witness leaves behind (db,
     * WAL sidecars, child script, both homes) — unlink-first recursion, no
     * rm -rf, so a surprise path can only fail loudly.
     */
    private function removeCarriedTree(string $path): void
    {
        foreach ((array) glob($path . '/{,.*}*', GLOB_BRACE) ?: [] as $entry) {
            if (!is_string($entry) || basename($entry) === '.' || basename($entry) === '..') {
                // '.' and '..' match the dot-glob but walk UPWARD — the
                // recursion must never leave the scratch tree.
                continue;
            }
            if (is_dir($entry) && !is_link($entry)) {
                $this->removeCarriedTree($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($path);
    }
}
