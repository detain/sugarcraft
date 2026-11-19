<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\PluginInstaller\Tests\Support\Scratch;

/**
 * Drives a real `composer` against a scratch project: the installer is loaded
 * from this checkout by path repository, and a sample sugar-plugin package is
 * required and removed. Skipped when composer is not on PATH.
 *
 * Composer runs with a scratch HOME, COMPOSER_HOME and cache, and with network
 * disabled, so nothing outside the scratch tree is read or written.
 */
final class ComposerIntegrationTest extends TestCase
{
    private string $base;
    private string $home;
    private string $project;

    protected function setUp(): void
    {
        $composer = trim((string) shell_exec('command -v composer 2>/dev/null'));
        if ($composer === '') {
            $this->markTestSkipped('composer is not on PATH');
        }

        $this->base = Scratch::dir('integration');
        $this->home = $this->base . '/home';
        $this->project = $this->base . '/project';
        mkdir($this->home, 0700, true);
        mkdir($this->project, 0700, true);

        $this->writeSamplePlugin($this->base . '/sample');
        $this->seedTrustAndLedger();
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->base);
    }

    private function writeSamplePlugin(string $dir): void
    {
        Scratch::write($dir . '/composer.json', json_encode([
            'name' => 'sugarcraft/sample-plugin',
            'description' => 'Scratch sugar-plugin used by the installer integration test.',
            'type' => 'sugar-plugin',
            'license' => 'MIT',
            'autoload' => ['psr-4' => ['Sample\\Plugin\\' => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        Scratch::write($dir . '/src/Hello.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Sample\\Plugin;\n\nfinal class Hello\n{\n}\n");
    }

    /**
     * The trust list and the ledger the installer must leave alone. The bytes
     * are fixed here and compared after every Composer step.
     */
    private function seedTrustAndLedger(): void
    {
        Scratch::write($this->home . '/.candy-code/config.json', json_encode([
            'trustedBundleChannels' => ['sample-plugin'],
        ]));
        Scratch::write($this->home . '/.candy-code/channels.json', json_encode([
            'sample-plugin' => ['credentials' => 'kept-in-channels-store'],
        ]));
    }

    /**
     * @param array<string, mixed>|null $extra the project's `extra` section, or null for none
     */
    private function writeProject(?array $extra): void
    {
        $manifest = [
            'name' => 'sugarcraft/scratch-project',
            'type' => 'project',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'path', 'url' => dirname(__DIR__), 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $this->base . '/sample', 'options' => ['symlink' => false]],
            ],
            'config' => ['allow-plugins' => ['sugarcraft/sugar-plugin-installer' => true]],
        ];
        if ($extra !== null) {
            $manifest['extra'] = $extra;
        }
        Scratch::write($this->project . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{int, string} exit status and combined output
     */
    private function composer(string $args): array
    {
        // --no-progress and --no-audit exist only on the install-family commands;
        // dump-autoload rejects them, so they are added per verb.
        $verb = (string) strtok($args, ' ');
        $installFlags = in_array($verb, ['require', 'remove', 'install', 'update'], true) ? ' --no-progress --no-audit' : '';
        $command = sprintf(
            'env HOME=%s COMPOSER_HOME=%s COMPOSER_CACHE_DIR=%s COMPOSER_DISABLE_NETWORK=1 '
            . 'composer --working-dir=%s --no-interaction --no-ansi%s %s < /dev/null 2>&1',
            escapeshellarg($this->home),
            escapeshellarg($this->base . '/composer-home'),
            escapeshellarg($this->base . '/cache'),
            escapeshellarg($this->project),
            $installFlags,
            $args,
        );
        exec($command, $lines, $status);

        return [$status, implode("\n", $lines)];
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, string}>
     */
    public static function stagingCases(): iterable
    {
        yield 'user default (no section)' => [null, 'home'];
        yield 'user scope, explicit' => [['sugar-plugin-installer' => ['scope' => 'user']], 'home'];
        yield 'project scope' => [['sugar-plugin-installer' => ['scope' => 'project']], 'project'];
    }

    /**
     * @param array<string, mixed>|null $extra
     */
    #[DataProvider('stagingCases')]
    public function testRequireStagesAndRemoveDeletesTheTree(?array $extra, string $tier): void
    {
        $this->writeProject($extra);
        $before = Scratch::snapshot($this->home . '/.candy-code', ['bundles']);

        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");

        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));
        $this->assertSame(0, $status, "sample require failed:\n$out");

        $stage = $tier === 'project'
            ? $this->project . '/.candy-code/bundles/sample-plugin'
            : $this->home . '/.candy-code/bundles/sample-plugin';
        $other = $tier === 'project'
            ? $this->home . '/.candy-code/bundles/sample-plugin'
            : $this->project . '/.candy-code/bundles/sample-plugin';

        $this->assertFileExists($stage . '/composer.json', 'package not staged to the selected tier');
        $this->assertFileExists($stage . '/src/Hello.php');
        $this->assertDirectoryDoesNotExist($other, 'package staged to the wrong tier');
        $this->assertDirectoryDoesNotExist($this->project . '/vendor/sugarcraft/sample-plugin', 'package fell back to vendor/');
        $this->assertSame($before, Scratch::snapshot($this->home . '/.candy-code', ['bundles']), 'install touched the trust list or ledger');

        [$status, $out] = $this->composer('remove ' . escapeshellarg('sugarcraft/sample-plugin'));
        $this->assertSame(0, $status, "remove failed:\n$out");

        $this->assertDirectoryDoesNotExist($stage, 'remove left the staged tree behind');
        $this->assertSame($before, Scratch::snapshot($this->home . '/.candy-code', ['bundles']), 'remove touched the trust list or ledger');
    }

    public function testRootOverrideStagesUnderTheConfiguredRoot(): void
    {
        $shared = $this->base . '/shared';
        $this->writeProject(['sugar-plugin-installer' => ['scope' => 'project', 'root' => $shared]]);

        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));
        $this->assertSame(0, $status, "sample require failed:\n$out");

        $this->assertFileExists($shared . '/.candy-code/bundles/sample-plugin/composer.json');
        $this->assertDirectoryDoesNotExist($this->project . '/.candy-code/bundles/sample-plugin');
    }

    /**
     * @param array<string, mixed> $fields merged over the sample package's manifest
     */
    private function amendSample(array $fields): void
    {
        $path = $this->base . '/sample/composer.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        Scratch::write($path, json_encode(array_merge($manifest, $fields), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function testRemoveWithATargetDirMatchingTheNameDeletesOnlyThatPackage(): void
    {
        // Composer refuses target-dir with PSR-4 autoloading, so the sample uses a classmap.
        $this->amendSample(['target-dir' => 'sample-plugin', 'autoload' => ['classmap' => ['src/']]]);
        $this->writeProject(null);
        $sibling = $this->home . '/.candy-code/bundles/other-plugin';
        Scratch::write($sibling . '/composer.json', '{}');

        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));
        $this->assertSame(0, $status, "sample require failed:\n$out");

        $stage = $this->home . '/.candy-code/bundles/sample-plugin';
        $this->assertFileExists($stage . '/composer.json');

        [$status, $out] = $this->composer('remove ' . escapeshellarg('sugarcraft/sample-plugin'));
        $this->assertSame(0, $status, "remove failed:\n$out");

        clearstatcache();
        $this->assertDirectoryDoesNotExist($stage, 'remove left the staged tree behind');
        $this->assertFileExists($sibling . '/composer.json', 'remove deleted another staged plugin');
    }

    public function testDeclaredBinEntriesAreLinkedIntoVendorBinAndRemoved(): void
    {
        Scratch::write($this->base . '/sample/bin/sample-tool', "#!/bin/sh\necho sample\n");
        chmod($this->base . '/sample/bin/sample-tool', 0755);
        $this->amendSample(['bin' => ['bin/sample-tool']]);
        $this->writeProject(null);

        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));
        $this->assertSame(0, $status, "sample require failed:\n$out");

        // Composer links declared bin entries into the project bin dir, outside the
        // staged tree. The README documents this exception; this pins it.
        $link = $this->project . '/vendor/bin/sample-tool';
        $this->assertTrue(is_link($link) || is_file($link), 'declared bin entry was not linked into vendor/bin');

        [$status, $out] = $this->composer('remove ' . escapeshellarg('sugarcraft/sample-plugin'));
        $this->assertSame(0, $status, "remove failed:\n$out");

        // The earlier checks cached this path's stat; Composer changed it in another process.
        clearstatcache();
        $this->assertFalse(is_link($link) || is_file($link), 'remove left the bin link behind');
        $this->assertDirectoryDoesNotExist($this->home . '/.candy-code/bundles/sample-plugin');
    }

    public function testBadScopeFailsTheInstallWithAClearMessage(): void
    {
        $this->writeProject(['sugar-plugin-installer' => ['scope' => 'global']]);

        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");

        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));

        $this->assertNotSame(0, $status, 'a bad scope must refuse the install');
        // Composer wraps long lines, so compare with all whitespace removed.
        $this->assertStringContainsString('scopemustbe"user"or"project"', (string) preg_replace('/\s+/', '', $out));
        $this->assertDirectoryDoesNotExist($this->home . '/.candy-code/bundles/sample-plugin');
    }

    /**
     * Replaces only the project's `extra` section, keeping its requires and repositories.
     *
     * @param array<string, mixed> $extra
     */
    private function setProjectExtra(array $extra): void
    {
        $path = $this->project . '/composer.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['extra'] = $extra;
        Scratch::write($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * A section broken after the install must not stop dump-autoload or remove:
     * Composer reads the installed plugin's path on both, and remove has to delete
     * the tree that is already staged, not orphan it.
     */
    public function testBadScopeAfterInstallStillAutoloadsAndRemovesThePlugin(): void
    {
        $this->writeProject(null);
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));
        $this->assertSame(0, $status, "sample require failed:\n$out");

        $stage = $this->home . '/.candy-code/bundles/sample-plugin';
        $this->assertFileExists($stage . '/composer.json');

        $this->setProjectExtra(['sugar-plugin-installer' => ['scope' => 'global']]);

        [$status, $out] = $this->composer('dump-autoload');
        $this->assertSame(0, $status, "dump-autoload failed on a malformed section:\n$out");
        $this->assertFileExists($stage . '/composer.json', 'dump-autoload moved or removed the staged tree');

        [$status, $out] = $this->composer('remove ' . escapeshellarg('sugarcraft/sample-plugin'));
        $this->assertSame(0, $status, "remove failed on a malformed section:\n$out");

        clearstatcache();
        $this->assertDirectoryDoesNotExist($stage, 'remove orphaned the staged tree after the section broke');
    }

    /**
     * A valid scope change after install must not orphan the tree: remove reads it
     * from the tier it was staged into, not only from the tier the section now selects.
     */
    public function testRemoveAfterAScopeChangeDeletesTheTreeStagedUnderTheOldScope(): void
    {
        $this->writeProject(['sugar-plugin-installer' => ['scope' => 'user']]);
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));
        $this->assertSame(0, $status, "sample require failed:\n$out");

        $stage = $this->home . '/.candy-code/bundles/sample-plugin';
        $this->assertFileExists($stage . '/composer.json');

        $this->setProjectExtra(['sugar-plugin-installer' => ['scope' => 'project']]);

        [$status, $out] = $this->composer('remove ' . escapeshellarg('sugarcraft/sample-plugin'));
        $this->assertSame(0, $status, "remove failed after a scope change:\n$out");

        clearstatcache();
        $this->assertDirectoryDoesNotExist($stage, 'remove orphaned the tree staged under the old scope');
        $this->assertDirectoryDoesNotExist($this->project . '/.candy-code/bundles/sample-plugin', 'remove staged a tree into the new scope');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rootChangeCases(): iterable
    {
        yield 'root changed' => [['sugar-plugin-installer' => ['scope' => 'project', 'root' => 'moved']]];
        yield 'root key dropped' => [['sugar-plugin-installer' => ['scope' => 'project']]];
    }

    /**
     * A root change or a dropped root key moves the configured tier away from the
     * tree. Remove must still find the tree where Composer staged it, not report
     * success and leave it on disk.
     *
     * @param array<string, mixed> $changed the section after the root change
     */
    #[DataProvider('rootChangeCases')]
    public function testRemoveAfterARootChangeDeletesTheTreeStagedUnderTheOldRoot(array $changed): void
    {
        $this->writeProject(['sugar-plugin-installer' => ['scope' => 'project', 'root' => 'first']]);
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));
        $this->assertSame(0, $status, "sample require failed:\n$out");

        $stage = $this->project . '/first/.candy-code/bundles/sample-plugin';
        $this->assertFileExists($stage . '/composer.json');

        $this->setProjectExtra($changed);

        [$status, $out] = $this->composer('remove ' . escapeshellarg('sugarcraft/sample-plugin'));
        $this->assertSame(0, $status, "remove failed after a root change:\n$out");

        clearstatcache();
        $this->assertDirectoryDoesNotExist($stage, 'remove orphaned the tree staged under the old root');
        $this->assertDirectoryDoesNotExist($this->project . '/moved/.candy-code/bundles/sample-plugin', 'remove staged a tree under the new root');
        $this->assertDirectoryDoesNotExist($this->project . '/.candy-code/bundles/sample-plugin', 'remove staged a tree under the default root');
    }

    /**
     * An update after a scope change must move the tree, not leave the old copy
     * behind and stage a second one beside it.
     */
    public function testUpdateAfterAScopeChangeMovesTheTreeInsteadOfDuplicatingIt(): void
    {
        $this->writeProject(['sugar-plugin-installer' => ['scope' => 'user']]);
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sugar-plugin-installer:*'));
        $this->assertSame(0, $status, "installer require failed:\n$out");
        [$status, $out] = $this->composer('require ' . escapeshellarg('sugarcraft/sample-plugin:*'));
        $this->assertSame(0, $status, "sample require failed:\n$out");

        $old = $this->home . '/.candy-code/bundles/sample-plugin';
        $this->assertFileExists($old . '/composer.json');

        // A new version gives update a real target to move to.
        $this->amendSample(['version' => '1.1.0']);
        $this->setProjectExtra(['sugar-plugin-installer' => ['scope' => 'project']]);

        [$status, $out] = $this->composer('update ' . escapeshellarg('sugarcraft/sample-plugin'));
        $this->assertSame(0, $status, "update failed after a scope change:\n$out");

        clearstatcache();
        $new = $this->project . '/.candy-code/bundles/sample-plugin';
        $this->assertFileExists($new . '/composer.json', 'update did not stage the tree into the new scope');
        $this->assertStringContainsString('"version": "1.1.0"', (string) file_get_contents($new . '/composer.json'));
        $this->assertDirectoryDoesNotExist($old, 'update left the old tree behind');
    }
}
