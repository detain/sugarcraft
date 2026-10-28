<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Marketplace;
use SugarCraft\Code\Bundles\MarketplaceRegistry;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * C-EXT-4.a: the user-tier marketplace store and the install gate. The floor
 * these pins exist for is refuse-by-default: the catalog is empty until a
 * marketplace is explicitly added, registration requires a pinned commit,
 * and {@see MarketplaceRegistry::installGate()} — not the manifest bytes —
 * is what decides whether anything may install.
 */
final class MarketplaceRegistryTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir;

    private string $file;

    private string $checkouts;

    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/cc-cext4a-registry-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0700, true);
        $this->file = $this->tempDir . '/marketplaces.json';
        $this->checkouts = $this->tempDir . '/marketplaces';
        $this->sha = str_repeat('0a1f', 10);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testAFreshCatalogIsPinnedEmpty(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);

        self::assertTrue($registry->isBacked());
        self::assertSame([], $registry->names());
        self::assertSame([], $registry->loadErrors());
        self::assertFalse($registry->has('anything'));
    }

    public function testNothingInstallsFromACatalogThatWasNeverAdded(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);

        $refusal = $registry->installGate($this->catalogSpelled('acme-hub'), 'beta');

        self::assertNotNull($refusal);
        self::assertStringContainsString('has not been added to this machine', $refusal);
        self::assertNull($registry->installEntry($this->catalogSpelled('acme-hub'), 'beta'));
    }

    public function testRegistrationRequiresAFullPinnedCommit(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);

        self::assertStringContainsString('pinned commit', (string) $registry->add('acme-hub', 'github', 'acme/hub', ''));
        self::assertStringContainsString('pinned commit', (string) $registry->add('acme-hub', 'github', 'acme/hub', 'abc123'));
        self::assertNull($registry->add('acme-hub', 'github', 'acme/hub', $this->sha));
        self::assertTrue($registry->has('acme-hub'));
        self::assertSame(['acme-hub'], $registry->names());
    }

    public function testRegistrationRefusesTraversalNamesForeignSourceTypesAndRepeats(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);

        self::assertStringContainsString('valid bundle name', (string) $registry->add('../evil', 'github', 'acme/hub', $this->sha));
        self::assertStringContainsString('valid bundle name', (string) $registry->add('.', 'github', 'acme/hub', $this->sha));
        self::assertStringContainsString('unknown source type', (string) $registry->add('hub', 'mega-market', 'acme/hub', $this->sha));
        self::assertNull($registry->add('hub', 'github', 'acme/hub', $this->sha));
        self::assertStringContainsString('already added', (string) $registry->add('hub', 'github', 'acme/other', $this->sha));
    }

    public function testAnAddedMarketplaceRoundTripsThroughDiskWithItsCheckoutBelowTheBase(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertNull($registry->add('acme-hub', 'github', 'acme/hub', $this->sha));

        $again = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertSame(['acme-hub'], $again->names());
        self::assertSame($this->sha, $again->record('acme-hub')['sha']);
        self::assertSame($this->checkouts . '/acme-hub', realpath($again->checkoutDir('acme-hub')));
    }

    public function testAnUnknownEnvelopeVersionLoadsNothingAndSaysWhy(): void
    {
        file_put_contents($this->file, json_encode(['version' => 2, 'marketplaces' => []]));

        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);

        self::assertSame([], $registry->names());
        self::assertCount(1, $registry->loadErrors());
        self::assertStringContainsString('version 2', $registry->loadErrors()[0]);
    }

    public function testMalformedRowsAreSkippedByNameWhileSoundRowsLoad(): void
    {
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'marketplaces' => [
                ['name' => 'good', 'sourceType' => 'github', 'source' => 'a/b', 'sha' => $this->sha, 'addedAt' => 7],
                ['name' => 'bad/name', 'sourceType' => 'github', 'source' => 'a/b', 'sha' => $this->sha],
                ['name' => 'foreign', 'sourceType' => 'sftp', 'source' => 'a/b', 'sha' => $this->sha],
                'not even an object',
            ],
        ]));

        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);

        self::assertSame(['good'], $registry->names());
        self::assertCount(3, $registry->loadErrors());
    }

    public function testTheGatePassesOnlyForARegisteredPinOfferingTheEntry(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertNull($registry->add('acme-hub', 'github', 'acme/hub', $this->sha));

        self::assertNull($registry->installGate($this->catalogSpelled('acme-hub'), 'beta'));

        $entry = $registry->installEntry($this->catalogSpelled('acme-hub'), 'beta');
        self::assertNotNull($entry);
        self::assertSame($this->sha, $entry->sha);

        self::assertStringContainsString('offers no plugin named "nope"', (string) $registry->installGate($this->catalogSpelled('acme-hub'), 'nope'));
    }

    public function testARowLoadedWithoutAPinRefusesInstallEvenFromDisk(): void
    {
        // add() will not mint this shape; the file can carry it, and the
        // gate re-checks rather than trusting what made it onto disk.
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'marketplaces' => [['name' => 'acme-hub', 'sourceType' => 'github', 'source' => 'acme/hub', 'sha' => '', 'addedAt' => 1]],
        ]));

        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);

        self::assertStringContainsString('without a pinned commit', (string) $registry->installGate($this->catalogSpelled('acme-hub'), 'beta'));
    }

    public function testAnExplicitAllowlistDecidesSourceKeysCaseInsensitively(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertNull($registry->add('acme-hub', 'github', 'acme/hub', $this->sha));

        self::assertStringContainsString(
            'not on the pinned allowlist',
            (string) $registry->installGate($this->catalogSpelled('acme-hub'), 'beta', ['other/repo']),
        );
        self::assertNull($registry->installGate($this->catalogSpelled('acme-hub'), 'beta', ['Acme/Beta']));
    }

    public function testAPlantedLinkOutOfTheCheckoutBaseIsNotACheckout(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertNull($registry->add('acme-hub', 'github', 'acme/hub', $this->sha));

        $dir = $registry->checkoutDir('acme-hub');
        self::assertNotNull($dir);
        rmdir($dir);
        symlink($this->tempDir, $dir);

        self::assertNull($registry->checkoutDir('acme-hub'));
        self::assertNull($registry->marketplace('acme-hub'));
    }

    public function testMarketplaceReadsTheCatalogOfTheRegisteredCheckout(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertNull($registry->add('acme-hub', 'github', 'acme/hub', $this->sha));

        $dir = $registry->checkoutDir('acme-hub');
        self::assertNotNull($dir);
        mkdir($dir . '/.claude-plugin', 0700, true);
        file_put_contents($dir . '/.claude-plugin/marketplace.json', json_encode([
            'name' => 'acme-hub',
            'owner' => 'Acme',
            'plugins' => [['name' => 'beta', 'source' => ['source' => 'github', 'repo' => 'acme/beta']]],
        ]));

        $marketplace = $registry->marketplace('acme-hub');
        self::assertNotNull($marketplace);
        self::assertSame('acme-hub', $marketplace->name);
        self::assertNotNull($marketplace->entry('beta'));
    }

    public function testAPathRowCarriesItsOwnDirectoryAndNeedsNoCheckout(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        $sourceDir = $this->tempDir . '/vendor-checkout';
        mkdir($sourceDir, 0700, true);

        self::assertNull($registry->add('local-hub', 'path', $sourceDir . '/', $this->sha));
        self::assertSame($sourceDir, realpath($registry->checkoutDir('local-hub')));
        self::assertStringContainsString('cannot be found', (string) $registry->add('ghost-hub', 'path', $this->tempDir . '/nowhere', $this->sha));
    }

    public function testRepinAndRemoveMoveTheDurableRecord(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertNull($registry->add('acme-hub', 'github', 'acme/hub', $this->sha));

        self::assertStringContainsString('full sha', (string) $registry->repin('acme-hub', 'nope'));
        $other = str_repeat('b', 40);
        self::assertNull($registry->repin('acme-hub', $other));
        self::assertStringContainsString('is not added', (string) $registry->repin('nothere', $other));

        $again = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertSame($other, $again->record('acme-hub')['sha']);

        self::assertStringContainsString('is not added', (string) $registry->remove('ghost'));
        self::assertNull($registry->remove('acme-hub'));

        $third = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertSame([], $third->names());
    }

    public function testAnUnbackedRegistryRefusesEveryMutationAndInstallsNothing(): void
    {
        $registry = MarketplaceRegistry::inMemory();

        self::assertFalse($registry->isBacked());
        self::assertStringContainsString('vouchsafe home', (string) $registry->add('acme-hub', 'github', 'acme/hub', $this->sha));
        self::assertStringContainsString('vouchsafe home', (string) $registry->remove('acme-hub'));
        self::assertStringContainsString('is not added', (string) $registry->repin('acme-hub', $this->sha));
        self::assertStringContainsString('has not been added', (string) $registry->installGate($this->catalogSpelled('acme-hub'), 'beta'));
        self::assertNull($registry->checkoutDir('acme-hub'));
    }

    public function testPrepareCheckoutStaysBelowTheBaseAndIsIdempotent(): void
    {
        $registry = MarketplaceRegistry::atPaths($this->file, $this->checkouts);
        self::assertNull($registry->add('acme-hub', 'github', 'acme/hub', $this->sha));

        $first = $registry->prepareCheckout('acme-hub');
        self::assertNotNull($first);
        self::assertSame($first, $registry->prepareCheckout('acme-hub'));
        self::assertNull($registry->prepareCheckout('never-added'));
        self::assertTrue(ContainedPath::below($first, $this->checkouts));
    }

    /** A remote catalog with one beta row, under a root its sources never use. */
    private function catalogSpelled(string $name): Marketplace
    {
        $catalog = Marketplace::fromArray([
            'name' => $name,
            'plugins' => [['name' => 'beta', 'source' => ['source' => 'github', 'repo' => 'acme/beta']]],
        ], $this->tempDir . '/no-checkout-needed');

        self::assertNotNull($catalog);

        return $catalog;
    }
}
