<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-SES-1.a: a headless `-p` run's provider-reported usage reaches the DURABLE
 * ledger, not just the envelope. The persist block routes the buffered steps'
 * summed Usage through the same SpendLedger::account() choke the TUI settles
 * via {@see \SugarCraft\Code\Chat::accountUsage()} and the serve path via
 * {@see \SugarCraft\Code\Host\SessionHost::usageRecordSink()}, with an
 * appendUsageRecord sink on the run's own session row.
 *
 * The billed cases reuse the loopback-endpoint harness of
 * {@see NonInteractiveSessionPersistenceTest} verbatim in shape — same
 * CANDY_CODE_PROVIDER/OPENAI_BASE_URL selection, same self-expiring child —
 * so what is exercised end-to-end is the funnel's own provider wiring, not a
 * hand-fed store. The throwing-store case runs the real bin/candy-code as a
 * child process because stderr is only observable across a process boundary,
 * and it breaks the store by chmod after a SUCCESSFUL first run so the failure
 * lands on the persist block (the reads still work on a write-refusing
 * database — measured) rather than downgrading through the funnel.
 */
final class NonInteractiveUsageLedgerTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * The variables that decide WHICH backend answers, cleared so the run
     * deterministically takes the offline echo path under the sandbox HOME
     * unless a case re-arms them on purpose.
     */
    private const SELECTION_ENV_KEYS = [
        'CANDY_CODE_PROVIDER',
        'CANDY_CODE_MODEL',
        'CANDY_CODE_TITLE_MODEL',
        'CANDY_CODE_BACKEND_CMD',
        'CANDY_CODE_BACKEND_CMD_STREAM',
    ];

    private string $tempDir;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/crush-noninteractive-ledger-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/home', 0o700, true);
        mkdir($this->tempDir . '/repo', 0o700, true);

        // Snapshot BEFORE clearing (the loop unsets HOME too), then re-arm —
        // the both-call shape HomeSandboxTrait documents.
        $this->useHomeSandbox($this->tempDir . '/home');
        foreach (self::SELECTION_ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        $this->useHomeSandbox($this->tempDir . '/home');

        // The launch mode is a process-wide static read by the funnel.
        Bootstrap::useSessionLaunch(false);
        Bootstrap::useMaxTurns(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useSessionLaunch(false);
        Bootstrap::useMaxTurns(null);

        foreach ($this->savedEnv as $key => $value) {
            if (is_string($value)) {
                putenv($key . '=' . $value);
            } else {
                putenv($key);
            }
        }
        $this->savedEnv = [];
        $this->restoreHomeSandbox();
        self::purgeTempTree($this->tempDir);

        parent::tearDown();
    }

    private function store(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->tempDir . '/home/.candy-code/session.db');
    }

    private static function purgeTempTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::purgeTempTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    /**
     * Run one plain (or flagged) one-shot, capturing its stdout in isolation.
     *
     * @param list<string> $extraArgv
     */
    private function oneShot(array $extraArgv): array
    {
        $args = ArgvParser::parse(array_merge(
            ['candy-code', '--root', $this->tempDir . '/repo'],
            $extraArgv,
        ));
        $this->assertNull($args->usageError, implode(' ', $extraArgv));

        ob_start();
        $code = NonInteractive::run($args, null, $args->outputFormat);
        $stdout = (string) ob_get_clean();

        return [$code, $stdout, $args];
    }

    /**
     * Declare prices for a named model in the sandbox's user config: the
     * `modelPrices` map is a user-tier setting (USD per 1M tokens), so writing
     * the file BEFORE the run is exactly what an operator does.
     *
     * @param array<string, mixed> $prices
     */
    private function writeModelPrices(string $model, array $prices): void
    {
        mkdir($this->tempDir . '/home/.candy-code', 0o700, true);
        self::assertIsInt(file_put_contents(
            $this->tempDir . '/home/.candy-code/config.json',
            json_encode(['modelPrices' => [$model => $prices]], JSON_THROW_ON_ERROR),
        ));
    }

    public function testABilledRunAccountsItsUsageRowIntoTheLedger(): void
    {
        [$process, $pipes, $address] = $this->startBilledEndpoint();

        $savedProvider = getenv('CANDY_CODE_PROVIDER');
        $savedBaseUrl = getenv('OPENAI_BASE_URL');
        $savedModel = getenv('CANDY_CODE_MODEL');
        putenv('CANDY_CODE_PROVIDER=custom');
        putenv('OPENAI_BASE_URL=http://' . $address . '/v1');
        putenv('CANDY_CODE_MODEL=cmp-ledger-model');
        // $1 per 1M tokens each direction: the wire's 11 + 7 tokens price to
        // a non-zero figure the ledger must carry unchanged.
        $this->writeModelPrices('cmp-ledger-model', ['input' => 1000, 'output' => 1000]);

        try {
            [$code, $stdout] = $this->oneShot(['-p', 'bill this run', '--output-format', 'json']);
            self::assertSame(NonInteractive::EXIT_OK, $code);
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);

            $id = (string) $document['session_id'];
            $totals = $this->store()->usageTotals($id);
            self::assertNotNull($totals, 'a billed run must leave a usage_records row, not just an envelope');
            self::assertSame(1, $totals['calls']);
            self::assertSame(18, $totals['totalTokens']);
            self::assertSame(
                $document['usage']['costUsd'],
                $totals['costUsd'],
                'the ledger carries the SAME figure the envelope reported, per token — a silently re-priced row is a bill the caller never saw',
            );
            self::assertFalse($totals['unpriced'], 'a priced model is measured, not a lower bound');

            $row = $this->store()->getSession($id);
            self::assertSame($totals['costUsd'], (float) $row['cost_usd'], 'the sessions-table spend cache is folded in the same write');
        } finally {
            $savedProvider === false ? putenv('CANDY_CODE_PROVIDER') : putenv('CANDY_CODE_PROVIDER=' . $savedProvider);
            $savedBaseUrl === false ? putenv('OPENAI_BASE_URL') : putenv('OPENAI_BASE_URL=' . $savedBaseUrl);
            $savedModel === false ? putenv('CANDY_CODE_MODEL') : putenv('CANDY_CODE_MODEL=' . $savedModel);
            $this->stopBilledEndpoint($process, $pipes);
        }
    }

    public function testAResumedRunAppendsToTheSameLedgerRow(): void
    {
        [$process, $pipes, $address] = $this->startBilledEndpoint();
        putenv('CANDY_CODE_PROVIDER=custom');
        putenv('OPENAI_BASE_URL=http://' . $address . '/v1');
        putenv('CANDY_CODE_MODEL=cmp-ledger-model');
        $this->writeModelPrices('cmp-ledger-model', ['input' => 1000, 'output' => 1000]);

        try {
            [$firstCode, $firstOut] = $this->oneShot(['-p', 'first billed', '--output-format', 'json']);
            self::assertSame(NonInteractive::EXIT_OK, $firstCode);
            $id = (string) json_decode(trim($firstOut), true)['session_id'];

            [$secondCode, $secondOut] = $this->oneShot([
                '-p', 'second billed', '--output-format', 'json', '--resume', $id,
            ]);
            self::assertSame(NonInteractive::EXIT_OK, $secondCode);
            self::assertSame($id, json_decode(trim($secondOut), true)['session_id'], 'the resume reuses the named row');

            $totals = $this->store()->usageTotals($id);
            self::assertNotNull($totals);
            self::assertSame(2, $totals['calls'], 'one appended row per billed run, not one overwrite');
            self::assertSame(36, $totals['totalTokens']);
            self::assertSame($totals['costUsd'], (float) $this->store()->getSession($id)['cost_usd']);
        } finally {
            putenv('CANDY_CODE_PROVIDER');
            putenv('OPENAI_BASE_URL');
            putenv('CANDY_CODE_MODEL');
            $this->stopBilledEndpoint($process, $pipes);
        }
    }

    public function testAnUnpricedModelReachesTheLedgerAsALowerBound(): void
    {
        [$process, $pipes, $address] = $this->startBilledEndpoint();
        putenv('CANDY_CODE_PROVIDER=custom');
        putenv('OPENAI_BASE_URL=http://' . $address . '/v1');
        putenv('CANDY_CODE_MODEL=cmp-bogus-model');
        // A declared rate that fails validation prices NOTHING and says so
        // loudly: the usage arrives with unpricedModel set (CustomProvider's
        // documented refusal to silently re-price the operator's own row).
        $this->writeModelPrices('cmp-bogus-model', ['input' => 'banana', 'output' => 1000]);

        try {
            [$code, $stdout] = $this->oneShot(['-p', 'bill this run', '--output-format', 'json']);
            self::assertSame(NonInteractive::EXIT_OK, $code);
            $id = (string) json_decode(trim($stdout), true)['session_id'];

            $totals = $this->store()->usageTotals($id);
            self::assertNotNull($totals, 'even an unpriced model produced real tokens the ledger must keep');
            self::assertSame(1, $totals['calls']);
            self::assertSame(18, $totals['totalTokens']);
            self::assertTrue($totals['unpriced']);
            self::assertSame(1, (int) $this->store()->getSession($id)['cost_unpriced'], 'the spend cache flags the bound');
        } finally {
            putenv('CANDY_CODE_PROVIDER');
            putenv('OPENAI_BASE_URL');
            putenv('CANDY_CODE_MODEL');
            $this->stopBilledEndpoint($process, $pipes);
        }
    }

    public function testAEchoRunLeavesNoLedgerRow(): void
    {
        [$code, $stdout] = $this->oneShot(['-p', 'quiet echo', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $code);
        $id = (string) json_decode(trim($stdout), true)['session_id'];

        self::assertNull(
            $this->store()->usageTotals($id),
            'nothing was measured, so nothing is accounted — the absent-usage envelope polarity holds in the ledger too',
        );
    }

    /**
     * The store-failure contract of the widened persist block: the answer is
     * already out, the save cannot retract it — exit 0 and EXACTLY one extra
     * stderr line naming the failure. Child processes because stderr has no
     * in-process capture seam, and the store is broken AFTER a successful run
     * so the funnel's read still lands (measured: a write-refusing SQLite
     * database opens and answers SELECTs) and the failure strikes the persist
     * block itself.
     */
    public function testAStoreThatCannotWriteCostsTheAnswerNothingButOneStderrLine(): void
    {
        $home = $this->tempDir . '/childhome';
        mkdir($home, 0o700, true);

        [$firstOut, $firstErr, $firstExit] = $this->runHeadlessChild(['-p', 'first question'], $home);
        self::assertSame(0, $firstExit, $firstErr);
        self::assertStringContainsString('first question', $firstOut);

        $dbPath = $home . '/.candy-code/session.db';
        self::assertFileExists($dbPath);
        // The child is gone, so this read opens the fresh-on-disk state the
        // child left behind — no live connection can paper over the mode flip.
        $childStore = new EnhancedSessionStore($dbPath);
        $id = (string) $childStore->listSessions(10)[0]['id'];
        unset($childStore);

        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                self::assertTrue(chmod($file, 0o444));
            }
        }

        [, $secondErr, $secondExit] = $this->runHeadlessChild(['-p', 'second question', '--resume', $id], $home);
        self::assertSame(0, $secondExit, 'a failed save must never cost the answer the caller already holds: ' . $secondErr);

        self::assertSame(
            1,
            substr_count($secondErr, 'the session store could not save this answer'),
            'the persist failure is exactly one announced line: ' . $secondErr,
        );
        self::assertSame(
            self::labelledStderrLines($firstErr) + 1,
            self::labelledStderrLines($secondErr),
            'no other stderr line appeared or vanished beside it',
        );
    }

    /** @param array<string, string> $extraEnv */
    private function runHeadlessChild(array $argv, string $home, array $extraEnv = []): array
    {
        $root = dirname(__DIR__, 2);
        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/candy-code');
        foreach ($argv as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, array_merge([
            'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $home,
            'TMPDIR' => $home,
        ], $extraEnv));
        self::assertIsResource($process, 'the headless child did not start');

        fclose($pipes[0]);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 60.0;
        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, $stderr, proc_close($process)];
    }

    /** Count the labelled `candy-code: ` announcement lines in a stderr capture. */
    private static function labelledStderrLines(string $stderr): int
    {
        return preg_match_all('/^candy-code: /m', $stderr);
    }

    /**
     * Launch the throwaway endpoint as a detached PHP child (the house
     * tempnam-script + proc_open-array shape) and wait for its READY line,
     * which carries the address it bound to port 0. The child self-expires:
     * a 20-second accept timeout ends its loop even if this process dies, so
     * a crash here leaves no listener behind.
     *
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    private function startBilledEndpoint(): array
    {
        $script = <<<'PHP'
            <?php
            $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($server === false) {
                fwrite(STDERR, "bind failed: {$errstr}\n");
                exit(1);
            }
            fwrite(STDOUT, 'READY ' . stream_socket_get_name($server, false) . "\n");
            fflush(STDOUT);

            $first = 'data: ' . json_encode(['choices' => [[
                'index' => 0,
                'delta' => ['role' => 'assistant', 'content' => 'billed answer'],
                'finish_reason' => 'stop',
            ]]]) . "\n\n";
            $usage = 'data: ' . json_encode(['choices' => [], 'usage' => [
                'prompt_tokens' => 11,
                'completion_tokens' => 7,
                'total_tokens' => 18,
            ]]) . "\n\n";
            $body = $first . $usage . "data: [DONE]\n\n";

            for ($seen = 0; $seen < 16; $seen++) {
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
                fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
                fclose($conn);
            }
            fclose($server);
            PHP;

        $file = $this->tempDir . '/billed-endpoint-child.php';
        self::assertIsInt(file_put_contents($file, $script));

        $process = proc_open([PHP_BINARY, $file], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process, 'the billed endpoint child did not start');
        stream_set_timeout($pipes[1], 10);

        $ready = fgets($pipes[1]);
        self::assertIsString($ready, 'the billed endpoint never announced its address');
        self::assertMatchesRegularExpression('/^READY 127\.0\.0\.1:\d+\R$/', $ready);

        return [$process, $pipes, substr(trim($ready), 6)];
    }

    /** @param array<int, resource> $pipes */
    private function stopBilledEndpoint($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, \defined('SIGTERM') ? SIGTERM : 15);
        }
        proc_close($process);
    }
}
