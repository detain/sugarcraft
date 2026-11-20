<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Loader;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Loader\PluginCandidate;
use SugarCraft\Plugins\Manifest\PluginManifest;
use SugarCraft\Plugins\Tests\Support\Scratch;

final class PluginCandidateTest extends TestCase
{
    public function testTheCandidateExposesItsNameAndDirectory(): void
    {
        $candidate = new PluginCandidate('demo', '/plugins/demo', static fn (): ?PluginManifest => null, trustable: true);

        $this->assertSame('demo', $candidate->name);
        $this->assertSame('/plugins/demo', $candidate->dir);
        $this->assertTrue($candidate->trustable);
    }

    public function testTheManifestIsReadOnlyWhenAskedFor(): void
    {
        $dir = Scratch::dir('candidate');
        Scratch::put($dir . '/plugin.json', '{"name":"demo","entrypoints":{"channel":"src/Missing.php"}}');
        $reads = 0;
        $candidate = new PluginCandidate('demo', $dir, static function () use ($dir, &$reads): ?PluginManifest {
            $reads++;

            return PluginManifest::fromDir($dir);
        }, trustable: true);

        $this->assertSame(0, $reads, 'constructing a candidate reads nothing');
        $this->assertNotNull($candidate->manifest());
        $this->assertSame(1, $reads);
        $this->assertSame([], $candidate->entrypoints());

        Scratch::remove($dir);
    }

    public function testAnAbsentManifestYieldsNoEntrypoints(): void
    {
        $candidate = new PluginCandidate('none', '/nowhere', static fn (): ?PluginManifest => null, trustable: true);

        $this->assertNull($candidate->manifest());
        $this->assertSame([], $candidate->entrypoints());
    }
}
