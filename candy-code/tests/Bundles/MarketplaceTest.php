<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Marketplace;
use SugarCraft\Code\Bundles\MarketplaceEntry;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * C-EXT-4.a: the marketplace catalog parser. The rows are the manifest shape
 * Claude spells; the refusals are this build's floor — a row whose name or
 * source could not be honoured safely never reaches an installer, and a
 * catalog path that resolves outside the checkout it was named by is not read
 * at all.
 */
final class MarketplaceTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/cc-cext4a-marketplace-' . uniqid((string) getmypid(), true);
        $this->checkout = $this->tempDir . '/checkout';
        mkdir($this->checkout . '/plugins/alpha', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testParsesAClaudeShapedCatalogWithEverySourceSpelling(): void
    {
        $marketplace = Marketplace::fromArray([
            'name' => 'acme-hub',
            'owner' => ['name' => 'Acme', 'email' => 'acme@example.invalid'],
            'plugins' => [
                ['name' => 'alpha', 'source' => 'plugins/alpha', 'description' => 'local one', 'version' => '1.2.3'],
                ['name' => 'beta', 'source' => ['source' => 'github', 'repo' => 'acme/beta'], 'keywords' => ['PHP', ' Laravel ']],
                ['name' => 'gamma', 'source' => ['source' => 'url', 'url' => 'https://git.example.invalid/acme/gamma.git'], 'tags' => ['php']],
                ['name' => 'delta', 'source' => ['source' => 'git', 'url' => 'https://git.example.invalid/acme/delta'], 'category' => 'tools'],
            ],
        ], $this->checkout);

        self::assertNotNull($marketplace);
        self::assertSame('acme-hub', $marketplace->name);
        self::assertSame('Acme', $marketplace->owner);
        self::assertSame([], $marketplace->refusals);

        self::assertSame(MarketplaceEntry::SOURCE_PATH, $marketplace->entries[0]->sourceType);
        self::assertSame($this->checkout . '/plugins/alpha', realpath($marketplace->entries[0]->path));
        self::assertSame('1.2.3', $marketplace->entries[0]->version);

        self::assertSame(MarketplaceEntry::SOURCE_GITHUB, $marketplace->entries[1]->sourceType);
        self::assertSame('acme/beta', $marketplace->entries[1]->repo);
        self::assertSame(['php', 'laravel'], $marketplace->entries[1]->keywords);

        self::assertSame(MarketplaceEntry::SOURCE_URL, $marketplace->entries[2]->sourceType);
        self::assertSame(['php'], $marketplace->entries[2]->keywords);

        self::assertSame(MarketplaceEntry::SOURCE_GIT, $marketplace->entries[3]->sourceType);
        self::assertSame('tools', $marketplace->entries[3]->category);
    }

    public function testUnknownManifestKeysAreToleratedNotRefused(): void
    {
        $marketplace = Marketplace::fromArray([
            'name' => 'acme-hub',
            'futureField' => ['deep' => ['shape' => 1]],
            'plugins' => [[
                'name' => 'alpha',
                'source' => 'plugins/alpha',
                'someNewerKey' => 'whatever',
            ]],
        ], $this->checkout);

        self::assertNotNull($marketplace);
        self::assertCount(1, $marketplace->entries);
        self::assertSame([], $marketplace->refusals);
    }

    public function testACatalogWithoutAUsableNameOrPluginsArrayIsNotACatalog(): void
    {
        self::assertNull(Marketplace::fromArray(['name' => 'bad/name', 'plugins' => []], $this->checkout));
        self::assertNull(Marketplace::fromArray(['name' => '.', 'plugins' => []], $this->checkout));
        self::assertNull(Marketplace::fromArray(['plugins' => []], $this->checkout));
        self::assertNull(Marketplace::fromArray(['name' => 'ok'], $this->checkout));
    }

    public function testRowLevelRefusalsNameTheRowAndKeepTheSoundRows(): void
    {
        $marketplace = Marketplace::fromArray([
            'name' => 'acme-hub',
            'owner' => 'Acme',
            'plugins' => [
                ['name' => 'ok one', 'source' => 'plugins/alpha'],
                ['name' => 'escaping', 'source' => '../stolen'],
                ['name' => 'absolute', 'source' => '/etc'],
                ['name' => 'ssh-source', 'source' => ['source' => 'git', 'url' => 'ssh://git@example.invalid/x']],
                ['name' => 'userinfo', 'source' => ['source' => 'url', 'url' => 'https://@evil.example.invalid/x']],
                ['name' => 'half-repo', 'source' => ['source' => 'github', 'repo' => 'owneronly']],
                ['name' => 'weird', 'source' => ['source' => 'mega-market', 'repo' => 'a/b']],
                ['name' => 'alpha', 'source' => 'plugins/alpha'],
            ],
        ], $this->checkout);

        self::assertNotNull($marketplace);
        self::assertCount(1, $marketplace->entries);
        self::assertSame('alpha', $marketplace->entries[0]->name);
        self::assertCount(7, $marketplace->refusals);
        self::assertStringContainsString('"escaping"', $marketplace->refusals[1]);
        self::assertStringContainsString('https', $marketplace->refusals[3]);
    }

    public function testAStringSourceResolvingToAMissingOrFileShapeIsRefused(): void
    {
        $stray = $this->checkout . '/loose-file';
        file_put_contents($stray, 'not a directory');

        $marketplace = Marketplace::fromArray([
            'name' => 'acme-hub',
            'plugins' => [
                ['name' => 'gone', 'source' => 'plugins/never-was'],
                ['name' => 'filey', 'source' => 'loose-file'],
            ],
        ], $this->checkout);

        self::assertNotNull($marketplace);
        self::assertSame([], $marketplace->entries);
        self::assertCount(2, $marketplace->refusals);
    }

    public function testReadBoundsTheManifestInsideItsCheckout(): void
    {
        $manifestDir = $this->checkout . '/.claude-plugin';
        mkdir($manifestDir, 0700, true);
        file_put_contents($manifestDir . '/marketplace.json', json_encode(['name' => 'acme-hub', 'plugins' => []]));

        $read = Marketplace::read($this->checkout);
        self::assertNotNull($read);
        self::assertSame('acme-hub', $read->name);

        self::assertNull(Marketplace::read($this->tempDir . '/not-a-checkout'));

        // A `.claude-plugin` symlinked out of the tree is a plant: its bytes
        // live beyond the boundary, so the read refuses them.
        $outside = $this->tempDir . '/outside';
        mkdir($outside, 0700, true);
        file_put_contents($outside . '/marketplace.json', json_encode(['name' => 'evil-hub', 'plugins' => []]));
        $planted = $this->tempDir . '/planted';
        mkdir($planted, 0700, true);
        symlink($outside, $planted . '/.claude-plugin');

        self::assertNull(Marketplace::read($planted));
    }

    public function testARelativeSourceWalkedOutByASymlinkIsRefused(): void
    {
        $outside = $this->tempDir . '/elsewhere';
        mkdir($outside, 0700, true);
        symlink($outside, $this->checkout . '/plugins/linkout');

        $marketplace = Marketplace::fromArray([
            'name' => 'acme-hub',
            'plugins' => [['name' => 'linked', 'source' => 'plugins/linkout']],
        ], $this->checkout);

        self::assertNotNull($marketplace);
        self::assertSame([], $marketplace->entries);
        self::assertCount(1, $marketplace->refusals);
        self::assertStringContainsString('does not resolve inside the checkout', $marketplace->refusals[0]);
    }

    public function testEntryLookupAnswersOnlyThisCatalog(): void
    {
        $marketplace = Marketplace::fromArray([
            'name' => 'acme-hub',
            'plugins' => [['name' => 'alpha', 'source' => ['source' => 'github', 'repo' => 'acme/alpha']]],
        ], $this->checkout);

        self::assertNotNull($marketplace);
        self::assertNotNull($marketplace->entry('alpha'));
        self::assertNull($marketplace->entry('beta'));
    }

    public function testEntryFactoriesAndNameRule(): void
    {
        self::assertTrue(MarketplaceEntry::isValidName('a.b_c-d'));
        self::assertFalse(MarketplaceEntry::isValidName(''));
        self::assertFalse(MarketplaceEntry::isValidName('.'));
        self::assertFalse(MarketplaceEntry::isValidName('..'));
        self::assertFalse(MarketplaceEntry::isValidName('with space'));
        self::assertFalse(MarketplaceEntry::isValidName('with/slash'));

        $entry = MarketplaceEntry::github('beta', 'acme/beta');
        self::assertSame('acme/beta', $entry->sourceKey());
        $stamped = $entry->withPinnedSha(str_repeat('f', 40));
        self::assertSame('', $entry->sha);
        self::assertSame(str_repeat('f', 40), $stamped->sha);
        self::assertSame('acme/beta', $stamped->repo);

        $urlish = MarketplaceEntry::remoteUrl('g', 'https://x.invalid/g');
        self::assertSame('https://x.invalid/g', $urlish->sourceKey());
    }
}
