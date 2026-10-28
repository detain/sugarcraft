<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Bundles\MarketplaceRegistry;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-EXT-4.b: `bundles update` — the provenance-driven refresh. Every pin here
 * is about the SWAP LAW: the old tree moves aside first, the new one lands,
 * the record re-writes, and only then does the no-follow walk remove the
 * aside — an update never leaves a bundle absent, never follows a link out
 * of the tree it is discarding, and never re-keys or invents a source for
 * what `install` did not record.
 */
final class BundleUpdateTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private const SHA_PIN = '1111111111222222222233333333334444444444';

    private string $tempDir;

    private string $home;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-update-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
        $this->root = $this->home . '/.candy-code/bundles';
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Subcommands::class, 'bundlesInstallSpawn'))->setValue(null, null);
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testUpdateRefreshesARecordedBundleAndLeavesNoSwapResidue(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));
        self::assertSame('v1 for alpha', file_get_contents($this->root . '/alpha/UPSTREAM.txt'));
        file_put_contents($this->root . '/alpha/LOCAL-NOTE.txt', 'mine');

        file_put_contents($source . '/plugins/alpha/UPSTREAM.txt', 'v2 for alpha');
        file_put_contents($source . '/plugins/alpha/ARRIVED.txt', 'new upstream file');

        [$exit, $out] = $this->verbCapture('update');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('Updated bundle alpha from its marketplace record', $out);
        self::assertStringContainsString('v2 for alpha', (string) file_get_contents($this->root . '/alpha/UPSTREAM.txt'));
        self::assertFileExists($this->root . '/alpha/ARRIVED.txt');
        self::assertFileDoesNotExist($this->root . '/alpha/LOCAL-NOTE.txt', 'the swap replaces the tree; local droppings are the OLD tree and leave with it');

        $record = json_decode((string) file_get_contents($this->root . '/alpha/.candy-code-source.json'), true);
        self::assertSame(self::SHA_PIN, $record['sha']);

        self::assertSame(['alpha'], $this->entriesUnderRoot(), 'no .install-/.swap- scratch leaked into the bundle root');
    }

    public function testUpdateOfARecordlessBundleSkipsQuietlyWhenScannedAndFailsWhenNamed(): void
    {
        $source = $this->stagedPlainBundle();
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source));

        [$exit, $out] = $this->verbCapture('update');
        self::assertSame(NonInteractive::EXIT_OK, $exit, 'a scanned root with unrecorded bundles answers OK — update only refuses what it was pointed at');
        self::assertSame(['plain'], $this->entriesUnderRoot());

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('update', 'plain'));
        self::assertFileExists($this->root . '/plain/.claude-plugin/plugin.json');
    }

    public function testUpdateStopsWhenTheRecordedMarketplaceIsGoneAndLeavesTheBundleAsIs(): void
    {
        $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));
        file_put_contents($this->root . '/alpha/MINE.txt', 'kept');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'remove', 'demo'));

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('update', 'alpha'));
        self::assertSame('kept', file_get_contents($this->root . '/alpha/MINE.txt'));
        self::assertSame(['alpha'], $this->entriesUnderRoot());
    }

    public function testScanUpdateSkipsABundleWhoseMarketplaceIsGoneAndExitsOk(): void
    {
        $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));
        file_put_contents($this->root . '/alpha/MINE.txt', 'kept');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'remove', 'demo'));

        [$exit, $out] = $this->verbCapture('update');
        self::assertSame(NonInteractive::EXIT_OK, $exit, 'the scan tolerates a gone marketplace: it reports and skips, only the named form refuses');
        self::assertNull(MarketplaceRegistry::loadDefault()->marketplace('demo'));
        self::assertStringNotContainsString('Updated bundle', $out);
        self::assertSame(
            'bundle alpha records marketplace demo, which is not added; the bundle was left exactly as it is — re-add the marketplace to update it',
            Lang::t('cli.bundles.update.marketplace_gone', ['name' => 'alpha', 'marketplace' => 'demo']),
            'the exact skip notice the scan writes to the verb stderr (bundlesLine bytes ride no transcript buffer — family law)',
        );
        self::assertSame('kept', file_get_contents($this->root . '/alpha/MINE.txt'));
        self::assertSame(['alpha'], $this->entriesUnderRoot());
    }

    public function testUpdateRefusesWhenTheEntryLeftTheCatalogAndKeepsTheTree(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));
        file_put_contents($this->root . '/alpha/MINE.txt', 'kept');

        file_put_contents(
            $source . '/.claude-plugin/marketplace.json',
            json_encode(['name' => 'demo', 'owner' => 'op', 'plugins' => []]),
        );

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('update', 'alpha'));
        self::assertSame('kept', file_get_contents($this->root . '/alpha/MINE.txt'), 'the gate sentence fired, but the live tree moved anyway');
        self::assertSame(['alpha'], $this->entriesUnderRoot());
    }

    public function testScanUpdateRefusesAnEntryThatLeftTheCatalogAndKeepsTheTree(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));
        file_put_contents($this->root . '/alpha/MINE.txt', 'kept');

        file_put_contents(
            $source . '/.claude-plugin/marketplace.json',
            json_encode(['name' => 'demo', 'owner' => 'op', 'plugins' => []]),
        );

        [$exit, $out] = $this->verbCapture('update');
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit, 'a scan is no pardon: the catalog gate refuses by entry, and the scan carries that refusal to the exit code');
        self::assertStringNotContainsString('Updated bundle', $out);
        $registry = MarketplaceRegistry::loadDefault();
        $marketplace = $registry->marketplace('demo');
        self::assertNotNull($marketplace, 'the row is still added — it is the ENTRY that left the catalog');
        self::assertSame(
            'marketplace "demo" offers no plugin named "alpha"',
            $registry->installGate($marketplace, 'alpha'),
            'the typed refusal the scan prints to the verb stderr (bundlesLine bytes ride no transcript buffer — family law)',
        );
        self::assertSame('kept', file_get_contents($this->root . '/alpha/MINE.txt'), 'the gate sentence fired, but the live tree moved anyway');
        self::assertSame(['alpha'], $this->entriesUnderRoot());
    }

    public function testUpdateRefusesAnUpstreamRenameWithoutReKeyingTheInstall(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));
        file_put_contents($this->root . '/alpha/MINE.txt', 'kept');

        file_put_contents(
            $source . '/plugins/alpha/.claude-plugin/plugin.json',
            json_encode(['name' => 'renamed-upstream', 'version' => '2.0.0']),
        );

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('update', 'alpha'));
        self::assertSame('kept', file_get_contents($this->root . '/alpha/MINE.txt'));
        self::assertSame(['alpha'], $this->entriesUnderRoot(), 'the refused rename must not land a second directory under the new name');
    }

    public function testTheSwapRemovesTheOldTreeWithoutFollowingItsLinks(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));

        $outside = $this->tempDir . '/outside-sentinel';
        mkdir($outside . '/nested', 0777, true);
        file_put_contents($outside . '/canary.txt', 'do not touch');
        file_put_contents($outside . '/nested/keep.txt', 'still here');
        symlink($outside, $this->root . '/alpha/escape');

        file_put_contents($source . '/plugins/alpha/UPSTREAM.txt', 'v3 for alpha');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('update', 'alpha'));
        self::assertStringContainsString('v3', (string) file_get_contents($this->root . '/alpha/UPSTREAM.txt'));
        self::assertSame('do not touch', @file_get_contents($outside . '/canary.txt'), 'the swap cleanup followed the old tree out through a link');
        self::assertSame('still here', @file_get_contents($outside . '/nested/keep.txt'));
        self::assertFalse(is_link($this->root . '/alpha/escape'), 'the link rode the aside into the cleanup, not the new tree');
        self::assertSame(['alpha'], $this->entriesUnderRoot());
    }

    public function testUpdateAnswersNothingBeforeTheFirstBundleRootExists(): void
    {
        [$exit, $out] = $this->verbCapture('update');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('No bundle root yet', $out);
    }

    public function testUpdateOfAnUnknownNameIsRefusedWithoutTouchingAnything(): void
    {
        $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));

        [$exit, $out] = $this->verbCapture('update', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $exit, 'a recorded bundle up to date still answers OK');

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('update', 'never-installed'));
        self::assertSame(['alpha'], $this->entriesUnderRoot());
    }

    /**
     * Add a path-typed marketplace carrying one installable plugin named
     * $plugin under ./plugins/$plugin, pinned at {@see self::SHA_PIN}.
     */
    private function addPathMarketplace(string $name, string $plugin): string
    {
        $source = $this->tempDir . '/' . $name . '-src';
        mkdir($source . '/.claude-plugin', 0777, true);
        mkdir($source . '/plugins/' . $plugin . '/.claude-plugin', 0777, true);
        file_put_contents(
            $source . '/plugins/' . $plugin . '/.claude-plugin/plugin.json',
            json_encode(['name' => $plugin, 'version' => '1.0.0']),
        );
        file_put_contents($source . '/plugins/' . $plugin . '/UPSTREAM.txt', 'v1 for ' . $plugin);
        file_put_contents(
            $source . '/.claude-plugin/marketplace.json',
            json_encode(['name' => $name, 'owner' => ['name' => 'op'], 'plugins' => [
                ['name' => $plugin, 'source' => './plugins/' . $plugin],
            ]]),
        );

        mkdir($source . '/.git/refs/heads', 0777, true);
        file_put_contents($source . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($source . '/.git/refs/heads/main', self::SHA_PIN . "\n");

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', $source));

        return $source;
    }

    /** A plain directory installable through the OLD grammar: no record. */
    private function stagedPlainBundle(): string
    {
        $dir = $this->tempDir . '/plain-src';
        mkdir($dir . '/.claude-plugin', 0777, true);
        file_put_contents($dir . '/.claude-plugin/plugin.json', json_encode(['name' => 'plain', 'version' => '1.0.0']));

        return $dir;
    }

    private function verb(string ...$operands): int
    {
        $output = ob_start();

        try {
            return Subcommands::dispatch(ArgvParser::parse(array_merge(['candy-code', 'bundles'], $operands)));
        } finally {
            if ($output !== false) {
                ob_end_clean();
            }
        }
    }

    /**
     * @return array{0: int, 1: string} exit code and captured stdout
     */
    private function verbCapture(string ...$operands): array
    {
        ob_start();

        try {
            $exit = Subcommands::dispatch(ArgvParser::parse(array_merge(['candy-code', 'bundles'], $operands)));
        } finally {
            $captured = (string) ob_get_clean();
        }

        return [$exit, $captured];
    }

    /** @return list<string> names directly under the bundle root */
    private function entriesUnderRoot(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $names = [];
        foreach ((array) scandir($this->root) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }
}
