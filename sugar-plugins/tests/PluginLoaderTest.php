<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Loader\LoadResult;
use SugarCraft\Plugins\Loader\PluginCandidate;
use SugarCraft\Plugins\Loader\PluginLoader;
use SugarCraft\Plugins\Manifest\PluginManifest;
use SugarCraft\Plugins\Plugin;
use SugarCraft\Plugins\Tests\Support\MemoryLedger;
use SugarCraft\Plugins\Tests\Support\Plugins;
use SugarCraft\Plugins\Tests\Support\Scratch;
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

        $second = $this->load([$candidate], $ledger);

        $this->assertCount(1, $second->loaded);
        $this->assertSame([], $second->quarantined);
        $this->assertSame($plugin['class'], $second->loaded[0]['class']);
        $this->assertTrue(class_exists($plugin['class'], false));
    }

    public function testOnePathIsLoadedOnceAcrossKinds(): void
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

        $this->assertCount(1, $result->loaded);
        $this->assertSame('channel', $result->loaded[0]['kind']);
        $this->assertSame([], $result->refusals);
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

    public function testAClassOfTheWrongInterfaceIsRefusedAsInterfaceMismatch(): void
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $built = $this->build('demo', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\nfinal class Impl\n{\n}\n", [
            'channel' => ['file' => 'src/Impl.php', 'class' => $ns . '\\Impl'],
        ]);
        $ledger = new MemoryLedger();
        $ledger->prime($built['file']);

        $result = $this->load([Plugins::candidate('demo', $built['dir'])], $ledger);

        $this->assertSame(['interface-mismatch'], array_column($result->refusals, 'reason'));
        $this->assertSame([], $result->loaded);
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
