<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\PluginInstaller\InstallerConfig;
use SugarCraft\PluginInstaller\Scope;

final class InstallerConfigTest extends TestCase
{
    public function testAbsentExtraDefaultsToUserScopeWithoutRoot(): void
    {
        $config = InstallerConfig::fromExtra(null);

        $this->assertSame(Scope::User, $config->scope);
        $this->assertNull($config->root);
    }

    public function testExtraWithoutOurSectionDefaultsToUser(): void
    {
        $config = InstallerConfig::fromExtra(['class' => 'Something\\Else']);

        $this->assertSame(Scope::User, $config->scope);
        $this->assertNull($config->root);
    }

    public function testEmptySectionDefaultsToUser(): void
    {
        $config = InstallerConfig::fromExtra(['sugar-plugin-installer' => []]);

        $this->assertSame(Scope::User, $config->scope);
        $this->assertNull($config->root);
    }

    public function testProjectScopeIsRead(): void
    {
        $config = InstallerConfig::fromExtra(['sugar-plugin-installer' => ['scope' => 'project']]);

        $this->assertSame(Scope::Project, $config->scope);
        $this->assertNull($config->root);
    }

    public function testRootIsReadWithProjectScope(): void
    {
        $config = InstallerConfig::fromExtra([
            'sugar-plugin-installer' => ['scope' => 'project', 'root' => '/srv/app'],
        ]);

        $this->assertSame(Scope::Project, $config->scope);
        $this->assertSame('/srv/app', $config->root);
    }

    public function testRootAloneKeepsUserScope(): void
    {
        $config = InstallerConfig::fromExtra(['sugar-plugin-installer' => ['root' => 'app']]);

        $this->assertSame(Scope::User, $config->scope);
        $this->assertSame('app', $config->root);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badScopes(): iterable
    {
        yield 'unknown word' => [['scope' => 'global']];
        yield 'wrong case' => [['scope' => 'Project']];
        yield 'null' => [['scope' => null]];
        yield 'number' => [['scope' => 2]];
    }

    #[DataProvider('badScopes')]
    public function testBadScopeIsRefused(array $section): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be "user" or "project"');

        InstallerConfig::fromExtra(['sugar-plugin-installer' => $section]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badRoots(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'number' => [5];
        yield 'list' => [['app']];
    }

    #[DataProvider('badRoots')]
    public function testBadRootIsRefused(mixed $root): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('root must be a non-empty path string');

        InstallerConfig::fromExtra(['sugar-plugin-installer' => ['scope' => 'project', 'root' => $root]]);
    }

    public function testUnknownKeyIsRefusedSoATypoCannotFallBackToUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key "scoep"');

        InstallerConfig::fromExtra(['sugar-plugin-installer' => ['scoep' => 'project']]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonObjectSections(): iterable
    {
        yield 'string' => ['project'];
        yield 'number' => [1];
        yield 'non-empty list' => [['project']];
    }

    #[DataProvider('nonObjectSections')]
    public function testSectionMustBeAnObject(mixed $section): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an object');

        InstallerConfig::fromExtra(['sugar-plugin-installer' => $section]);
    }
}
