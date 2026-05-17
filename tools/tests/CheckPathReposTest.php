<?php

declare(strict_types=1);

/**
 * PHPUnit test for tools/check-path-repos.php --fix functionality.
 *
 * Runs as a standalone script: php tools/tests/CheckPathReposTest.php
 * Bootstraps from monorepo root vendor/autoload.php.
 */

namespace SugarCraft\Tools\Tests;

// Standalone script: use the global composer autoload which has PHPUnit.
// The script under test (check-path-repos.php) is invoked as a subprocess,
// so no SugarCraft lib classes are needed here.
require_once '/home/my/.composer/vendor/autoload.php';

use PHPUnit\Framework\TestCase;

final class CheckPathReposTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/check_path_repos_test_' . \uniqid('', true);
        \mkdir($this->tmpDir . '/lib-b', 0777, true);
        \mkdir($this->tmpDir . '/lib-a', 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory.
        if ($this->tmpDir !== '' && \is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }
        \rmdir($dir);
    }

    private function writeComposerJson(string $dir, array $data): void
    {
        $json = \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        \file_put_contents($dir . '/composer.json', $json . "\n");
    }

    private function makeSlug(string $name): string
    {
        return \preg_replace('#^sugarcraft/##', '', $name);
    }

    private function runScript(string $fixtureRoot, array $args = []): array
    {
        // Use SUGARCRAFT_CHECK_PATH_REPOS_ROOT env var to point the script
        // at the fixture directory instead of the real monorepo.
        $env = 'SUGARCRAFT_CHECK_PATH_REPOS_ROOT=' . \escapeshellarg($fixtureRoot);
        $script = __DIR__ . '/../check-path-repos.php';
        $cmd = \escapeshellcmd(PHP_BINARY) . ' ' . \escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . \escapeshellarg($arg);
        }
        $fullCmd = $env . ' ' . $cmd;
        $output = [];
        $exitCode = 0;
        \exec($fullCmd . ' 2>&1', $output, $exitCode);
        return ['exit' => $exitCode, 'output' => \implode("\n", $output)];
    }

    public function testFixInsertsMissingPathRepoEntry(): void
    {
        // Set up lib-b (the dependency) — must be a sugarcraft/ prefix so
        // the script's sugarcraft/ filter catches it.
        $this->writeComposerJson($this->tmpDir . '/lib-b', [
            'name' => 'sugarcraft/lib-b',
            'type' => 'library',
            'require' => ['php' => '^8.3'],
            'repositories' => [],
            'autoload' => ['psr-4' => ['SugarCraft\LibB\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Set up lib-a (the consumer) — requires sugarcraft/lib-b@dev but has NO
        // path-repo entry.
        $this->writeComposerJson($this->tmpDir . '/lib-a', [
            'name' => 'sugarcraft/lib-a',
            'type' => 'library',
            'require' => [
                'php' => '^8.3',
                'sugarcraft/lib-b' => '@dev',
            ],
            'repositories' => [],  // deliberately missing path-repo for lib-b.
            'autoload' => ['psr-4' => ['SugarCraft\LibA\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Run script WITHOUT --fix — should detect issue and exit non-zero.
        // We redirect to a temporary monorepo root (our temp dir) so the script
        // scans only our fixture libs.
        $resultBefore = $this->runScript($this->tmpDir);
        $this->assertNotEquals(0, $resultBefore['exit'], 'Script should exit non-zero when path-repo is missing');
        $this->assertStringContainsString('lib-a', $resultBefore['output']);
        $this->assertStringContainsString('no path-repo entry', $resultBefore['output']);

        // Apply --fix.
        $resultFix = $this->runScript($this->tmpDir, ['--fix']);
        $this->assertEquals(0, $resultFix['exit'], 'Script with --fix should exit 0 when all issues are fixable');

        // Verify the lib-a composer.json was updated.
        $libAComposer = \json_decode(\file_get_contents($this->tmpDir . '/lib-a/composer.json'), true);
        $this->assertIsArray($libAComposer);

        $repos = $libAComposer['repositories'] ?? [];
        $this->assertNotEmpty($repos, 'repositories should not be empty after --fix');

        // Find the path-repo entry for lib-b.
        $found = false;
        foreach ($repos as $repo) {
            if (($repo['type'] ?? '') === 'path'
                && ($repo['url'] ?? '') === '../lib-b'
                && ($repo['options']['symlink'] ?? false) === true
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'lib-a composer.json should contain path-repo entry for ../lib-b');

        // After fix, running without args should be clean.
        $resultAfter = $this->runScript($this->tmpDir);
        $this->assertEquals(0, $resultAfter['exit'], 'Script should exit 0 after fix on clean closure');
        $this->assertStringContainsString('closure clean', $resultAfter['output']);
    }

    public function testHelpFlagExitsZeroAndPrintsUsage(): void
    {
        // Running from the monorepo root (not a fixture dir) for the help test.
        $result = $this->runScript(\dirname(__DIR__, 2), ['--help']);
        $this->assertEquals(0, $result['exit']);
        $this->assertStringContainsString('Usage:', $result['output']);
        $this->assertStringContainsString('--fix', $result['output']);
    }

    public function testIdempotentWithoutFixFlag(): void
    {
        // Set up lib-b (sugarcraft prefix so the script cares about it).
        $this->writeComposerJson($this->tmpDir . '/lib-b', [
            'name' => 'sugarcraft/lib-b',
            'type' => 'library',
            'require' => ['php' => '^8.3'],
            'repositories' => [],
            'autoload' => ['psr-4' => ['SugarCraft\LibB\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Set up lib-a with missing path-repo.
        $this->writeComposerJson($this->tmpDir . '/lib-a', [
            'name' => 'sugarcraft/lib-a',
            'type' => 'library',
            'require' => [
                'php' => '^8.3',
                'sugarcraft/lib-b' => '@dev',
            ],
            'repositories' => [],
            'autoload' => ['psr-4' => ['SugarCraft\LibA\\' => 'src/']],
            'minimum-stability' => 'dev',
        ]);

        // Record the original content.
        $originalContent = \file_get_contents($this->tmpDir . '/lib-a/composer.json');

        // Run WITHOUT --fix twice — content must be unchanged after each run.
        $result1 = $this->runScript($this->tmpDir);
        $this->assertNotEquals(0, $result1['exit']);
        $this->assertSame($originalContent, \file_get_contents($this->tmpDir . '/lib-a/composer.json'));

        $result2 = $this->runScript($this->tmpDir);
        $this->assertNotEquals(0, $result2['exit']);
        $this->assertSame($originalContent, \file_get_contents($this->tmpDir . '/lib-a/composer.json'));
    }
}
