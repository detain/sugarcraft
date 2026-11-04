<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Auth\ViewerTokens;
use SugarCraft\Code\Support\PrivateRetainedDir;

/**
 * The viewer-token store (X-DESIGN1 D-1, reviews-1103 G4-G): hashed at rest,
 * raw never persisted nor logged, the cap REFUSES rather than evicts, expiry
 * is absolute, and every revocation path — explicit, sweep, rotation — holds
 * on disk as loudly as it does in memory.
 */
final class TokenScopeTest extends TestCase
{
    private float $now = 1_700_000_000.0;

    private string $dir = '';

    /** @var list<string> extra state dirs tests create outside $this->dir */
    private array $extraDirs = [];

    /** @var list<string> lines the store's log closure was handed */
    private array $notes = [];

    protected function setUp(): void
    {
        $this->now = 1_700_000_000.0;
        $this->dir = (string) \sys_get_temp_dir() . '/crush-g4g-' . \bin2hex(\random_bytes(6));
        $this->notes = [];
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, ...$this->extraDirs] as $victim) {
            if (!\is_dir($victim)) {
                continue;
            }
            foreach ((array) \scandir($victim) as $entry) {
                if ($entry !== '.' && $entry !== '..' && \is_file($victim . '/' . $entry)) {
                    @\unlink($victim . '/' . $entry);
                }
            }
            @\rmdir($victim);
        }
    }

    private function tokens(): TokenStore
    {
        return TokenStore::new($this->dir);
    }

    private function store(TokenStore $tokens): ViewerTokens
    {
        $log = function (string $line): void {
            $this->notes[] = $line;
        };

        return ViewerTokens::persisted($tokens, fn (): float => $this->now, $log);
    }

    public function testGrantedTokenResolvesAndTheRawValueNeverLandsOnDisk(): void
    {
        $store = $this->store($this->tokens());
        $grant = $store->grant('phone watch');

        self::assertStringStartsWith(ViewerTokens::PREFIX, $grant['token']);
        self::assertSame(44, \strlen($grant['token']), 'cv1_ plus 40 hex');
        self::assertTrue(\ctype_xdigit(\substr($grant['token'], 4)));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $grant['id']);
        self::assertSame(['read'], $grant['scopes'], 'the v1 grant is read-only, no exceptions');
        self::assertNull($grant['sessionIds'], 'an unpinned grant reaches every session');
        self::assertSame($grant['expiresAt'] - $grant['createdAt'], 30 * 86400.0, 'the default life is 30 days');

        $row = $store->match($grant['token']);
        self::assertNotNull($row);
        self::assertSame($grant['id'], $row['id']);
        self::assertSame(['read'], $row['scopes']);
        self::assertArrayNotHasKey('hash', $row, 'the durable key never rides out to callers');
        self::assertArrayNotHasKey('token', $row);

        $bytes = (string) \file_get_contents($this->dir . '/' . ViewerTokens::FILE);
        self::assertStringContainsString(\hash('sha256', $grant['token']), $bytes, 'what lands is the digest');
        self::assertStringNotContainsString($grant['token'], $bytes, 'the raw token is never persisted');

        foreach ($store->list() as $listed) {
            self::assertArrayNotHasKey('hash', $listed);
            self::assertArrayNotHasKey('token', $listed);
        }
        self::assertStringNotContainsString($grant['token'], (string) \json_encode($store->list()));
    }

    public function testGrantValidationEdges(): void
    {
        $store = $this->store($this->tokens());

        $this->expectException(\InvalidArgumentException::class);
        $store->grant('   ');
    }

    public function testAnOversizeLabelIsRefused(): void
    {
        $store = $this->store($this->tokens());

        try {
            $store->grant(\str_repeat('x', ViewerTokens::LABEL_MAX + 1));
            self::fail('a 65-character label must not mint');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('64', $refusal->getMessage());
        }
        self::assertSame(64, \strlen($store->grant(\str_repeat('y', ViewerTokens::LABEL_MAX))['label']), 'the bound itself grants');
    }

    public function testDayBoundsAreEnforced(): void
    {
        $store = $this->store($this->tokens());

        foreach ([0, -1, ViewerTokens::MAX_DAYS + 1] as $days) {
            try {
                $store->grant('bounds', null, $days);
                self::fail('day count ' . $days . ' must not mint');
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString('days', $refusal->getMessage());
            }
        }
        $grant = $store->grant('bounds', null, ViewerTokens::MAX_DAYS);
        self::assertSame(365 * 86400.0, $grant['expiresAt'] - $grant['createdAt']);
    }

    public function testASessionPinListMustHoldNonEmptyStrings(): void
    {
        $store = $this->store($this->tokens());

        foreach ([[''], ['ok', 5], ['ok', '']] as $listing) {
            try {
                $store->grant('pinned', $listing);
                self::fail('a malformed session list must not mint');
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString('session', $refusal->getMessage());
            }
        }

        $grant = $store->grant('pinned', ['s1', 's2']);
        self::assertSame(['s1', 's2'], $grant['sessionIds']);
        $row = $store->match($grant['token']);
        self::assertNotNull($row);
        self::assertSame(['s1', 's2'], $row['sessionIds'], 'the pin rides every validation');
    }

    public function testTheCapRefusesAndNeverEvicts(): void
    {
        $store = $this->store($this->tokens());
        $first = $store->grant('first');
        for ($n = 1; $n < ViewerTokens::MAX_GRANTS; ++$n) {
            $store->grant('filler ' . $n);
        }
        self::assertSame(ViewerTokens::MAX_GRANTS, $store->count());

        try {
            $store->grant('one too many');
            self::fail('the 65th grant must be refused');
        } catch (\RuntimeException $refusal) {
            self::assertStringContainsString('cap', $refusal->getMessage());
            self::assertStringContainsString('revoke', $refusal->getMessage(), 'the refusal names the way out');
        }

        self::assertSame(ViewerTokens::MAX_GRANTS, $store->count(), 'a refusal changes nothing');
        self::assertNotNull($store->match($first['token']), 'cap pressure never silently revokes');
    }

    public function testExpiryIsAbsoluteAndSweptOnSight(): void
    {
        $store = $this->store($this->tokens());
        $grant = $store->grant('short', null, 1);

        $this->now += 2 * 86400.0;
        self::assertNull($store->match($grant['token']));
        self::assertSame([], $store->list());
        self::assertSame(0, $store->count());

        // Activity does not slide the deadline: just before expiry it still
        // authenticates, and usage never bought it a longer life.
        $other = $store->grant('deadline', null, 1);
        $this->now += 86399.0;
        self::assertNotNull($store->match($other['token']));
        $this->now += 2.0;
        self::assertNull($store->match($other['token']));
    }

    public function testUnknownExpiredAndRevokedAllAnswerTheSameNull(): void
    {
        $store = $this->store($this->tokens());
        $live = $store->grant('still here');
        $doomed = $store->grant('doomed', null, 1);
        $killed = $store->grant('killed', null, 1);
        self::assertTrue($store->revoke($killed['id']));

        $this->now += 2 * 86400.0;

        $forged = ViewerTokens::PREFIX . \str_repeat('0', 40);
        self::assertNull($store->match($forged));
        self::assertNull($store->match($doomed['token']));
        self::assertNull($store->match($killed['token']));
        self::assertSame($store->match($forged), $store->match($doomed['token']), 'no oracle forges from');
        self::assertSame($store->match($forged), $store->match($killed['token']), 'no oracle for revocation either');
        self::assertNull($store->match('not-even-the-prefix'), 'a foreign credential is not even looked up');
        self::assertNotNull($store->match($live['token']), 'expiry of others never touches a live grant');
    }

    public function testGrantsSurviveARestartAndMalformedRowsDoNot(): void
    {
        $tokens = $this->tokens();
        $grant = $this->store($tokens)->grant('from before the restart');

        $rebuilt = $this->store($tokens);
        $row = $rebuilt->match($grant['token']);
        self::assertNotNull($row, 'the file is the truth across a restart');
        self::assertSame($grant['id'], $row['id']);

        // A second boot on a corrupted durable copy imports nothing and says
        // so — fail closed row by row, never half-parse a grant.
        PrivateRetainedDir::write($tokens->dir(), ViewerTokens::FILE, 'not json at all', 'server state');
        $third = $this->store($tokens);
        self::assertSame(0, $third->count());
        self::assertNotEmpty($this->notes, 'the unreadable copy is announced');

        $grafted = \json_encode(['grants' => [
            ['id' => 'deadbeef', 'label' => 'wide scopes', 'scopes' => ['read', 'write'], 'sessionIds' => null,
                'hash' => \hash('sha256', ViewerTokens::PREFIX . \str_repeat('1', 40)),
                'createdAt' => 1.0, 'expiresAt' => 4_000_000_000.0, 'lastUsedAt' => null],
            ['id' => 'cafebabe', 'label' => 'well formed', 'scopes' => ['read'], 'sessionIds' => ['s9'],
                'hash' => \hash('sha256', ViewerTokens::PREFIX . \str_repeat('2', 40)),
                'createdAt' => 1.0, 'expiresAt' => 4_000_000_000.0, 'lastUsedAt' => null],
        ]]);
        PrivateRetainedDir::write($tokens->dir(), ViewerTokens::FILE, (string) $grafted, 'server state');
        $fourth = $this->store($tokens);
        self::assertSame(1, $fourth->count(), 'the widened-scope row was dropped, the honest one imported');
        $imported = $fourth->list();
        self::assertSame('cafebabe', $imported[0]['id']);
        self::assertSame(['s9'], $imported[0]['sessionIds']);
        self::assertNotNull($fourth->match(ViewerTokens::PREFIX . \str_repeat('2', 40)));
        self::assertNull($fourth->match(ViewerTokens::PREFIX . \str_repeat('1', 40)));
    }

    public function testRevocationWritesTheDurableCopyAndAnswersTheTruth(): void
    {
        $tokens = $this->tokens();
        $store = $this->store($tokens);
        $keep = $store->grant('keep');
        $drop = $store->grant('drop');

        self::assertTrue($store->revoke($drop['id']));
        self::assertFalse($store->revoke($drop['id']), 'an id that names nothing answers false, once or twice');
        self::assertFalse($store->revoke('ffffffff'), 'and no never-existed-versus-already-gone oracle');

        $restarted = $this->store($tokens);
        self::assertNull($restarted->match($drop['token']), 'a revocation that survives restart or is none');
        self::assertNotNull($restarted->match($keep['token']));

        $restarted->revokeAll();
        self::assertSame(0, $this->store($tokens)->count(), 'wipe-all is durable too');
        self::assertSame([], $restarted->list());
    }

    public function testRevokeAllOnAnUntouchedStoreWritesNoFileAndNeverFails(): void
    {
        $dir = (string) \sys_get_temp_dir() . '/crush-g4g-cold-' . \bin2hex(\random_bytes(6));
        $this->extraDirs[] = $dir;
        $store = $this->store(TokenStore::new($dir));

        $store->revokeAll();
        self::assertNull($store->match(ViewerTokens::PREFIX . \str_repeat('0', 40)));
        self::assertSame([], $store->list());
        // The durable law: an untouched store writes no grants file at all —
        // an empty wipe of nothing is not an event. (Parent-dir creation on
        // first resolve is the house norm, exactly as SessionFileStore's own
        // read resolves through TokenStore::dir().)
        self::assertFileDoesNotExist($dir . '/' . ViewerTokens::FILE);
    }

    public function testLastUsedIsStampedLiveAndFlushedOnTheThrottle(): void
    {
        $tokens = $this->tokens();
        $store = $this->store($tokens);
        $grant = $store->grant('recency');

        $this->now += 10.0;
        $row = $store->match($grant['token']);
        self::assertNotNull($row);
        self::assertSame($this->now, $row['lastUsedAt'], 'the live answer carries the stamp immediately');
        $untouched = $this->store($tokens)->list();
        self::assertNull($untouched[0]['lastUsedAt'], 'inside the throttle window the disk copy lags by design');

        $this->now += ViewerTokens::SLIDE_FLUSH_SECONDS + 1;
        self::assertNotNull($store->match($grant['token']));
        $flushed = $this->store($tokens)->list();
        self::assertSame(1_700_000_000.0 + 10.0 + ViewerTokens::SLIDE_FLUSH_SECONDS + 1.0, $flushed[0]['lastUsedAt']);
    }

    public function testRotationRevokesEveryViewerGrant(): void
    {
        $tokens = $this->tokens();
        $tokens->token(); // establish the reload baseline the store needs
        $auth = AuthContext::new($tokens, fn (): float => $this->now);
        $watcher = $auth->viewerTokens->grant('rotation victim');
        $second = $auth->viewerTokens->grant('also a victim');
        self::assertNotNull($auth->viewerTokens->match($watcher['token']));

        TokenStore::new($this->dir)->rotate(); // the operator swapped the token on disk

        self::assertTrue($auth->reloadToken(), 'the changed token is noticed');
        self::assertNull($auth->viewerTokens->match($watcher['token']), 'rotation nukes every viewer grant');
        self::assertNull($auth->viewerTokens->match($second['token']));
        self::assertSame([], $auth->viewerTokens->list());

        $restarted = $this->store($tokens);
        self::assertSame(0, $restarted->count(), 'and the wipe is durable, not just in-memory');
        self::assertStringNotContainsString($watcher['token'], (string) \file_get_contents($this->dir . '/' . ViewerTokens::FILE));
    }

    public function testAuthContextCarriesTheStoreOnTheSameTokenDir(): void
    {
        $tokens = $this->tokens();
        $auth = AuthContext::new($tokens, fn (): float => $this->now);

        self::assertInstanceOf(ViewerTokens::class, $auth->viewerTokens);
        $grant = $auth->viewerTokens->grant('via context');
        self::assertFileExists($this->dir . '/' . ViewerTokens::FILE);
        self::assertNotNull($auth->viewerTokens->match($grant['token']));
    }
}
