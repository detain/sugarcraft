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

    public function testEntrypointsAreReadFromEveryDeclaredShape(): void
    {
        $dir = $this->bundle('entrypoints-shapes', [
            'name' => 'shapes',
            'entrypoints' => [
                'channel' => 'channels/discord.php',
                'subscriber' => ['subscribers/a.php', 'subscribers/b.php'],
                'hook' => ['file' => 'hooks/live.php', 'class' => 'Hooks\\Live'],
                'tool' => [
                    ['file' => 'tools/one.php'],
                    ['file' => 'tools/two.php', 'class' => 'Tools\\Two'],
                ],
            ],
        ]);
        foreach (['channels', 'subscribers', 'hooks', 'tools'] as $folder) {
            mkdir($dir . '/' . $folder, 0777, true);
        }
        foreach (['channels/discord.php', 'subscribers/a.php', 'subscribers/b.php', 'hooks/live.php', 'tools/one.php', 'tools/two.php'] as $file) {
            file_put_contents($dir . '/' . $file, "<?php\n");
        }

        $entrypoints = Bundle::fromDir($dir, 'user')?->entrypoints();

        self::assertNotNull($entrypoints);
        self::assertSame(
            [['path' => realpath($dir) . '/channels/discord.php', 'class' => '']],
            $entrypoints['channel'],
            'a bare string is one entry with no class name',
        );
        self::assertSame(
            realpath($dir) . '/subscribers/a.php',
            $entrypoints['subscriber'][0]['path'],
        );
        self::assertSame(realpath($dir) . '/subscribers/b.php', $entrypoints['subscriber'][1]['path'], 'declaration order is kept');
        self::assertSame('Hooks\\Live', $entrypoints['hook'][0]['class'], 'an object entry carries its class name');
        self::assertSame('Tools\\Two', $entrypoints['tool'][1]['class']);
        self::assertSame('', $entrypoints['tool'][0]['class'], 'an object entry without a class stays blank for the loader to refuse later');
    }

    public function testEntrypointsNothingNamesLoadNothing(): void
    {
        $silent = $this->bundle('entrypoints-silent', ['name' => 'none']);
        self::assertSame([], Bundle::fromDir($silent, 'user')?->entrypoints(), 'a manifest that names no entrypoints declares none');

        // A top-level LIST of files is un-named by kind: the kinded-map law
        // refuses it whole rather than guess a surface for each file.
        $unkinded = $this->bundle('entrypoints-unkinded', ['name' => 'list', 'entrypoints' => ['index.php']]);
        file_put_contents($unkinded . '/index.php', "<?php\n");
        self::assertSame([], Bundle::fromDir($unkinded, 'user')?->entrypoints(), 'nothing is loaded that no manifest names BY KIND');

        $broken = $this->bundle('entrypoints-broken', ['name' => 'scalar', 'entrypoints' => 'index.php']);
        self::assertSame([], Bundle::fromDir($broken, 'user')?->entrypoints(), 'a scalar where a map must be declares nothing');
    }

    public function testAnEntrypointEscapingTheBundleDirIsDropped(): void
    {
        $dir = $this->bundle('entrypoints-escape', [
            'name' => 'escape',
            'entrypoints' => [
                'channel' => ['../outside.php', $this->tempDir . '/outside.php', 'channels/linked.php'],
            ],
        ]);
        file_put_contents($this->tempDir . '/outside.php', "<?php\n");
        mkdir($dir . '/channels', 0777, true);
        file_put_contents($dir . '/channels/real.php', "<?php\n");
        symlink($this->tempDir . '/outside.php', $dir . '/channels/linked.php');

        $entrypoints = Bundle::fromDir($dir, 'user')?->entrypoints();

        self::assertNotNull($entrypoints);
        self::assertSame([], $entrypoints, 'dot-dot, absolute-outside and planted-symlink-out paths all fail containment, and a kind whose every declaration escapes disappears whole');
        $kept = $this->bundle('entrypoints-kept', ['name' => 'kept', 'entrypoints' => ['channel' => 'kept.php']]);
        file_put_contents($kept . '/kept.php', "<?php\n");
        self::assertCount(1, Bundle::fromDir($kept, 'user')?->entrypoints()['channel'] ?? [], 'a plain file inside survives as proof the kind itself was read');
    }

    public function testMissingAndDirectoryEntrypointsAreDroppedWhileContainedAbsolutesSurvive(): void
    {
        $absInside = $this->tempDir . '/entrypoints-missing/channels/abs-inside.php';
        $dir = $this->bundle('entrypoints-missing', [
            'name' => 'gaps',
            'entrypoints' => [
                'channel' => ['channels/gone.php', 'channels', $absInside, '  '],
            ],
        ]);
        mkdir($dir . '/channels', 0777, true);
        file_put_contents($dir . '/channels/abs-inside.php', "<?php\n");

        $entrypoints = Bundle::fromDir($dir, 'user')?->entrypoints();

        self::assertNotNull($entrypoints);
        self::assertCount(1, $entrypoints['channel'], 'a missing file, a directory and a blank entry drop; the contained absolute survives');
        self::assertSame(realpath($absInside), $entrypoints['channel'][0]['path']);
    }

    public function testEntrypointsDedupePerKindAcrossShapes(): void
    {
        $dir = $this->bundle('entrypoints-dedupe', [
            'name' => 'twice',
            'entrypoints' => [
                'channel' => ['channels/one.php', ['file' => 'channels/one.php', 'class' => 'A'], 'channels/./one.php', 'channels//one.php'],
            ],
        ]);
        mkdir($dir . '/channels', 0777, true);
        file_put_contents($dir . '/channels/one.php', "<?php\n");

        $entrypoints = Bundle::fromDir($dir, 'user')?->entrypoints();

        self::assertNotNull($entrypoints);
        self::assertCount(1, $entrypoints['channel'], 'the same realpath appears once per kind however many shapes declare it');
    }

    public function testUnknownEntrypointKindsPassThroughTheReader(): void
    {
        $dir = $this->bundle('entrypoints-unknown-kind', [
            'name' => 'future',
            'entrypoints' => ['server-tools' => 'future/register.php', ' ' => 'named-but-kindless.php'],
        ]);
        mkdir($dir . '/future', 0777, true);
        file_put_contents($dir . '/future/register.php', "<?php\n");
        file_put_contents($dir . '/named-but-kindless.php', "<?php\n"); // would appear if the blank kind were not skipped

        $entrypoints = Bundle::fromDir($dir, 'user')?->entrypoints();

        self::assertNotNull($entrypoints);
        self::assertSame(['server-tools'], array_keys($entrypoints), 'the reader answers what is named; the registry refuses kinds it has no home for, and a blank kind-name is no name at all');
    }

    public function testTheBlankKindNameIsSkipped(): void
    {
        $dir = $this->bundle('entrypoints-blank-kind', [
            'name' => 'blank',
            'entrypoints' => ['' => 'kindless.php', 'channel' => 'channels/ok.php'],
        ]);
        file_put_contents($dir . '/kindless.php', "<?php\n"); // exists on purpose: only the blank KIND may drop it
        mkdir($dir . '/channels', 0777, true);
        file_put_contents($dir . '/channels/ok.php', "<?php\n");

        $entrypoints = Bundle::fromDir($dir, 'user')?->entrypoints();

        self::assertNotNull($entrypoints);
        self::assertSame(['channel'], array_keys($entrypoints));
    }
}
