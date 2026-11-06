<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * D2 (RS-DRIVER b): `--input-format stream-json` turns piped stdin from a
 * lump of prepended context into NDJSON input events — one JSON object per
 * line — where every accepted `{"type":"user"}` line becomes its OWN user
 * turn, placed before the typed `-p` prompt so the prompt stays the last
 * word the model reads.
 *
 * The decoder is pinned in-process (it is public static and pure); the run
 * plumbing is pinned by child witnesses that own their real stdin, the shape
 * {@see SessionIdPresetTest} established for D1 — helper names diverge wholly
 * per the drift law, and the endpoint answers with distinct bytes.
 *
 * Polarity pinned here: a malformed JSON LINE is a broken protocol frame and
 * refuses as a usage error (exit 2) naming its physical line number; valid
 * JSON of the wrong SHAPE (scalars, arrays, non-user types, user events
 * without text) is counted and named once on stderr, then skipped; stdin with
 * nothing to read refuses with exit 2; the flag without a prompt, beside a
 * subcommand, or spelled outside the whitelist refuses at parse time. The
 * default `text` mode keeps prepending raw bytes, byte-identically.
 */
final class StreamJsonInputTest extends TestCase
{
    // ------------------------------------------------------------------
    // Decoder legs — in-process, direct.
    // ------------------------------------------------------------------

    public function testEachAcceptedUserLineBecomesItsOwnTurn(): void
    {
        $raw = "{\"type\":\"user\",\"content\":\"alpha\"}\n"
            . "{\"type\":\"assistant\",\"content\":\"noise\"}\n"
            . "{\"type\":\"user\",\"content\":\"beta\"}\n";

        [$turns, $ignored, $firstIgnoredType] = NonInteractive::parseStreamJsonInput($raw);

        self::assertSame(['alpha', 'beta'], $turns);
        self::assertSame(1, $ignored);
        self::assertSame('type assistant', $firstIgnoredType);
    }

    public function testTheThreeTextSpellingsResolveInDocumentedPrecedence(): void
    {
        $contentWins = NonInteractive::parseStreamJsonInput(
            '{"type":"user","content":"first","message":"second"}',
        );
        self::assertSame(['first'], $contentWins[0]);

        $bareMessage = NonInteractive::parseStreamJsonInput('{"type":"user","message":"spoken"}');
        self::assertSame(['spoken'], $bareMessage[0]);

        $nested = NonInteractive::parseStreamJsonInput('{"type":"user","message":{"content":"round-tripped"}}');
        self::assertSame(['round-tripped'], $nested[0]);
    }

    public function testAnEmptyContentFallsThroughToTheMessageSpellings(): void
    {
        [$turns, $ignored, $firstIgnoredType] = NonInteractive::parseStreamJsonInput(
            '{"type":"user","content":"","message":"fallback"}',
        );

        self::assertSame(['fallback'], $turns);
        self::assertSame(0, $ignored);
        self::assertNull($firstIgnoredType);
    }

    /**
     * @return array<string, array{0:string,1:string}>
     */
    public static function unusableShapes(): array
    {
        return [
            'user without text' => ['{"type":"user"}', 'a user event without text content'],
            'non-string content' => ['{"type":"user","content":42}', 'a user event without text content'],
            'missing type' => ['{"content":"orphan"}', 'an event without a type'],
            'json scalar' => ['123', 'an event that is not a JSON object'],
            'json string' => ['"just a string"', 'an event that is not a JSON object'],
            'json array' => ['[1,2]', 'an event without a type'],
        ];
    }

    #[DataProvider('unusableShapes')]
    public function testUnusableButValidLinesAreCountedAndNamed(string $line, string $shape): void
    {
        [$turns, $ignored, $firstIgnoredType] = NonInteractive::parseStreamJsonInput($line);

        self::assertSame([], $turns, 'a counted line must never ship as an empty turn');
        self::assertSame(1, $ignored);
        self::assertSame($shape, $firstIgnoredType);
    }

    public function testAMalformedLineThrowsNamingItsPhysicalLine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candy-code: --input-format stream-json: line 2 is not valid JSON');

