<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * BM-1 (RS-DRIVER, owner ruled BUILD — smallest shape): --batch executes
 * each stream-json user event as its OWN sequential turn in one process,
 * instead of seeding one run's history.
 *
 * The pins ride the same child-witness machinery as the D2 seed law
 * (tests/Cli/StreamJsonInputTest.php): a loopback endpoint answers every
 * chat with one SSE row, and the child's own transcript is the ground
 * truth for WHAT ran. Helper names diverge from that file deliberately —
 * a byte-identical copy is the drift guard's accusation shape, and these
 * bodies genuinely differ (explicit flag arrays, multi-line document
 * reading, a content-keyed failure the seed test has no reason to keep).
 *
 * @see NonInteractive::run()
 */
final class BatchModeTest extends TestCase
{
    // ---------------------------------------------------------------- flag

    public function testTheSwitchParsesToTheBatchSlot(): void
    {
        $args = ArgvParser::parse(['candy-code', '--batch', '--input-format', 'stream-json', '-p', 'go']);

        self::assertNull($args->usageError);
        self::assertTrue($args->batch);
    }

    public function testTheSlotDefaultsToFalseForEveryOtherLaunch(): void
    {
        self::assertFalse(ArgvParser::parse(['candy-code', '-p', 'go'])->batch);
        self::assertFalse(ArgvParser::parse(['candy-code', '--input-format', 'stream-json', '-p', 'go'])->batch);
    }

    // ---------------------------------------------------------------- doors

    public function testTheSwitchWithoutAFeedRefuses(): void
    {
        $args = ArgvParser::parse(['candy-code', '--batch', '-p', 'go']);

        self::assertNotNull($args->usageError);
        self::assertStringContainsString('candy-code: ', $args->usageError);
        self::assertStringContainsString('executes the stream-json user events as turns', $args->usageError);
        self::assertNotNull($args->usageHint);
        self::assertStringContainsString('--input-format stream-json', $args->usageHint);
    }

    public function testTheFeedWithoutAPromptRunsLegallyUnderTheSwitch(): void
    {
        $args = ArgvParser::parse(['candy-code', '--batch', '--input-format', 'stream-json']);

        self::assertNull($args->usageError, 'the queue IS the work — no -p needed: ' . (string) $args->usageError);
        self::assertTrue($args->batch);
        self::assertFalse($args->promptRequested);
    }

    public function testThePresetFlagBesideTheQueueRefuses(): void
    {
        $args = ArgvParser::parse(['candy-code', '--agent', 'worker', '--batch', '--input-format', 'stream-json']);

        self::assertNotNull($args->usageError);
        self::assertStringContainsString('no session to narrow', $args->usageError);
    }

    public function testTheCompletionTableOffersTheSwitch(): void
    {
        $options = (new \ReflectionClass(Subcommands::class))->getConstant('OPTIONS');
        self::assertIsArray($options);
        self::assertArrayHasKey('--batch', $options);
        self::assertNull($options['--batch']['short']);
        self::assertNull($options['--batch']['value']);
        $label = Lang::t((string) $options['--batch']['desc']);
        self::assertNotSame((string) $options['--batch']['desc'], $label, 'the completion label is a real catalogue key');
    }

    public function testTheDispatchWindowHonoursTheSwitch(): void
    {
        $bin = (string) \file_get_contents(\dirname(__DIR__, 2) . '/bin/candy-code');
        self::assertStringContainsString('if ($args->promptRequested || $args->batch) {', $bin);
    }

    // ---------------------------------------------------------------- children

