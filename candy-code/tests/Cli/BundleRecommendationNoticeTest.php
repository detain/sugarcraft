<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Marketplace;
use SugarCraft\Code\Bundles\MarketplaceRegistry;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\SlicesDeclaredMethodsTrait;

/**
 * C-EXT-4.d: the launch drain that names the marketplace bundles fitting the
 * opened project.
 *
 * The rows this pin family exists to keep honest are the four limits of the
 * design block: SILENT when no marketplace has been added (the pinned-empty
 * catalog, not a warning about it); ONE aggregate row whatever the match
 * count; ONCE per project per marketplace pin, via a stamp written into the
 * registry's own `marketplaces.json` under a top-level key the registry's
 * loader ignores and its writer drops on the next mutation — which is exactly
 * when a re-show is owed; and NEVER NETWORK, which the drain keeps by only
 * ever walking {@see \SugarCraft\Code\Bundles\BundleRecommender}'s fixed
 * signal list over one scandir, local catalog reads, and the install root.
 *
 * Every fixture feeds the seam parameters directly — registry, stamp file and
 * install root — so no test touches the real home, and the notice shelf is
 * cleared between drains the way the sibling notice tests clear it, which is
 * also what makes the once-gate decidable: a re-shown row and a deduped row
 * are the same shelf content, so the gate itself is pinned by clearing the
 * shelf and asking again.
 */
final class BundleRecommendationNoticeTest extends TestCase
{
    use SlicesDeclaredMethodsTrait;
    use TemporaryDirectoryTrait;

    private string $tempDir;

    private string $stampFile;

    private string $checkouts;

    private string $installedRoot;

    private string $projectRoot;

    private const SHA_A = '0123456789abcdef0123456789abcdef01234567';

    private const SHA_B = 'fedcba9876543210fedcba9876543210fedcba98';

    protected function setUp(): void
    {
        $this->resetNoticeStatics();
        $this->tempDir = sys_get_temp_dir() . '/cc-cext4d-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0700, true);
        $this->stampFile = $this->tempDir . '/marketplaces.json';
        $this->checkouts = $this->tempDir . '/marketplaces';
        $this->installedRoot = $this->tempDir . '/bundles';
        mkdir($this->installedRoot, 0700, true);
        $this->projectRoot = $this->tempDir . '/proj';
        mkdir($this->projectRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->resetNoticeStatics();
        $this->removeDirectory($this->tempDir);
    }

    public function testSilentWhenNoMarketplaceHasBeenAdded(): void
    {
        // The pinned-empty catalog answers before anything is walked: no row,
        // and no stamp file minted by a drain that showed nothing.
        $registry = MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);
        $this->withPhpSignal();

        Bootstrap::reportBundleRecommendations($this->projectRoot, $registry, $this->stampFile, $this->installedRoot);

        $this->assertSame([], Bootstrap::launchNotices(), 'an empty catalog is silent at launch, not a warning');
        $this->assertFileDoesNotExist($this->stampFile, 'nothing shown means nothing stamped');
    }

    public function testSilentWhenTheProjectCarriesNoSignalTheCatalogAnswers(): void
    {
        // A marketplace IS added, but the (empty) project root answers nothing
        // against the fixed signal list — the walk stops at its own gate.
        $this->addMarketplace('acme', self::SHA_A, [$this->entry('php-tools', ['php', 'composer'])]);
        $registry = MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);

        Bootstrap::reportBundleRecommendations($this->projectRoot, $registry, $this->stampFile, $this->installedRoot);

