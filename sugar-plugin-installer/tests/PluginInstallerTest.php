<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use PHPUnit\Framework\TestCase;
use SugarCraft\PluginInstaller\PluginInstaller;
use SugarCraft\PluginInstaller\Tests\Support\Scratch;

final class PluginInstallerTest extends TestCase
{
    private string $project;
    private string $home;
    private string $cwd;
    private string|false $savedHome;

    protected function setUp(): void
    {
        $this->project = Scratch::dir('project');
        $this->home = Scratch::dir('home');
        $this->cwd = (string) getcwd();
        $this->savedHome = getenv('HOME');
        chdir($this->project);
        putenv('HOME=' . $this->home);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        if ($this->savedHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->savedHome);
        }
        Scratch::remove($this->project);
        Scratch::remove($this->home);
    }

    /**
     * @param array<string, mixed>|null $extra the root package's `extra` map
     */
    private function installerFor(?array $extra): PluginInstaller
    {
        $composer = new Composer();
        $composer->setConfig(new Config(false, $this->project));
        $composer->setDownloadManager(new DownloadManager(new NullIO()));

        $root = new RootPackage('sugarcraft/scratch-project', '1.0.0.0', '1.0.0');
        $root->setExtra($extra ?? []);
        $composer->setPackage($root);

        return new PluginInstaller(new NullIO(), $composer);
    }

    private static function samplePackage(): Package
    {
        $package = new Package('sugarcraft/sample-plugin', '1.0.0.0', '1.0.0');
        $package->setType('sugar-plugin');

        return $package;
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

    public function testBadScopeIsRefusedWhenAPluginIsInstalled(): void
    {
        $installer = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'global']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be "user" or "project"');

        $installer->getInstallPath(self::samplePackage());
    }

    public function testBadScopeDoesNotStopConstruction(): void
    {
        // Only a sugar-plugin install reads the section, so a malformed one must
        // not break the installer for every other Composer command.
        $installer = $this->installerFor(['sugar-plugin-installer' => ['scope' => 'global']]);

        $this->assertTrue($installer->supports('sugar-plugin'));
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
