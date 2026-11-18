<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Channel\SubscribesToSessionEvents;
use SugarCraft\Plugins\Loader\LoadResult;
use SugarCraft\Plugins\Loader\PluginCandidate;
use SugarCraft\Plugins\Loader\PluginLoader;
use SugarCraft\Plugins\Manifest\PluginManifest;
use SugarCraft\Plugins\Plugin;
use SugarCraft\Plugins\Tests\Support\MemoryLedger;
use SugarCraft\Plugins\Tests\Support\Plugins;
use SugarCraft\Plugins\Tests\Support\Scratch;
use SugarCraft\Plugins\Trust\QuarantineLedger;
use SugarCraft\Plugins\Trust\TrustList;

final class PluginLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            Scratch::remove($dir);
        }
        $this->dirs = [];
    }

    /**
     * Write a plugin whose manifest names $entrypoints and whose file is $source.
     *
     * @param array<string, mixed> $entrypoints
     *
     * @return array{dir: string, file: string}
     */
    private function build(string $name, string $source, array $entrypoints, ?int $contract = null): array
    {
        $dir = Scratch::dir('loader');
        $this->dirs[] = $dir;
        $file = Scratch::put($dir . '/src/Impl.php', $source);

        $manifest = ['name' => $name, 'entrypoints' => $entrypoints];
        if ($contract !== null) {
            $manifest['contract'] = $contract;
        }
        Scratch::put($dir . '/plugin.json', (string) json_encode($manifest));

        return ['dir' => $dir, 'file' => (string) realpath($file)];
    }

    /** A plugin whose entrypoint names $class in src/Impl.php, declared by $source. */
    private function declaring(string $class, ?int $contract = null): array
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $fqcn = $ns . '\\' . $class;
        $built = $this->build('demo', Plugins::pluginSource($ns, $class, 'demo'), [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ], $contract);

        return $built + ['class' => $fqcn];
    }

    /** @param list<string> $trusted */
    private function load(
        array $candidates,
        MemoryLedger $ledger,
        array $trusted = ['demo'],
        array $kinds = ['channel'],
        array $interfaces = ['channel' => Plugin::class],
        int $host = 3,
    ): LoadResult {
        return PluginLoader::load($candidates, new TrustList($trusted), $ledger, $kinds, $interfaces, $host);
    }

    public function testFirstSightQuarantinesAndTheNextLaunchLoads(): void
    {
        $plugin = $this->declaring('Impl');
        $ledger = new MemoryLedger();
        $candidate = Plugins::candidate('demo', $plugin['dir']);

        $first = $this->load([$candidate], $ledger);

        $this->assertCount(1, $first->quarantined);
        $this->assertSame([], $first->loaded);
        $this->assertSame([], $first->refusals);
        $this->assertFalse(class_exists($plugin['class'], false), 'a quarantined file must not execute');
        $this->assertSame([$plugin['file']], $ledger->recorded);

        $second = $this->load([$candidate], $ledger->nextLaunch());

        $this->assertCount(1, $second->loaded);
        $this->assertSame([], $second->quarantined);
        $this->assertSame($plugin['class'], $second->loaded[0]['class']);
        $this->assertTrue(class_exists($plugin['class'], false));
    }

    public function testOnePathUnderTwoKindsIsRequiredOnceAndYieldsTheFirstKindsRowOnly(): void
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $fqcn = $ns . '\\Impl';
        $built = $this->build('demo', Plugins::pluginSource($ns, 'Impl', 'demo'), [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
            'subscriber' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ]);
        $ledger = new MemoryLedger();
        $ledger->prime($built['file']);

        $result = $this->load(
            [Plugins::candidate('demo', $built['dir'])],
            $ledger,
            kinds: ['channel', 'subscriber'],
            interfaces: ['channel' => Plugin::class, 'subscriber' => Plugin::class],
        );

        // One path, one row: the first kind in the list wins, and the second
        // kind's entry for the same file yields no row of its own.
        $this->assertSame(['channel'], array_column($result->loaded, 'kind'));
        $this->assertSame([], $result->quarantined);
        $this->assertSame([], $result->refusals);
        $this->assertTrue(class_exists($fqcn, false));
    }

    public function testADuplicatePathUnderTwoKindsQuarantinesOneRowAndLoadsOnlyTheFirstKind(): void
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $fqcn = $ns . '\\Impl';
        $built = $this->build('demo', Plugins::pluginSource($ns, 'Impl', 'demo'), [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
            'subscriber' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ]);
        $candidate = [Plugins::candidate('demo', $built['dir'])];
        $ledger = new MemoryLedger();

        // First sight: the notice counts the file once, under the first kind.
        $first = $this->load(
            $candidate,
            $ledger,
            kinds: ['channel', 'subscriber'],
            interfaces: ['channel' => Plugin::class, 'subscriber' => SubscribesToSessionEvents::class],
        );
        $this->assertSame([['channel']], array_map(static fn (array $row): array => [$row['kind']], $first->quarantined));
        $this->assertSame([$built['file']], $ledger->recorded);
        $this->assertFalse(class_exists($fqcn, false));

        // Next launch: the first kind is judged and loaded, and the second
        // kind never produces a row for the same file.
        $second = $this->load(
            $candidate,
            $ledger->nextLaunch(),
            kinds: ['subscriber', 'channel'],
            interfaces: ['channel' => Plugin::class, 'subscriber' => SubscribesToSessionEvents::class],
        );
        $this->assertSame([], $second->refusals);
        $this->assertSame(
            [['subscriber']],
            array_map(static fn (array $row): array => [$row['kind']], $second->loaded),
            'one path, one loaded row, for the first kind in the list',
        );
        $this->assertTrue(class_exists($fqcn, false));
    }

    public function testASecondCallOnTheSameLedgerIsStillThisLaunchAndRunsNothingFirstSeen(): void
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $fqcn = $ns . '\\Impl';
        $built = $this->build('demo', Plugins::pluginSource($ns, 'Impl', 'demo'), [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
            'theme' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ]);
        $candidate = [Plugins::candidate('demo', $built['dir'])];
        $ledger = new MemoryLedger();
        $interfaces = ['channel' => Plugin::class, 'theme' => Plugin::class];

        // A host that loads per kind reaches the same entry twice in one launch.
        $first = $this->load($candidate, $ledger, kinds: ['channel'], interfaces: $interfaces);
        $second = $this->load($candidate, $ledger, kinds: ['theme'], interfaces: $interfaces);

        $this->assertSame([['channel']], array_map(static fn (array $row): array => [$row['kind']], $first->quarantined));
        $this->assertSame([], $second->loaded, 'the file was first seen this launch and must not load');
        $this->assertCount(1, $second->quarantined);
        $this->assertSame([], $second->refusals);
        $this->assertFalse(class_exists($fqcn, false), 'a file first seen in this launch must not run in a later call');
        $this->assertSame([$built['file']], $ledger->recorded, 'the mark is recorded once per launch');

        $next = $this->load($candidate, $ledger->nextLaunch(), kinds: ['theme'], interfaces: $interfaces);

        $this->assertCount(1, $next->loaded, 'the next launch reads the mark and loads the file');
        $this->assertTrue(class_exists($fqcn, false));
    }

    public function testTwoTrustedCandidatesNamingOneFileQuarantineItOnceAndRunNothingThisLaunch(): void
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $class = $ns . '\\Hook';
        $outer = Scratch::dir('loader');
        $this->dirs[] = $outer;
        Scratch::put($outer . '/inner/plugin.php', Plugins::pluginSource($ns, 'Hook', 'demo'));
        Scratch::put($outer . '/plugin.json', (string) json_encode([
            'name' => 'outer',
            'entrypoints' => ['channel' => ['file' => 'inner/plugin.php', 'class' => $class]],
        ]));
        Scratch::put($outer . '/inner/plugin.json', (string) json_encode([
            'name' => 'inner',
            'entrypoints' => ['channel' => ['file' => 'plugin.php', 'class' => $class]],
        ]));
        $file = (string) realpath($outer . '/inner/plugin.php');
        $candidates = [
            Plugins::candidate('outer', $outer),
            Plugins::candidate('inner', $outer . '/inner'),
        ];
        $ledger = new MemoryLedger();

        $first = $this->load($candidates, $ledger, trusted: ['outer', 'inner']);

        $this->assertSame(['outer', 'inner'], array_column($first->quarantined, 'bundle'));
        $this->assertSame([], $first->loaded);
        $this->assertSame([], $first->refusals);
        $this->assertFalse(class_exists($class, false), 'a file first seen under one name must not run under another');
        $this->assertSame([$file], $ledger->recorded);

        $second = $this->load($candidates, $ledger->nextLaunch(), trusted: ['outer', 'inner']);

        $this->assertSame(['outer', 'inner'], array_column($second->loaded, 'bundle'));
        $this->assertSame([], $second->quarantined);
        $this->assertSame([], $second->refusals);
        $this->assertTrue(class_exists($class, false));
    }

    public function testATrustedDirectoryWithNoManifestOwesNoRow(): void
    {
        $dir = Scratch::dir('loader');
        $this->dirs[] = $dir;

        $result = $this->load(
            [Plugins::candidate('demo', $dir)],
            new MemoryLedger(),
            kinds: ['channel', 'theme'],
            interfaces: ['channel' => Plugin::class, 'theme' => Plugin::class],
        );

        $this->assertSame(['loaded' => [], 'quarantined' => [], 'refusals' => []], $result->toArray());
    }

    public function testAForeignOwnedFileIsRefusedAtTheLoaderDoor(): void
    {
        $plugin = $this->declaring('Impl');
        $ledger = new MemoryLedger();
        $ledger->prime($plugin['file']);
        $owner = (int) fileowner($plugin['file']);

        $result = PluginLoader::load(
            [Plugins::candidate('demo', $plugin['dir'])],
            new TrustList(['demo']),
            $ledger,
            ['channel'],
            ['channel' => Plugin::class],
            3,
            processUid: $owner + 1,
        );

        $this->assertSame(['foreign-owner'], array_column($result->refusals, 'reason'));
        $this->assertSame([], $result->loaded);
        $this->assertFalse(class_exists($plugin['class'], false), 'a foreign-owned file must not execute');
    }

    public function testAHostClassOfTheSameNameIsNotAdoptedByAPluginFileThatDeclaresNothing(): void
    {
        $hostClass = MemoryLedger::class;
        $built = $this->build('demo', "<?php\n\ndeclare(strict_types=1);\n\n// declares nothing\n", [
            'channel' => ['file' => 'src/Impl.php', 'class' => $hostClass],
        ]);
        $ledger = new MemoryLedger();
        $ledger->prime($built['file']);

        $result = $this->load([Plugins::candidate('demo', $built['dir'])], $ledger);

        $this->assertSame(['class-not-declared'], array_column($result->refusals, 'reason'));
        $this->assertSame([], $result->loaded);
    }

    public function testAnUntrustedNameNeverHasItsManifestRead(): void
    {
        $plugin = $this->declaring('Impl');
        $reads = 0;
        $candidate = Plugins::candidate('demo', $plugin['dir'], $reads);

        $result = $this->load([$candidate], new MemoryLedger(), trusted: ['someone-else']);

        $this->assertSame(0, $reads);
        $this->assertSame(['loaded' => [], 'quarantined' => [], 'refusals' => []], $result->toArray());
    }

    public function testAnEmptyTrustListReadsNothing(): void
    {
        $plugin = $this->declaring('Impl');
        $reads = 0;

        $this->load([Plugins::candidate('demo', $plugin['dir'], $reads)], new MemoryLedger(), trusted: []);

        $this->assertSame(0, $reads);
    }

    public function testTrustIsMatchedExactlyByName(): void
    {
        $plugin = $this->declaring('Impl');
        $reads = 0;

        $result = $this->load([Plugins::candidate('Demo', $plugin['dir'], $reads)], new MemoryLedger(), trusted: ['demo']);

        $this->assertSame(0, $reads);
        $this->assertSame([], $result->quarantined);
    }

    public function testAnEntrypointOutsideTheCandidateDirectoryIsRefusedUncontained(): void
    {
        $plugin = $this->declaring('Impl');
        $elsewhere = Scratch::dir('elsewhere');
        $this->dirs[] = $elsewhere;
        $ledger = new MemoryLedger();
        $ledger->prime($plugin['file']);
        $candidate = new PluginCandidate('demo', $elsewhere, static fn (): ?PluginManifest => PluginManifest::fromDir($plugin['dir']));

        $result = $this->load([$candidate], $ledger);

        $this->assertSame(['uncontained'], array_column($result->refusals, 'reason'));
    }

    public function testAContractAboveTheHostIsRefusedBeforeAnyQuarantine(): void
    {
        $plugin = $this->declaring('Impl', contract: 5);
        $ledger = new MemoryLedger();

        $result = $this->load([Plugins::candidate('demo', $plugin['dir'])], $ledger, host: 3);

        $this->assertSame(['contract-unsupported'], array_column($result->refusals, 'reason'));
        $this->assertSame([], $result->quarantined);
        $this->assertSame([], $ledger->recorded, 'a refused plugin must not be recorded as seen');
    }

    public function testAMalformedContractIsRefusedWithOneRowNotDropped(): void
    {
        $dir = Scratch::dir('loader');
        $this->dirs[] = $dir;
        Scratch::put($dir . '/plugin.json', (string) json_encode([
            'name' => 'demo',
            'contract' => ['sugar-plugins' => '^1.0'],
            'entrypoints' => ['channel' => ['file' => 'src/Impl.php', 'class' => 'X\\Impl']],
        ]));
        $ledger = new MemoryLedger();

        $result = $this->load([Plugins::candidate('demo', $dir)], $ledger);

        $this->assertSame([], $result->loaded);
        $this->assertSame([], $result->quarantined);
        $this->assertSame([[
            'bundle' => 'demo',
            'path' => $dir,
            'class' => '',
            'kind' => 'channel',
            'reason' => 'manifest-refused',
        ]], $result->refusals, 'a trusted plugin with a refused manifest must land in exactly one refusal row');
        $this->assertSame([], $ledger->recorded);
    }

    public function testANonObjectManifestIsRefusedWithOneRowPerRequestedKind(): void
    {
        $dir = Scratch::dir('loader');
        $this->dirs[] = $dir;
        Scratch::put($dir . '/plugin.json', '["not", "an", "object"]');

        $result = $this->load([Plugins::candidate('demo', $dir)], new MemoryLedger(), kinds: ['channel', 'theme'], interfaces: ['channel' => Plugin::class, 'theme' => Plugin::class]);

        $this->assertSame(['manifest-refused', 'manifest-refused'], array_column($result->refusals, 'reason'));
        $this->assertSame(['channel', 'theme'], array_column($result->refusals, 'kind'));
    }

    public function testAContractAtOrBelowTheHostIsAccepted(): void
    {
        $plugin = $this->declaring('Impl', contract: 3);

        $result = $this->load([Plugins::candidate('demo', $plugin['dir'])], new MemoryLedger(), host: 3);

        $this->assertCount(1, $result->quarantined);
        $this->assertSame([], $result->refusals);
    }

    public function testAWorldWritableFileIsRefused(): void
    {
        $plugin = $this->declaring('Impl');
        $ledger = new MemoryLedger();
        $ledger->prime($plugin['file']);
        chmod($plugin['file'], 0666);

        $result = $this->load([Plugins::candidate('demo', $plugin['dir'])], $ledger);

        $this->assertSame(['world-writable'], array_column($result->refusals, 'reason'));
        $this->assertFalse(class_exists($plugin['class'], false));
    }

    public function testAWorldWritableDirectoryIsRefused(): void
    {
        $plugin = $this->declaring('Impl');
        $ledger = new MemoryLedger();
        $ledger->prime($plugin['file']);
        chmod($plugin['dir'] . '/src', 0777);

        $result = $this->load([Plugins::candidate('demo', $plugin['dir'])], $ledger);

        $this->assertSame(['world-writable'], array_column($result->refusals, 'reason'));
    }

    public function testARequireFailureIsRefusedWithItsDiagnosticAsData(): void
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $built = $this->build('demo', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\nthrow new \\RuntimeException('boom');\n", [
            'channel' => ['file' => 'src/Impl.php', 'class' => $ns . '\\Impl'],
        ]);
        $ledger = new MemoryLedger();
        $ledger->prime($built['file']);

        $result = $this->load([Plugins::candidate('demo', $built['dir'])], $ledger);

        $this->assertSame(['require-failed'], array_column($result->refusals, 'reason'));
        $this->assertStringContainsString('boom', (string) $result->refusals[0]['error']);
        $this->assertStringStartsWith('plugin require', (string) $result->refusals[0]['error']);
    }

    public function testAnEntrypointWithoutAClassIsRefused(): void
    {
        $plugin = $this->declaring('Impl');
        $built = $this->build('demo', (string) file_get_contents($plugin['file']), ['channel' => 'src/Impl.php']);
        $ledger = new MemoryLedger();
        $ledger->prime($built['file']);

        $result = $this->load([Plugins::candidate('demo', $built['dir'])], $ledger);

        $this->assertSame(['class-missing'], array_column($result->refusals, 'reason'));
    }

    public function testAClassTheFileDoesNotDeclareIsRefused(): void
    {
        $plugin = $this->declaring('Impl');
        $built = $this->build('demo', (string) file_get_contents($plugin['file']), [
            'channel' => ['file' => 'src/Impl.php', 'class' => 'PluginFixtures\\Nowhere\\Missing'],
        ]);
        $ledger = new MemoryLedger();
        $ledger->prime($built['file']);

        $result = $this->load([Plugins::candidate('demo', $built['dir'])], $ledger);

        $this->assertSame(['class-not-declared'], array_column($result->refusals, 'reason'));
    }

    public function testAClassDeclaredByAnotherPluginsFileIsNotCreditedToThisOne(): void
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $fqcn = $ns . '\\Shared';
        $owner = $this->build('pa', Plugins::pluginSource($ns, 'Shared', 'pa'), [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ]);
        $squatter = $this->build('pb', "<?php\n\ndeclare(strict_types=1);\n\n// declares nothing\n", [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ]);
        $ledger = new MemoryLedger();
        $ledger->prime($owner['file']);
        $ledger->prime($squatter['file']);

        $result = $this->load([
            Plugins::candidate('pa', $owner['dir']),
            Plugins::candidate('pb', $squatter['dir']),
        ], $ledger, trusted: ['pa', 'pb']);

        $this->assertSame(['pa'], array_column($result->loaded, 'bundle'));
        $this->assertSame([['pb', 'class-not-declared']], array_map(
            static fn (array $row): array => [$row['bundle'], $row['reason']],
            $result->refusals,
        ));
    }

    public function testAFileRedeclaringAnotherPluginsClassIsRefusedBeforeItsRequire(): void
    {
        // Without the pre-require check this second require is an uncatchable
        // compile error, and the test process itself would die here.
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $fqcn = $ns . '\\Greeter';
        $owner = $this->build('pa', Plugins::pluginSource($ns, 'Greeter', 'pa'), [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ]);
        $redeclarer = $this->build('pb', Plugins::pluginSource($ns, 'Greeter', 'pb'), [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ]);
        $ledger = new MemoryLedger();
        $ledger->prime($owner['file']);
        $ledger->prime($redeclarer['file']);

        $result = $this->load([
            Plugins::candidate('pa', $owner['dir']),
            Plugins::candidate('pb', $redeclarer['dir']),
        ], $ledger, trusted: ['pa', 'pb']);

        $this->assertSame(['pa'], array_column($result->loaded, 'bundle'));
        $this->assertSame([['pb', 'class-collision']], array_map(
            static fn (array $row): array => [$row['bundle'], $row['reason']],
            $result->refusals,
        ));
        $this->assertStringContainsString($fqcn, (string) $result->refusals[0]['error']);
    }

    public function testAClassOfAnotherInterfaceLoadsBecauseTheTypeCheckBelongsToTheCaller(): void
    {
        // The loader checks that a file declares its class, not which interface
        // the class implements: candy-code's ChannelRegistry judges the type it
        // is handed. Pins the decision so an interface check cannot creep back in.
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $built = $this->build('demo', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\nfinal class Impl\n{\n}\n", [
            'channel' => ['file' => 'src/Impl.php', 'class' => $ns . '\\Impl'],
        ]);
        $ledger = new MemoryLedger();
        $ledger->prime($built['file']);

        $result = $this->load([Plugins::candidate('demo', $built['dir'])], $ledger);

        $this->assertSame([], $result->refusals);
        $this->assertSame([$ns . '\\Impl'], array_column($result->loaded, 'class'));
    }

    public function testAGroupWritableEntrypointLoadsBecauseOnlyWorldWriteIsRefused(): void
    {
        // Regression: the gate must match candy-code's refuseLooseBytes, which
        // refuses world write only. A 0664 file in a 0755 tree loads.
        $plugin = $this->declaring('Impl');
        $ledger = new MemoryLedger();
        $ledger->prime($plugin['file']);
        chmod($plugin['file'], 0664);

        $result = $this->load([Plugins::candidate('demo', $plugin['dir'])], $ledger);

        $this->assertSame([], $result->refusals);
        $this->assertCount(1, $result->loaded);
        $this->assertTrue(class_exists($plugin['class'], false));
    }

    public function testTwoTrustedNamesReachingOneFileThroughASymlinkYieldOneLoadedRowEachAndDeclareTheClassOnce(): void
    {
        // Pins the documented decision on load(): one loaded row per trusted name,
        // and the file is required once, so its class is declared a single time.
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $fqcn = $ns . '\\Alias';
        $alpha = $this->build('alpha', Plugins::pluginSource($ns, 'Alias', 'alpha'), [
            'channel' => ['file' => 'src/Impl.php', 'class' => $fqcn],
        ]);
        $link = $alpha['dir'] . '-alias';
        $this->dirs[] = $link;
        symlink($alpha['dir'], $link);

        $ledger = new MemoryLedger();
        $ledger->prime($alpha['file']);

        $result = $this->load([
            Plugins::candidate('alpha', $alpha['dir']),
            Plugins::candidate('beta', $link),
        ], $ledger, trusted: ['alpha', 'beta']);

        $this->assertSame(['alpha', 'beta'], array_column($result->loaded, 'bundle'));
        $this->assertSame([], $result->refusals);
        $this->assertSame([$fqcn, $fqcn], array_column($result->loaded, 'class'));
        $this->assertTrue(class_exists($fqcn, false));
    }

    public function testALedgerThatRefusesToPersistDoesNotAbortTheLaunch(): void
    {
        $plugin = $this->declaring('Impl');
        $ledger = new class () implements QuarantineLedger {
            public function seen(string $path): bool
            {
                return false;
            }

            public function record(string $path): void
            {
                throw new \RuntimeException('disk full');
            }
        };

        $result = PluginLoader::load(
            [Plugins::candidate('demo', $plugin['dir'])],
            new TrustList(['demo']),
            $ledger,
            ['channel'],
            ['channel' => Plugin::class],
            3,
        );

        $this->assertCount(1, $result->quarantined, 'the mark still holds for this launch, so the file is listed');
        $this->assertSame([], $result->loaded);
        $this->assertSame([], $result->refusals);
        $this->assertFalse(class_exists($plugin['class'], false), 'a file the ledger could not record must not run');
    }

    public function testAKindWithNoMappedInterfaceIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->load([], new MemoryLedger(), kinds: ['channel'], interfaces: []);
    }

    public function testTheResultConvertsToTheArrayShapeTheChannelLoaderExposes(): void
    {
        $result = new LoadResult(
            loaded: [['bundle' => 'b', 'path' => '/p', 'class' => 'C', 'kind' => 'channel']],
            quarantined: [],
            refusals: [['bundle' => 'b', 'path' => '/q', 'class' => '', 'kind' => 'channel', 'reason' => 'class-missing']],
        );

        $this->assertSame(['loaded', 'quarantined', 'refusals'], array_keys($result->toArray()));
        $this->assertSame($result->loaded, $result->toArray()['loaded']);
        $this->assertSame($result->refusals, $result->toArray()['refusals']);
    }
}