        $this->assertSame([], Bootstrap::launchNotices());
    }

    public function testOneAggregateRowNamesTheMatchAtItsMarketplace(): void
    {
        $this->addMarketplace('acme', self::SHA_A, [$this->entry('php-tools', ['php', 'composer'])]);
        $this->withPhpSignal();
        $registry = MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);

        Bootstrap::reportBundleRecommendations($this->projectRoot, $registry, $this->stampFile, $this->installedRoot);

        $this->assertSame(
            [Lang::t('bundles.recommend.launch', ['names' => 'php-tools@acme'])],
            Bootstrap::launchNotices(),
            'exactly one row, in the name@marketplace vocabulary the install verb takes',
        );
        $this->assertStringContainsString('candy-code bundles install', Bootstrap::launchNotices()[0]);
    }

    public function testEveryMatchIsNamedInsideTheOneRowAndTheCapStillBoundsIt(): void
    {
        // Four matches, MAX_RECOMMENDATIONS is three, the ROW count is one:
        // the aggregate row is the cap, not a per-entry fan-out.
        $this->addMarketplace('acme', self::SHA_A, [
            $this->entry('php-alpha', ['php']),
            $this->entry('php-beta', ['php']),
            $this->entry('php-gamma', ['php']),
            $this->entry('php-delta', ['php']),
        ]);
        $this->withPhpSignal();
        $registry = MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);

        Bootstrap::reportBundleRecommendations($this->projectRoot, $registry, $this->stampFile, $this->installedRoot);

        $notices = Bootstrap::launchNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('php-alpha@acme, php-beta@acme, php-delta@acme', $notices[0]);
        $this->assertStringNotContainsString('php-gamma', $notices[0], 'the recommender cap is the row content cap');
    }

    public function testInstalledNamesAreExcludedAndNothingIsStampedForSilence(): void
    {
        mkdir($this->installedRoot . '/php-tools', 0700, true);
        $this->addMarketplace('acme', self::SHA_A, [$this->entry('php-tools', ['php', 'composer'])]);
        $this->withPhpSignal();
        $registry = MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);

        Bootstrap::reportBundleRecommendations($this->projectRoot, $registry, $this->stampFile, $this->installedRoot);

        $this->assertSame([], Bootstrap::launchNotices(), 'an installed bundle is not a recommendation');
        $document = json_decode((string) file_get_contents($this->stampFile), true);
        $this->assertIsArray($document);
        $this->assertArrayNotHasKey('launchRecommendations', $document, 'a silent drain stamps nothing');
    }

    public function testTheRowShowsOncePerProjectPerPinAndARepinedMarketplaceShowsAgain(): void
    {
        $this->addMarketplace('acme', self::SHA_A, [$this->entry('php-tools', ['php', 'composer'])]);
        $this->withPhpSignal();

        Bootstrap::reportBundleRecommendations(
            $this->projectRoot,
            MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts),
            $this->stampFile,
            $this->installedRoot,
        );
        $this->assertCount(1, Bootstrap::launchNotices());
        $this->assertSame(
            [$this->projectRoot => ['acme' => self::SHA_A]],
            $this->stamps(),
            'the pin shown is the pin stamped, under the project root',
        );

        // Clear the shelf and ask again: silence can only come from the stamp,
        // because a deduped re-show would read identically on the shelf.
        $this->resetNoticeStatics();
        Bootstrap::reportBundleRecommendations(
            $this->projectRoot,
            MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts),
            $this->stampFile,
            $this->installedRoot,
        );
        $this->assertSame([], Bootstrap::launchNotices(), 'shown once at this pin: the stamp is the gate');

        // Re-pin: the row promised the old commit, so the next launch owes it again.
        $reloaded = MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);
        $this->assertNull($reloaded->repin('acme', self::SHA_B));
        $this->resetNoticeStatics();
        Bootstrap::reportBundleRecommendations(
            $this->projectRoot,
            MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts),
            $this->stampFile,
            $this->installedRoot,
        );
        $this->assertCount(1, Bootstrap::launchNotices(), 'a moved pin re-opens the once-gate');
        $this->assertSame([$this->projectRoot => ['acme' => self::SHA_B]], $this->stamps());
    }

    public function testARegistryMutationDropsTheStampAndTheNextLaunchReshowsWidened(): void
    {
        $this->addMarketplace('acme', self::SHA_A, [$this->entry('php-alpha', ['php'])]);
        $this->withPhpSignal();

        Bootstrap::reportBundleRecommendations(
            $this->projectRoot,
            MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts),
            $this->stampFile,
            $this->installedRoot,
        );
        $this->assertCount(1, Bootstrap::launchNotices());

        // A second marketplace joins: the registry's own write drops the stamp
        // (documented consequence), and the next launch shows the aggregate of
        // BOTH — first offering wins, registration order, same as the verb.
        $widened = MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);
        $this->addMarketplace('hub', self::SHA_B, [$this->entry('php-beta', ['php'])], existing: $widened);
        $this->resetNoticeStatics();
        Bootstrap::reportBundleRecommendations(
            $this->projectRoot,
            MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts),
            $this->stampFile,
            $this->installedRoot,
        );
        $this->assertSame(
            [Lang::t('bundles.recommend.launch', ['names' => 'php-alpha@acme, php-beta@hub'])],
            Bootstrap::launchNotices(),
        );
        $this->assertSame(
            [$this->projectRoot => ['acme' => self::SHA_A, 'hub' => self::SHA_B]],
            $this->stamps(),
        );

        $this->resetNoticeStatics();
        Bootstrap::reportBundleRecommendations(
            $this->projectRoot,
            MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts),
            $this->stampFile,
            $this->installedRoot,
        );
        $this->assertSame([], Bootstrap::launchNotices(), 'both pins shown: the project rests until a pin moves');
    }

    public function testTheStampRidesTheRegistryEnvelopeWithoutDisturbingIt(): void
    {
        $this->addMarketplace('acme', self::SHA_A, [$this->entry('php-tools', ['php'])]);
        $this->withPhpSignal();

        Bootstrap::reportBundleRecommendations(
            $this->projectRoot,
            MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts),
            $this->stampFile,
            $this->installedRoot,
        );

        $document = json_decode((string) file_get_contents($this->stampFile), true);
        $this->assertSame(1, $document['version'], 'the envelope the registry itself writes stays intact');
        $this->assertCount(1, $document['marketplaces']);
        $this->assertSame('acme', $document['marketplaces'][0]['name']);
        $this->assertSame(self::SHA_A, $document['marketplaces'][0]['sha']);
        $this->assertArrayHasKey('launchRecommendations', $document);
        // The registry reads the stamped file back as if the key were not there.
        $this->assertSame(['acme'], MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts)->names());
        $this->assertSame([], MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts)->loadErrors());
    }

    public function testAnAbsentStampFileStillShowsTheRowAndIsNeverMintedByTheDrain(): void
    {
        // Rows exist but the drain is pointed at a stamp path that is not the
        // registry file: fail-open — the notice rides, no file is written.
        $marketplaceDir = $this->marketplaceDir('acme', [$this->entry('php-tools', ['php'])]);
        $registry = MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);
        $this->assertNull($registry->add('acme', 'path', $marketplaceDir, self::SHA_A));
        $this->withPhpSignal();
        $other = $this->tempDir . '/not-yet.json';

        Bootstrap::reportBundleRecommendations($this->projectRoot, $registry, $other, $this->installedRoot);

        $this->assertCount(1, Bootstrap::launchNotices());
        $this->assertFileDoesNotExist($other);
    }

    public function testAnUnreadableStampFileFailsOpenToTheRowAndIsLeftUntouched(): void
    {
        $this->addMarketplace('acme', self::SHA_A, [$this->entry('php-tools', ['php'])]);
        $this->withPhpSignal();
        $corrupt = $this->tempDir . '/corrupt-stamp.json';
        file_put_contents($corrupt, '{this is not json');

        Bootstrap::reportBundleRecommendations(
            $this->projectRoot,
            MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts),
            $corrupt,
            $this->installedRoot,
        );

        $this->assertCount(1, Bootstrap::launchNotices(), 'a stamp that cannot be read must not swallow the row');
        $this->assertSame('{this is not json', file_get_contents($corrupt), 'a broken stamp file is not rewritten');
    }

    public function testTheDrainRunsFromTheBootBuildExactlyOnce(): void
    {
        $bootstrapFile = \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php';
        $lines = file($bootstrapFile);
        $method = new \ReflectionMethod(Bootstrap::class, 'buildChat');
        $this->assertSame(
            $bootstrapFile,
            $method->getFileName(),
            'buildChat() is no longer declared in ' . $bootstrapFile
                . '; read the body from $method->getFileName() like the sibling drain pins do.',
        );
        $slice = self::declaredSlice($lines, 'buildChat', $method->getStartLine(), $method->getEndLine());

        $this->assertSame(
            1,
            substr_count($slice, 'self::reportBundleRecommendations($root);'),
            'the boot build must drain the recommendation row exactly once, with the workspace root',
        );
        $this->assertLessThan(
            strpos($slice, 'self::reportBundleRecommendations($root);'),
            strpos($slice, 'self::reportTerminalGuidance();'),
            'the drain sits beside its siblings, after the terminal guidance row',
        );
    }

    /**
     * The project's own composer.json is the PHP signal the fixed list
     * answers; one file, no walks beyond the root itself.
     */
    private function withPhpSignal(): void
    {
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{}}');
    }

    /**
     * @param array<int, array<string, mixed>> $plugins
     */
    private function addMarketplace(string $name, string $sha, array $plugins, ?MarketplaceRegistry $existing = null): void
    {
        $dir = $this->marketplaceDir($name, $plugins);
        $registry = $existing ?? MarketplaceRegistry::atPaths($this->stampFile, $this->checkouts);
        $this->assertNull($registry->add($name, 'path', $dir, $sha));
    }

    /**
     * A checkout directory as `marketplace add` leaves a PATH row's checkout:
     * the catalog manifest under the plugin-relative path, nothing else.
     *
     * @param array<int, array<string, mixed>> $plugins
     */
    private function marketplaceDir(string $name, array $plugins): string
    {
        $dir = $this->tempDir . '/src-' . $name;
        mkdir($dir . '/' . \dirname(Marketplace::MARKETPLACE_RELATIVE_PATH), 0700, true);
        file_put_contents(
            $dir . '/' . Marketplace::MARKETPLACE_RELATIVE_PATH,
            (string) json_encode(['name' => $name, 'plugins' => $plugins]),
        );

        return $dir;
    }

    /**
     * One catalog row: a github-sourced entry, so nothing in the fixture ever
     * resolves outside the temp tree, and keywords as the real manifests spell them.
     *
     * @param list<string> $keywords
     *
     * @return array<string, mixed>
     */
    private function entry(string $name, array $keywords): array
    {
        return [
            'name' => $name,
            'source' => ['source' => 'github', 'repo' => 'acme/' . $name],
            'description' => 'the ' . $name . ' bundle',
            'keywords' => $keywords,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function stamps(): array
    {
        $document = json_decode((string) file_get_contents($this->stampFile), true);
        $this->assertIsArray($document);

        return $document['launchRecommendations'] ?? [];
    }

    private function resetNoticeStatics(): void
    {
        foreach ([
            'launchNotices' => [],
            'launchNoticesDropped' => [],
            'reportedPermissionConfigWarnings' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(LaunchNotices::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
