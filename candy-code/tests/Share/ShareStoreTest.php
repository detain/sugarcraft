<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Share;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Share\ShareStore;

/**
 * The `shares` table (SES-3, COMPARISON §6.5): the token is the capability,
 * so its entropy, the no-oracle death of a row, and the snapshot being plain
 * stored text are the properties pinned here. Rendering the snapshot is
 * ShareCommand's and the controller's business, pinned beside them.
 */
final class ShareStoreTest extends TestCase
{
    private string $dir = '';

    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/sc-shares-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o700);
        $this->dbPath = $this->dir . '/session.db';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testPublishAnswersWithARowThatRoundTripsThroughLookup(): void
    {
        $store = ShareStore::atPath($this->dbPath);

        $record = $store->publish('sess-1', 'html', '<!doctype html><p>hello</p>', 7);

        self::assertSame(32, strlen($record->token));
        self::assertSame('sess-1', $record->sessionId);
        self::assertSame('/s/' . $record->token, $record->path());
        $found = $store->lookup($record->token);
        self::assertNotNull($found);
        self::assertSame($record->html, $found->html);
        self::assertSame($record->expiresAt, $found->expiresAt);
    }

    /**
     * 128 bits from the CSPRNG, and nothing derived from the session: a
     * token that leaked an id, a timestamp or a counter is the whole
     * capability, so the shape IS the security.
     */
    public function testTokensAreFullWidthHexAndNeverRepeat(): void
    {
        $store = ShareStore::atPath($this->dbPath);

        $seen = [];
        for ($i = 0; $i < 64; $i++) {
            $token = $store->publish('sess-' . $i, 'html', '<p>' . $i . '</p>', 1)->token;
            self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', $token), 'token shape: ' . $token);
            $seen[$token] = true;
        }
        self::assertCount(64, $seen, '64 draws must not collide or repeat');
        self::assertStringNotContainsString('sess-0', implode('', array_keys($seen)), 'a token never embeds its session id');
    }

    /**
     * The BEHAVIOUR above cannot tell 128 CSPRNG bits from anything shaped
     * like 32 hex characters: an md5-of-uniqid swap passes every draw check
     * while trading away the one property the URL's life depends on (an
     * independent review proved it: the whole family stayed green under
     * exactly that swap). So the entropy SOURCE is pinned structurally, in
     * the house source-slice doctrine (cf. MailboxTest's ordering pin): the
     * publish() body must mint through random_bytes() itself, and must not
     * name a time/hash/loop PRNG — the shape tests prove width, this proves
     * where the width comes from.
     */
    public function testPublishMintsItsTokenFromTheCsprngAndNowhereElse(): void
    {
        $method = new \ReflectionMethod(ShareStore::class, 'publish');
        $lines = file((string) $method->getFileName());
        self::assertNotFalse($lines);
        $body = implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            'bin2hex(random_bytes(self::TOKEN_BYTES))',
            $body,
            'the token must be hex of the CSPRNG constant, not of a hash or a clock',
        );
        self::assertSame(ShareStore::TOKEN_BYTES * 2, 32, 'the pinned hex width is the documented 128-bit capability');
        foreach (['uniqid', 'microtime', 'mt_rand', 'rand', 'md5', 'sha1'] as $forbidden) {
            self::assertStringNotContainsString($forbidden . '(', $body, $forbidden . '() is not an entropy source for a capability token');
        }
    }

    public function testLookupOfAnUnknownTokenIsPlainlyNull(): void
    {
        $store = ShareStore::atPath($this->dbPath);

        self::assertNull($store->lookup(str_repeat('0f', 16)));
    }

    /**
     * A database no `/share serve` ever touched has no `shares` table; the
     * public HTTP path must then answer like a dead token, not like an error.
     */
    public function testLookupOnATableThatDoesNotExistFailsSafeToNull(): void
    {
        $store = ShareStore::atPath($this->dir . '/never-shared.db');

        self::assertNull($store->lookup(str_repeat('ab', 16)));
    }

    /**
     * Malformed shapes — too short, too long, uppercase, a traversal attempt —
     * never reach SQLite as a query candidate.
     */
    public function testLookupRefusesNonTokenShapes(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $store->publish('sess-1', 'html', '<p>x</p>', 1);

        foreach (['', 'short', str_repeat('AB', 16), str_repeat('0f', 17), '../../etc/passwd', str_repeat('0f', 15) . 'g'] as $shape) {
            self::assertNull($store->lookup($shape), 'shape accepted a probe: ' . $shape);
        }
    }

    public function testAnExpiredRowLooksExactlyLikeAnUnknownToken(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $dead = $store->publish('sess-1', 'html', '<p>x</p>', 1);

        $this->expire($dead->token);

        self::assertNull($store->lookup($dead->token));
    }

    public function testRevocationIsImmediateAndAnswersLikeEveryOtherDeath(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $live = $store->publish('sess-1', 'html', '<p>x</p>', 7);

        self::assertNotNull($store->lookup($live->token));
        self::assertTrue($store->revoke($live->token));
        self::assertNull($store->lookup($live->token));
        // Second revoke: no LIVE row flipped — false, without saying it once existed.
        self::assertFalse($store->revoke($live->token));
        self::assertFalse($store->revoke(str_repeat('0f', 16)));
    }

    public function testLiveForSessionOrdersNewestFirstAndSkipsDeadRows(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $older = $store->publish('sess-1', 'html', '<p>older</p>', 7);
        $newer = $store->publish('sess-1', 'html', '<p>newer</p>', 7);
        $revoked = $store->publish('sess-1', 'html', '<p>gone</p>', 7);
        $store->revoke($revoked->token);
        $elsewhere = $store->publish('sess-2', 'html', '<p>not mine</p>', 7);

        $live = $store->liveForSession('sess-1');
        $tokens = array_map(static fn($r): string => $r->token, $live);

        self::assertSame([$newer->token, $older->token], $tokens);
        self::assertNotContains($elsewhere->token, $tokens);
    }

    public function testPublishSweepsRowsNoLookupCouldReturn(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $expired = $store->publish('sess-1', 'html', '<p>x</p>', 1);
        $revoked = $store->publish('sess-1', 'html', '<p>y</p>', 1);
        $store->revoke($revoked->token);
        $this->expire($expired->token);

        $store->publish('sess-1', 'html', '<p>fresh</p>', 1);

        self::assertSame(0, $this->countRowsOtherThan('fresh'), 'publish() deletes inert rows on its way past');
    }

    /** @dataProvider outOfRangeDays */
    public function testDayBoundsAreRefusedAtTheStoreToo(int $days): void
    {
        $store = ShareStore::atPath($this->dbPath);

        // Refused loudly before touching SQLite.
        $this->expectException(\RuntimeException::class);
        $store->publish('sess-1', 'html', '<p>x</p>', $days);
    }

    /** @return array<string, array{0: int}> */
    public static function outOfRangeDays(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'over-cap' => [ShareStore::MAX_EXPIRY_DAYS + 1]];
    }

    public function testTheStoreReportsTheDatabaseItIsOn(): void
    {
        self::assertSame($this->dbPath, ShareStore::atPath($this->dbPath)->path());
    }

    /**
     * Force a row past its expiry without waiting out a clock — the column
     * is data, so this is honest fixture surgery, not a test seam in src/.
     */
    private function expire(string $token): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $statement = $pdo->prepare('UPDATE shares SET expires_at = :past WHERE token = :token');
        $statement->execute([':token' => $token, ':past' => time() - 1]);
        $pdo = null;
    }

    /** Rows whose snapshot does not carry the marker sentence. */
    private function countRowsOtherThan(string $marker): int
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM shares WHERE html <> '<p>" . $marker . "</p>'")->fetchColumn();
        $pdo = null;

        return $count;
    }
}
