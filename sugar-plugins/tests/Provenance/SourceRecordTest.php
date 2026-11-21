<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Provenance;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Provenance\SourceRecord;
use SugarCraft\Plugins\Tests\Support\Scratch;

final class SourceRecordTest extends TestCase
{
    private string $root;

    private string $bundleDir;

    /** The record name an earlier host chose; existing installs carry it on disk. */
    private const HOST_NAME = '.host-source.json';

    protected function setUp(): void
    {
        $this->root = Scratch::dir('provenance');
        $this->bundleDir = $this->root . '/bundles/beta';
        mkdir($this->bundleDir, 0700, true);
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->root);
    }

    /** @return callable(string): bool */
    private static function nameRule(): callable
    {
        return static fn (string $name): bool => (bool) preg_match('/^[a-z0-9-]+$/', $name);
    }

    /** @return list<string> */
    private static function sourceTypes(): array
    {
        return ['path', 'github', 'url', 'git'];
    }

    private function boundary(): string
    {
        return $this->root . '/bundles';
    }

    public function testFromTrustedRecordExposesEveryField(): void
    {
        $record = SourceRecord::fromTrustedRecord('hub', 'beta', 'github', 'acme/beta', 'https://x.invalid/b', str_repeat('a', 40), 42);

        self::assertSame('hub', $record->marketplace);
        self::assertSame('beta', $record->name);
        self::assertSame('github', $record->sourceType);
        self::assertSame('acme/beta', $record->repo);
        self::assertSame('https://x.invalid/b', $record->url);
        self::assertSame(str_repeat('a', 40), $record->sha);
        self::assertSame(42, $record->installedAt);
    }

    public function testAWrittenRecordReadsBackUnderTheSameInjectedName(): void
    {
        $record = SourceRecord::fromTrustedRecord('hub', 'beta', 'url', '', 'https://x.invalid/beta', str_repeat('e', 40), 11);

        self::assertNull($record->write($this->bundleDir, self::HOST_NAME));
        self::assertFileExists($this->bundleDir . '/' . self::HOST_NAME);

        $read = SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), self::sourceTypes());
        self::assertNotNull($read);
        self::assertSame('hub', $read->marketplace);
        self::assertSame('beta', $read->name);
        self::assertSame('url', $read->sourceType);
        self::assertSame('https://x.invalid/beta', $read->url);
        self::assertSame(str_repeat('e', 40), $read->sha);
        self::assertSame(11, $read->installedAt);
    }

    public function testTheRecordWrittenUnderOneNameIsInvisibleUnderAnother(): void
    {
        SourceRecord::fromTrustedRecord('hub', 'beta', 'path', '', '', '', 1)->write($this->bundleDir, self::HOST_NAME);

        self::assertNull(SourceRecord::read($this->bundleDir, $this->boundary(), '.other.json', self::nameRule(), self::sourceTypes()));
    }

    public function testAnExistingInstallRecordStillReadsUnderItsOriginalName(): void
    {
        // The shape an install written before the split leaves behind, byte for byte.
        $legacy = '{"marketplace":"acme-hub","name":"beta","sourceType":"github","repo":"acme/beta",'
            . '"url":"","sha":"' . str_repeat('c', 40) . '","installedAt":1700000000}';
        Scratch::put($this->bundleDir . '/.candy-code-source.json', $legacy);

        $read = SourceRecord::read($this->bundleDir, $this->boundary(), '.candy-code-source.json', self::nameRule(), self::sourceTypes());

        self::assertNotNull($read);
        self::assertSame('acme-hub', $read->marketplace);
        self::assertSame('beta', $read->name);
        self::assertSame('github', $read->sourceType);
        self::assertSame('acme/beta', $read->repo);
        self::assertSame(str_repeat('c', 40), $read->sha);
        self::assertSame(1700000000, $read->installedAt);
    }

    public function testAMissingUnparsableOrWrongShapedRecordReadsAsNone(): void
    {
        self::assertNull(SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), self::sourceTypes()));

        Scratch::put($this->bundleDir . '/' . self::HOST_NAME, 'not json at all {');
        self::assertNull(SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), self::sourceTypes()));

        foreach ([
            ['name' => 'A/B', 'sourceType' => 'github', 'marketplace' => 'h'],
            ['name' => 'beta', 'sourceType' => 'sftp', 'marketplace' => 'h'],
            ['name' => 'beta', 'sourceType' => 'github'],
        ] as $shape) {
            Scratch::put(
                $this->bundleDir . '/' . self::HOST_NAME,
                json_encode($shape + ['repo' => 'a/b', 'url' => '', 'sha' => '', 'installedAt' => 3]),
            );
            self::assertNull(
                SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), self::sourceTypes()),
                json_encode($shape),
            );
        }
    }

    public function testTheHostNameRuleAndSourceTypeListDecideWhatIsValid(): void
    {
        Scratch::put($this->bundleDir . '/' . self::HOST_NAME, json_encode([
            'marketplace' => 'h', 'name' => 'beta', 'sourceType' => 'path',
        ]));

        // A host that accepts no names refuses the record even though its shape is fine.
        self::assertNull(SourceRecord::read(
            $this->bundleDir, $this->boundary(), self::HOST_NAME, static fn (string $n): bool => false, self::sourceTypes(),
        ));
        // A host whose source-type list lacks "path" refuses it too.
        self::assertNull(SourceRecord::read(
            $this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), ['github'],
        ));
        self::assertNotNull(SourceRecord::read(
            $this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), ['path'],
        ));
    }

    public function testARecordLinkedOutOfItsBundleIsNeverRead(): void
    {
        $outside = $this->root . '/forged.json';
        Scratch::put($outside, json_encode([
            'marketplace' => 'innocent', 'name' => 'beta', 'sourceType' => 'github',
            'repo' => 'acme/beta', 'url' => '', 'sha' => str_repeat('d', 40), 'installedAt' => 9,
        ]));
        symlink($outside, $this->bundleDir . '/' . self::HOST_NAME);

        // The boundary may be the bundle itself, or its parent: the link target
        // resolves outside both, so the compare refuses it either way.
        self::assertNull(SourceRecord::read($this->bundleDir, $this->bundleDir, self::HOST_NAME, self::nameRule(), self::sourceTypes()));
        self::assertNull(SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), self::sourceTypes()));
    }

    public function testANonIntegerTimestampCollapsesToZeroNotNull(): void
    {
        Scratch::put($this->bundleDir . '/' . self::HOST_NAME, json_encode([
            'marketplace' => 'h', 'name' => 'beta', 'sourceType' => 'path',
            'repo' => '', 'url' => '', 'sha' => '', 'installedAt' => 'soon',
        ]));

        $read = SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), self::sourceTypes());
        self::assertNotNull($read);
        self::assertSame(0, $read->installedAt);
    }

    public function testWritingIntoAMissingDirectoryRefusesLoudly(): void
    {
        $record = SourceRecord::fromTrustedRecord('h', 'gone', 'github', 'a/gone', '', '', 1);

        self::assertStringContainsString('does not exist', (string) $record->write($this->root . '/nowhere', self::HOST_NAME));
    }

    public function testTheRecordFileIsWrittenOwnerOnly(): void
    {
        SourceRecord::fromTrustedRecord('h', 'beta', 'path', '', '', '', 1)->write($this->bundleDir, self::HOST_NAME);

        self::assertSame(0o600, fileperms($this->bundleDir . '/' . self::HOST_NAME) & 0o777);
    }

    public function testAVersionWrittenAtInstallReadsBackUnderItsOwnField(): void
    {
        $record = SourceRecord::fromTrustedRecord('composer', 'sample-plugin', 'composer', 'acme/sample-plugin', '', '', 5, '2.1.0');

        self::assertSame('2.1.0', $record->version);
        self::assertNull($record->write($this->bundleDir, self::HOST_NAME));

        $read = SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), ['composer']);
        self::assertNotNull($read);
        self::assertSame('acme/sample-plugin', $read->repo);
        self::assertSame('2.1.0', $read->version);
    }

    public function testVersionDefaultsToEmptyAndAnExistingRecordWithoutItStillReads(): void
    {
        self::assertSame('', SourceRecord::fromTrustedRecord('h', 'beta', 'path', '', '', '', 1)->version);

        // The shape candy-code's marketplace installs wrote before the field existed.
        Scratch::put($this->bundleDir . '/' . self::HOST_NAME, json_encode([
            'marketplace' => 'acme-hub', 'name' => 'beta', 'sourceType' => 'github', 'repo' => 'acme/beta',
            'url' => '', 'sha' => str_repeat('f', 40), 'installedAt' => 4,
        ]));

        $read = SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), self::sourceTypes());
        self::assertNotNull($read);
        self::assertSame('acme/beta', $read->repo);
        self::assertSame('', $read->version);
    }

    public function testANonStringVersionCollapsesToEmpty(): void
    {
        Scratch::put($this->bundleDir . '/' . self::HOST_NAME, json_encode([
            'marketplace' => 'h', 'name' => 'beta', 'sourceType' => 'path', 'version' => 2,
        ]));

        $read = SourceRecord::read($this->bundleDir, $this->boundary(), self::HOST_NAME, self::nameRule(), self::sourceTypes());
        self::assertNotNull($read);
        self::assertSame('', $read->version);
    }
}
