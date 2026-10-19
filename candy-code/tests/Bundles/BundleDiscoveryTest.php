<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Bundles\BundleDiscovery;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Tests for BundleDiscovery — the three install roots (project, user, the
 * pluginPaths setting), the quiet-skip/broken-warn split, and the per-tier
 * confinement promise for symlinked bundle directories.
 */
final class BundleDiscoveryTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-discovery-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        // Discovery reads the user tier through HomeDirectory::owned(), so the
        // sandbox must be up before EVERY discover() — otherwise a suite run on
        // a machine that has real ~/.candy-code/bundles invents fixtures.
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $manifest */
    private function writeBundleRoot(string $dir, array $manifest = ['name' => 'x']): void
    {
        mkdir($dir . '/.claude-plugin', 0777, true);
        file_put_contents($dir . '/.claude-plugin/plugin.json', json_encode($manifest));
    }

    public function testNothingInstalledDiscoversNothing(): void
    {
        $discovery = new BundleDiscovery($this->tempDir . '/project');

        self::assertSame([], $discovery->discover());
        self::assertSame([], $discovery->warnings());
    }

    public function testProjectAndUserTiersAreBothFoundAndStamped(): void
    {
        $project = $this->tempDir . '/project';
        $this->writeBundleRoot($project . '/' . Bundle::INSTALLED_RELATIVE_PATH . '/repo-bundle', ['name' => 'repo-bundle']);
        $this->writeBundleRoot($this->home . '/' . Bundle::INSTALLED_RELATIVE_PATH . '/mine', ['name' => 'mine']);

        $bundles = (new BundleDiscovery($project))->discover();

        $byName = [];

        foreach ($bundles as $bundle) {
            $byName[$bundle->name] = $bundle;
        }

        self::assertSame('project', $byName['repo-bundle']->tier);
        self::assertSame('user', $byName['mine']->tier);
    }

    public function testAPluginPathMayBeABundleOrAContainer(): void
    {
        $lone = $this->tempDir . '/dev/lone-plugin';
        $this->writeBundleRoot($lone, ['name' => 'lone']);

        $container = $this->tempDir . '/dev/bundle-hoard';
        $this->writeBundleRoot($container . '/alpha', ['name' => 'alpha']);
        $this->writeBundleRoot($container . '/beta', ['name' => 'beta']);
        mkdir($container . '/not-a-bundle', 0777, true);

        $bundles = (new BundleDiscovery($this->tempDir . '/project', [$lone, $container]))->discover();

        $names = array_map(static fn (Bundle $b): string => $b->name, $bundles);
        sort($names);

        self::assertSame(['alpha', 'beta', 'lone'], $names, 'a container directory without a manifest is passed over quietly');
    }

    public function testABrokenManifestIsWarnedByNameWhileItsNeighboursLoad(): void
    {
        $root = $this->home . '/' . Bundle::INSTALLED_RELATIVE_PATH;
        mkdir($root . '/broken/.claude-plugin', 0777, true);
        file_put_contents($root . '/broken/.claude-plugin/plugin.json', 'not json at all');
        $this->writeBundleRoot($root . '/healthy', ['name' => 'healthy']);

        $discovery = new BundleDiscovery($this->tempDir . '/project');
        $bundles = $discovery->discover();

        self::assertSame(['healthy'], array_map(static fn (Bundle $b): string => $b->name, $bundles));
        self::assertCount(1, $discovery->warnings());
        self::assertStringContainsString('broken', $discovery->warnings()[0]);
    }

    public function testAProjectBundleThatEscapesTheCheckoutIsRefused(): void
    {
        $project = $this->tempDir . '/checkout';
        $outside = $this->tempDir . '/elsewhere/real-bundle';
        $this->writeBundleRoot($outside, ['name' => 'escaped']);
        mkdir($project . '/' . Bundle::INSTALLED_RELATIVE_PATH, 0777, true);
        symlink($outside, $project . '/' . Bundle::INSTALLED_RELATIVE_PATH . '/looks-local');

        $discovery = new BundleDiscovery($project);
        $bundles = $discovery->discover();

        self::assertSame([], $bundles, 'a committed symlink may not import a tree from outside the clone');
        $refused = $discovery->refusedDirectories();
        $link = $project . '/' . Bundle::INSTALLED_RELATIVE_PATH . '/looks-local';
        self::assertSame([$link], array_keys($refused), 'keyed by the directory as given, not the escape');
        self::assertStringContainsString('outside the project tree', $refused[$link]);
        self::assertStringContainsString($outside, $refused[$link], 'the reason names where it really resolves');
    }

    public function testAUserBundleMayReachAnywhereInsideHome(): void
    {
        $target = $this->home . '/src/plugins/real-deal';
        $this->writeBundleRoot($target, ['name' => 'real-deal']);
        mkdir($this->home . '/' . Bundle::INSTALLED_RELATIVE_PATH, 0777, true);
        symlink($target, $this->home . '/' . Bundle::INSTALLED_RELATIVE_PATH . '/linked');

        $bundles = (new BundleDiscovery($this->tempDir . '/project'))->discover();

        self::assertSame(['real-deal'], array_map(static fn (Bundle $b): string => $b->name, $bundles));
    }

    public function testContainerChildrenAreWalkedInSortedOrder(): void
    {
        $container = $this->tempDir . '/hoard';

        foreach (['zeta', 'mid', 'alpha'] as $leaf) {
            $this->writeBundleRoot($container . '/' . $leaf, ['name' => $leaf]);
        }

        $bundles = (new BundleDiscovery($this->tempDir . '/project', [$container]))->discover();

        self::assertSame(['alpha', 'mid', 'zeta'], array_map(static fn (Bundle $b): string => $b->name, $bundles));
    }

    public function testDiscoveringTwiceReportsTheSameOnce(): void
    {
        $root = $this->home . '/' . Bundle::INSTALLED_RELATIVE_PATH;
        mkdir($root . '/broken/.claude-plugin', 0777, true);
        file_put_contents($root . '/broken/.claude-plugin/plugin.json', '{');
        $this->writeBundleRoot($root . '/ok', ['name' => 'ok']);

        $discovery = new BundleDiscovery($this->tempDir . '/project');
        $first = array_map(static fn (Bundle $b): string => $b->dir, $discovery->discover());
        $second = array_map(static fn (Bundle $b): string => $b->dir, $discovery->discover());

        self::assertSame($first, $second);
        self::assertCount(1, $discovery->warnings(), 'a re-discovery replaces rather than stacks its warnings');
    }

    public function testAMissingPluginPathWarnsInsteadOfThrowing(): void
    {
        $discovery = new BundleDiscovery($this->tempDir . '/project', [$this->tempDir . '/no-such-thing']);

        self::assertSame([], $discovery->discover());
        self::assertCount(1, $discovery->warnings());
        self::assertStringContainsString('no-such-thing', $discovery->warnings()[0]);
    }

    public function testHomeWithoutAnOwnerContributesNoUserTier(): void
    {
        $this->writeBundleRoot($this->home . '/' . Bundle::INSTALLED_RELATIVE_PATH . '/ghost', ['name' => 'ghost']);
        // A HOME pointing at a bare relative is not an owned directory; the
        // user tier must fall silent rather than resolve through CWD.
        $this->useHomeSandbox('.', create: false);

        $bundles = (new BundleDiscovery($this->tempDir . '/project'))->discover();

        self::assertSame([], $bundles);
    }
}
