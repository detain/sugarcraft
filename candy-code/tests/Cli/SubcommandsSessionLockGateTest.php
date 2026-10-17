<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * ISS-079: `session delete|rename|pin|unpin|archive|unarchive` against a row
 * another LIVE candy-code window holds are refused before the mutation is
 * attempted — the live window keeps writing the row, so the CLI's promise
 * could only be a lie — and `--force` overrides. A lock file left behind by a
 * dead process refuses nothing: the gate asks the flock, never the pid file.
 *
 * Driven in-process like {@see SessionSubcommandsTest} (HOME sandbox +
 * `Subcommands::dispatch`), asserting the `--output-format json` document
 * because in-process stderr is not capturable.
 */
final class SubcommandsSessionLockGateTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;
    private EnhancedSessionStore $store;
    private string|false $originalRetention;

    protected function setUp(): void
    {
        $this->home = (string) realpath(sys_get_temp_dir()) . '/iss079-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->home . '/.candy-code', 0700, true));
        chmod($this->home, 0700);
        $this->originalRetention = getenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        $this->useHomeSandbox($this->home);
        putenv('CANDY_CODE_SESSION_RETENTION_DAYS');

        $this->store = new EnhancedSessionStore($this->home . '/.candy-code/session.db');
        $this->store->createSession('sess-live-0001', 'openai', 'gpt-4o', null, 'open window');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        putenv($this->originalRetention === false
            ? 'CANDY_CODE_SESSION_RETENTION_DAYS'
            : 'CANDY_CODE_SESSION_RETENTION_DAYS=' . $this->originalRetention);
        $this->removeSandboxTree($this->home);
    }

    private function removeSandboxTree(string $dir): void
    {
        // chr(42), not a glob-shaped literal: see the GlobDialect note in
        // LatestResumableSessionCwdTest.
        foreach (glob($dir . '/.candy-code/sessions/' . chr(42)) ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($dir . '/.candy-code/' . chr(42)) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir . '/.candy-code/sessions');
        @rmdir($dir . '/.candy-code');
        @rmdir($dir);
    }

    /**
     * The OTHER end of the store is a second instance over the same file:
     * exactly what a second TUI process is, minus the process.
     */
    private function reopening(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->home . '/.candy-code/session.db');
    }

    /** @param list<string> $args @return array{0:int,1:string} */
    private function runVerb(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['candy-code', ...$args, '--output-format', 'json']));

        return [$rc, (string) ob_get_clean()];
    }

    public function testAVerbOnAnUnlockedRowIsNotGated(): void
    {
        [$rc, $out] = $this->runVerb(['session', 'delete', 'sess-live-0001']);

        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        self::assertNull($this->reopening()->getSession('sess-live-0001'));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function gatedVerbs(): iterable
    {
        yield 'delete' => [['session', 'delete', 'sess-live-0001']];
        yield 'rename' => [['session', 'rename', 'sess-live-0001', 'stolen title']];
        yield 'pin' => [['session', 'pin', 'sess-live-0001']];
        yield 'unpin' => [['session', 'unpin', 'sess-live-0001']];
        yield 'archive' => [['session', 'archive', 'sess-live-0001']];
        yield 'unarchive' => [['session', 'unarchive', 'sess-live-0001']];
    }

    #[DataProvider('gatedVerbs')]
    public function testEveryMutatingVerbRefusesALiveLockedRow(array $verb): void
    {
        $held = $this->store->lockSession('sess-live-0001');
        self::assertNotNull($held, 'the fixture could not take its own lock.');

        [$rc, $out] = $this->runVerb($verb);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $out);
        $document = json_decode($out, true);
        self::assertSame('usage', $document['error']['type'], $out);
        self::assertStringContainsString('another candy-code', $document['error']['message']);
        self::assertStringContainsString((string) getmypid(), $document['error']['message'], 'the refusal names the holder');
        // The row is untouched: the promise of the gate is that the mutation
        // was never attempted.
        $row = $this->reopening()->getSession('sess-live-0001');
        self::assertNotNull($row);
        self::assertSame('open window', $row['name']);
        self::assertFalse((bool) $row['pinned']);
        self::assertNull($row['archived_at'] ?? null);
    }

    #[DataProvider('gatedVerbs')]
    public function testForceActsOnALiveLockedRow(array $verb): void
    {
        $held = $this->store->lockSession('sess-live-0001');
        self::assertNotNull($held);

        [$rc, $out] = $this->runVerb([...$verb, '--force']);

        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
    }

    public function testAStaleLockFileFromADeadProcessHoldsNothingBack(): void
    {
        $directory = $this->home . '/.candy-code/' . SessionLock::DIRECTORY;
        mkdir($directory, 0700, true);
        // A pid that cannot be us: some long-dead window's leftovers. A
        // pid-file reader would refuse; the flock probe takes the lock,
        // releases it, and lets the verb through.
        file_put_contents(SessionLock::pathFor($directory, 'sess-live-0001'), '999999' . "\n");
        self::assertNotNull($this->reopening()->sessionLockHolder('sess-live-0001'), 'the pid file is there for the reader that trusts it');
        self::assertNull($this->reopening()->liveLockHolder('sess-live-0001'));

        [$rc, $out] = $this->runVerb(['session', 'delete', 'sess-live-0001']);

        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        self::assertNull($this->reopening()->getSession('sess-live-0001'));
    }

    public function testSessionListAndShowAreNeverGated(): void
    {
        $held = $this->store->lockSession('sess-live-0001');
        self::assertNotNull($held);

        [$rc, $out] = $this->runVerb(['session', 'show', 'sess-live-0001']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        [$rc, $out] = $this->runVerb(['session', 'list', '--all']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
    }

    public function testForceOnALockedAwayTargetStillResolvesNotFoundFirst(): void
    {
        // --force overrides the lock question, never the resolution question.
        [$rc, $out] = $this->runVerb(['session', 'delete', 'nope', '--force']);

        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $out);
        self::assertSame('not-found', json_decode($out, true)['error']['type']);
    }
}
