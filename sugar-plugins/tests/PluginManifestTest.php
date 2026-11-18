<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Manifest\Entrypoint;
use SugarCraft\Plugins\Manifest\PluginManifest;
use SugarCraft\Plugins\Tests\Support\Scratch;

final class PluginManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Scratch::dir('manifest');
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->dir);
    }

    /** @param array<string, mixed> $manifest */
    private function writeBare(array $manifest, ?string $dir = null): string
    {
        $dir ??= $this->dir;
        Scratch::put($dir . '/plugin.json', (string) json_encode($manifest));

        return $dir;
    }

    /** @param array<string, mixed> $manifest */
    private function writeClaudeShape(array $manifest): string
    {
        Scratch::put($this->dir . '/.claude-plugin/plugin.json', (string) json_encode($manifest));

        return $this->dir;
    }

    public function testBareManifestIsRead(): void
    {
        $dir = $this->writeBare(['name' => 'demo', 'version' => '1.2.0', 'description' => 'A demo.']);

        $manifest = PluginManifest::fromDir($dir);

        $this->assertNotNull($manifest);
        $this->assertSame('demo', $manifest->name());
        $this->assertSame('1.2.0', $manifest->version());
        $this->assertSame('A demo.', $manifest->description());
        $this->assertSame($dir, $manifest->dir());
    }

    public function testClaudePluginManifestIsRead(): void
    {
        $this->writeClaudeShape(['name' => 'nested']);

        $manifest = PluginManifest::fromDir($this->dir);

        $this->assertNotNull($manifest);
        $this->assertSame('nested', $manifest->name());
        $this->assertSame(
            $this->dir . '/.claude-plugin/plugin.json',
            PluginManifest::manifestPath($this->dir),
        );
    }

    public function testTheClaudePluginShapeWinsOverTheBareShape(): void
    {
        $this->writeBare(['name' => 'bare']);
        $this->writeClaudeShape(['name' => 'claude']);

        $this->assertSame('claude', PluginManifest::fromDir($this->dir)?->name());
    }

    public function testHasManifestAndManifestPathReportAnAbsentManifest(): void
    {
        $this->assertFalse(PluginManifest::hasManifest($this->dir));
        $this->assertNull(PluginManifest::manifestPath($this->dir));
        $this->assertNull(PluginManifest::fromDir($this->dir));

        $this->writeBare(['name' => 'here']);

        $this->assertTrue(PluginManifest::hasManifest($this->dir));
    }

    public function testNonObjectManifestIsRefused(): void
    {
        Scratch::put($this->dir . '/plugin.json', '["a", "b"]');
        $this->assertNull(PluginManifest::fromDir($this->dir));

        Scratch::put($this->dir . '/plugin.json', '"just a string"');
        $this->assertNull(PluginManifest::fromDir($this->dir));

        Scratch::put($this->dir . '/plugin.json', '{not json');
        $this->assertNull(PluginManifest::fromDir($this->dir));
    }

    public function testAMissingNameFallsBackToTheDirectoryName(): void
    {
        $this->writeBare(['version' => '0.1']);

        $this->assertSame(basename($this->dir), PluginManifest::fromDir($this->dir)?->name());
    }

    public function testADotNameIsRefused(): void
    {
        $this->writeBare(['name' => '.invisible']);

        $this->assertNull(PluginManifest::fromDir($this->dir));
    }

    public function testNonScalarMetadataReadsAsEmpty(): void
    {
        $this->writeBare(['name' => 'quiet', 'version' => ['not', 'scalar'], 'description' => ['nope']]);

        $manifest = PluginManifest::fromDir($this->dir);

        $this->assertSame('', $manifest?->version());
        $this->assertSame('', $manifest?->description());
    }

    public function testContractIsParsedAsAnInteger(): void
    {
        $this->writeBare(['name' => 'contracted', 'contract' => 2]);

        $this->assertSame(2, PluginManifest::fromDir($this->dir)?->contract());
    }

    public function testAnAbsentContractIsNull(): void
    {
        $this->writeBare(['name' => 'plain']);

        $this->assertNull(PluginManifest::fromDir($this->dir)?->contract());
    }

    public function testAMalformedContractRefusesTheManifest(): void
    {
        $this->writeBare(['name' => 'stringy', 'contract' => '2']);
        $this->assertNull(PluginManifest::fromDir($this->dir));

        $this->writeBare(['name' => 'negative', 'contract' => -1]);
        $this->assertNull(PluginManifest::fromDir($this->dir));
    }

    public function testUnKindedEntrypointsAreRefused(): void
    {
        $file = Scratch::put($this->dir . '/src/Ch.php', '<?php');
        $this->writeBare(['name' => 'nokind', 'entrypoints' => ['' => 'src/Ch.php']]);

        $this->assertSame([], PluginManifest::fromDir($this->dir)?->entrypoints());
        $this->assertFileExists($file);
    }

    public function testAListShapedEntrypointsBlockIsRefused(): void
    {
        Scratch::put($this->dir . '/src/Ch.php', '<?php');
        $this->writeBare(['name' => 'listy', 'entrypoints' => ['src/Ch.php']]);

        $this->assertSame([], PluginManifest::fromDir($this->dir)?->entrypoints());
    }

    public function testAnEntrypointResolvesToAContainedRealpathWithItsClass(): void
    {
        $file = Scratch::put($this->dir . '/src/Chan.php', '<?php');
        $this->writeBare([
            'name' => 'ok',
            'entrypoints' => ['channel' => [['file' => 'src/Chan.php', 'class' => 'Demo\\Chan']]],
        ]);

        $entrypoints = PluginManifest::fromDir($this->dir)?->entrypoints() ?? [];

        $this->assertArrayHasKey('channel', $entrypoints);
        $this->assertEquals([new Entrypoint('channel', realpath($file), 'Demo\\Chan')], $entrypoints['channel']);
    }

    public function testAnEntrypointEscapingTheDirectoryIsDropped(): void
    {
        $outside = Scratch::put(Scratch::dir('outside') . '/evil.php', '<?php');
        $this->writeBare([
            'name' => 'escape',
            'entrypoints' => ['channel' => ['../' . basename(dirname($outside)) . '/evil.php', $outside]],
        ]);

        $this->assertSame([], PluginManifest::fromDir($this->dir)?->entrypoints() ?? []);
        Scratch::remove(dirname($outside));
    }

    public function testASymlinkPlantedInsideThePluginPointingOutIsDropped(): void
    {
        $outside = Scratch::put(Scratch::dir('linked') . '/evil.php', '<?php');
        symlink($outside, $this->dir . '/link.php');
        $this->writeBare(['name' => 'linked', 'entrypoints' => ['channel' => 'link.php']]);

        $this->assertSame([], PluginManifest::fromDir($this->dir)?->entrypoints() ?? []);
        Scratch::remove(dirname($outside));
    }

    public function testANulByteInAnEntrypointFileIsDroppedWithoutAbortingTheRead(): void
    {
        Scratch::put($this->dir . '/src/Ok.php', '<?php');
        $this->writeBare([
            'name' => 'nul',
            'entrypoints' => ['channel' => [
                ['file' => "src/Chan\u{0}.php", 'class' => 'Demo\\Chan'],
                ['file' => 'src/Ok.php', 'class' => 'Demo\\Ok'],
            ]],
        ]);

        $entries = PluginManifest::fromDir($this->dir)?->entrypoints()['channel'] ?? [];

        $this->assertCount(1, $entries, 'realpath() throws ValueError on a NUL byte; the item must be refused, not thrown');
        $this->assertSame('Demo\\Ok', $entries[0]->class);
    }

    public function testAMissingEntrypointFileIsDropped(): void
    {
        $this->writeBare(['name' => 'gone', 'entrypoints' => ['channel' => 'src/Missing.php']]);

        $this->assertSame([], PluginManifest::fromDir($this->dir)?->entrypoints() ?? []);
    }

    public function testAStringItemNamesNoClass(): void
    {
        Scratch::put($this->dir . '/src/Ch.php', '<?php');
        $this->writeBare(['name' => 'bare-item', 'entrypoints' => ['channel' => 'src/Ch.php']]);

        $entry = PluginManifest::fromDir($this->dir)?->entrypoints()['channel'][0] ?? null;

        $this->assertInstanceOf(Entrypoint::class, $entry);
        $this->assertSame('', $entry->class);
    }

    public function testADuplicatePathUnderOneKindKeepsTheFirstDeclaration(): void
    {
        Scratch::put($this->dir . '/src/Ch.php', '<?php');
        $this->writeBare([
            'name' => 'dupes',
            'entrypoints' => ['channel' => [
                ['file' => 'src/Ch.php', 'class' => 'First'],
                ['file' => 'src/Ch.php', 'class' => 'Second'],
            ]],
        ]);

        $entries = PluginManifest::fromDir($this->dir)?->entrypoints()['channel'] ?? [];

        $this->assertCount(1, $entries);
        $this->assertSame('First', $entries[0]->class);
    }

    public function testEntrypointsAreKeyedByKind(): void
    {
        Scratch::put($this->dir . '/src/A.php', '<?php');
        Scratch::put($this->dir . '/src/B.php', '<?php');
        $this->writeBare([
            'name' => 'kinds',
            'entrypoints' => [
                'channel' => ['file' => 'src/A.php', 'class' => 'A'],
                'subscriber' => ['file' => 'src/B.php', 'class' => 'B'],
            ],
        ]);

        $entrypoints = PluginManifest::fromDir($this->dir)?->entrypoints() ?? [];

        $this->assertSame(['channel', 'subscriber'], array_keys($entrypoints));
        $this->assertSame('subscriber', $entrypoints['subscriber'][0]->kind);
    }
}
