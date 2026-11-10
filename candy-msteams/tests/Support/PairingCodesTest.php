<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\CredentialStore;
use CandyCode\Bundles\MsTeams\Support\PairingCodes;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use PHPUnit\Framework\TestCase;

/**
 * §7.3 pairing SHARED LAW, re-stated for this bundle: length-8 codes from
 * an ambiguous-glyph-free alphabet, one hour TTL, at most three pending,
 * one reminder per hour, and the code is a redaction secret the moment it
 * exists. The constants below are pinned byte-for-byte against the law the
 * discord bundle states at its own window — the pair of pins is what keeps
 * the "same code" promise real across the two bundles.
 */
final class PairingCodesTest extends TestCase
{
    private string $dir;
    private string $path;
    private CredentialStore $store;
    private TextSafety $safety;
    private PairingCodes $codes;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/p3-pair-' . getmypid() . '-' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
        $this->path = $this->dir . '/channels.json';
        $this->store = new CredentialStore($this->path);
        $this->safety = new TextSafety();
        $this->codes = new PairingCodes($this->store, $this->safety);
    }

    protected function tearDown(): void
    {
        foreach ([$this->path] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }
    }

    public function testFreshPeersReceiveAnEightGlyphCodeFromTheSafeAlphabet(): void
    {
        $answer = $this->codes->request('default', 'peer-1');
        self::assertSame('new', $answer['kind']);
        $code = (string) $answer['code'];
        self::assertSame(8, strlen($code));
        self::assertSame(1, preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $code));
    }

    public function testImmediateReRequestStaysSilentOnTheSameCode(): void
    {
        $first = $this->codes->request('default', 'peer-1');
        $again = $this->codes->request('default', 'peer-1');
        self::assertSame('silent', $again['kind']);
        self::assertSame($first['code'], $again['code'], 'no reminder outside the hour window');
    }

    public function testAStaleReminderRowRotatesWithThePendingAnswer(): void
    {
        $now = time();
        $this->store->writeMsteamsPairing('default', [
            'ZZZZZZZZ' => ['userId' => 'peer-1', 'createdAt' => $now - 7100, 'expiresAt' => $now + 100, 'lastReminderAt' => $now - 3700],
        ], []);
        $answer = $this->codes->request('default', 'peer-1', $now);
        self::assertSame('pending', $answer['kind']);
        self::assertSame('ZZZZZZZZ', $answer['code']);
    }

    public function testThePendingRosterCapsAtThreeAndRefusesMore(): void
    {
        $this->codes->request('default', 'peer-1');
        $this->codes->request('default', 'peer-2');
        $this->codes->request('default', 'peer-3');
        self::assertSame(['kind' => 'full'], $this->codes->request('default', 'peer-4'));
    }

    public function testApprovalConsumesTheCodeAndAdmitsThePeerForever(): void
    {
        $issued = $this->codes->request('default', 'peer-1');
        $code = (string) $issued['code'];

        self::assertSame('peer-1', $this->codes->approve('default', $code));
        self::assertTrue($this->codes->isPaired('default', 'peer-1'));
        self::assertSame(['kind' => 'paired'], $this->codes->request('default', 'peer-1'));
        self::assertNull($this->codes->approve('default', $code), 'the code is spent');
        self::assertSame([], $this->codes->pending('default'));
    }

    public function testApproveOnAnUnknownCodeReturnsNull(): void
    {
        self::assertNull($this->codes->approve('default', 'QQQQQQQQ'));
    }

    public function testExpiredRowsArePrunedFromDiskAndFreeTheirSlot(): void
    {
        $this->store->writeMsteamsPairing('default', [
            'EXPIRED1' => ['userId' => 'peer-9', 'createdAt' => 100, 'expiresAt' => 4600, 'lastReminderAt' => 100],
        ], []);
        self::assertSame('new', $this->codes->request('default', 'peer-1')['kind'], 'expired rows must not hold the cap');
        $live = $this->codes->pending('default');
        self::assertCount(1, $live, 'only the fresh code stays live');
        self::assertNotSame('EXPIRED1', $live[0]['code'], 'the dead row is gone from disk');
    }

    public function testAMintedCodeNeverLeavesThroughTheOutboundFilter(): void
    {
        $code = (string) $this->codes->request('default', 'peer-1')['code'];
        self::assertStringNotContainsString($code, $this->safety->outbound('your code is ' . $code));
        self::assertStringNotContainsString($code, $this->safety->fence('your code is ' . $code));
    }

    public function testTheRosterSurvivesAProcessRestartByReadingTheStoreAgain(): void
    {
        $code = (string) $this->codes->request('default', 'peer-1')['code'];
        $rebuilt = new PairingCodes(new CredentialStore($this->path), new TextSafety());
        self::assertSame('silent', $rebuilt->request('default', 'peer-1')['kind']);
        self::assertSame('peer-1', $rebuilt->approve('default', $code));
    }

    public function testTheStoreFileIsAlwaysZeroSixZeroZero(): void
    {
        $this->codes->request('default', 'peer-1');
        clearstatcache();
        self::assertSame(0o600, fileperms($this->path) & 0o777);
    }

    public function testTheAlphabetCarriesNoAmbiguousGlyphs(): void
    {
        self::assertSame(
            'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
            PairingCodes::ALPHABET,
            'codes are read aloud and typed by hand: 0/O and 1/I must never be mintable',
        );
        foreach (['0', 'O', '1', 'I'] as $banned) {
            self::assertStringNotContainsString($banned, PairingCodes::ALPHABET);
        }
        $glyphs = \str_split(PairingCodes::ALPHABET);
        self::assertSame(\count($glyphs), \count(\array_unique($glyphs)), 'one slot per glyph');
        self::assertSame(8, PairingCodes::CODE_LENGTH, 'the shared §7.3 law is length 8 in both bundles');
        self::assertSame(3600, PairingCodes::TTL_SECONDS, 'one hour');
        self::assertSame(3, PairingCodes::MAX_PENDING, 'at most three pending');
        self::assertSame(3600, PairingCodes::REMINDER_SECONDS, 'at most one reminder per hour');

        // Behavioural leg: sample the REAL generator, not the constant — 40
        // draws from a 36-glyph alphabet hide a reintroduced '0' with
        // probability (35/36)^320 < 2e-5; the structural leg above is the
        // deterministic keystone.
        $mint = new \ReflectionMethod(PairingCodes::class, 'mintCode');
        $mint->setAccessible(true);
        for ($i = 0; $i < 40; $i++) {
            $code = (string) $mint->invoke($this->codes, []);
            self::assertSame(
                1,
                \preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $code),
                'sample ' . $i . ' left the safe alphabet: ' . $code,
            );
        }
    }
}
