<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Channels\BundleChannelLoader;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Trust-door pins for the bundle channel/subscriber loader (code-chat P1 step 4/8).
 *
 * Every fixture class file writes a MARKER SENTINEL at file top level the moment
 * PHP executes it. The load-bearing claim of this whole lane — an untrusted
 * bundle's class bytes are NEVER executed, not merely unregistered — is pinned
 * by the marker's ABSENCE, which no registration-side assert could prove.
 *
 * @see \SugarCraft\Code\Channels\BundleChannelLoader
 */
final class BundleChannelLoaderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir = '';

    private int $counter = 0;

    /** @var int process-wide fixture class serial — required class names live for the whole PHP process */
    private static int $classSeq = 0;

    /** @var array<string,string> slug => the bundle name this test instance trusts */
    private array $names = [];

    /** @var array<string,string> slug => the bundle directory on disk */
    private array $dirs = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy-code-loader-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testAnEmptyTrustListReadsNothingAtAll(): void
    {
        [$bundle, $marker] = $this->singleEntryBundle('empty-door');
        $store = $this->store();
        $buckets = BundleChannelLoader::load([$bundle], [], $store);
        self::assertSame([], $buckets['loaded'], 'nothing loads when nobody is trusted');
        self::assertSame([], $buckets['quarantined'], 'not even quarantine: the door is shut BEFORE any read');
        self::assertSame([], $buckets['refusals'], 'an untrusted bundle is not refused, it is never opened');
        self::assertFileDoesNotExist($marker, 'the class bytes were never executed');
    }

    public function testAForeignNameInTheTrustListOpensNothing(): void
    {
        [$bundle, $marker] = $this->singleEntryBundle('wrong-name');
        $buckets = BundleChannelLoader::load([$bundle], ['some-other-bundle'], $this->store());
        self::assertSame([], $buckets['loaded']);
        self::assertFileDoesNotExist($marker, 'trusting a different bundle does not lend its door to this one');
    }

    public function testTheTrustMatchIsExactCaseAndNeverFolded(): void
    {
        [$bundle, $marker] = $this->singleEntryBundle('case-fold');
        $folded = ucfirst($this->trusted('case-fold'));
        self::assertNotSame($this->trusted('case-fold'), $folded, 'the fixture really does differ only in case');
        $buckets = BundleChannelLoader::load([$bundle], [$folded], $this->store());
        self::assertSame([], $buckets['loaded'], 'a trust spelling that differs only in case is a DIFFERENT name');
        self::assertFileDoesNotExist($marker);
    }

    public function testAFirstSightEntrypointIsQuarantinedAndNotExecuted(): void
    {
        [$bundle, $marker, $entry] = $this->singleEntryBundle('quarantine');
        $store = $this->store();
        $buckets = BundleChannelLoader::load([$bundle], [$this->trusted('quarantine')], $store);
        self::assertSame([], $buckets['loaded'], 'launch one never runs an entrypoint the launch has never seen');
        self::assertCount(1, $buckets['quarantined']);
        self::assertFileDoesNotExist($marker, 'quarantine means the require never happened, not that it was undone');
        self::assertTrue($store->seenEntrypoint($entry['path']), 'but the first sight is on the ledger for launch two');
    }

    public function testTheQuarantinedEntrypointLoadsOnTheSecondLaunch(): void
    {
        [$bundle, $marker] = $this->singleEntryBundle('two-launch');
        $trusted = [$this->trusted('two-launch')];
        $store = $this->store();
        BundleChannelLoader::load([$bundle], $trusted, $store);
        $second = BundleChannelLoader::load([$bundle], $trusted, $store);
        self::assertSame([], $second['quarantined'], 'the ledger remembered the first sight');
        self::assertCount(1, $second['loaded']);
        self::assertFileExists($marker, 'launch two is where trusted bytes finally run');
    }

    public function testWorldWritableBytesAreRefusedEvenFromATrustedName(): void
    {
        [$bundle, $marker, $entry] = $this->singleEntryBundle('loose-file');
        chmod($entry['path'], 0o666);
        $buckets = BundleChannelLoader::load([$bundle], [$this->trusted('loose-file')], $this->store());
        self::assertSame([], $buckets['loaded']);
        self::assertSame('world-writable', $buckets['refusals'][0]['reason'] ?? null);
        self::assertFileDoesNotExist($marker, 'name-trust does not extend to bytes another account can rewrite');
    }

    public function testAWorldWritableContainingDirRefusesTheEntrypointToo(): void
    {
        [$bundle, $marker, $entry] = $this->singleEntryBundle('loose-dir');
        chmod(dirname($entry['path']), 0o777);
        $buckets = BundleChannelLoader::load([$bundle], [$this->trusted('loose-dir')], $this->store());
        self::assertSame([], $buckets['loaded']);
        self::assertSame('world-writable', $buckets['refusals'][0]['reason'] ?? null);
        self::assertFileDoesNotExist($marker);
    }

    public function testARefusedEntrypointIsNeverRecordedInTheQuarantineLedger(): void
    {
        [$bundle, , $entry] = $this->singleEntryBundle('refusal-shape');
        chmod($entry['path'], 0o666);
        $store = $this->store();
        BundleChannelLoader::load([$bundle], [$this->trusted('refusal-shape')], $store);
        self::assertFalse($store->seenEntrypoint($entry['path']), 'a refusal is not a first sight: tightening the bytes must still quarantine before it loads');
    }

    public function testAQuarantineBucketRowCarriesItsBundlePathClassAndKind(): void
    {
        [$bundle, , $entry] = $this->singleEntryBundle('row-shape');
        $buckets = BundleChannelLoader::load([$bundle], [$this->trusted('row-shape')], $this->store());
        $row = $buckets['quarantined'][0] ?? [];
        self::assertSame($this->trusted('row-shape'), $row['bundle'] ?? null);
        self::assertSame($entry['path'], $row['path'] ?? null);
        self::assertSame($entry['class'], $row['class'] ?? null);
        self::assertSame('channel', $row['kind'] ?? null);
    }

    public function testAClassTheFileNeverDeclaresRefusesAfterExecution(): void
    {
        // Honest shape: the file RUNS (a quarantined-then-trusted entrypoint that
        // mislabels its class has already paid the first-sight cost), then the
        // declared-class check refuses it. The sentinel proves load happened.
        $slug = 'wrong-class';
        [, $marker, $entry] = $this->singleEntryBundle($slug);
        $ghost = $this->bundleFromEntrypoints($slug, ['channel' => [['path' => $entry['path'], 'class' => 'CcP1Ghost' . ++$this->counter]]]);
        $trusted = [$this->trusted($slug)];
        $store = $this->store();
        BundleChannelLoader::load([$ghost], $trusted, $store);
        $buckets = BundleChannelLoader::load([$ghost], $trusted, $store);
        self::assertSame([], $buckets['loaded']);
        self::assertSame('class-not-declared', $buckets['refusals'][0]['reason'] ?? null);
        self::assertFileExists($marker, 'the check is load-then-verify, and the sentinel ran before it');
    }

    public function testAThrowingEntrypointIsRefusedAndItsSiblingBundlesStillLoad(): void
    {
        // m04: a trusted bundle whose entrypoint file THROWS at require time used
        // to abort the entire loader — every sibling bundle lost its channels for
        // the launch. Now the blow-up is one refused row carrying its diagnostic,
        // and the remaining bundles load normally.
        $boom = $this->throwingEntryFile('boom');
        $badBundle = $this->bundleFromEntrypoints('boom', ['channel' => [$boom]]);
        $calmEntry = $this->entryFile('calm');
        $goodBundle = $this->bundleFromEntrypoints('calm', ['channel' => [$calmEntry]]);
        $trusted = [$this->trusted('boom'), $this->trusted('calm')];
        $store = $this->store();
        $first = BundleChannelLoader::load([$badBundle, $goodBundle], $trusted, $store);
        self::assertCount(2, $first['quarantined'], 'first sight quarantines both, runs neither');
        self::assertFileDoesNotExist($calmEntry['marker'], 'first sight runs nothing');
        $buckets = BundleChannelLoader::load([$badBundle, $goodBundle], $trusted, $store);
        self::assertSame('require-failed', $buckets['refusals'][0]['reason'] ?? null);
        self::assertSame($badBundle->name, $buckets['refusals'][0]['bundle'] ?? null);
        self::assertSame($boom['path'], $buckets['refusals'][0]['path'] ?? null);
        $diagnostic = (string) ($buckets['refusals'][0]['error'] ?? '');
        self::assertNotSame('', $diagnostic, 'the row carries its diagnostic as data for the registry to voice');
        self::assertStringContainsString('cc-p1-m04 blown at require', $diagnostic);
        self::assertCount(1, $buckets['loaded'], 'the sibling bundle still loads — one blown file cannot dim the feed');
        self::assertSame($calmEntry['class'], $buckets['loaded'][0]['class'] ?? null);
        self::assertFileExists($calmEntry['marker'], 'the good bytes ran');
        self::assertFileExists($boom['marker'], 'honest shape: the thrower ran until it threw');
        self::assertTrue($store->seenEntrypoint($boom['path']), 'an attempted file stays on the ledger, matching the class-check refusal');
    }

    public function testAnEntrypointWithNoDeclaredClassRefusesAfterExecution(): void
    {
        $slug = 'no-class';
        [, $marker, $entry] = $this->singleEntryBundle($slug);
        $blank = $this->bundleFromEntrypoints($slug, ['channel' => [['path' => $entry['path'], 'class' => '']]]);
        $trusted = [$this->trusted($slug)];
        $store = $this->store();
        BundleChannelLoader::load([$blank], $trusted, $store);
        $buckets = BundleChannelLoader::load([$blank], $trusted, $store);
        self::assertSame([], $buckets['loaded']);
        self::assertSame('class-missing', $buckets['refusals'][0]['reason'] ?? null);
        self::assertFileExists($marker);
    }

    public function testOnlyTheChannelKindLoadsUnderTheDefaultKindList(): void
    {
        $entry = $this->entryFile('kind-door');
        $bundle = $this->bundleFromEntrypoints('kind-door', ['subscriber' => [$entry]]);
        $buckets = BundleChannelLoader::load([$bundle], [$this->trusted('kind-door')], $this->store());
        self::assertSame([], $buckets['loaded'], 'a subscriber entrypoint is invisible to the channel-only default read');
        self::assertFileDoesNotExist($this->markers['kind-door'] ?? '');
    }

    public function testAWidenedKindListReadsTheSubscriberEntrypoint(): void
    {
        $slug = 'kind-open';
        $entry = $this->entryFile($slug);
        $bundle = $this->bundleFromEntrypoints($slug, ['subscriber' => [$entry]]);
        $trusted = [$this->trusted($slug)];
        $kinds = ['channel', 'subscriber'];
        $store = $this->store();
        $first = BundleChannelLoader::load([$bundle], $trusted, $store, $kinds);
        self::assertCount(1, $first['quarantined'], 'first sight quarantines whatever kind it carries');
        $second = BundleChannelLoader::load([$bundle], $trusted, $store, $kinds);
        self::assertCount(1, $second['loaded']);
        self::assertSame('subscriber', $second['loaded'][0]['kind']);
    }

    public function testTheSameFileUnderTwoKindsIsReadOnceForTheBundle(): void
    {
        $slug = 'dupe';
        $entry = $this->entryFile($slug);
        $bundle = $this->bundleFromEntrypoints($slug, ['channel' => [$entry], 'subscriber' => [$entry]]);
        $store = $this->store();
        $first = BundleChannelLoader::load([$bundle], [$this->trusted($slug)], $store, ['channel', 'subscriber']);
        self::assertCount(1, $first['quarantined'], 'one path, one quarantine row');
        $second = BundleChannelLoader::load([$bundle], [$this->trusted($slug)], $store, ['channel', 'subscriber']);
        self::assertCount(1, $second['loaded'], 'the second kind sees the path already consumed for this bundle');
        self::assertSame('channel', $second['loaded'][0]['kind'], 'the first kind in the list wins the row');
    }

    public function testAManifestWhoseEntrypointsAreAListSilentlyCarriesNothing(): void
    {
        // The reader (Bundle::entrypoints) requires a kind-keyed map; the old
        // list spelling yields zero rows — the loader must then produce zero
        // buckets WITHOUT touching the store, not crash on a half-shape.
        $slug = 'list-shape';
        $dir = $this->bundleDir($slug);
        $this->writeManifest($slug, $dir, ['entrypoints' => ['chan.php']]);
        $bundle = Bundle::fromDir($dir, 'project');
        self::assertNotNull($bundle);
        $store = $this->store();
        $buckets = BundleChannelLoader::load([$bundle], [$this->trusted($slug)], $store);
        self::assertSame([], $buckets['loaded']);
        self::assertSame([], $buckets['refusals']);
    }

    public function testTheLedgerFileLandsPrivateToTheOwner(): void
    {
        [$bundle] = $this->singleEntryBundle('mode');
        $configDir = $this->tempDir . '/cfg-mode';
        mkdir($configDir, 0700, true);
        $store = ChannelsStore::create(static fn (): string => $configDir);
        BundleChannelLoader::load([$bundle], [$this->trusted('mode')], $store);
        $file = $configDir . '/' . ChannelsStore::FILE_NAME;
        self::assertFileExists($file);
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($file)), -4), 'the ledger says which classes will run next launch — group-readable is not private enough');
    }

    public function testAMalformedLedgerDegradesToFreshQuarantineInsteadOfLoading(): void
    {
        [$bundle] = $this->singleEntryBundle('garbage');
        $configDir = $this->tempDir . '/cfg-garbage';
        mkdir($configDir, 0700, true);
        file_put_contents($configDir . '/' . ChannelsStore::FILE_NAME, '{not json at all');
        $store = ChannelsStore::create(static fn (): string => $configDir);
        $buckets = BundleChannelLoader::load([$bundle], [$this->trusted('garbage')], $store);
        self::assertSame([], $buckets['loaded'], 'the corrupt record fails CLOSED — the quarantine treats every entrypoint as first-sighted and nothing loads');
        self::assertCount(1, $buckets['quarantined']);
    }

    public function testTrustIsPerBundleAndATrustedNameNeverCracksOpenItsSibling(): void
    {
        $loudEntry = $this->entryFile('side');
        $loud = $this->bundleFromEntrypoints('side', ['channel' => [$loudEntry]]);
        $quietEntry = $this->entryFile('quiet');
        $quiet = $this->bundleFromEntrypoints('quiet', ['channel' => [$quietEntry]]);
        $buckets = BundleChannelLoader::load([$quiet, $loud], [$this->trusted('side')], $this->store());
        self::assertCount(1, $buckets['quarantined'], 'only the named bundle reaches its entries');
        self::assertFileDoesNotExist($this->markers['quiet'] ?? '', 'a trusted sibling opens nothing for the untrusted one');
    }

    // ---- fixture builders (loader-domain spellings; the registry file must NOT copy these) ----

    /** @var array<string,string> slug => marker file path */
    private array $markers = [];

    /** One trusted bundle name per slug, minted once so manifest and trust list agree. */
    private function trusted(string $slug): string
    {
        return $this->names[$slug] ??= 'cc-p1-' . $slug . '-' . getmypid() . '-' . (++$this->counter);
    }

    private function bundleDir(string $slug): string
    {
        return $this->dirs[$slug] ??= (static function (string $d): string {
            mkdir($d . '/.claude-plugin', 0777, true);
            return $d;
        })($this->tempDir . '/bundles/' . $slug);
    }

    /** @return array{path:string,class:string,marker:string} */
    private function entryFile(string $slug): array
    {
        $dir = $this->bundleDir($slug);
        $class = 'CcP1Entry' . getmypid() . '_' . (++self::$classSeq);
        $marker = $this->markers[$slug] ??= $this->tempDir . '/marker-' . $slug . '-' . $this->counter;
        $markerExport = var_export($marker, true);
        $file = $dir . '/entry-' . $this->counter . '.php';
        file_put_contents($file, "<?php\nfile_put_contents({$markerExport}, 'executed');\nclass {$class}\n{\n}\n");
        return ['path' => (string) realpath($file), 'class' => $class, 'marker' => $marker];
    }

    /** @return array{path:string,class:string,marker:string} an entrypoint file that throws at top level. */
    private function throwingEntryFile(string $slug): array
    {
        $dir = $this->bundleDir($slug);
        $class = 'CcP1Blown' . getmypid() . '_' . (++self::$classSeq);
        $marker = $this->markers[$slug] ??= $this->tempDir . '/marker-' . $slug . '-' . $this->counter;
        $markerExport = var_export($marker, true);
        $file = $dir . '/entry-boom-' . $slug . '.php';
        file_put_contents($file, "<?php\nfile_put_contents({$markerExport}, 'executed');\nthrow new \\RuntimeException('cc-p1-m04 blown at require');\n");
        return ['path' => (string) realpath($file), 'class' => $class, 'marker' => $marker];
    }

    /** @param array<string,list<array{path:string,class:string}>> $kinds */
    private function bundleFromEntrypoints(string $slug, array $kinds): Bundle
    {
        $dir = $this->bundleDir($slug);
        $entrypoints = [];
        foreach ($kinds as $kind => $entries) {
            foreach ($entries as $entry) {
                $entrypoints[$kind][] = ['file' => $entry['path'], 'class' => $entry['class']];
            }
        }
        $this->writeManifest($slug, $dir, ['entrypoints' => $entrypoints]);
        $bundle = Bundle::fromDir($dir, 'project');
        self::assertNotNull($bundle, 'fixture bundle must parse: ' . $slug);
        return $bundle;
    }

    /** @param array<string,mixed> $extraManifestKeys */
    private function writeManifest(string $slug, string $dir, array $extraManifestKeys = []): void
    {
        $manifest = array_merge(['name' => $this->trusted($slug), 'version' => '1.0.0'], $extraManifestKeys);
        file_put_contents($dir . '/.claude-plugin/plugin.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /** @return array{0:Bundle,1:string,2:array{path:string,class:string,marker:string}} */
    private function singleEntryBundle(string $slug): array
    {
        $entry = $this->entryFile($slug);
        return [$this->bundleFromEntrypoints($slug, ['channel' => [$entry]]), $entry['marker'], $entry];
    }

    private function store(): ChannelsStore
    {
        $configDir = $this->tempDir . '/cfg-' . (++$this->counter);
        mkdir($configDir, 0700, true);
        return ChannelsStore::create(static fn (): string => $configDir);
    }
}
