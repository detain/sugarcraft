<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\BundleSource;
use SugarCraft\Code\Bundles\MarketplaceEntry;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * C-EXT-4.a: the install provenance record. The pins here are deliberately
 * about the READ floor — the record lives inside the bundle directory, so a
 * cloned tree can plant one — and about what shape survives a re-read. The
 * record is a claim the directory makes; {@see BundleSource::read()} only
 * guarantees it resolves inside the named boundary and parses.
 */
final class BundleSourceTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir;

    private string $boundary;

    private string $bundleDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/cc-cext4a-bundlesource-' . uniqid((string) getmypid(), true);
        $this->boundary = $this->tempDir . '/bundles';
        $this->bundleDir = $this->boundary . '/beta';
        mkdir($this->bundleDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testAnInstalledRecordsProvenanceRoundTrip(): void
    {
        $entry = MarketplaceEntry::github('beta', 'acme/beta')->withPinnedSha(str_repeat('c', 40));
        $source = BundleSource::fromEntry($entry, 'acme-hub');

        self::assertNull($source->write($this->bundleDir));
        self::assertFileExists($this->bundleDir . '/' . BundleSource::RECORD_RELATIVE_PATH);

        $read = BundleSource::read($this->bundleDir, $this->boundary);
        self::assertNotNull($read);
        self::assertSame('acme-hub', $read->marketplace);
        self::assertSame('beta', $read->name);
        self::assertSame(MarketplaceEntry::SOURCE_GITHUB, $read->sourceType);
        self::assertSame('acme/beta', $read->repo);
        self::assertSame(str_repeat('c', 40), $read->sha);
        self::assertGreaterThan(0, $read->installedAt);
    }

    public function testAMissingUnparsableOrWrongShapedRecordReadsAsNone(): void
    {
        self::assertNull(BundleSource::read($this->bundleDir, $this->boundary));

        file_put_contents($this->bundleDir . '/' . BundleSource::RECORD_RELATIVE_PATH, 'not json at all {');
        self::assertNull(BundleSource::read($this->bundleDir, $this->boundary));

        foreach ([
            ['name' => 'a/b', 'sourceType' => 'github', 'marketplace' => 'h'],
            ['name' => 'beta', 'sourceType' => 'sftp', 'marketplace' => 'h'],
            ['name' => 'beta', 'sourceType' => 'github'],
        ] as $shape) {
            file_put_contents(
                $this->bundleDir . '/' . BundleSource::RECORD_RELATIVE_PATH,
                json_encode($shape + ['repo' => 'a/b', 'url' => '', 'sha' => '', 'installedAt' => 3]),
            );
            self::assertNull(BundleSource::read($this->bundleDir, $this->boundary), json_encode($shape));
        }
    }

    public function testARecordLinkedOutOfItsBundleIsNeverRead(): void
    {
        $outside = $this->tempDir . '/forged.json';
        file_put_contents($outside, json_encode([
            'marketplace' => 'innocent',
            'name' => 'beta',
            'sourceType' => 'github',
            'repo' => 'acme/beta',
            'url' => '',
            'sha' => str_repeat('d', 40),
            'installedAt' => 9,
        ]));
        symlink($outside, $this->bundleDir . '/' . BundleSource::RECORD_RELATIVE_PATH);

        self::assertNull(BundleSource::read($this->bundleDir, $this->bundleDir));

        // The boundary may be the parent root, but the link target must
        // resolve inside it: an outside file fails that compare either way.
        self::assertNull(BundleSource::read($this->bundleDir, $this->boundary));
    }

    public function testANonIntegerTimestampCollapsesToZeroNotNull(): void
    {
        file_put_contents($this->bundleDir . '/' . BundleSource::RECORD_RELATIVE_PATH, json_encode([
            'marketplace' => 'h',
            'name' => 'beta',
            'sourceType' => 'path',
            'repo' => '',
            'url' => '',
            'sha' => '',
            'installedAt' => 'soon',
        ]));

        $read = BundleSource::read($this->bundleDir, $this->boundary);
        self::assertNotNull($read);
        self::assertSame(0, $read->installedAt);
    }

    public function testTrustedAndReadPathsBuildTheSameView(): void
    {
        $trusted = BundleSource::fromTrustedRecord('h', 'beta', 'url', '', 'https://x.invalid/beta', str_repeat('e', 40), 11);
        self::assertNull($trusted->write($this->bundleDir));
        $read = BundleSource::read($this->bundleDir, $this->boundary);
        self::assertNotNull($read);
        self::assertSame($trusted->marketplace, $read->marketplace);
        self::assertSame($trusted->url, $read->url);
        self::assertSame($trusted->sha, $read->sha);
        self::assertSame($trusted->installedAt, $read->installedAt);
    }

    public function testWritingIntoAMissingDirectoryRefusesLoudly(): void
    {
        $source = BundleSource::fromTrustedRecord('h', 'gone', 'github', 'a/gone', '', '', 1);

        self::assertStringContainsString('does not exist', (string) $source->write($this->tempDir . '/nowhere'));
    }

    public function testDescribeNamesOriginAndShortCommit(): void
    {
        self::assertSame(
            'hub/beta (github:acme/beta @ abcdefa)',
            BundleSource::fromTrustedRecord('hub', 'beta', 'github', 'acme/beta', '', 'abcdefabc', 1)->describe(),
        );
        self::assertSame(
            'hub/plain (path)',
            BundleSource::fromTrustedRecord('hub', 'plain', 'path', '', '', '', 1)->describe(),
        );
        self::assertStringContainsString(
            'url:https://x.invalid/y',
            BundleSource::fromTrustedRecord('hub', 'y', 'url', '', 'https://x.invalid/y', '', 1)->describe(),
        );
    }
}
