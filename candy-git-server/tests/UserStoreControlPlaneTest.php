<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\UserStore;
use SugarCraft\GitServer\User;

/**
 * Control-plane persistence pins .
 *
 * Before UserStore existed, `user add` / `user key` / `user list` printed
 * durability claims while storing nothing — a fresh process could never see
 * what the last one claimed to create. These tests pin the fix from BOTH
 * sides: the store's on-disk contract, and whole-child-process CLI runs that
 * prove a record created in one process is readable in the next. Reverting
 * the persistence wiring reddens the cross-process legs even if the in-memory
 * output lines stay pretty.
 */
final class UserStoreControlPlaneTest extends TestCase
{
    private string $tmpDir;

    /** A base64 blob long enough to pass the ed25519 minimum (68 chars). */
    private const ED25519_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOkFq0Z4T1CkPMWStQ8kQ0kM0kFq0Z4T1CkPMWStQ8kT pin@candy-git-server';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/gitserver-userstore-' . \uniqid();
        \mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tmpDir . '/**/*') ?: [] as $f) {
            if (\is_file($f)) {
                @\unlink($f);
            }
        }
        foreach (\array_reverse(\glob($this->tmpDir . '/*') ?: []) as $d) {
            if (\is_dir($d)) {
                @\rmdir($d);
            }
        }
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Store unit contract
    // -------------------------------------------------------------------------

    public function testAddedUserRoundTripsThroughDiskWithKeysAndFlags(): void
    {
        $path = $this->tmpDir . '/users.json';
        $store = new UserStore($path);
        $this->assertTrue($store->add(User::new('alice')->withAdmin(true)->addAuthorizedKey(self::ED25519_KEY)));
        $store->save();

        $reread = new UserStore($path);
        $alice = $reread->find('alice');
        $this->assertNotNull($alice, 'a saved account MUST be visible to the next store instance');
        $this->assertTrue($alice->isAdmin);
        $this->assertTrue($alice->isActive);
        $this->assertSame([self::ED25519_KEY], $alice->authorizedKeysList());
    }

    public function testAddRefusesDuplicateAndChangesNothing(): void
    {
        $store = new UserStore($this->tmpDir . '/users.json');
        $this->assertTrue($store->add(User::new('bob')->withAdmin(true)));
        $this->assertFalse($store->add(User::new('bob')->withAdmin(false)), 'duplicate username must be refused, never silently overwritten');
        $this->assertTrue($store->find('bob')->isAdmin, 'the refused add must not have mutated the stored row');
    }

    public function testStoreFileNeverContainsCleartextPassword(): void
    {
        $path = $this->tmpDir . '/users.json';
        $store = new UserStore($path);
        $store->add(User::new('carol')->withPasswordHash(\password_hash('hunter2', PASSWORD_DEFAULT)));
        $store->save();

        $raw = (string) \file_get_contents($path);
        $this->assertStringNotContainsString('hunter2', $raw, 'the on-disk form stores digests only');
        $this->assertStringContainsString('$', $raw);
    }

    public function testSaveIsAtomicLeavesNoTempResidueAndCarriesZeroSixZeroZero(): void
    {
        $path = $this->tmpDir . '/users.json';
        $store = new UserStore($path);
        $store->add(User::new('dave'));
        $store->save();

        $this->assertSame([], \glob(\dirname($path) . '/users.json.*.tmp'), 'atomic publish must not strand temp files');
        if (\DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0600, \fileperms($path) & 0777, 'accounts file holds authentication material');
        }
    }

    public function testSaveSettlesThePayloadWithFsyncBeforeThePublishRename(): void
    {
        // The class docblock promises "flushed, fsync'd, then renamed" — the
        // only durable ordering. Pinned structurally on the method source:
        // every functional save still passes when the fsync is removed, so
        // a behavior test here would be vacuous by construction.
        // (S11 moved the durable body behind save() into publish(); the
        // chain being pinned is unchanged, only its address.)
        $reflection = new \ReflectionMethod(UserStore::class, 'publish');
        $lines = \file((string) $reflection->getFileName());
        $body = \implode('', \array_slice(
            (array) $lines,
            (int) $reflection->getStartLine() - 1,
            (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1
        ));

        $this->assertSame(1, \substr_count($body, 'fsync('), 'exactly one settle point for the payload');

        $write  = \strpos($body, 'fwrite(');
        $flush  = \strpos($body, 'fflush(');
        $sync   = \strpos($body, 'fsync(');
        $close  = \strpos($body, 'fclose(');
        $rename = \strpos($body, 'rename(');
        foreach (['fwrite', 'fflush', 'fsync', 'fclose', 'rename'] as $i => $step) {
            $this->assertIsInt(
                [$write, $flush, $sync, $close, $rename][$i],
                "save() must contain the {$step} step of the durable publish chain"
            );
        }
        $this->assertLessThan((int) $flush, (int) $write, 'payload lands before the flush');
        $this->assertLessThan((int) $sync, (int) $flush, 'flush precedes the settle');
        $this->assertLessThan((int) $close, (int) $sync, 'the settle happens on the open handle');
        $this->assertLessThan((int) $rename, (int) $close, 'nothing is still in flight when the name flips');
    }

    public function testCorruptStoreFailsLoudInsteadOfReadingAsEmpty(): void
    {
        $path = $this->tmpDir . '/users.json';
        \file_put_contents($path, '{not json');

        $this->expectException(\RuntimeException::class);
        new UserStore($path);
    }

    public function testMissingStoreIsAnEmptyStoreNotAnError(): void
    {
        $store = UserStore::forDataPath($this->tmpDir);
        $this->assertSame([], $store->all());
    }

    // -------------------------------------------------------------------------
    // Whole-CLI cross-process pins (the anti-theater keystone)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $env
     *
     * @return array{rc: int, out: string, err: string}
     */
    private function runBin(array $args, array $env = []): array
    {
        $bin = (string) \realpath(__DIR__ . '/../bin/candy-git-server');
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '15', \PHP_BINARY, $bin, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            $env + ['PATH' => \getenv('PATH') ?: '/usr/bin:/bin']
        );
        $this->assertIsResource($proc);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        return ['rc' => \proc_close($proc), 'out' => $out, 'err' => $err];
    }

    public function testUserAddInOneProcessIsVisibleInAFreshProcess(): void
    {
        $env = ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir];
        $add = $this->runBin(['user', 'add', 'alice'], $env);
        $this->assertSame(0, $add['rc'], $add['err']);
        $this->assertFileExists($this->tmpDir . '/users.json', 'user add must write the store');

        // A brand-new process — the pre-fix theater died exactly here.
        $list = $this->runBin(['user', 'list'], $env);
        $this->assertSame(0, $list['rc']);
        $this->assertStringContainsString('alice', $list['out']);
        $this->assertStringNotContainsString('No users registered', $list['out']);
    }

    public function testDuplicateUserAddRefusesWithNonZeroExit(): void
    {
        $env = ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir];
        $this->assertSame(0, $this->runBin(['user', 'add', 'bob'], $env)['rc']);
        $again = $this->runBin(['user', 'add', 'bob'], $env);
        $this->assertSame(1, $again['rc']);
        $this->assertStringContainsString('already exists', $again['err']);
    }

    public function testDotDotUsernameIsRefused(): void
    {
        $env = ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir];
        $r = $this->runBin(['user', 'add', '..'], $env);
        $this->assertSame(1, $r['rc'], 'the C-05 gate shape must not be reachable via usernames');
        $this->assertFileDoesNotExist($this->tmpDir . '/users.json');
    }

    public function testUserKeyPersistsToTheStoredAccountAndRejectsUnknownUsers(): void
    {
        $env = ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir];
        $keyFile = $this->tmpDir . '/alice.pub';
        \file_put_contents($keyFile, self::ED25519_KEY . "\n");

        $orphan = $this->runBin(['user', 'key', 'ghost', $keyFile], $env);
        $this->assertSame(1, $orphan['rc'], 'keys for nonexistent accounts must be refused loudly');

        $this->assertSame(0, $this->runBin(['user', 'add', 'alice'], $env)['rc']);
        $key = $this->runBin(['user', 'key', 'alice', $keyFile], $env);
        $this->assertSame(0, $key['rc'], $key['err']);

        $alice = (new UserStore($this->tmpDir . '/users.json'))->find('alice');
        $this->assertNotNull($alice);
        $this->assertSame([self::ED25519_KEY], $alice->authorizedKeysList(), 'the key must live on the STORED account, not a throwaway');
    }

    public function testUserKeyWithInvalidKeyFailsCleanlyWithoutFatalTrace(): void
    {
        $env = ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir];
        $this->assertSame(0, $this->runBin(['user', 'add', 'dave'], $env)['rc']);
        $badKeyFile = $this->tmpDir . '/bad.pub';
        \file_put_contents($badKeyFile, "not-a-key\n");

        $r = $this->runBin(['user', 'key', 'dave', $badKeyFile], $env);
        $this->assertSame(1, $r['rc']);
        $this->assertStringNotContainsString('Fatal error', $r['err'], 'invalid input is a refusal, not a stack trace');
        $this->assertStringContainsString('Invalid SSH public key', $r['err']);
    }

    public function testListRefusesLoudlyOnCorruptStore(): void
    {
        \file_put_contents($this->tmpDir . '/users.json', 'garbage');
        $r = $this->runBin(['user', 'list'], ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir]);
        $this->assertSame(1, $r['rc']);
        $this->assertStringContainsString('corrupt', $r['err']);
    }

    /**
     * The refusal wording used to name `auth.secret` — a key the loader
     * never parses. Operators following it would add a dead top-level
     * section; the only parsed home for the secret is http.auth_secret.
     */
    public function testUserTokenRefusalNamesTheParsedConfigKeyNotADeadOne(): void
    {
        \file_put_contents($this->tmpDir . '/config.yaml', "http:\n  listen_addr: \":0\"\n");
        $r = $this->runBin(['user', 'token', 'alice'], ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir]);
        $this->assertSame(1, $r['rc']);
        $this->assertStringContainsString('http.auth_secret', $r['err']);
        $this->assertStringNotContainsString('token: auth.secret', $r['err']);
    }

    public function testUserTokenMintsWhenSecretSitsUnderHttp(): void
    {
        \file_put_contents($this->tmpDir . '/config.yaml', "http:\n  auth_secret: \"s3cr3t\"\n");
        $r = $this->runBin(['user', 'token', 'alice'], ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir]);
        $this->assertSame(0, $r['rc'], $r['out'] . $r['err']);
        $this->assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/', \trim($r['out']));
    }

    // -------------------------------------------------------------------------
    // S11: version stamp, map-key integrity, transaction semantics
    // -------------------------------------------------------------------------

    public function testSaveStampsTheVersionAndALegacyUnstampedFileStillReads(): void
    {
        // Legacy (v0-era) file: no version key at all — must read fine.
        $path = $this->tmpDir . '/users.json';
        \file_put_contents($path, \json_encode([
            'users' => ['old' => ['username' => 'old', 'admin' => true, 'active' => true, 'authorized_keys' => []]],
        ]));
        $store = new UserStore($path);
        $this->assertNotNull($store->find('old'));

        // And the first save re-publishes it stamped.
        $store->put(User::new('old')->withAdmin(true));
        $store->save();
        $decoded = (array) \json_decode((string) \file_get_contents($path), true);
        $this->assertSame(UserStore::VERSION, $decoded['version']);
    }

    public function testNewerFormatRefusesToLoadInsteadOfDroppingFields(): void
    {
        $path = $this->tmpDir . '/users.json';
        \file_put_contents($path, \json_encode([
            'version' => 999,
            'users' => ['x' => ['username' => 'x', 'admin' => false, 'active' => true]],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/newer than this server/');
        new UserStore($path);
    }

    public function testMapKeyDivergingFromRowUsernameRefusesAsCorruption(): void
    {
        // Finding 106: an access decision keyed by the payload while the
        // file labels it differently is identity smuggling, not a quirk.
        $path = $this->tmpDir . '/users.json';
        \file_put_contents($path, \json_encode([
            'users' => ['alice' => ['username' => 'mallory', 'admin' => true, 'active' => true, 'authorized_keys' => []]],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/map key .alice. carries username .mallory./');
        new UserStore($path);
    }

    public function testTransactionReReadsUnderTheLockSoAStaleInstanceSeesFreshBytes(): void
    {
        $path = $this->tmpDir . '/users.json';
        $early = new UserStore($path);   // snapshot: empty world

        $late = new UserStore($path);
        $late->transaction(static function (UserStore $s): bool {
            return $s->add(User::new('first'));
        });

        // The stale instance's NEXT transaction must see the other
        // writer's committed row — that re-read is what serializes CLI
        // processes instead of losing updates.
        $sawFirst = null;
        $early->transaction(static function (UserStore $s) use (&$sawFirst): void {
            $sawFirst = $s->find('first') !== null;
            $s->add(User::new('second'));
        });
        $this->assertTrue($sawFirst, 'transaction must not operate on the constructor snapshot');

        $final = new UserStore($path);
        $this->assertNotNull($final->find('first'));
        $this->assertNotNull($final->find('second'), 'both writers must survive');
    }

    public function testTransactionAbortsThePublishWhenTheMutatorThrows(): void
    {
        $path = $this->tmpDir . '/users.json';
        $store = new UserStore($path);
        $store->transaction(static function (UserStore $s): void {
            $s->add(User::new('kept'));
        });

        try {
            $store->transaction(static function (UserStore $s): void {
                $s->add(User::new('rolled-back'));
                throw new \RuntimeException('mid-flight fault');
            });
            $this->fail('the mutator exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('mid-flight fault', $e->getMessage());
        }

        $after = new UserStore($path);
        $this->assertNotNull($after->find('kept'));
        $this->assertNull($after->find('rolled-back'), 'a thrown transaction publishes nothing');

        // And the re-entry guard released with the exception:
        $after->transaction(static function (UserStore $s): void {
            $s->add(User::new('next-one-works'));
        });
        $this->assertNotNull((new UserStore($path))->find('next-one-works'));
    }

    public function testNestedTransactionRefusesLoudly(): void
    {
        $store = new UserStore($this->tmpDir . '/users.json');

        $this->expectException(\LogicException::class);
        $store->transaction(static function (UserStore $s): void {
            $s->transaction(static function (UserStore $inner): void {
                $inner->add(User::new('never'));
            });
        });
    }

    // -------------------------------------------------------------------------
    // S23 (finding 040): the revocation door — remove + deactivate
    // -------------------------------------------------------------------------

    public function testRemoveInsideTheTransactionErasesTheRowForEveryFreshReader(): void
    {
        $path = $this->tmpDir . '/users.json';
        $store = new UserStore($path);
        $store->transaction(static fn (UserStore $s): bool => $s->add(User::new('alice')));

        $stale = new UserStore($path);
        $this->assertTrue($stale->transaction(static fn (UserStore $s): bool => $s->remove('alice')));

        $fresh = new UserStore($path);
        $this->assertNull($fresh->find('alice'), 'a committed remove must be visible to the next reader');
        $this->assertSame([], $fresh->all());
    }

    public function testRemoveOfUnknownUserAnswersFalseAndPublishesNothing(): void
    {
        $path = $this->tmpDir . '/users.json';
        $store = new UserStore($path);
        $store->transaction(static fn (UserStore $s): bool => $s->add(User::new('bob')));
        $bytesBefore = (string) \file_get_contents($path);

        $answer = $store->transaction(static fn (UserStore $s): bool => $s->remove('ghost'));
        $this->assertFalse($answer);
        $this->assertSame($bytesBefore, \file_get_contents($path), 'an unpublishable no-op must not rewrite the store');
    }

    public function testDeactivateCommitsTheInactiveRowAndKeepsEveryCredential(): void
    {
        $path = $this->tmpDir . '/users.json';
        $store = new UserStore($path);
        $on = $store->transaction(static function (UserStore $s): User {
            $u = User::new('carol')->withPasswordHash(\password_hash('pw', \PASSWORD_DEFAULT));
            $s->put($u);

            return $u;
        });

        $off = $store->transaction(static fn (UserStore $s): ?User => $s->deactivate('carol'));
        $this->assertNotNull($off);
        $this->assertFalse($off->isActive, 'the returned VO is the row that was committed');
        $this->assertSame($on->passwordHash, $off->passwordHash, 'credentials survive deactivation intact');

        $fresh = new UserStore($path);
        $reread = $fresh->find('carol');
        $this->assertNotNull($reread);
        $this->assertFalse($reread->isActive, 'the inactive posture reached disk');
    }

    public function testUnknownUserRemoveAndDeactivateExitOneOverTheCli(): void
    {
        $env = ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir];
        $rm = $this->runBin(['user', 'remove', 'ghost'], $env);
        $this->assertSame(1, $rm['rc'], 'revoking a name that never existed is a refusal, not a success');
        $this->assertStringContainsString('No such user', $rm['err']);
        $this->assertFileDoesNotExist($this->tmpDir . '/users.json');

        $off = $this->runBin(['user', 'deactivate', 'ghost'], $env);
        $this->assertSame(1, $off['rc']);
        $this->assertStringContainsString('No such user', $off['err']);
    }

    public function testRemoveAndDeactivateRoundTripOverTheCli(): void
    {
        $env = ['CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir];
        $this->assertSame(0, $this->runBin(['user', 'add', 'alice'], $env)['rc']);

        $deact = $this->runBin(['user', 'deactivate', 'alice'], $env);
        $this->assertSame(0, $deact['rc'], $deact['err']);
        $this->assertStringContainsString('active: false', $deact['out']);
        $row = \json_decode((string) \file_get_contents($this->tmpDir . '/users.json'), true);
        $this->assertFalse($row['users']['alice']['active'], 'the echo must match the persisted row');

        // Second deactivate is honest about the repeat, never a false new revocation.
        $again = $this->runBin(['user', 'deactivate', 'alice'], $env);
        $this->assertSame(0, $again['rc']);
        $this->assertStringContainsString('already inactive', $again['out']);

        $rm = $this->runBin(['user', 'remove', 'alice'], $env);
        $this->assertSame(0, $rm['rc'], $rm['err']);
        $this->assertStringContainsString('removed from', $rm['out']);
        $row = \json_decode((string) \file_get_contents($this->tmpDir . '/users.json'), true);
        $this->assertArrayNotHasKey('alice', $row['users']);

        $list = $this->runBin(['user', 'list'], $env);
        $this->assertStringNotContainsString('alice', $list['out']);
    }
}
