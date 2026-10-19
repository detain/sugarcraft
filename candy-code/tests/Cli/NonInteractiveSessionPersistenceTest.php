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
 * the one shape that owns a session ($ownsSession) — against a sandbox HOME
 * and the offline echo default, so the answer is deterministic, nothing
 * touches the network, and the row/transcript assertions read the store the
 * same code path wrote. The envelope's usage leg is pinned at the format
 * layer instead of the e2e layer on purpose: the echo provider bills nothing,
 * and an ABSENT `usage` there is the honest default this design commits to
 * (`jq '.usage'` answers null because nothing was measured).
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
}
