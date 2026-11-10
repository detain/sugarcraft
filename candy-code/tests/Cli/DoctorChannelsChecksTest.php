<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Channels\ChannelInboundLog;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Cli\DoctorChannelsChecks;
use SugarCraft\Code\Tests\Cli\Support\DoctorChannelsProbeFixture;

/**
 * The three channel rows judged against fixture trees, never the live HOME.
 *
 * Each leg is a pure read: the store row decodes raw bytes loudly, the
 * bundles row mirrors the loader's read-only decision subset, and the
 * inbound row only names siblings. The fake Azure password planted in
 * fixtures must never surface on any row — detail lines carry counts,
 * modes, and paths only. The is_readable refusal is exercised only by
 * mutation (root reads mode-0000 files, so no honest fixture exists).
 */
final class DoctorChannelsChecksTest extends TestCase
{
    private const SECRET = 'azure-secret-value-xyz';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = DoctorChannelsProbeFixture::makeChannelProbeDir();
    }

    protected function tearDown(): void
    {
        DoctorChannelsProbeFixture::purgeProbeDir($this->dir);
    }

    public function testAnAbsentStoreReadsAsDefaults(): void
    {
        $row = DoctorChannelsChecks::channelsStoreRow($this->dirSeam());

        self::assertSame('OK', $row['status']);
        self::assertStringContainsString(ChannelsStore::FILE_NAME, $row['detail']);
        self::assertStringContainsString('absent', $row['detail']);
    }

    public function testAStrictStoreReportsCountsAndPath(): void
    {
        $path = $this->writeStore([
            'discord' => ['appId' => 'app-1', 'appPassword' => self::SECRET],
            'seenEntrypoints' => ['/armed.php' => true],
        ], 0600);

        $row = DoctorChannelsChecks::channelsStoreRow($this->dirSeam());

        self::assertSame('OK', $row['status']);
        self::assertStringContainsString($path, $row['detail']);
        self::assertStringContainsString('1 channel(s)', $row['detail']);
        self::assertStringContainsString('1 armed entry point(s)', $row['detail']);
        self::assertStringNotContainsString(self::SECRET, $row['detail']);
        self::assertStringNotContainsString('app-1', $row['detail']);
    }

    public function testALooseStoreFailsWithItsMode(): void
    {
        $path = $this->writeStore(['discord' => ['appPassword' => self::SECRET]], 0644);
        if (!DoctorChannelsProbeFixture::probeFileModeIs($path, 0644)) {
            self::markTestSkipped('host refused to apply the loose mode');
        }

        $row = DoctorChannelsChecks::channelsStoreRow($this->dirSeam());

        self::assertSame('FAIL', $row['status']);
        self::assertStringContainsString('0644', $row['detail']);
        self::assertStringContainsString('must be 0600', $row['detail']);
        self::assertStringNotContainsString(self::SECRET, $row['detail']);
    }

    public function testAnEmptyStoreFailsLoudly(): void
    {
        $this->writeStoreBody('', 0600);

        $row = DoctorChannelsChecks::channelsStoreRow($this->dirSeam());

        self::assertSame('FAIL', $row['status']);
        self::assertStringContainsString('is empty', $row['detail']);
    }

    public function testACorruptStoreFailsLoudly(): void
    {
        $this->writeStoreBody('{oops', 0600);

        $row = DoctorChannelsChecks::channelsStoreRow($this->dirSeam());

        self::assertSame('FAIL', $row['status']);
        self::assertStringContainsString('invalid JSON', $row['detail']);
    }

    public function testTheSecretNeverRidesTheCorruptRow(): void
    {
        $this->writeStoreBody('{"discord":{"appPassword":"' . self::SECRET . '"},oops', 0600);

        $row = DoctorChannelsChecks::channelsStoreRow($this->dirSeam());

        self::assertSame('FAIL', $row['status']);
        self::assertStringContainsString('invalid JSON', $row['detail']);
        self::assertStringNotContainsString(self::SECRET, $row['detail']);
    }

    public function testTheStoreBytesSurviveTheDiagnosis(): void
    {
        $path = $this->writeStore(['discord' => ['appId' => 'app-1']], 0600);
        $before = \md5_file($path);
        DoctorChannelsChecks::channelsStoreRow($this->dirSeam());
        self::assertSame($before, \md5_file($path));

        $this->writeStoreBody('{oops', 0600);
        $before = \md5_file($path);
        DoctorChannelsChecks::channelsStoreRow($this->dirSeam());
        self::assertSame($before, \md5_file($path), 'the loud corruption read must stay read-only');
    }

    public function testAnUnresolvedDirectoryIsReportedQuietly(): void
    {
        $row = DoctorChannelsChecks::channelsStoreRow(static fn (): string => '');

        self::assertSame('OK', $row['status']);
        self::assertStringContainsString('unresolved', $row['detail']);
    }

    public function testAnEmptyTrustListStaysDark(): void
    {
        $row = DoctorChannelsChecks::channelBundlesRow(null, [], [], $this->store());

        self::assertSame('OK', $row['status']);
        self::assertStringContainsString('dark', $row['detail']);
    }

    public function testAStaleTrustNamesItsGhost(): void
    {
        $row = DoctorChannelsChecks::channelBundlesRow(null, [], ['ghost'], $this->store());

        self::assertSame('WARN', $row['status']);
        self::assertStringContainsString('ghost', $row['detail']);
        self::assertStringContainsString('match no discovered bundle', $row['detail']);
    }

    public function testAFirstSightEntryPointWarnsQuarantine(): void
    {
        $bundle = $this->bundleNamed('alpha');

        $row = DoctorChannelsChecks::channelBundlesRow(null, [$bundle], ['alpha'], $this->store());

        self::assertSame('WARN', $row['status']);
        self::assertStringContainsString('not yet armed', $row['detail']);
    }

    public function testAnArmedEntryPointPasses(): void
    {
        $bundle = $this->bundleNamed('alpha');
        $entry = \realpath($bundle->dir . '/channel.php');
        \assert(false !== $entry);
        $this->writeStore(['seenEntrypoints' => [$entry => true]], 0600);

        $row = DoctorChannelsChecks::channelBundlesRow(null, [$bundle], ['alpha'], $this->store());

        self::assertSame('OK', $row['status']);
        self::assertStringContainsString('all armed', $row['detail']);
    }

    public function testALooseEntryPointReasonTravels(): void
    {
        $bundle = $this->bundleNamed('alpha');
        $entry = $bundle->dir . '/channel.php';
        \chmod($entry, 0666);
        if (!DoctorChannelsProbeFixture::probeFileModeIs($entry, 0666)) {
            self::markTestSkipped('host refused to apply the loose mode');
        }

        $row = DoctorChannelsChecks::channelBundlesRow(null, [$bundle], ['alpha'], $this->store());

        self::assertSame('WARN', $row['status']);
        self::assertStringContainsString('world-writable', $row['detail']);
        self::assertStringContainsString('loose-bytes', $row['detail']);
    }

    public function testStaleAndFirstSightJoinWithSemicolons(): void
    {
        $bundle = $this->bundleNamed('alpha');

        $row = DoctorChannelsChecks::channelBundlesRow(null, [$bundle], ['alpha', 'ghost'], $this->store());

        self::assertSame('WARN', $row['status']);
        self::assertStringContainsString('match no discovered bundle', $row['detail']);
        self::assertStringContainsString('not yet armed', $row['detail']);
        self::assertStringContainsString('; ', $row['detail']);
    }

    public function testAnAbsentLedgerWithNoCorralsIsDefaults(): void
    {
        $row = DoctorChannelsChecks::inboundLogRow($this->dirSeam());

        self::assertSame('OK', $row['status']);
        self::assertStringContainsString(ChannelInboundLog::FILE_NAME, $row['detail']);
        self::assertStringContainsString('absent', $row['detail']);
    }

    public function testAStrictLedgerReportsItsBytes(): void
    {
        $path = $this->writeLedger(0600);

        $row = DoctorChannelsChecks::inboundLogRow($this->dirSeam());

        self::assertSame('OK', $row['status']);
        self::assertStringContainsString($path, $row['detail']);
        self::assertStringContainsString(' B)', $row['detail']);
    }

    public function testALooseLedgerFails(): void
    {
        $path = $this->writeLedger(0644);
        if (!DoctorChannelsProbeFixture::probeFileModeIs($path, 0644)) {
            self::markTestSkipped('host refused to apply the loose mode');
        }

        $row = DoctorChannelsChecks::inboundLogRow($this->dirSeam());

        self::assertSame('FAIL', $row['status']);
        self::assertStringContainsString('0644', $row['detail']);
    }

    public function testCorralledBytesWarnWithoutTheLedger(): void
    {
        DoctorChannelsProbeFixture::writeProbeFile($this->dir, ChannelInboundLog::FILE_NAME . '.corral-1700000000', '{}', 0600);

        $row = DoctorChannelsChecks::inboundLogRow($this->dirSeam());

        self::assertSame('WARN', $row['status']);
        self::assertStringContainsString('1 quarantined inbound ledger file', $row['detail']);
    }

    public function testCorralledBytesWarnBesideALiveLedger(): void
    {
        $this->writeLedger(0600);
        DoctorChannelsProbeFixture::writeProbeFile($this->dir, ChannelInboundLog::FILE_NAME . '.corral-1700000000', '{}', 0600);

        $row = DoctorChannelsChecks::inboundLogRow($this->dirSeam());

        self::assertSame('WARN', $row['status']);
        self::assertStringContainsString('quarantined', $row['detail']);
    }

    public function testNeighboursAreNotCorrals(): void
    {
        $this->writeLedger(0600);
        DoctorChannelsProbeFixture::writeProbeFile($this->dir, ChannelInboundLog::FILE_NAME . '.bak', '{}', 0600);

        $row = DoctorChannelsChecks::inboundLogRow($this->dirSeam());

        self::assertSame('OK', $row['status']);
    }

    public function testTheLedgerTreeSurvivesTheDiagnosis(): void
    {
        $ledger = $this->writeLedger(0600);
        $corral = DoctorChannelsProbeFixture::writeProbeFile($this->dir, ChannelInboundLog::FILE_NAME . '.corral-1700000000', '{}', 0600);
        DoctorChannelsProbeFixture::writeProbeFile($this->dir, ChannelInboundLog::FILE_NAME . '.bak', '{}', 0600);
        $listing = \scandir($this->dir);
        $digests = [$ledger => \md5_file($ledger), $corral => \md5_file($corral)];

        DoctorChannelsChecks::inboundLogRow($this->dirSeam());

        self::assertSame($listing, \scandir($this->dir));
        foreach ($digests as $path => $digest) {
            self::assertSame($digest, \md5_file($path), 'inbound diagnosis must not touch bytes');
        }
    }

    /** @return callable():string */
    private function dirSeam(): callable
    {
        return fn (): string => $this->dir;
    }

    private function store(): ChannelsStore
    {
        return ChannelsStore::create($this->dirSeam());
    }

    /** @param array<string,mixed> $record */
    private function writeStore(array $record, int $mode): string
    {
        return $this->writeStoreBody(
            \json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            $mode
        );
    }

    private function writeStoreBody(string $body, int $mode): string
    {
        return DoctorChannelsProbeFixture::writeProbeFile($this->dir, ChannelsStore::FILE_NAME, $body, $mode);
    }

    private function writeLedger(int $mode): string
    {
        return DoctorChannelsProbeFixture::writeProbeFile(
            $this->dir,
            ChannelInboundLog::FILE_NAME,
            '{"rows":[]}',
            $mode
        );
    }

    private function bundleNamed(string $name): Bundle
    {
        $dir = DoctorChannelsProbeFixture::makeChannelProbeBundle($this->dir, $name);

        return new Bundle($dir, $name, 'user', '', '', [
            'entrypoints' => ['channel' => [['file' => 'channel.php', 'class' => 'ProbeChannel']]],
        ]);
    }
}
