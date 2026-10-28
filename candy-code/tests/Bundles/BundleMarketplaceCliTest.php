<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-EXT-4.b: the `bundles marketplace` family, `install <plugin>@<marketplace>`
 * and the read-only `search`/`recommend`. Every write the family performs is
 * pinned WHERE it lands (the registry file, the private checkout dir, the
 * bundle root) and WHAT refuses it (the C-EXT-4.a gate sentences ride these
 * paths unchanged — the tests assert the refusal's effect on disk, not the
 * stderr wording, which no transcript buffer can see). The clone leg runs
 * through the SAME `$bundlesInstallSpawn` seam `install` uses: no test here
 * dials a network, and each one that arms the seam proves the family reaches
 * it instead of minting a second clone site.
 */
final class BundleMarketplaceCliTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private const SHA_ONE = '0123456789abcdef0123456789abcdef01234567';

    private const SHA_TWO = 'fedcba9876543210fedcba9876543210fedcba98';

    private string $tempDir;

    private string $home;

    private string $root;

    private string $checkouts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-mkt-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
        $this->root = $this->home . '/.candy-code/bundles';
        $this->checkouts = $this->home . '/.candy-code/marketplaces';
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Subcommands::class, 'bundlesInstallSpawn'))->setValue(null, null);
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testAPathMarketplaceWithAPinAddsARowAndServesAGatedInstall(): void
    {
        $source = $this->catalogDir('demo', [['name' => 'alpha', 'source' => './plugins/alpha', 'keywords' => ['docker']]]);
        $this->pinGit($source, self::SHA_ONE);

        [$exit, $out] = $this->verbCapture('marketplace', 'add', $source);
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('Added marketplace demo pinned at ' . self::SHA_ONE, $out);

        $rows = $this->registryRows();
        self::assertCount(1, $rows);
        self::assertSame('demo', $rows[0]['name']);
        self::assertSame('path', $rows[0]['sourceType']);
        self::assertSame(realpath($source), $rows[0]['source']);
        self::assertSame(self::SHA_ONE, $rows[0]['sha']);

        [$exit, $out] = $this->verbCapture('install', 'alpha@demo');
        self::assertSame(NonInteractive::EXIT_OK, $exit, 'a gated install of a path entry must land through the @ grammar');
        self::assertStringContainsString('Installed bundle alpha', $out);
        self::assertStringContainsString('Recorded alpha from marketplace demo at commit ' . self::SHA_ONE, $out);
        self::assertFileExists($this->root . '/alpha/.claude-plugin/plugin.json');

        $record = json_decode((string) file_get_contents($this->root . '/alpha/.candy-code-source.json'), true);
        self::assertIsArray($record);
        self::assertSame('demo', $record['marketplace']);
        self::assertSame('alpha', $record['name']);
        self::assertSame(self::SHA_ONE, $record['sha']);

        self::assertSame(['alpha'], $this->entriesUnderRoot());
    }

    public function testAPathMarketplaceWithoutAGitPinIsRefusedEntirely(): void
    {
        $source = $this->catalogDir('demo', [['name' => 'alpha', 'source' => './plugins/alpha']]);

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('marketplace', 'add', $source));
        self::assertSame([], $this->registryRows());
        self::assertSame([], $this->entriesUnderRoot());
    }

    public function testADirectoryWithoutACatalogFileIsRefusedEvenWhenPinned(): void
    {
        $dir = $this->tempDir . '/plain-mp';
        mkdir($dir . '/plugins', 0777, true);
        $this->pinGit($dir, self::SHA_ONE);

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('marketplace', 'add', $dir));
        self::assertSame([], $this->registryRows());
    }

    public function testAddingTheSameMarketplaceTwiceIsRefused(): void
    {
        $source = $this->catalogDir('demo', [['name' => 'alpha', 'source' => './plugins/alpha']]);
        $this->pinGit($source, self::SHA_ONE);

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', $source));
        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('marketplace', 'add', $source));
        self::assertCount(1, $this->registryRows());
    }

    public function testMarketplaceListAnswersAnEmptyCatalogAndATextRowAfterAdd(): void
    {
        [$exit, $out] = $this->verbCapture('marketplace', 'list');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('No marketplaces are added', $out);

        $source = $this->catalogDir('demo', [['name' => 'alpha', 'source' => './plugins/alpha']]);
        $this->pinGit($source, self::SHA_ONE);
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', $source));

        [$exit, $out] = $this->verbCapture('marketplace', 'list');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('MARKETPLACE', $out);
        self::assertStringContainsString('demo', $out);
        self::assertStringContainsString(substr(self::SHA_ONE, 0, 12), $out, 'the text listing must carry the twelve-char pin');

        [$exit, $out] = $this->verbCapture('marketplace', 'list', '--output-format', 'json');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['marketplaces']);
        self::assertSame('demo', $decoded['marketplaces'][0]['name']);
        self::assertSame(self::SHA_ONE, $decoded['marketplaces'][0]['sha']);
        self::assertSame('path', $decoded['marketplaces'][0]['sourceType']);
    }

    public function testMarketplaceRemoveRetiresTheRowAndKeepsTheCheckout(): void
    {
        $this->armFakeClone(static function (string $url, string $scratch): array {
            self::seedScratch($scratch, 'demo', [['name' => 'alpha', 'source' => './plugins/alpha']], self::SHA_ONE);

            return ['ok' => true, 'detail' => ''];
        });

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', 'https://example.invalid/demo.git'));

        [$exit, $out] = $this->verbCapture('marketplace', 'remove', 'demo');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('Removed marketplace demo.', $out);
        self::assertSame([], $this->registryRows());
        self::assertDirectoryExists($this->checkouts . '/demo', 'retiring the row must not delete the checkout from behind recorded installs');

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('marketplace', 'remove', 'ghost'));
    }

    public function testAGitMarketplaceAddClonesThroughTheExistingSeamShedsHistoryAndPins(): void
    {
        $recorded = [];
        $this->armFakeClone(static function (string $url, string $scratch) use (&$recorded): array {
            $recorded[] = ['url' => $url, 'scratch' => $scratch];
            self::seedScratch($scratch, 'demo', [['name' => 'alpha', 'source' => './plugins/alpha']], self::SHA_ONE);

            return ['ok' => true, 'detail' => ''];
        });

        [$exit, $out] = $this->verbCapture('marketplace', 'add', 'https://example.invalid/demo.git');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('1 entries offered', $out);

        self::assertCount(1, $recorded);
        self::assertSame('https://example.invalid/demo.git', $recorded[0]['url']);
        self::assertStringStartsWith($this->root . '/.mkt-', $recorded[0]['scratch']);

        $rows = $this->registryRows();
        self::assertSame('url', $rows[0]['sourceType']);
        self::assertSame(self::SHA_ONE, $rows[0]['sha']);

        self::assertFileExists($this->checkouts . '/demo/.claude-plugin/marketplace.json');
        self::assertDirectoryDoesNotExist($this->checkouts . '/demo/.git', 'a cloned marketplace checkout kept the history it must shed');
        self::assertSame([], $this->entriesUnderRoot(), 'the moved scratch must leave nothing under the bundle root');
    }

    public function testAFailedCloneAddsNothingAndLeaksNoScratch(): void
    {
        $this->armFakeClone(static fn (): array => ['ok' => false, 'detail' => 'repository not found']);

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('marketplace', 'add', 'https://example.invalid/gone.git'));
        self::assertSame([], $this->registryRows());
        self::assertSame([], $this->entriesUnderRoot());
    }

    public function testMarketplaceUpdateSwapsTheCheckoutMovesThePinAndPrintsTheDiff(): void
    {
        $this->armFakeClone(static function (string $url, string $scratch): array {
            self::seedScratch($scratch, 'demo', [
                ['name' => 'alpha', 'source' => './plugins/alpha'],
                ['name' => 'gamma', 'source' => './plugins/gamma'],
            ], self::SHA_ONE, 'OLDONLY.txt');

            return ['ok' => true, 'detail' => ''];
        });

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', 'https://example.invalid/demo.git'));
        self::assertFileExists($this->checkouts . '/demo/OLDONLY.txt');

        $this->armFakeClone(static function (string $url, string $scratch): array {
            self::seedScratch($scratch, 'demo', [
                ['name' => 'alpha', 'source' => './plugins/alpha'],
                ['name' => 'beta', 'source' => './plugins/beta'],
            ], self::SHA_TWO, 'NEWONLY.txt');

            return ['ok' => true, 'detail' => ''];
        });

        [$exit, $out] = $this->verbCapture('marketplace', 'update', 'demo');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('Updated marketplace demo to commit ' . self::SHA_TWO, $out);
        self::assertStringContainsString('Marketplace update: beta was added to the catalog.', $out);
        self::assertStringContainsString('Marketplace update: gamma left the catalog', $out);

        $rows = $this->registryRows();
        self::assertSame(self::SHA_TWO, $rows[0]['sha']);
        self::assertFileExists($this->checkouts . '/demo/NEWONLY.txt');
        self::assertFileDoesNotExist($this->checkouts . '/demo/OLDONLY.txt', 'the swap left the old checkout beside the new one');
        self::assertSame(['demo'], $this->listDirNames($this->checkouts), 'no scratch or aside directory leaked beside the checkout');
    }

    public function testMarketplaceUpdateSkipsAPathRow(): void
    {
        $source = $this->catalogDir('demo', [['name' => 'alpha', 'source' => './plugins/alpha']]);
        $this->pinGit($source, self::SHA_ONE);
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', $source));

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'update', 'demo'));
        $rows = $this->registryRows();
        self::assertSame(self::SHA_ONE, $rows[0]['sha'], 'a path row is never re-pinned behind the operator');
    }

    public function testAMarketplaceThatRenamedItsCatalogIsRefusedAndLeftIntact(): void
    {
        $this->armFakeClone(static function (string $url, string $scratch): array {
            self::seedScratch($scratch, 'demo', [['name' => 'alpha', 'source' => './plugins/alpha']], self::SHA_ONE, 'OLDONLY.txt');

            return ['ok' => true, 'detail' => ''];
        });
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', 'https://example.invalid/demo.git'));

        $this->armFakeClone(static function (string $url, string $scratch): array {
            self::seedScratch($scratch, 'other', [['name' => 'alpha', 'source' => './plugins/alpha']], self::SHA_TWO);

            return ['ok' => true, 'detail' => ''];
        });

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('marketplace', 'update', 'demo'));
        $rows = $this->registryRows();
        self::assertSame(self::SHA_ONE, $rows[0]['sha']);
        self::assertFileExists($this->checkouts . '/demo/OLDONLY.txt', 'a refused update touched the live checkout');
        self::assertSame(['demo'], $this->listDirNames($this->checkouts));
    }

    public function testInstallThroughTheGrammarRefusesUnknownMarketplacesAndMissingEntries(): void
    {
        $source = $this->catalogDir('demo', [['name' => 'alpha', 'source' => './plugins/alpha']]);
        $this->pinGit($source, self::SHA_ONE);
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', $source));

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', 'alpha@nope'));
        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', 'ghost@demo'));
        self::assertSame([], $this->entriesUnderRoot(), 'a gate refusal must write nothing');
    }

    public function testSearchReadsTheAddedCatalogInTextAndJsonAndNeedsAQuery(): void
    {
        $source = $this->catalogDir('demo', [
            ['name' => 'dockerish', 'source' => './plugins/dockerish', 'keywords' => ['docker'], 'description' => 'container helper'],
            ['name' => 'plain', 'source' => './plugins/plain'],
        ]);
        $this->pinGit($source, self::SHA_ONE);

        [$exit, $out] = $this->verbCapture('search', 'zzz-nothing');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('No catalog entry matches zzz-nothing', $out);

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', $source));

        [$exit, $out] = $this->verbCapture('search', 'docker');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('dockerish', $out);
        self::assertStringNotContainsString('plain', $out);

        [$exit, $out] = $this->verbCapture('search', 'DOCKER', '--output-format', 'json');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertSame('DOCKER', $decoded['query']);
        self::assertCount(1, $decoded['results']);
        self::assertSame('dockerish', $decoded['results'][0]['name']);
        self::assertSame('demo', $decoded['results'][0]['marketplace']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $this->verb('search'));
    }

    public function testRecommendScoresProjectSignalsAndSkipsInstalledNames(): void
    {
        $source = $this->catalogDir('demo', [
            ['name' => 'webby', 'source' => './plugins/webby', 'keywords' => ['react']],
            ['name' => 'golangy', 'source' => './plugins/golangy', 'keywords' => ['golang']],
        ]);
        $this->pinGit($source, self::SHA_ONE);

        $project = $this->tempDir . '/proj';
        mkdir($project, 0777, true);
        file_put_contents($project . '/package.json', "{\"name\":\"app\"}\n");

        [$exit, $out] = $this->verbCapture('recommend', '--root', $project);
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringContainsString('No recommendations for this project', $out, 'the empty catalog must answer none BEFORE any add');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', $source));

        [$exit, $out] = $this->verbCapture('recommend', '--root', $project, '--output-format', 'json');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertSame($project, $decoded['project_root']);
        self::assertCount(1, $decoded['recommendations']);
        self::assertSame('webby', $decoded['recommendations'][0]['name']);
        self::assertSame('demo', $decoded['recommendations'][0]['marketplace']);

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'webby@demo'));

        [$exit, $out] = $this->verbCapture('recommend', '--root', $project, '--output-format', 'json');
        self::assertSame(NonInteractive::EXIT_OK, $exit);
        $decoded = json_decode($out, true);
        self::assertSame([], $decoded['recommendations'], 'an installed bundle must drop out of the recommendation');
    }

    public function testBareAndUnknownMarketplaceActionsAnswerUsageWithExitTwo(): void
    {
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->verb('marketplace'));
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->verb('marketplace', 'frobnicate'));
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->verb('marketplace', 'remove'));
    }

    /**
     * @param list<array<string, mixed>> $plugins
     */
    private function catalogDir(string $folder, array $plugins): string
    {
        $dir = $this->tempDir . '/' . $folder;
        mkdir($dir . '/.claude-plugin', 0777, true);

        foreach ($plugins as $plugin) {
            $relative = (string) ($plugin['source'] ?? '');

            if (str_starts_with($relative, './')) {
                $pluginDir = $dir . '/' . substr($relative, 2);
                mkdir($pluginDir . '/.claude-plugin', 0777, true);
                file_put_contents(
                    $pluginDir . '/.claude-plugin/plugin.json',
                    json_encode(['name' => (string) $plugin['name'], 'version' => '1.0.0']),
                );
                file_put_contents($pluginDir . '/UPSTREAM.txt', 'v1 for ' . $plugin['name']);
            }
        }

        file_put_contents(
            $dir . '/.claude-plugin/marketplace.json',
            json_encode(['name' => $folder, 'owner' => ['name' => 'op'], 'plugins' => $plugins]),
        );

        return $dir;
    }

    private function pinGit(string $dir, string $sha): void
    {
        mkdir($dir . '/.git/refs/heads', 0777, true);
        file_put_contents($dir . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($dir . '/.git/refs/heads/main', $sha . "\n");
    }

    /**
     * @param list<array<string, mixed>> $plugins
     */
    private static function seedScratch(string $scratch, string $name, array $plugins, string $sha, string $extraFile = ''): void
    {
        mkdir($scratch . '/.claude-plugin', 0777, true);

        foreach ($plugins as $plugin) {
            $relative = (string) ($plugin['source'] ?? '');

            if (str_starts_with($relative, './')) {
                $pluginDir = $scratch . '/' . substr($relative, 2);
                mkdir($pluginDir . '/.claude-plugin', 0777, true);
                file_put_contents(
                    $pluginDir . '/.claude-plugin/plugin.json',
                    json_encode(['name' => (string) $plugin['name'], 'version' => '1.0.0']),
                );
            }
        }

        file_put_contents(
            $scratch . '/.claude-plugin/marketplace.json',
            json_encode(['name' => $name, 'owner' => 'op', 'plugins' => $plugins]),
        );

        if ($extraFile !== '') {
            file_put_contents($scratch . '/' . $extraFile, 'marked');
        }

        mkdir($scratch . '/.git/refs/heads', 0777, true);
        file_put_contents($scratch . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($scratch . '/.git/refs/heads/main', $sha . "\n");
    }

    /**
     * @param callable(string, string): array{ok: bool, detail: string} $fn
     */
    private function armFakeClone(callable $fn): void
    {
        (new ReflectionProperty(Subcommands::class, 'bundlesInstallSpawn'))->setValue(null, \Closure::fromCallable($fn));
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

    /**
     * @return list<array<string, mixed>>
     */
    private function registryRows(): array
    {
        $raw = @file_get_contents($this->home . '/.candy-code/marketplaces.json');

        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && is_array($decoded['marketplaces'] ?? null) ? array_values($decoded['marketplaces']) : [];
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

    /** @return list<string> */
    private function listDirNames(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach ((array) scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }
}
