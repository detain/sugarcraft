<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\CookieSessions;
use SugarCraft\Code\Server\Auth\SessionFileStore;
use SugarCraft\Code\Server\Auth\TokenStore;

/**
 * WEB-6: cookie sessions survive a server restart WITHOUT the plaintext ever
 * touching the disk. The file holds `sha256(id) => expiresAt` rows bound to a
 * fingerprint of the owner token; the pins here walk the whole contract —
 * restart round-trip, hash-not-plaintext census, prune-on-boot landing on
 * disk, the slide-write throttle, write-through revokes — including their
 * fail-closed half (a revoke whose write failed discards the durable copy
 * rather than resurrect the row; a stranded crash-leftover partial is cleared
 * and retried, a young one is left alone), token rotation
 * across a restart, and the fail-open degradation for an unusable state dir.
 *
 * Hermetic by construction: every store points at a fresh 0700 temp dir or at
 * `/nonexistent` with a token override, never the real HOME.
 */
final class CookieSessionPersistenceTest extends TestCase
{
    private const OVERRIDE_TOKEN = 'abababababababababababababababababababababababababababababababab';

    private string $w2gDir;

    private float $w2gNow = 1_700_000_000.0;

    protected function setUp(): void
    {
        $this->w2gDir = \sys_get_temp_dir() . '/w2g_sessions_' . \uniqid('', true);
        \mkdir($this->w2gDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->w2gDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @\unlink($this->w2gDir . '/' . $entry);
            }
        }
        @\rmdir($this->w2gDir);
    }

    public function testARestartKeepsSignedInBrowsersSignedIn(): void
    {
        $before = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $cookie = $before->open();

        $after = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        self::assertTrue($after->validate($cookie), 'the process restarted; the browser did not');
        self::assertSame(1, $after->count());
    }

    public function testTheAuthContextWiresThePersistedStore(): void
    {
        $auth = AuthContext::new(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $cookie = $auth->sessions->open();

        $restarted = AuthContext::new(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        self::assertTrue($restarted->sessions->validate($cookie));
    }

    public function testTheFileHoldsHashesAndNeverAPlaintextId(): void
    {
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $cookies = [$store->open(), $store->open(), $store->open()];

        $bytes = $this->w2gFileBytes();
        foreach ($cookies as $cookie) {
            self::assertStringNotContainsString($cookie, $bytes, 'a minted id reached the disk');
            self::assertStringContainsString(\hash('sha256', $cookie), $bytes);
        }
    }

    public function testPruneOnBootLandsOnDiskAndNotJustInMemory(): void
    {
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $cookie = $store->open();
        self::assertCount($store->count(), $this->w2gRowHashes(), 'the stale row is still on disk pre-boot');

        $this->w2gNow += CookieSessions::LIFETIME_SECONDS + 1;
        self::assertFalse($store->validate($cookie));

        $restarted = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        self::assertSame(0, $restarted->count());
        self::assertSame([], $this->w2gRowHashes(), 'boot found the row expired and rewrote the file');
    }

    public function testSlidingValidateRewritesTheFileOnlyAfterTheInterval(): void
    {
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $cookie = $store->open();
        $writtenAtOpen = $this->w2gFileBytes();

        $this->w2gNow += 100.0;
        self::assertTrue($store->validate($cookie), 'a slide within the interval must not hit the disk');
        self::assertSame($writtenAtOpen, $this->w2gFileBytes());

        $this->w2gNow += CookieSessions::SLIDE_FLUSH_SECONDS;
        self::assertTrue($store->validate($cookie));
        $rows = \json_decode($this->w2gFileBytes(), true, 8);
        self::assertSame([$this->w2gNow + CookieSessions::LIFETIME_SECONDS], \array_values($rows['sessions']));
    }

    public function testRevokeAndRevokeAllWriteThroughAtOnce(): void
    {
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $kept = $store->open();
        $dropped = $store->open();
        $store->revoke($dropped);

        $rows = \json_decode($this->w2gFileBytes(), true, 8);
        self::assertSame([\hash('sha256', $kept)], \array_keys($rows['sessions']));

        $store->revokeAll();
        $rows = \json_decode($this->w2gFileBytes(), true, 8);
        self::assertSame([], $rows['sessions']);
    }

    public function testASuccessfulRevokeStillKeepsTheKeptRowsLiveAcrossARestart(): void
    {
        // The fail-closed pair's happy polarity: a revoke whose write SUCCEEDED
        // must not touch the durable copy beyond dropping the revoked row.
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $kept = $store->open();
        $dropped = $store->open();
        $store->revoke($dropped);

        $after = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        self::assertTrue($after->validate($kept), 'a healthy revoke may not sign the kept browsers out');
        self::assertFalse($after->validate($dropped));
    }

    public function testAStrandedPartialIsClearedAndDurabilityRecovers(): void
    {
        // WEB-6 A hard shape: a crash mid-write leaves sessions.json.partial,
        // the shared write discipline refuses it forever, and every later
        // boot silently persists nothing. A provably-dead leftover at exactly
        // that name must be cleared (logged) and the write retried.
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $first = $store->open();

        $this->w2gPlantPartial('{"sessions":{}}-half-written', 7_200);

        $lines = [];
        $next = CookieSessions::persisted(
            TokenStore::new($this->w2gDir),
            fn (): float => $this->w2gNow,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );
        $second = $next->open();

        self::assertFalse(
            \file_exists($this->w2gDir . '/' . SessionFileStore::FILE . '.partial'),
            'the stranded leftover survived the write that refused it',
        );
        self::assertCount(1, $lines, 'clearing a stranded partial is a fail-loud repair');
        self::assertStringContainsString('stranded', $lines[0]);

        $bytes = $this->w2gFileBytes();
        self::assertStringContainsString(\hash('sha256', $first), $bytes);
        self::assertStringContainsString(\hash('sha256', $second), $bytes, 'durability stayed dead behind the leftover');

        $restarted = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        self::assertTrue($restarted->validate($first));
        self::assertTrue($restarted->validate($second));
    }

    public function testAYoungPartialIsALiveWriterAndStaysPut(): void
    {
        // The sweep's other polarity: a fresh `sessions.json.partial` is some
        // writer IN FLIGHT, not a stranding — clearing it would corrupt a
        // concurrent write. The failed write degrades quietly, as before.
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $first = $store->open();
        $bytesBefore = $this->w2gFileBytes();

        $this->w2gPlantPartial('in-flight-bytes', 5);

        $next = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $second = $next->open();

        self::assertTrue(\file_exists($this->w2gDir . '/' . SessionFileStore::FILE . '.partial'));
        self::assertSame($bytesBefore, $this->w2gFileBytes(), 'the young leftover must not be touched, nor the write forced');
        self::assertTrue($next->validate($second), 'in memory the session is still live');
        self::assertTrue($next->validate($first));
    }

    public function testARevocationThatCannotBeWrittenDiscardsTheFileInsteadOfResurrecting(): void
    {
        // WEB-6 B the resurrection shape, pre-fix: revoke() -> persist() fails
        // and is swallowed -> the stale file still holds the revoked row ->
        // the next boot imports it back. Fail-closed instead: the durable copy
        // is discarded (all rows sign out at restart — the conservative
        // availability cost), and the revocation stands.
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $kept = $store->open();
        $doomed = $store->open();

        $this->w2gPlantPartial('in-flight-bytes', 5); // blocks every later write, is never cleared

        $lines = [];
        $durable = CookieSessions::persisted(
            TokenStore::new($this->w2gDir),
            fn (): float => $this->w2gNow,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );
        $durable->revoke($doomed); // must NOT throw: the discard is a legal landing

        self::assertFalse(
            @\file_exists($this->w2gDir . '/' . SessionFileStore::FILE) && \is_file($this->w2gDir . '/' . SessionFileStore::FILE),
            'the copy that could not take the revocation must not remain importable',
        );
        self::assertSame([], $this->w2gRowHashes());
        self::assertNotEmpty($lines, 'discarding the durable copy is a fail-loud repair');
        self::assertStringContainsString('discarded', $lines[0]);

        $after = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        self::assertFalse($after->validate($doomed), 'a revoked row may not ride over a restart');
        self::assertFalse($after->validate($kept), 'the conservative side of the choice: everyone restarts signed out');
    }

    public function testARevocationThatCanNeitherBeWrittenNorDiscardedFailsLoudly(): void
    {
        // The last resort: a durable copy that refuses the write AND the
        // unlink (here: a directory sitting on the file name — uid-agnostic).
        // Silent swallowing would resurrect the rows; the store raises.
        self::assertTrue(\mkdir($this->w2gDir . '/' . SessionFileStore::FILE));

        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $cookie = $store->open(); // a failed courtesy write on open may not throw

        $raised = false;
        try {
            $store->revoke($cookie);
        } catch (\RuntimeException $failure) {
            $raised = true;
            self::assertStringContainsString('resurrect', $failure->getMessage());
        }
        self::assertTrue($raised, 'revocation with no legal landing must fail loudly');
        self::assertFalse($store->validate($cookie), 'the in-memory revocation stands regardless');

        @\rmdir($this->w2gDir . '/' . SessionFileStore::FILE);
    }

    public function testATokenRotatedWhileTheServerWasDownSignsTheCookiesOut(): void
    {
        $store = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        $cookie = $store->open();

        TokenStore::new($this->w2gDir)->rotate();

        $restarted = CookieSessions::persisted(TokenStore::new($this->w2gDir), fn (): float => $this->w2gNow);
        self::assertFalse($restarted->validate($cookie), 'cookies minted under the old token must not ride over');
        self::assertSame([], $this->w2gRowHashes());
    }

    public function testAnUnusableStateDirectoryDegradesToMemoryOnly(): void
    {
        $tokens = TokenStore::new('/nonexistent')->withOverride(self::OVERRIDE_TOKEN);
        $store = CookieSessions::persisted($tokens, fn (): float => $this->w2gNow);
        $cookie = $store->open();

        self::assertTrue($store->validate($cookie), 'durability failed, the request may not');
        self::assertSame(1, $store->count());
        $store->revoke($cookie);
        $store->revokeAll();
        self::assertFalse(\is_dir('/nonexistent'), 'a failed persistence must not have minted a directory');
    }

    public function testPlainNewStaysTheMemoryOnlyShape(): void
    {
        $store = CookieSessions::new(fn (): float => $this->w2gNow);
        $cookie = $store->open();

        self::assertTrue($store->validate($cookie));
        self::assertSame([], $this->w2gRowHashes(), 'new() must never touch the disk');
    }

    /** @return list<string> the row hashes currently on disk */
    private function w2gRowHashes(): array
    {
        try {
            $bytes = $this->w2gFileBytes();
        } catch (\RuntimeException) {
            return [];
        }

        return \array_keys(\json_decode($bytes, true, 8)['sessions']);
    }

    private function w2gFileBytes(): string
    {
        $path = $this->w2gDir . '/' . SessionFileStore::FILE;
        $bytes = @\file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('no session file at ' . $path);
        }

        return $bytes;
    }

    /** Plant a `sessions.json.partial` aged $ageSeconds, as a crash mid-write would. */
    private function w2gPlantPartial(string $bytes, int $ageSeconds): void
    {
        $path = $this->w2gDir . '/' . SessionFileStore::FILE . '.partial';
        self::assertNotFalse(\file_put_contents($path, $bytes));
        $stamp = \time() - $ageSeconds;
        self::assertTrue(@\touch($path, $stamp));
    }
}
