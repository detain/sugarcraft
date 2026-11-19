<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use PHPUnit\Framework\TestCase;
use SugarCraft\PluginInstaller\PluginInstaller;
use SugarCraft\PluginInstaller\Tests\Support\RecordingDownloader;
use SugarCraft\PluginInstaller\Tests\Support\Scratch;

final class PluginInstallerTest extends TestCase
{
    private string $project;
    private string $home;
    private string $cwd;
    private string|false $savedHome;
    private string|false $savedProfile;
    private string|false $savedComposer;

    protected function setUp(): void
    {
        $this->project = Scratch::dir('project');
        $this->home = Scratch::dir('home');
        $this->cwd = (string) getcwd();
        $this->savedHome = getenv('HOME');
        $this->savedProfile = getenv('USERPROFILE');
        $this->savedComposer = getenv('COMPOSER');
        chdir($this->project);
        putenv('HOME=' . $this->home);
        putenv('USERPROFILE');
        putenv('COMPOSER');
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        self::restoreEnv('HOME', $this->savedHome);
        self::restoreEnv('USERPROFILE', $this->savedProfile);
        self::restoreEnv('COMPOSER', $this->savedComposer);
        Scratch::remove($this->project);
        Scratch::remove($this->home);
    }

    private static function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }
        putenv($name . '=' . $value);
    }

    /**
     * @param array<string, mixed>|null $extra the root package's `extra` map
     * @param list<Package> $installed the packages the project already has installed
     * @param RecordingDownloader|null $downloader registered for the `file` dist type, so removal records its paths
     */
    private function installerFor(?array $extra, array $installed = [], ?RecordingDownloader $downloader = null): PluginInstaller
    {
        $composer = new Composer();
        $config = new Config(false, $this->project);
        $composer->setConfig($config);
        $downloads = new DownloadManager(new NullIO());
        if ($downloader !== null) {
            $downloads->setDownloader('file', $downloader);
        }
        $composer->setDownloadManager($downloads);

        $manager = new RepositoryManager(new NullIO(), $config, new HttpDownloader(new NullIO(), $config));
        $manager->setLocalRepository(new InstalledArrayRepository($installed));
        $composer->setRepositoryManager($manager);

        $root = new RootPackage('sugarcraft/scratch-project', '1.0.0.0', '1.0.0');
        $root->setExtra($extra ?? []);
        $composer->setPackage($root);

        return new PluginInstaller(new NullIO(), $composer);
    }

    private static function samplePackage(string $name = 'sugarcraft/sample-plugin'): Package
    {
        $package = new Package($name, '1.0.0.0', '1.0.0');
        $package->setType('sugar-plugin');

        return $package;
    }

    /**
     * A sample package with a dist source, so Composer routes its removal to the
     * `file` downloader registered by installerFor().
     */
    private static function removablePackage(string $name = 'acme/sample-plugin'): Package
    {
        $package = self::samplePackage($name);
        $package->setInstallationSource('dist');
        $package->setDistType('file');

        return $package;
    }

    /** A staged tree whose composer.json names $packageName, as a download writes it. */
    private function stageTree(string $dir, string $packageName): void
    {
        Scratch::write($dir . '/composer.json', (string) json_encode(['name' => $packageName]));
    }

    public function testSupportsOnlyTheSugarPluginType(): void
    {
        $installer = $this->installerFor(null);

        $this->assertTrue($installer->supports('sugar-plugin'));
        $this->assertFalse($installer->supports('library'));
        $this->assertFalse($installer->supports('composer-plugin'));
    }

    public function testDefaultInstallPathIsTheUserBundleTree(): void
    {
        $path = $this->installerFor(null)->getInstallPath(self::samplePackage());

        $this->assertSame($this->home . '/.candy-code/bundles/sample-plugin', $path);
    }

    public function testProjectScopeInstallPathIsTheProjectBundleTree(): void
    {
        $extra = ['sugar-plugin-installer' => ['scope' => 'project']];

        $path = $this->installerFor($extra)->getInstallPath(self::samplePackage());

        $this->assertSame($this->project . '/.candy-code/bundles/sample-plugin', $path);
    }

    public function testProjectScopeNeedsNoHomeDirectory(): void
    {
        // A CI container or service account may have no HOME. Project scope never
        // reads it, so the install must not fail on it.
        putenv('HOME');
        putenv('USERPROFILE');
        $extra = ['sugar-plugin-installer' => ['scope' => 'project']];

        $path = $this->installerFor($extra)->getInstallPath(self::samplePackage());

        $this->assertSame($this->project . '/.candy-code/bundles/sample-plugin', $path);
    }

    public function testUserScopeWithoutHomeIsRefusedForInstall(): void
    {
        putenv('HOME');
        putenv('USERPROFILE');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot resolve the user home directory');

        $this->installerFor(null)->install(new InstalledArrayRepository(), self::samplePackage());
    }

    public function testUserScopeWithoutHomeReadsTheProjectTreeAndRemovesIt(): void
    {
        // Composer reads the install path on dump-autoload and remove, where a
        // missing HOME must not abort the command. A project-tier tree from an
        // earlier project-scope install is the answer, and remove deletes it.
        putenv('HOME');
        putenv('USERPROFILE');
        $staged = $this->project . '/.candy-code/bundles/sample-plugin';
        $this->stageTree($staged, 'sugarcraft/sample-plugin');
        $downloader = new RecordingDownloader();
        $installer = $this->installerFor(null, [], $downloader);
        $package = self::removablePackage('sugarcraft/sample-plugin');

        $this->assertSame($staged, $installer->getInstallPath($package));

        $installer->uninstall(new InstalledArrayRepository([$package]), $package);

        $this->assertSame([$staged], $downloader->removed);
    }

    public function testRemoveLeavesAForeignTreeAtTheConfiguredPath(): void
    {
        // acme/sample-plugin has no tree of its own. The configured directory holds
        // sugarcraft/sample-plugin, staged by another package under the same name.
        // Remove must not hand that directory to the downloader.
        $foreign = $this->home . '/.candy-code/bundles/sample-plugin';
        $this->stageTree($foreign, 'sugarcraft/sample-plugin');
        $downloader = new RecordingDownloader();
        $installer = $this->installerFor(null, [], $downloader);
        $package = self::removablePackage('acme/sample-plugin');

        $installer->uninstall(new InstalledArrayRepository([$package]), $package);

        $this->assertSame([], $downloader->removed);
        $this->assertFileExists($foreign . '/composer.json');
    }

    public function testRemoveStillReachesThisPackagesOwnTreeAtTheConfiguredPath(): void
    {
        // The control for the foreign-tree case: a tree that names this package is
        // still removed, so the guard does not block ordinary removal.
        $own = $this->home . '/.candy-code/bundles/sample-plugin';
        $this->stageTree($own, 'acme/sample-plugin');
        $downloader = new RecordingDownloader();
        $installer = $this->installerFor(null, [], $downloader);
        $package = self::removablePackage('acme/sample-plugin');

        $installer->uninstall(new InstalledArrayRepository([$package]), $package);

        $this->assertSame([$own], $downloader->removed);
    }

    public function testRemoveLeavesAnUnnamedTreeAtTheConfiguredPath(): void
    {
        // A directory with no composer.json names no package, so it is not this
        // package's tree. The package has nothing on disk, and remove must not
        // hand the unnamed directory to the downloader.
        $unnamed = $this->home . '/.candy-code/bundles/sample-plugin';
        Scratch::write($unnamed . '/bundle.php', '<?php');
        $downloader = new RecordingDownloader();
        $installer = $this->installerFor(null, [], $downloader);
        $package = self::removablePackage('acme/sample-plugin');

        $installer->uninstall(new InstalledArrayRepository([$package]), $package);

        $this->assertSame([], $downloader->removed);
        $this->assertFileExists($unnamed . '/bundle.php');
    }

    public function testUpdateRefusesAnUnnamedDirectoryAtTheConfiguredPath(): void
    {
        $unnamed = $this->home . '/.candy-code/bundles/sample-plugin';
        Scratch::write($unnamed . '/bundle.php', '<?php');

        $this->assertUpdateRefusesOver($unnamed . '/bundle.php');
    }

    public function testUpdateRefusesAForeignTreeAtTheConfiguredPath(): void
    {
        // The directory names sugarcraft/sample-plugin, which is not the package
        // being updated. The download would write over it, so the update refuses.
        $foreign = $this->home . '/.candy-code/bundles/sample-plugin';
        $this->stageTree($foreign, 'sugarcraft/sample-plugin');

        $this->assertUpdateRefusesOver($foreign . '/composer.json');
    }

    /**
     * Runs an update of acme/sample-plugin over the directory that holds $survivor,
     * a file that does not name the package, and asserts the update refuses before
     * removing anything.
     */
    private function assertUpdateRefusesOver(string $survivor): void
    {
        $downloader = new RecordingDownloader();
        $installer = $this->installerFor(null, [], $downloader);
        $initial = self::removablePackage('acme/sample-plugin');
        $target = new Package('acme/sample-plugin', '2.0.0.0', '2.0.0');
        $target->setType('sugar-plugin');
        $target->setInstallationSource('dist');
        $target->setDistType('file');

        $refusal = null;
        try {
            $installer->update(new InstalledArrayRepository([$initial]), $initial, $target);
        } catch (\RuntimeException $caught) {
            $refusal = $caught;
        }

        $this->assertNotNull($refusal, 'an update must not overwrite a directory that does not name the package');
        $this->assertStringContainsString('refuses to overwrite', $refusal->getMessage());
        $this->assertSame([], $downloader->removed);
        $this->assertFileExists($survivor);
    }

    public function testInstallPathFollowsTheTreeAfterAScopeChange(): void
    {
        // Installed under project scope, then the root moved to user scope. Composer's
        // dump-autoload asks for the install path, and the autoload map must name the
        // tree that exists, not the empty user-tier directory.
        $staged = $this->project . '/.candy-code/bundles/sample-plugin';
        $this->stageTree($staged, 'sugarcraft/sample-plugin');

        $path = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'user']])
            ->getInstallPath(self::samplePackage());

        $this->assertSame($staged, $path);
    }

    public function testInstallRefusesAForeignTreeAtTheConfiguredPath(): void
    {
        // The directory names sugarcraft/sample-plugin, so a fresh install of
        // acme/sample-plugin would overwrite another package's tree. The install
        // refuses before the download replaces anything.
        $foreign = $this->home . '/.candy-code/bundles/sample-plugin';
        $this->stageTree($foreign, 'sugarcraft/sample-plugin');
        $installer = $this->installerFor(null);

        $refusal = null;
        try {
            $installer->install(new InstalledArrayRepository(), self::samplePackage('acme/sample-plugin'));
        } catch (\RuntimeException $caught) {
            $refusal = $caught;
        }

        $this->assertNotNull($refusal, 'an install must not overwrite a foreign tree');
        $this->assertStringContainsString('refuses to overwrite', $refusal->getMessage());
        $this->assertFileExists($foreign . '/composer.json');
    }

    public function testComposerFileDecidesTheProjectDirectory(): void
    {
        // Composer reads COMPOSER's file without chdir-ing, so getcwd() is the
        // wrong root here: the tree belongs next to the composer.json Composer loaded.
        $elsewhere = Scratch::dir('elsewhere');
        try {
            Scratch::write($elsewhere . '/composer.json', '{}');
            putenv('COMPOSER=' . $elsewhere . '/composer.json');
            $extra = ['sugar-plugin-installer' => ['scope' => 'project']];

            $path = $this->installerFor($extra)->getInstallPath(self::samplePackage());

            $this->assertSame(realpath($elsewhere) . '/.candy-code/bundles/sample-plugin', $path);
        } finally {
            Scratch::remove($elsewhere);
        }
    }

    public function testInstallRefusesAVendorSiblingThatStagesToTheSameDirectory(): void
    {
        // acme/candy-x and sugarcraft/candy-x both stage to bundles/candy-x. The
        // second install would overwrite the first, and remove would delete both.
        $installed = [self::samplePackage('sugarcraft/candy-x')];
        $installer = $this->installerFor(null, $installed);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('both stage to');

        $installer->install(new InstalledArrayRepository(), self::samplePackage('acme/candy-x'));
    }

    public function testRootOverrideMovesTheProjectBundleTree(): void
    {
        $shared = Scratch::dir('shared');
        try {
            $extra = ['sugar-plugin-installer' => ['scope' => 'project', 'root' => $shared]];

            $path = $this->installerFor($extra)->getInstallPath(self::samplePackage());

            $this->assertSame($shared . '/.candy-code/bundles/sample-plugin', $path);
        } finally {
            Scratch::remove($shared);
        }
    }

    public function testInstallPathStaysInsideTheBundleTree(): void
    {
        $extras = [
            null,
            ['sugar-plugin-installer' => ['scope' => 'project']],
            ['sugar-plugin-installer' => ['scope' => 'project', 'root' => 'nested']],
        ];

        foreach ($extras as $extra) {
            $path = $this->installerFor($extra)->getInstallPath(self::samplePackage());

            $this->assertStringContainsString('/.candy-code/bundles/', $path);
            $this->assertStringEndsWith('/sample-plugin', $path);
        }
    }

    public function testRemoveBasePathStaysTheStagingDirectoryWhenTargetDirMatchesTheName(): void
    {
        // LibraryInstaller strips a trailing `/<target-dir>`; with the name as
        // target-dir that lands on the bundles root, which remove must never delete.
        $package = self::samplePackage();
        $package->setTargetDir('sample-plugin');
        $installer = $this->installerFor(null);

        $method = new \ReflectionMethod($installer, 'getPackageBasePath');
        $base = $method->invoke($installer, $package);

        $this->assertSame($this->home . '/.candy-code/bundles/sample-plugin', $base);
    }

    public function testBadScopeRefusesDownloadBeforeAnythingIsStaged(): void
    {
        $installer = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'global']]);

        try {
            $installer->download(self::samplePackage());
            $this->fail('a bad scope must refuse the download');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must be "user" or "project"', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->home . '/.candy-code/bundles/sample-plugin');
    }

    public function testBadScopeRefusesInstall(): void
    {
        $installer = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'global']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be "user" or "project"');

        $installer->install(new InstalledArrayRepository(), self::samplePackage());
    }

    public function testBadScopeRefusesUpdate(): void
    {
        $initial = self::samplePackage();
        $target = new Package('sugarcraft/sample-plugin', '1.0.1.0', '1.0.1');
        $target->setType('sugar-plugin');
        $installer = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'global']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be "user" or "project"');

        $installer->update(new InstalledArrayRepository([$initial]), $initial, $target);
    }

    public function testBadScopeDoesNotStopTheReadPaths(): void
    {
        // Composer asks for the install path of every installed sugar-plugin on
        // dump-autoload, show, validate and remove. A malformed section must not
        // stop those, and the tree already staged is the answer.
        $installer = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'global']]);
        $staged = $this->home . '/.candy-code/bundles/sample-plugin';
        Scratch::write($staged . '/composer.json', '{}');

        $this->assertTrue($installer->supports('sugar-plugin'));
        $this->assertSame($staged, $installer->getInstallPath(self::samplePackage()));
    }

    public function testBadScopeReadsTheProjectTreeWhenThatIsWhereItIs(): void
    {
        $installer = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'global']]);
        $staged = $this->project . '/.candy-code/bundles/sample-plugin';
        Scratch::write($staged . '/composer.json', '{}');

        $this->assertSame($staged, $installer->getInstallPath(self::samplePackage()));
    }

    public function testBadScopeReadsTheRootOverrideTreeWhenThatIsWhereItIs(): void
    {
        // The raw `root` is still honoured on the read paths, so a removal after a
        // typo in the scope deletes the tree the root named rather than orphaning it.
        $shared = Scratch::dir('shared');
        try {
            $installer = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'global', 'root' => $shared]]);
            $staged = $shared . '/.candy-code/bundles/sample-plugin';
            Scratch::write($staged . '/composer.json', '{}');

            $this->assertSame($staged, $installer->getInstallPath(self::samplePackage()));
        } finally {
            Scratch::remove($shared);
        }
    }

    public function testInstallerSourceNeverReferencesTheTrustOrChannelStores(): void
    {
        $dir = dirname(__DIR__) . '/src';
        $forbidden = ['trustedBundleChannels', 'channels.json', 'ChannelsStore'];

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString($word, $source, basename($file) . " names $word");
            }
        }
    }
}
