<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Tests for Bundle — the manifest/surface reader of a Claude-format plugin
 * bundle directory. No home, no project: every fixture is a plain temp tree.
 */
final class BundleManifestTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-manifest-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $manifest */
    private function bundle(string $relative, array $manifest, bool $hidden = true): string
    {
        $dir = $this->tempDir . '/' . $relative;

        if ($hidden) {
            mkdir($dir . '/.claude-plugin', 0777, true);
            file_put_contents($dir . '/.claude-plugin/plugin.json', json_encode($manifest));
        } else {
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/plugin.json', json_encode($manifest));
        }

        return $dir;
    }

    public function testTheHiddenManifestShapeIsPreferred(): void
    {
        $dir = $this->bundle('both-shapes', ['name' => 'hidden-wins', 'version' => '9.9']);
        file_put_contents($dir . '/plugin.json', '{"name":"bare-loses"}');

        $bundle = Bundle::fromDir($dir, 'user');

        self::assertNotNull($bundle);
        self::assertSame('hidden-wins', $bundle->name);
        self::assertSame('9.9', $bundle->version);
    }

    public function testTheBareManifestShapeIsAccepted(): void
    {
        $dir = $this->bundle('bare-only', ['name' => 'plain', 'description' => 'no dot-dir'], hidden: false);

        $bundle = Bundle::fromDir($dir, 'project');

        self::assertNotNull($bundle);
        self::assertSame('plain', $bundle->name);
        self::assertSame('no dot-dir', $bundle->description);
        self::assertSame('project', $bundle->tier);
    }

    public function testTheDirectoryNameCarriesTheIdentityWhenTheManifestStaysSilent(): void
    {
        $dir = $this->bundle('unnamed-bundle', ['description' => 'nameless']);

        $bundle = Bundle::fromDir($dir, 'user');

        self::assertNotNull($bundle);
        self::assertSame('unnamed-bundle', $bundle->name);
    }

    public function testUnknownManifestFieldsAreIgnoredNotRefused(): void
    {
        $dir = $this->bundle('future-format', [
            'name' => 'from-2027',
            'lspServers' => './lsp.json',
            'someFieldNobodyHasHeardOf' => ['deep' => true],
        ]);

        $bundle = Bundle::fromDir($dir, 'user');

        self::assertNotNull($bundle);
        self::assertSame('from-2027', $bundle->name);
        self::assertNull($bundle->mcpFile());
        self::assertSame([], $bundle->hooksPaths());
    }

    public function testAMissingManifestMeansNotABundleAndABrokenOneMeansNotLoadable(): void
    {
        mkdir($this->tempDir . '/empty-dir', 0777, true);
        self::assertFalse(Bundle::hasManifest($this->tempDir . '/empty-dir'));
        self::assertNull(Bundle::fromDir($this->tempDir . '/empty-dir', 'user'));

        mkdir($this->tempDir . '/broken/.claude-plugin', 0777, true);
        file_put_contents($this->tempDir . '/broken/.claude-plugin/plugin.json', '{"name": ');
        self::assertTrue(Bundle::hasManifest($this->tempDir . '/broken'));
        self::assertNull(Bundle::fromDir($this->tempDir . '/broken', 'user'));
    }

    public function testSurfaceDirectoriesDefaultToTheConventionalNames(): void
    {
        $dir = $this->bundle('conventional', ['name' => 'conv']);

        foreach (['skills', 'commands', 'agents'] as $surface) {
            self::assertSame([], (Bundle::fromDir($dir, 'user'))?->{$surface . 'Paths'}(), $surface . ' absent');
            mkdir($dir . '/' . $surface, 0777, true);
        }

        $bundle = Bundle::fromDir($dir, 'user');

        self::assertSame([$dir . '/skills'], $bundle?->skillsPaths());
        self::assertSame([$dir . '/commands'], $bundle?->commandsPaths());
        self::assertSame([$dir . '/agents'], $bundle?->agentsPaths());
    }

    public function testDeclaredSurfacePathsOverrideTheConventionAndMissingOnesDrop(): void
    {
        $dir = $this->bundle('declared', [
            'name' => 'dec',
            'commands' => 'cmd',
            'skills' => ['sk', 'gone'],
        ]);
        mkdir($dir . '/cmd', 0777, true);
        mkdir($dir . '/sk', 0777, true);
        mkdir($dir . '/commands', 0777, true);

        $bundle = Bundle::fromDir($dir, 'user');

        self::assertSame([$dir . '/cmd'], $bundle?->commandsPaths(), 'a declaration replaces the conventional directory wholesale');
        self::assertSame([$dir . '/sk'], $bundle?->skillsPaths(), 'a declared path that does not exist is dropped');
    }

    public function testTheMcpFileIsFoundInBothShapes(): void
    {
        $dir = $this->bundle('with-mcp', ['name' => 'mcp-default']);
        file_put_contents($dir . '/.mcp.json', '{"mcpServers":{}}');

        self::assertSame($dir . '/.mcp.json', Bundle::fromDir($dir, 'user')?->mcpFile());

        $other = $this->bundle('declared-mcp', ['name' => 'mcp-declared', 'mcp' => 'servers/model.json']);
        mkdir($other . '/servers', 0777, true);
        file_put_contents($other . '/servers/model.json', '{}');

        self::assertSame($other . '/servers/model.json', Bundle::fromDir($other, 'user')?->mcpFile());
    }

    public function testHooksAreRecognisedFromDirectoryAndManifestEntry(): void
    {
        $dir = $this->bundle('with-hooks', ['name' => 'hooked']);
        mkdir($dir . '/hooks', 0777, true);

        self::assertSame([$dir . '/hooks'], Bundle::fromDir($dir, 'user')?->hooksPaths());

        $declared = $this->bundle('declared-hooks', ['name' => 'hooked-2', 'hooks' => 'custom/hooks.json']);
        mkdir($declared . '/custom', 0777, true);
        file_put_contents($declared . '/custom/hooks.json', '{}');

        self::assertSame([$declared . '/custom/hooks.json'], Bundle::fromDir($declared, 'user')?->hooksPaths());
    }
}