    public function testEachRowBecomesItsOwnTurnInTheOneProcess(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openBatchStage();

        try {
            $stdin = "{\"type\":\"user\",\"content\":\"alpha\"}\n{\"type\":\"user\",\"content\":\"beta\"}\n{\"type\":\"user\",\"content\":\"gamma\"}\n";
            [$exit, $stdout, $stderr] = $this->runBatchChild(
                ['--batch', '--input-format', 'stream-json'],
                $stdin,
                $dir,
                $root,
                $address,
            );

            self::assertSame(0, $exit, $stderr);
            $lines = $this->batchDocumentLines($stdout);
            self::assertCount(3, $lines, 'one result document per row, in order: ' . $stdout);
            $documents = \array_map(static fn (string $line): array => \json_decode($line, true, 512, \JSON_THROW_ON_ERROR), $lines);
            foreach ($documents as $document) {
                self::assertSame('batch row answer', $document['result'] ?? null);
            }
            $sessionId = (string) ($documents[0]['session_id'] ?? '');
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sessionId, 'all rows share the ONE session');
            foreach ($documents as $document) {
                self::assertSame($sessionId, $document['session_id'] ?? null, 'one session, never a fresh one per row');
            }

            $rows = $this->batchTranscript($dir, $sessionId);
            self::assertSame(
                ['alpha', 'beta', 'gamma'],
                \array_values(\array_map(
                    static fn (array $row): string => (string) ($row['content'] ?? ''),
                    \array_filter($rows, static fn (array $row): bool => ($row['role'] ?? null) === 'user'),
                )),
                'the rows run AS TURNS in order — the seed path would have billed them inside one turn',
            );
            self::assertCount(
                3,
                \array_filter($rows, static fn (array $row): bool => ($row['role'] ?? null) === 'assistant'),
                'every row earned its own answer row',
            );
        } finally {
            $this->closeBatchStage($endpoint, $pipes);
            $this->removeBatchScratch($dir);
        }
    }

    public function testTheTypedPromptRunsLastBehindTheQueue(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openBatchStage();

        try {
            $stdin = "{\"type\":\"user\",\"content\":\"first row\"}\n{\"type\":\"user\",\"content\":\"second row\"}\n";
            [$exit, $stdout, $stderr] = $this->runBatchChild(
                ['--batch', '--input-format', 'stream-json', '-p', 'the closer'],
                $stdin,
                $dir,
                $root,
                $address,
            );

            self::assertSame(0, $exit, $stderr);
            $lines = $this->batchDocumentLines($stdout);
            self::assertCount(3, $lines);
            $sessionId = (string) (\json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR)['session_id'] ?? '');
            $rows = $this->batchTranscript($dir, $sessionId);
            self::assertSame(
                ['first row', 'second row', 'the closer'],
                \array_values(\array_map(
                    static fn (array $row): string => (string) ($row['content'] ?? ''),
                    \array_filter($rows, static fn (array $row): bool => ($row['role'] ?? null) === 'user'),
                )),
                'the argv prompt waits at the back of the queue',
            );
        } finally {
            $this->closeBatchStage($endpoint, $pipes);
            $this->removeBatchScratch($dir);
        }
    }

    /**
     * A feed of only ignored shapes is asked-for work that does not exist —
     * the refusal lands before any session opens. A truly closed stdin is
     * NOT this leg: it walks the pre-existing no-stdin door that fires
     * earlier in the same run.
     */
    public function testAFeedOfOnlyUnusableRowsRefusesBeforeAnySessionOpens(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openBatchStage();

        try {
            [$exit, $stdout, $stderr] = $this->runBatchChild(
                ['--batch', '--input-format', 'stream-json'],
                "{\"type\":\"assistant\",\"content\":\"not a user event\"}\n",
                $dir,
                $root,
                $address,
            );

            self::assertSame(2, $exit, 'a queue with no user events is a usage refusal: ' . $stdout);
            self::assertStringContainsString('no user events to run', $stderr);
            $lines = $this->batchDocumentLines($stdout);
            self::assertCount(1, $lines, 'the refusal answers as the one usage document, exactly like the closed-stdin door: ' . $stdout);
            $document = \json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame('usage', $document['error']['type'] ?? null);
            self::assertStringContainsString('no user events to run', (string) ($document['error']['message'] ?? ''));
        } finally {
            $this->closeBatchStage($endpoint, $pipes);
            $this->removeBatchScratch($dir);
        }
    }

    public function testTheSeedLawStandsWhenTheSwitchIsAbsent(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openBatchStage();

        try {
            $stdin = "{\"type\":\"user\",\"content\":\"seed one\"}\n{\"type\":\"user\",\"content\":\"seed two\"}\n";
            [$exit, $stdout, $stderr] = $this->runBatchChild(
                ['--input-format', 'stream-json', '-p', 'wrap up'],
                $stdin,
                $dir,
                $root,
                $address,
            );

            self::assertSame(0, $exit, $stderr);
            $lines = $this->batchDocumentLines($stdout);
            self::assertCount(1, $lines, 'without --batch the rows remain a seed and the run is one turn');
            $sessionId = (string) (\json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR)['session_id'] ?? '');
            $rows = $this->batchTranscript($dir, $sessionId);
            self::assertSame(
                ['seed one', 'seed two', 'wrap up'],
                \array_values(\array_map(
                    static fn (array $row): string => (string) ($row['content'] ?? ''),
                    \array_filter($rows, static fn (array $row): bool => ($row['role'] ?? null) === 'user'),
                )),
            );
            self::assertCount(1, \array_filter($rows, static fn (array $row): bool => ($row['role'] ?? null) === 'assistant'));
        } finally {
            $this->closeBatchStage($endpoint, $pipes);
            $this->removeBatchScratch($dir);
        }
    }

    /**
     * The loop's Throwable catch sits between two legs of one turn. The
     * failing row is keyed by CONTENT ('FAILME' rides its request body and
     * every transport retry of it), because the provider retries a bare
     * HTTP 500 — a count-keyed failure would be retried away by the next
     * request and the row would answer. The broken row costs its own
     * answer (its user row never reaches the transcript), the NEXT row
     * still runs, and the run's exit is the failure's although the final
     * answer is clean.
     */
    public function testAFailingRowRecordsItsEnvelopeAndTheQueueProceeds(): void
    {
        [$dir, $root, $endpoint, $pipes, $address] = $this->openBatchStage();

        try {
            $stdin = "{\"type\":\"user\",\"content\":\"alpha\"}\n{\"type\":\"user\",\"content\":\"beta FAILME\"}\n{\"type\":\"user\",\"content\":\"gamma\"}\n";
            [$exit, $stdout, $stderr] = $this->runBatchChild(
                ['--batch', '--input-format', 'stream-json'],
                $stdin,
                $dir,
                $root,
                $address,
            );

            self::assertSame(1, $exit, 'the queue ran; row 2 broke; the run reports it: ' . $stderr);
            $lines = $this->batchDocumentLines($stdout);
            self::assertCount(3, $lines, 'the broken row costs a document but not the rows behind it: ' . $stdout);
            $documents = \array_map(static fn (string $line): array => \json_decode($line, true, 512, \JSON_THROW_ON_ERROR), $lines);
            self::assertSame('batch row answer', $documents[0]['result'] ?? null);
            self::assertSame('backend', $documents[1]['error']['type'] ?? null, 'row 2 answers with its error envelope: ' . $lines[1]);
            self::assertSame('batch row answer', $documents[2]['result'] ?? null, 'row 3 ran behind the failure');
            self::assertStringContainsString('candy-code: ', $stderr);

            $sessionId = (string) ($documents[0]['session_id'] ?? '');
            $rows = $this->batchTranscript($dir, $sessionId);
            self::assertSame(
                ['alpha', 'gamma'],
                \array_values(\array_map(
                    static fn (array $row): string => (string) ($row['content'] ?? ''),
                    \array_filter($rows, static fn (array $row): bool => ($row['role'] ?? null) === 'user'),
                )),
                'the failing row never reached its persist; row 3 answered and recorded behind it',
            );
            self::assertCount(2, \array_filter($rows, static fn (array $row): bool => ($row['role'] ?? null) === 'assistant'));
        } finally {
            $this->closeBatchStage($endpoint, $pipes);
            $this->removeBatchScratch($dir);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Result documents arrive one per line under --batch (each turn renders
     * its own envelope on the run's stdout).
     *
     * @return list<string>
     */
    private function batchDocumentLines(string $stdout): array
    {
        return \array_values(\array_filter(
            \array_map('trim', \explode("\n", $stdout)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /** @return list<array<string, mixed>> */
    private function batchTranscript(string $dir, string $sessionId): array
    {
        $store = new EnhancedSessionStore($dir . '/home/.candy-code/session.db');

        return $store->loadTranscript($sessionId);
    }

    /**
     * Loopback endpoint answering every /chat request with one SSE delta,
     * except any request whose body carries the failure token — that one
     * answers 500 for good, so every transport retry of the poisoned row
     * keeps failing until the backend raises.
     *
     * @return array{0:string,1:string,2:resource,3:array<int,resource>,4:string}
     */
    private function openBatchStage(): array
    {
        $root = \dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/wf-bm-batch-' . \bin2hex(\random_bytes(6));
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
                'delta' => ['role' => 'assistant', 'content' => 'batch row answer'],
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
                $body = '';
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
                        $body .= $chunk;
                    }
                }
                if (stripos($head, 'chat') !== false) {
                    if (strpos($body, 'FAILME') !== false) {
                        $fail = json_encode(['error' => ['message' => 'planned batch failure']]);
                        fwrite($conn, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: " . strlen($fail) . "\r\n\r\n" . $fail);
                    } else {
                        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\nContent-Length: " . strlen($sse) . "\r\n\r\n" . $sse);
                    }
                } else {
                    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: 2\r\n\r\n{}");
                }
                fclose($conn);
            }
            fclose($server);
            PHP;

        $file = $dir . '/bm-batch-endpoint.php';
        self::assertIsInt(file_put_contents($file, $script));

        $process = proc_open([PHP_BINARY, $file], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $endpointPipes);
        self::assertIsResource($process, 'the batch endpoint child did not start');
        stream_set_timeout($endpointPipes[1], 15);

        $announced = fgets($endpointPipes[1]);
        self::assertIsString($announced, 'the batch endpoint never announced its address');
        self::assertMatchesRegularExpression('/^LISTEN 127\.0\.0\.1:\d+\R$/', $announced);

        return [$dir, $root, $process, $endpointPipes, substr(trim($announced), 7)];
    }

    /**
     * The child parses $flags exactly as a driver would, so the door and the
     * dispatch honour the same argv end to end. $flags arrive JSON-encoded:
     * a list travels in one argv slot and needs no shell quoting at all.
     */
    private function runBatchChild(array $flags, ?string $stdin, string $dir, string $root, string $address): array
    {
        $script = <<<'PHP'
            <?php
            require $argv[2] . '/vendor/autoload.php';

            use SugarCraft\Code\Cli\ArgvParser;
            use SugarCraft\Code\Cli\NonInteractive;

            $argvv = array_merge(
                ['candy-code', '--root', $argv[2], '--output-format', 'json'],
                json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR)
            );

            $args = ArgvParser::parse($argvv);
            if ($args->usageError !== null) {
                fwrite(STDERR, 'USAGE-ERROR ' . $args->usageError . "\n");
                exit(9);
            }

            exit(NonInteractive::run($args, null, NonInteractive::FORMAT_JSON));
            PHP;

        $file = $dir . '/bm-batch-child.php';
        self::assertIsInt(file_put_contents($file, $script));

        $process = proc_open([PHP_BINARY, $file, json_encode($flags, \JSON_THROW_ON_ERROR), $root], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, [
            'HOME' => $dir . '/home',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'CANDY_CODE_PROVIDER' => 'sglang',
            'CANDY_CODE_MODEL' => 'bm-test-model',
            'OPENAI_BASE_URL' => 'http://' . $address . '/v1',
        ]);
        self::assertIsResource($process, 'the batch child did not start');
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
        self::assertGreaterThanOrEqual(0, $exit, 'the batch child died on a signal');

        return [$exit, $stdout, $stderr];
    }

    /** @param array<int, resource> $pipes */
    private function closeBatchStage($endpoint, array $pipes): void
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

    /** Delete-first recursion via iterators only — no name-walking token. */
    private function removeBatchScratch(string $path): void
    {
        $entries = \iterator_to_array(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ),
            false,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
