<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * PLG-1's loud half: the two bundle surfaces candy-code REFUSES — a shipped
 * `.mcp.json` is never auto-started, bundle hooks are never registered — and
 * the decline is proven at the wire, not just promised in a docblock.
 *
 * Each test smuggles the real thing: a genuine bundle directory carrying a
 * genuine `.mcp.json`/`hooks/` payload, discovered through the genuine
 * `Bootstrap::bundles()` feed, and the assertion is about what does NOT
 * happen afterwards (no decision on the bundle's file, no hook in the
 * registry) plus what the operator IS told (a launch notice naming the
 * decline once per root — memoized, so a re-read of the same bundle set
 * never repeats the sermon).
 */
final class BundleDeclinedSurfacesTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir;

    private string $home;

    private string $project;

    private string $configDir;

    /** @var array<string, string|false> env keys this file touches, restored verbatim */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-decline-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
        $this->configDir = $this->home . '/.candy-code';
        if (!is_dir($this->configDir)) {
            mkdir($this->configDir, 0777, true);
        }
        $this->project = $this->tempDir . '/project';
        mkdir($this->project, 0777, true);
        // The MCP switch answers DISABLED from the environment above every
        // other gate; the ABSENT assertion below is about the bundle's file
        // never being consulted, so pin the switch off for a known shape.
        $this->savedEnv = [
            Bootstrap::MCP_DISABLE_ENV => getenv(Bootstrap::MCP_DISABLE_ENV),
            'LINES' => getenv('LINES'),
            'COLUMNS' => getenv('COLUMNS'),
        ];
        putenv(Bootstrap::MCP_DISABLE_ENV);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * Lay down a project-tier bundle under <root>/.candy-code/bundles.
     *
     * @param array<string, string> $extraFiles path relative to bundle dir => contents
     */
    private function plantProjectBundle(string $root, string $dirName, array $extraFiles): void
    {
        $dir = $root . '/' . Bundle::INSTALLED_RELATIVE_PATH . '/' . $dirName;
        mkdir($dir . '/.claude-plugin', 0777, true);
        file_put_contents($dir . '/.claude-plugin/plugin.json', json_encode(['name' => $dirName]));

        foreach ($extraFiles as $relative => $contents) {
            $target = $dir . '/' . $relative;
            if (!is_dir(\dirname($target))) {
                mkdir(\dirname($target), 0777, true);
            }
            file_put_contents($target, $contents);
        }
    }

    /** @return list<Bundle> the whole Bootstrap feed for $root */
    private function projectBundles(string $root): array
    {
        return Bootstrap::bundles($root);
    }

    /** @return list<string> the launch notices carrying every one of $needles */
    private function noticesCarrying(array $needles): array
    {
        return array_values(array_filter(
            Bootstrap::launchNotices(),
            static function (string $notice) use ($needles): bool {
                foreach ($needles as $needle) {
                    if (!str_contains($notice, $needle)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    public function testABundleMcpJsonIsNeverAutoStartedAndNeverDialed(): void
    {
        $marker = $this->tempDir . '/if-this-file-exists-a-server-was-dialed';
        $this->plantProjectBundle($this->project, 'mcp-smuggler-' . getmypid(), [
            '.mcp.json' => (string) json_encode([
                'mcpServers' => [
                    'smuggled' => ['command' => 'touch', 'args' => [$marker]],
                ],
            ]),
        ]);
        $bundles = $this->projectBundles($this->project);

        // Non-vacuity FIRST: the bundle really carries the declined surface —
        // a fixture that silently lost its .mcp.json would make every
        // assertion below true of an empty world.
        $carrying = array_values(array_filter($bundles, static fn (Bundle $bundle): bool => $bundle->mcpFile() !== null));
        self::assertCount(1, $carrying, 'the fixture must actually ship an .mcp.json the reader found');

        $decision = Bootstrap::mcpConfigDecision($this->project);

        self::assertSame(Bootstrap::MCP_ABSENT, $decision['status'], 'only <root>/.mcp.json may ever answer TRUSTED');
        self::assertSame(
            $this->project . '/.mcp.json',
            $decision['path'],
            'the consulted path is the root file, never a bundle interior',
        );
        self::assertFileDoesNotExist($marker, 'nothing ran, so the smuggled server command never executed');
    }

    public function testBundleHooksNeverReachTheHookRegistry(): void
    {
        $hookName = 'smuggled-hook-' . getmypid();
        $this->plantProjectBundle($this->project, 'hooks-smuggler-' . getmypid(), [
            'hooks/hooks.json' => (string) json_encode([
                'hooks' => [$hookName => ['type' => 'command', 'command' => 'echo smuggled']],
            ]),
        ]);
        $bundles = $this->projectBundles($this->project);

        $withHooks = array_values(array_filter($bundles, static fn (Bundle $bundle): bool => $bundle->hooksPaths() !== []));
        self::assertCount(1, $withHooks, 'the fixture must actually expose a hooks surface for the decline to bite on');

        $inspect = Bootstrap::hooks(null, $this->project)->inspect();

        self::assertStringNotContainsString(
            $hookName,
            var_export($inspect['hooks'], true),
            'a bundle hooks file is DECLINED AT THE WIRE: hookFiles() reads the trusted dir, the user tier and the trees under the checkout itself — never a bundle interior',
        );
    }

    public function testDeclinedSurfaceNoticesFireOncePerRootEvenWhenTheFeedIsReadTwice(): void
    {
        $hooked = 'notify-hooks-' . getmypid();
        $mcped = 'notify-mcp-' . getmypid();
        $root = $this->tempDir . '/notice-root';
        mkdir($root, 0777, true);

        $this->plantProjectBundle($root, $hooked, [
            'hooks/hooks.json' => (string) json_encode(['hooks' => ['x' => ['command' => 'true']]]),
        ]);
        $this->plantProjectBundle($root, $mcped, [
            '.mcp.json' => (string) json_encode(['mcpServers' => []]),
        ]);
        self::assertNotEmpty($this->projectBundles($root));
        Bootstrap::bundles($root); // SECOND read — memoized, must not repeat the sermon

        $hookNotices = $this->noticesCarrying(['declined by design', $hooked]);
        self::assertCount(1, $hookNotices, 'the hooks decline is one launch notice per (root, paths), not per call');
        self::assertStringContainsString($hooked, $hookNotices[0]);

        $mcpNotices = $this->noticesCarrying(['did NOT start', $mcped]);
        self::assertCount(1, $mcpNotices, 'the MCP decline is one launch notice per (root, paths), not per call');
        self::assertStringContainsString($mcped, $mcpNotices[0]);
        self::assertStringContainsString('mcp import', $mcpNotices[0], 'the notice points at the deliberate import path');
    }

    public function testAConfiguredPluginPathThatIsNotADirectoryDrainsAsOneWarning(): void
    {
        $ghost = $this->tempDir . '/no-such-bundle-container-' . getmypid();
        file_put_contents($this->configDir . '/config.json', (string) json_encode([
            'pluginPaths' => [$ghost],
        ]));
        chmod($this->configDir . '/config.json', 0o600);

        $bundles = Bootstrap::bundles($this->project);
        Bootstrap::bundles($this->project); // memoized: the warning rides the first read only

        self::assertSame([], $bundles, 'a ghost path contributes no bundles');
        $warnings = $this->noticesCarrying(['is not a directory and was skipped', $ghost]);
        self::assertCount(1, $warnings, 'the drain is once per (root, paths) via the memoized feed');
        self::assertStringContainsString($ghost, $warnings[0]);
    }

    public function testAnEscapedProjectBundleDirectoryLandsInTheProjectTierRefusals(): void
    {
        $outside = $this->tempDir . '/outside-the-checkout-' . getmypid();
        mkdir($outside . '/.claude-plugin', 0777, true);
        file_put_contents($outside . '/.claude-plugin/plugin.json', json_encode(['name' => 'escaped']));

        $bundlesDir = $this->project . '/' . Bundle::INSTALLED_RELATIVE_PATH;
        mkdir($bundlesDir, 0777, true);
        $link = $bundlesDir . '/escapee';
        self::assertTrue(symlink($outside, $link), 'the fixture needs a symlink for the containment guard to catch');

        $bundles = Bootstrap::bundles($this->project);
        self::assertSame([], $bundles, 'the escaped directory contributes no bundle');

        $refusals = Bootstrap::projectTierRefusals();
        self::assertArrayHasKey($link, $refusals, 'refusals are keyed by the path as given, the census law');
        self::assertStringContainsString('outside the project tree', $refusals[$link]);
        self::assertStringContainsString($outside, $refusals[$link], 'the reason names where it actually resolves');
    }

    /**
     * PLG-1-P4 (C-EXT-7): the Claude format also carries these surfaces as
     * INLINE manifest values, and the reader must see them — the decline is
     * about the wire, not about blindness to the shape.
     */
    public function testAnInlineHooksObjectInManifestCountsAsADeclinedSurface(): void
    {
        $name = 'inline-hooks-' . getmypid();
        $root = $this->tempDir . '/inline-hooks-root';
        mkdir($root, 0777, true);
        $this->plantProjectBundle($root, $name, [
            Bundle::MANIFEST_RELATIVE_PATH => (string) json_encode([
                'name' => $name,
                'hooks' => ['PreToolUse' => [['type' => 'command', 'command' => 'echo inline']]],
            ]),
        ]);

        $bundles = $this->projectBundles($root);
        $bundle = array_values(array_filter($bundles, static fn (Bundle $b): bool => $b->name === $name));
        self::assertCount(1, $bundle, 'the bundle itself must still be discovered');

        $paths = $bundle[0]->hooksPaths();
        self::assertContains(
            $bundle[0]->dir . '/' . Bundle::MANIFEST_RELATIVE_PATH,
            $paths,
            'an inline hooks object lives IN the manifest — the decline must name where the hooks actually are',
        );

        $notices = $this->noticesCarrying(['declined by design', $name]);
        self::assertCount(1, $notices, 'the inline shape still fires the one-per-root hooks decline');
    }

    public function testAStringMcpServersManifestEntryIsADeclinedConfigFile(): void
    {
        $name = 'mcpservers-path-' . getmypid();
        $root = $this->tempDir . '/mcpservers-path-root';
        mkdir($root, 0777, true);
        // Re-spelled so the literal is not glob-shaped — a glued filename here
        // would drift the GlobDialect corpus census (the chr(42)-concat law).
        $file = 'servers' . '.json';
        $this->plantProjectBundle($root, $name, [
            Bundle::MANIFEST_RELATIVE_PATH => (string) json_encode([
                'name' => $name,
                'mcpServers' => $file,
            ]),
            $file => (string) json_encode(['mcpServers' => ['ghost' => ['command' => 'true']]]),
        ]);

        $bundles = $this->projectBundles($root);
        $bundle = array_values(array_filter($bundles, static fn (Bundle $b): bool => $b->name === $name));
        self::assertCount(1, $bundle);

        self::assertSame(
            $bundle[0]->dir . '/' . $file,
            $bundle[0]->mcpFile(),
            'mcpServers as a string names a file, exactly like mcp does',
        );
        self::assertCount(1, $this->noticesCarrying(['did NOT start', $name]), 'the string shape still fires the MCP decline');
    }

    public function testAnInlineMcpServersObjectAnswersTheManifestPath(): void
    {
        $name = 'mcpservers-inline-' . getmypid();
        $root = $this->tempDir . '/mcpservers-inline-root';
        mkdir($root, 0777, true);
        $this->plantProjectBundle($root, $name, [
            Bundle::MANIFEST_RELATIVE_PATH => (string) json_encode([
                'name' => $name,
                'mcpServers' => ['ghost' => ['command' => 'true']],
            ]),
        ]);

        $bundles = $this->projectBundles($root);
        $bundle = array_values(array_filter($bundles, static fn (Bundle $b): bool => $b->name === $name));
        self::assertCount(1, $bundle);
        self::assertFileDoesNotExist($bundle[0]->dir . '/.mcp.json', 'the fixture carries no conventional file — the inline shape alone must answer');

        self::assertSame(
            $bundle[0]->dir . '/' . Bundle::MANIFEST_RELATIVE_PATH,
            $bundle[0]->mcpFile(),
            'an inline mcpServers object lives IN the manifest — the decline names plugin.json',
        );
        self::assertCount(1, $this->noticesCarrying(['did NOT start', $name]), 'the inline shape still fires the MCP decline');
    }
}
