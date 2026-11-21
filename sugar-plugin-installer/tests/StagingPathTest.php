<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\PluginInstaller\InstallerConfig;
use SugarCraft\PluginInstaller\StagingPath;

final class StagingPathTest extends TestCase
{
    /** @var array{0: string|false, 1: string|false} HOME and USERPROFILE as found, restored in tearDown */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->savedEnv = [getenv('HOME'), getenv('USERPROFILE')];
    }

    protected function tearDown(): void
    {
        self::setEnv('HOME', $this->savedEnv[0]);
        self::setEnv('USERPROFILE', $this->savedEnv[1]);
    }

    private static function setEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }
        putenv($name . '=' . $value);
    }

    private static function config(?array $extra): InstallerConfig
    {
        return InstallerConfig::fromExtra($extra);
    }

    public function testUserScopeStagesUnderHome(): void
    {
        $root = StagingPath::bundleRoot(self::config(null), '/work/app', '/home/ann');

        $this->assertSame('/home/ann/.candy-code/bundles', $root);
    }

    public function testProjectScopeDefaultsToProjectDirectory(): void
    {
        $config = self::config(['sugar-plugin-installer' => ['scope' => 'project']]);

        $root = StagingPath::bundleRoot($config, '/work/app', '/home/ann');

        $this->assertSame('/work/app/.candy-code/bundles', $root);
    }

    public function testProjectScopeRelativeRootResolvesAgainstProjectDirectory(): void
    {
        $config = self::config(['sugar-plugin-installer' => ['scope' => 'project', 'root' => 'packages/shell']]);

        $root = StagingPath::bundleRoot($config, '/work/app', '/home/ann');

        $this->assertSame('/work/app/packages/shell/.candy-code/bundles', $root);
    }

    public function testProjectScopeAbsoluteRootIsUsedAsGiven(): void
    {
        $config = self::config(['sugar-plugin-installer' => ['scope' => 'project', 'root' => '/srv/shared']]);

        $root = StagingPath::bundleRoot($config, '/work/app', '/home/ann');

        $this->assertSame('/srv/shared/.candy-code/bundles', $root);
    }

    public function testProjectScopeUncRootIsUsedAsGivenNotJoinedOntoProject(): void
    {
        $config = self::config(['sugar-plugin-installer' => ['scope' => 'project', 'root' => '\\\\nas\\sugar']]);

        $root = StagingPath::bundleRoot($config, 'C:\\proj', 'C:\\Users\\ann');

        $this->assertSame('\\\\nas\\sugar/.candy-code/bundles', $root);
    }

    public function testUserScopeIgnoresRoot(): void
    {
        $config = self::config(['sugar-plugin-installer' => ['root' => '/srv/shared']]);

        $root = StagingPath::bundleRoot($config, '/work/app', '/home/ann');

        $this->assertSame('/home/ann/.candy-code/bundles', $root);
    }

    public function testTrailingSeparatorsAreTrimmedFromBases(): void
    {
        $config = self::config(['sugar-plugin-installer' => ['scope' => 'project', 'root' => '/srv/shared//']]);

        $root = StagingPath::bundleRoot($config, '/work/app/', '/home/ann/');

        $this->assertSame('/srv/shared/.candy-code/bundles', $root);
        $this->assertSame('/home/ann/.candy-code/bundles', StagingPath::bundleRoot(self::config(null), '/work/app', '/home/ann/'));
    }

    public function testForPackageNamesTheDirectoryAfterTheVendorSlash(): void
    {
        $path = StagingPath::forPackage('/home/ann/.candy-code/bundles', 'sugarcraft/candy-discord');

        $this->assertSame('/home/ann/.candy-code/bundles/candy-discord', $path);
    }

    public function testForPackageWithoutVendorUsesTheWholeName(): void
    {
        $path = StagingPath::forPackage('/home/ann/.candy-code/bundles', 'sample-plugin');

        $this->assertSame('/home/ann/.candy-code/bundles/sample-plugin', $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNames(): iterable
    {
        yield 'parent' => ['sugarcraft/..'];
        yield 'hidden' => ['sugarcraft/.hidden'];
        yield 'empty name' => ['sugarcraft/'];
        yield 'leading dash' => ['sugarcraft/-dash'];
        yield 'space' => ['sugarcraft/has space'];
    }

    #[DataProvider('unsafeNames')]
    public function testForPackageRefusesNamesThatCannotBeDirectories(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StagingPath::forPackage('/home/ann/.candy-code/bundles', $name);
    }

    public function testUserHomeReadsHome(): void
    {
        self::setEnv('HOME', '/home/ann');

        $this->assertSame('/home/ann', StagingPath::userHome());
    }

    public function testUserHomeFallsBackToUserProfile(): void
    {
        self::setEnv('HOME', '');
        self::setEnv('USERPROFILE', 'C:\\Users\\ann');

        $this->assertSame('C:\\Users\\ann', StagingPath::userHome());
    }

    public function testUserHomeRefusesRelativeHome(): void
    {
        self::setEnv('HOME', 'relative/home');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be an absolute path');

        StagingPath::userHome();
    }

    public function testUserHomeRefusesMissingHome(): void
    {
        self::setEnv('HOME', '');
        self::setEnv('USERPROFILE', '');

        $this->expectException(\RuntimeException::class);

        StagingPath::userHome();
    }
}
