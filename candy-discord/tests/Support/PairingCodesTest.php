<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use CandyCode\Bundles\Discord\Support\CredentialStore;
use CandyCode\Bundles\Discord\Support\PairingCodes;
use CandyCode\Bundles\Discord\Support\TextSafety;
use PHPUnit\Framework\TestCase;

/**
 * §7.3 pairing law: 8-char codes from an ambiguous-glyph-free alphabet,
 * one hour TTL, at most three pending, one reminder per hour, and the code
 * is a redaction secret the moment it exists.
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
        $this->dir = sys_get_temp_dir() . '/p2-pair-' . getmypid() . '-' . uniqid('', true);
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

    public function testFreshPeersReceiveAnEightCharacterCodeFromTheSafeAlphabet(): void
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
        $this->store->writeDiscordPairing('default', [
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
        $this->store->writeDiscordPairing('default', [
            'EXPIRED1' => ['userId' => 'peer-9', 'createdAt' => 100, 'expiresAt' => 4600, 'lastReminderAt' => 100],
        ], []);
        self::assertSame('new', $this->codes->request('default', 'peer-1')['kind'], 'expired rows must not hold the cap');
        self::assertSame([], $this->codes->pending('default'), 'only the fresh code stays live');
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
}
