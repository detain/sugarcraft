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
        $command = sprintf(
            'env HOME=%s COMPOSER_HOME=%s COMPOSER_CACHE_DIR=%s COMPOSER_DISABLE_NETWORK=1 '
            . 'composer --working-dir=%s --no-interaction --no-progress --no-ansi --no-audit %s < /dev/null 2>&1',
            escapeshellarg($this->home),
            escapeshellarg($this->base . '/composer-home'),
            escapeshellarg($this->base . '/cache'),
            escapeshellarg($this->project),
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
}