        NonInteractive::parseStreamJsonInput("{\"type\":\"user\",\"content\":\"fine\"}\n{broken\n");
    }

    public function testTheLineNumberCountsBlankPhysicalLinesToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candy-code: --input-format stream-json: line 3 is not valid JSON');

        NonInteractive::parseStreamJsonInput("{\"type\":\"user\",\"content\":\"fine\"}\n\nnope\n");
    }

    public function testBlankLinesAndPaddingAreSkippedWithoutBeingCounted(): void
    {
        $raw = "\n  {\"type\":\"user\",\"content\":\"  kept  \"}  \n\n";

        [$turns, $ignored, $firstIgnoredType] = NonInteractive::parseStreamJsonInput($raw);

        self::assertSame(['  kept  '], $turns, 'each line is trimmed before decoding; the content itself rides verbatim');
        self::assertSame(0, $ignored);
        self::assertNull($firstIgnoredType);
    }

    // ------------------------------------------------------------------
    // Parser legs — argv shape refusals.
    // ------------------------------------------------------------------

    public function testTheEqualsAndSpaceFormsBothStoreTheFormat(): void
    {
        $equals = ArgvParser::parse(['candy-code', '--input-format=stream-json', '-p', 'go']);
        $space = ArgvParser::parse(['candy-code', '--input-format', 'stream-json', '-p', 'go']);

        self::assertNull($equals->usageError);
        self::assertSame('stream-json', $equals->inputFormat);
        self::assertNull($space->usageError);
        self::assertSame('stream-json', $space->inputFormat);
    }

    public function testAnAbsentFlagKeepsTheTextDefault(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', 'go']);

        self::assertNull($args->usageError);
        self::assertSame('text', $args->inputFormat);
    }

    public function testAForeignFormatSpellingIsRefusedAndBothWhitelistWordsAreNamed(): void
    {
        $args = ArgvParser::parse(['candy-code', '--input-format=nope', '-p', 'go']);

        self::assertNotNull($args->usageError);
        self::assertStringContainsString('--input-format nope: unsupported input format', (string) $args->usageError);
        self::assertStringContainsString('text', (string) $args->usageHint);
        self::assertStringContainsString('stream-json', (string) $args->usageHint);
    }

    public function testStreamJsonWithoutAPromptIsRefused(): void
    {
        $args = ArgvParser::parse(['candy-code', '--input-format', 'stream-json']);

        self::assertNotNull($args->usageError);
        self::assertStringContainsString('this launch has no prompt to run', (string) $args->usageError);
    }

    public function testStreamJsonBesideASubcommandVerbIsRefused(): void
    {
        $args = ArgvParser::parse(['candy-code', '--input-format', 'stream-json', 'session', 'list']);

        self::assertNotNull($args->usageError);
        self::assertStringContainsString('subcommand verbs run their own flow', (string) $args->usageError);
    }

    // ------------------------------------------------------------------
    // Run legs — child witnesses owning a real stdin pipe.
    // ------------------------------------------------------------------

    public function testStreamJsonFeedsUserTurnsAheadOfTheTypedPrompt(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openStreamStage();

        try {
            $stdin = "{\"type\":\"user\",\"content\":\"alpha\"}\n"
                . "{\"type\":\"assistant\",\"content\":\"skipped noise\"}\n"
                . "{\"type\":\"user\",\"content\":\"beta\"}\n";

            [$exit, $stdout, $stderr] = $this->runStreamChild('events', $dir, $root, $address, $stdin);

            self::assertSame(0, $exit, "the events child exited {$exit}; stderr:\n{$stderr}");
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('d2 stream answer', $document['result']);

            $sessionId = (string) $document['session_id'];
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sessionId);

            $store = new EnhancedSessionStore($dir . '/home/.candy-code/session.db');
            $transcript = $store->loadTranscript($sessionId) ?? [];
            $userRows = \array_values(\array_map(
                static fn (array $row): string => (string) $row['content'],
                \array_filter($transcript, static fn (array $row): bool => ($row['role'] ?? null) === 'user'),
            ));

            self::assertSame(
                ['alpha', 'beta', 'wrap up'],
                $userRows,
                'each accepted event is its own turn and the typed prompt stays the last word',
            );
            self::assertStringContainsString('ignored 1 stdin line(s)', $stderr, 'the skipped line is named once, loudly');
            self::assertStringContainsString('type assistant', $stderr);
        } finally {
            $this->closeStreamStage($endpoint, $pipes);
            $this->removeStreamScratch($dir);
        }
    }

    public function testTheFlagWithNothingToReadRefusesWithExitTwo(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openStreamStage();

        try {
            [$exit, $stdout, $stderr] = $this->runStreamChild('events', $dir, $root, $address, null);

            self::assertSame(2, $exit, "a stream-json run with closed stdin must refuse; stderr:\n{$stderr}");
            self::assertStringContainsString('no stdin to read', $stderr);
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('usage', $document['error']['type']);
        } finally {
            $this->closeStreamStage($endpoint, $pipes);
            $this->removeStreamScratch($dir);
        }
    }

    public function testAMalformedStdinLineRefusesWithExitTwoNamingTheLine(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openStreamStage();

        try {
            [$exit, $stdout, $stderr] = $this->runStreamChild(
                'events',
                $dir,
                $root,
                $address,
                "{\"type\":\"user\",\"content\":\"fine\"}\n{ not json at all\n",
            );

            self::assertSame(2, $exit, "a broken protocol frame must refuse the run; stderr:\n{$stderr}");
            self::assertStringContainsString('line 2 is not valid JSON', $stderr);
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('usage', $document['error']['type']);
            self::assertStringContainsString('line 2 is not valid JSON', (string) $document['error']['message']);
        } finally {
            $this->closeStreamStage($endpoint, $pipes);
            $this->removeStreamScratch($dir);
        }
    }

    /**
     * The byte-identity law: without the flag the prepend path is untouched —
     * raw stdin rides above the prompt inside ONE user row, exactly as it has
     * since the funnel existed.
     */
    public function testTextModeStillPrependsRawStdinIntoASingleRow(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openStreamStage();

        try {
            [$exit, $stdout, $stderr] = $this->runStreamChild('text', $dir, $root, $address, "CTX-BYTES\n");

            self::assertSame(0, $exit, "the text child exited {$exit}; stderr:\n{$stderr}");
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            $sessionId = (string) $document['session_id'];

            $store = new EnhancedSessionStore($dir . '/home/.candy-code/session.db');
            $transcript = $store->loadTranscript($sessionId) ?? [];
            $userRows = \array_values(\array_map(
                static fn (array $row): string => (string) $row['content'],
                \array_filter($transcript, static fn (array $row): bool => ($row['role'] ?? null) === 'user'),
            ));

            self::assertCount(1, $userRows, 'the default mode folds stdin into the prompt row, it never splits it');
            self::assertSame("CTX-BYTES\n\n\nask", $userRows[0]);
        } finally {
            $this->closeStreamStage($endpoint, $pipes);
            $this->removeStreamScratch($dir);
        }
    }

    // ------------------------------------------------------------------
    // Harness: throwaway stage (sandbox HOME + loopback endpoint), child
    // driver with a WRITABLE stdin, teardown. Fresh names over the D1 shape.
    // ------------------------------------------------------------------

    /**
     * @return array{0:string,1:string,2:resource,3:array<int,resource>,4:string}
     */
    private function openStreamStage(): array
    {
        $root = \dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/wf-d2-stream-' . bin2hex(random_bytes(6));
        mkdir($dir . '/home/.candy-code', 0o700, true);

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
                'delta' => ['role' => 'assistant', 'content' => 'd2 stream answer'],
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

        $file = $dir . '/d2-stream-endpoint.php';
        self::assertIsInt(file_put_contents($file, $script));

        $process = proc_open([PHP_BINARY, $file], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $endpointPipes);
        self::assertIsResource($process, 'the stream endpoint child did not start');
        stream_set_timeout($endpointPipes[1], 15);

        $announced = fgets($endpointPipes[1]);
        self::assertIsString($announced, 'the stream endpoint never announced its address');
        self::assertMatchesRegularExpression('/^LISTEN 127\.0\.0\.1:\d+\R$/', $announced);

        return [$dir, $root, $process, $endpointPipes, substr(trim($announced), 7)];
    }

    /**
     * Drive one child through the funnel-owned path (no injected backend)
     * with $stdin written to the real pipe BEFORE the run reads it — null
     * closes stdin unread, which is exactly the refusal shape for stream-json
     * mode. $mode 'events' adds --input-format stream-json; 'text' runs the
     * default path for the byte-identity law.
     *
     * @return array{0:int,1:string,2:string}
     */
    private function runStreamChild(string $mode, string $dir, string $root, string $address, ?string $stdin): array
    {
        $script = <<<'PHP'
            <?php
            require $argv[2] . '/vendor/autoload.php';

            use SugarCraft\Code\Cli\ArgvParser;
            use SugarCraft\Code\Cli\NonInteractive;

            $argvv = ['candy-code', '--root', $argv[2], '--output-format', 'json'];
            if ($argv[1] === 'events') {
                $argvv[] = '--input-format';
                $argvv[] = 'stream-json';
                $argvv[] = '-p';
                $argvv[] = 'wrap up';
            } else {
                $argvv[] = '-p';
                $argvv[] = 'ask';
            }

            $args = ArgvParser::parse($argvv);
            if ($args->usageError !== null) {
                fwrite(STDERR, 'USAGE-ERROR ' . $args->usageError . "\n");
                exit(9);
            }

            exit(NonInteractive::run($args, null, NonInteractive::FORMAT_JSON));
            PHP;

        $file = $dir . '/d2-stream-child.php';
        self::assertIsInt(file_put_contents($file, $script));

        $process = proc_open([PHP_BINARY, $file, $mode, $root], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, [
            'HOME' => $dir . '/home',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'CANDY_CODE_PROVIDER' => 'sglang',
            'CANDY_CODE_MODEL' => 'd2-stream-model',
            'OPENAI_BASE_URL' => 'http://' . $address . '/v1',
        ]);
        self::assertIsResource($process, "the {$mode} child did not start");
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
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
        $exit = proc_close($process);
        self::assertGreaterThanOrEqual(0, $exit, "the {$mode} child died on a signal");

        return [$exit, $stdout, $stderr];
    }

    /** @param array<int, resource> $pipes */
    private function closeStreamStage($endpoint, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        $status = proc_get_status($endpoint);
        if ($status['running']) {
            proc_terminate($endpoint, \defined('SIGTERM') ? SIGTERM : 15);
        }
        proc_close($endpoint);
    }

    /** Unlink-first recursion, no rm -rf, so a surprise path fails loudly. */
    private function removeStreamScratch(string $path): void
    {
        $entries = (array) scandir($path) ?: [];
        foreach ($entries as $entry) {
            if (!\is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full) && !is_link($full)) {
                $this->removeStreamScratch($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
