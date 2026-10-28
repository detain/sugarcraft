<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Host\TranscriptStore;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * AUT-2: `-p` runs persist a session through the SAME funnel a TUI launch
 * opens ({@see Bootstrap::openSession()}), and `--resume <target>` beside a
 * prompt resumes that stored transcript instead of refusing the parse.
 *
 * Every case drives {@see NonInteractive::run()} with NO injected backend —
 * the one shape that owns a session ($ownsSession) — against a sandbox HOME,
 * so the row/transcript assertions read the store the same code path wrote.
 *
 * Most cases answer from the offline echo default, and an ABSENT `usage`
 * there is the honest default this design commits to (`jq '.usage'` answers
 * null because nothing was measured). The billed case below closes the other
 * half of that claim (review w1a m-2): it points the funnel's own provider
 * selection at a throwaway OpenAI-compatible endpoint on loopback that DOES
 * report usage, so the observer → Usage::sum → envelope → store chain is
 * exercised POPULATED end-to-end, not only at the format layer. The
 * prune-free-read law of the funnel's store opens (review w1a m-1) is pinned
 * structurally over the run() body, because every functional mode of that
 * argument looks identical to a caller — only the sweep it would run is not.
 */
final class NonInteractiveSessionPersistenceTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * The variables that decide WHICH backend answers, cleared so the run
     * deterministically takes the offline echo path under the sandbox HOME.
     * (Not the provider-failure file's full ambient-credential sweep: this
     * class never selects a named provider, so a leaked key cannot change
     * which backend constructs.)
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

        $this->tempDir = sys_get_temp_dir() . '/crush-noninteractive-persist-' . bin2hex(random_bytes(6));
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

    public function testAPlainOneShotMintsItsRowAndSavesTheExchange(): void
    {
        [$code, $stdout] = $this->oneShot(['-p', 'first question']);

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertStringContainsString('first question', $stdout, 'the echo answer still rides stdout unchanged');

        $store = $this->store();
        $sessions = $store->listSessions(10);
        self::assertCount(1, $sessions, 'AUT-2: a one-shot run appears in the session list at all');

        $id = (string) $sessions[0]['id'];
        $row = $store->getSession($id);
        self::assertNotNull($row);
        // The label is the SAME selectedProviderLabel() the funnel passed to
        // openSession before any backend existed; recomputing it here reads
        // the identical offline-echo truth rather than a hand-copied pair.
        [$provider, $model] = Bootstrap::selectedProviderLabel();
        self::assertSame($provider, $row['provider'] ?? null, 'the row carries the real selection, not the save-time default pair');
        self::assertSame($model, $row['model'] ?? null);
        self::assertSame(1, (int) ($row['turns'] ?? 0), 'the lazy mint + recordTurn landed');
        self::assertSame('first question', $row['last_preview'] ?? null);

        $transcript = TranscriptStore::new($store)->load($id);
        self::assertCount(2, $transcript, 'user line + assistant answer persisted');
        self::assertSame(\SugarCraft\Code\Role::User, $transcript[0]->role);
        self::assertSame('first question', $transcript[0]->content);
        self::assertSame(\SugarCraft\Code\Role::Assistant, $transcript[1]->role);
    }

    public function testTheJsonEnvelopeCarriesSessionIdAndNoUnbilledUsage(): void
    {
        [$code, $stdout] = $this->oneShot(['-p', 'envelope check', '--output-format', 'json']);

        self::assertSame(NonInteractive::EXIT_OK, $code);
        $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(NonInteractive::SCHEMA, $document['schema']);
        self::assertStringContainsString('envelope check', (string) $document['result']);
        self::assertSame($this->store()->listSessions(10)[0]['id'], $document['session_id']);
        // Echo bills nothing; an absent key is the documented absence-until-
        // meaningful polarity, distinct from a zero-filled lie.
        self::assertArrayNotHasKey('usage', $document);
    }

    /**
     * C-AGT-7.b (AUT-2-P1): the id inside the json envelope is now the row
     * whose SAVE SUCCEEDED. Before this step the document was built first and
     * the save attempted after, so the ordering could not tell a caller the
     * truth — a store that refused the write still named a session the answer
     * was never written into, and `session_id` is exactly the key a CI consumer
     * follows with `--resume`.
     *
     * The lever is a WAL-mode store whose file has gone read-only AFTER a
     * first run minted a row: SQLite opens such a database happily and raises
     * only at the first write (MEASURED here, not assumed — a plain file-mode
     * refusal would have failed the funnel's open instead, which is the
     * pre-existing downgrade case, a different branch with the same-looking
     * document). So `openSession` succeeds, `$opened['id']` exists, and the
     * save is what fails — the ONLY state that distinguishes the old ordering
     * (id announced) from the new one (id withheld). Exit stays 0, the answer
     * stays whole, and the row stays exactly as the first run left it: the
     * failure is reported by the document's shape, not by a side effect.
     *
     * The post-failure read checks the row's own counter rather than loading
     * the transcript: a fresh connection cannot replay this database's -wal
     * sidecar while the file is read-only, so a load would report the
     * pre-existing downgrade case's symptom too and prove nothing about the
     * refused write. The exact shape of the failure is pinned structurally by
     * {@see testTheSaveRunsBeforeRenderingOnlyOnTheFormatsWhoseDocumentCarriesTheId()}.
     */
    public function testTheSessionIdKeyIsWithheldWhenTheSaveItselfFails(): void
    {
        [$firstCode] = $this->oneShot(['-p', 'first question']);
        self::assertSame(NonInteractive::EXIT_OK, $firstCode);
        $id = (string) $this->store()->listSessions(10)[0]['id'];

        $db = $this->tempDir . '/home/.candy-code/session.db';
        self::assertTrue(chmod($db, 0o444), 'the read-only lever must land or this test proves nothing');

        try {
            [$code, $stdout] = $this->oneShot(['-c', '-p', 'second question', '--output-format', 'json']);

            self::assertSame(NonInteractive::EXIT_OK, $code, 'a store that cannot write still does not cost the answer');
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('second question', (string) $document['result']);
            self::assertArrayNotHasKey(
                'session_id',
                $document,
                'the run cannot name a row it failed to write — announcing it is the lie this step closes',
            );

            $after = $this->store()->getSession($id);
            self::assertSame(1, (int) ($after['turns'] ?? 0), 'the refused save left the first run exactly as it was');
        } finally {
            chmod($db, 0o600);
        }
    }

    /**
     * C-AGT-7.b, structural arm: WHEN each format persists is a contract the
     * envelope cannot show from the happy path alone (a successful save looks
     * identical in either order), so the source-slice owns it. Under the two
     * JSON formats the save runs BEFORE the render; under `text` the answer
     * stays first and the save follows the echo. The success idiom appears
     * exactly once, so no ordering arm can grow a second, differently
     * judged notion of "saved".
     */
    public function testTheSaveRunsBeforeRenderingOnlyOnTheFormatsWhoseDocumentCarriesTheId(): void
    {
        $run = self::sliceRunBody(
            (string) file_get_contents(__DIR__ . '/../../src/Cli/NonInteractive.php')
        );

        $persist = strpos($run, '$persistedId = $persistsFirst ? $persistSession() : null;');
        $format = strpos($run, '$rendered = self::format(');
        $afterAnswer = strpos($run, 'if (!$persistsFirst) {');

        self::assertNotFalse($persist, 'the json/stream-json save site must stay in run() — name it differently if the shape moves');
        self::assertNotFalse($format);
        self::assertNotFalse($afterAnswer, 'the text arm must stay after the answer');
        self::assertLessThan($format, $persist, 'under json the save precedes the render, so the id it announces is the id it wrote');
        self::assertLessThan($afterAnswer, $format, 'under text the answer is still out before the save runs');
        self::assertSame(
            1,
            substr_count($run, 'return (string) $opened'),
            'exactly one success path returns the id; an ordering fork that invents its own notion of "saved" is the drift this pins out',
        );
        self::assertSame(
            1,
            substr_count($run, "\$persistsFirst ? \$persistedId : (\$opened['id'] ?? null)"),
            'the id handed to the renderer is chosen by ONE expression, the format test, never re-derived per arm',
        );
    }

    public function testContinueSeedsTheStoredTranscriptAndAppendsTheSecondExchange(): void
    {
        [$firstCode] = $this->oneShot(['-p', 'first question']);
        self::assertSame(NonInteractive::EXIT_OK, $firstCode);
        $id = (string) $this->store()->listSessions(10)[0]['id'];

        [$code, $stdout] = $this->oneShot(['-c', '-p', 'second question']);
        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertStringContainsString('second question', $stdout);

        $sessions = $this->store()->listSessions(10);
        self::assertCount(1, $sessions, '-c beside -p continues the cwd row instead of minting a second');

        $transcript = TranscriptStore::new($this->store())->load($id);
        $roles = array_map(static fn ($m): string => $m->role->value . ':' . $m->content, $transcript);
        self::assertSame(
            ['user:first question', 'assistant:You said:

> first question', 'user:second question', 'assistant:You said:

> second question'],
            $roles,
            'the seed keeps the earlier exchange and the save appends the new one — a dropped seed would truncate this row back to two',
        );
        self::assertSame(2, (int) ($this->store()->getSession($id)['turns'] ?? 0));
    }

    public function testResumeByTargetSeedsThatRowEvenWhenNewerOnesExist(): void
    {
        $this->oneShot(['-p', 'first question']);
        $first = (string) $this->store()->listSessions(10)[0]['id'];

        $this->oneShot(['-p', 'intervening']);
        [$code] = $this->oneShot(['-p', 'back to the first', '--resume', $first]);
        self::assertSame(NonInteractive::EXIT_OK, $code);

        $transcript = TranscriptStore::new($this->store())->load($first);
        $contents = array_map(static fn ($m): string => $m->content, $transcript);
        self::assertContains('first question', $contents);
        self::assertContains('back to the first', $contents);
        self::assertNotContains('intervening', $contents, 'the targeted resume opened the named row, not the latest');

        self::assertCount(2, $this->store()->listSessions(10), 'the resumed run wrote no new row');
    }

    public function testAnUnknownResumeTargetRefusesBeforeAnswering(): void
    {
        [$code, $stdout] = $this->oneShot(['-p', 'hi', '--resume', 'nosuchtarget']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $code);
        self::assertSame('', $stdout, 'no answer is produced for a session that does not exist');
    }

    public function testABrokenStoreDowngradesToThePreAutTwoRunInsteadOfCostingTheAnswer(): void
    {
        // A DIRECTORY where the SQLite file belongs: PDO cannot open it, so
        // the funnel's catch fires and the run proceeds exactly as a pre-AUT-2
        // one-shot did - answer on stdout, nothing persisted, exit unchanged.
        mkdir($this->tempDir . '/home/.candy-code', 0o700, true);
        mkdir($this->tempDir . '/home/.candy-code/session.db', 0o700, true);

        [$code, $stdout] = $this->oneShot(['-p', 'answer me']);

        self::assertSame(NonInteractive::EXIT_OK, $code, 'a store that cannot open must not cost the answer');
        self::assertStringContainsString('answer me', $stdout);
    }

    public function testFormatCarriesTheConditionalKeysOnlyWhenGivenThem(): void
    {
        $message = \SugarCraft\Code\Message::assistant('a steady answer');

        $bare = json_decode(NonInteractive::format($message, NonInteractive::FORMAT_JSON), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('session_id', $bare, 'an injected-backend or funnel-failed run keeps the pre-AUT-2 envelope byte-identically');
        self::assertArrayNotHasKey('usage', $bare);

        $usage = \SugarCraft\Code\Usage::new(totalTokens: 42, costUsd: 0.25)->toArray();
        $full = json_decode(
            NonInteractive::format($message, NonInteractive::FORMAT_JSON, [], 'sess-1', $usage),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('sess-1', $full['session_id']);
        self::assertSame(42, $full['usage']['totalTokens'], 'the buckets ride Usage::toArray verbatim');
        self::assertSame(0.25, $full['usage']['costUsd']);
    }

    /**
     * Review w1a m-1, structural arm: BOTH of run()'s session-store opens read
     * with `prune: false`, and neither may drift to the pruning default. A
     * read-only one-shot run must not delete rows it never wrote — the sweep
     * belongs to the TUI's owning launch ({@see Bootstrap::sessionStore()}'s
     * docblock says so, and this slice is the only teeth that claim has.
     *
     * WHY STRUCTURAL AND NOT FUNCTIONAL: both argument modes hand back the
     * same store object, so no caller-observable behaviour distinguishes them
     * inside one run; the only honest witness is the call-site text itself.
     */
    public function testTheOneShotFunnelOpensTheSessionStoreWithoutPruning(): void
    {
        $run = self::sliceRunBody(
            (string) file_get_contents(__DIR__ . '/../../src/Cli/NonInteractive.php')
        );

        self::assertSame(
            2,
            substr_count($run, 'Bootstrap::sessionStore(prune: false)'),
            'run() opens the store exactly twice — the funnel read and the @session: mention closure — and both must say prune: false',
        );
        self::assertSame(
            1,
            substr_count($run, '$sessionStore = Bootstrap::sessionStore(prune: false);'),
            'the funnel site keeps its assignment form; a rewrite that drops the named variable would hide a later pruning reopen from this census',
        );
        self::assertSame(
            1,
            substr_count($run, 'static fn () => Bootstrap::sessionStore(prune: false)'),
            'the @session: mention closure stays lazy AND prune-free',
        );
        self::assertSame(
            0,
            preg_match_all('/Bootstrap::sessionStore\((?:\s*prune:\s*true\s*)?\)/', $run),
            'a bare sessionStore() re-enables the retention sweep by default — as loud a violation as naming true',
        );
    }

    /**
     * Slice run()'s body out of the NonInteractive source. The terminator is
     * the first column-4 closing brace, which cannot precede run()'s own end:
     * every block nested inside a PSR-12 method closes at eight or deeper.
     */
    private static function sliceRunBody(string $source): string
    {
        $start = strpos($source, 'public static function run(');
        if ($start === false) {
            self::fail('NonInteractive no longer declares "public static function run(" — update this pin, do not delete it.');
        }

        $end = strpos($source, "\n    }\n", $start);
        if ($end === false) {
            self::fail('Could not find the end of NonInteractive::run().');
        }

        return substr($source, $start, $end - $start);
    }

    /**
     * Review w1a m-2: the populated half of the envelope, end-to-end through
     * the funnel's OWN provider selection. A throwaway loopback endpoint
     * answers the OpenAI-compatible batch call with real usage figures; the
     * run is pointed at it with the same two environment variables an
     * operator would use (CANDY_CODE_PROVIDER + OPENAI_BASE_URL), so
     * backendFor() builds a REAL EngineBackend, withStepUsageObserver fires
     * with a billed Usage, and session_id + usage must BOTH land in the
     * envelope AND the persisted row. Absence pins prove the null shape;
     * this proves the wire-to-store chain actually carries numbers.
     */
    public function testABilledOneShotPopulatesSessionIdAndUsageEndToEnd(): void
    {
        [$process, $pipes, $address] = $this->startBilledEndpoint();

        $savedProvider = getenv('CANDY_CODE_PROVIDER');
        $savedBaseUrl = getenv('OPENAI_BASE_URL');
        putenv('CANDY_CODE_PROVIDER=custom');
        putenv('OPENAI_BASE_URL=http://' . $address . '/v1');

        try {
            [$code, $stdout] = $this->oneShot(['-p', 'bill this run', '--output-format', 'json']);

            self::assertSame(NonInteractive::EXIT_OK, $code);
            $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);

            $id = (string) $this->store()->listSessions(10)[0]['id'];
            self::assertSame($id, $document['session_id'], 'the envelope names the row the funnel minted');
            self::assertSame('billed answer', $document['result']);

            self::assertArrayHasKey('usage', $document, 'a provider that reported 18 tokens must not envelope as if it measured nothing');
            self::assertSame(18, $document['usage']['totalTokens']);
            self::assertSame(11, $document['usage']['inputTokens']);
            self::assertSame(7, $document['usage']['outputTokens']);
            // Numeric, not a fixed figure: the dollar value is whatever the
            // active price table says for the model today (the built-in
            // custom-server table answers 0), and the token legs above are
            // what this case exists to prove end-to-end.
            self::assertIsNumeric($document['usage']['costUsd']);

            $row = $this->store()->getSession($id);
            self::assertSame('custom', $row['provider'] ?? null, 'the row carries the selected provider, not the echo default');
            $transcript = TranscriptStore::new($this->store())->load($id);
            self::assertCount(2, $transcript, 'the billed answer persisted through the same save as the echo cases');
        } finally {
            $savedProvider === false ? putenv('CANDY_CODE_PROVIDER') : putenv('CANDY_CODE_PROVIDER=' . $savedProvider);
            $savedBaseUrl === false ? putenv('OPENAI_BASE_URL') : putenv('OPENAI_BASE_URL=' . $savedBaseUrl);
            $this->stopBilledEndpoint($process, $pipes);
        }
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
