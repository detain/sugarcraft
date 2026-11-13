<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests;

use CandyCode\Bundles\MsTeams\MsTeamsChannel;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Channels\ChannelInterface;

/**
 * msteams-12: the manifest IS the loader contract — the P1 trust door
 * require_once's exactly the entrypoint file named here and instantiates
 * exactly the class named here (AGENTS entrypoint law). Until this pin the
 * contract was only checked from the host side; a bundle-local test keeps
 * the file even if the marketplace weld ever stops covering it (finding
 * candy-msteams-012).
 */
final class PluginManifestTest extends TestCase
{
    /** @return array<string,mixed> */
    private function manifest(): array
    {
        $path = \dirname(__DIR__) . '/plugin.json';
        self::assertFileExists($path, 'the bundle root must carry plugin.json');
        $raw = (string) \file_get_contents($path);
        $data = \json_decode($raw, true);
        self::assertIsArray($data, 'plugin.json must be a JSON object');

        return $data;
    }

    public function testTheManifestNamesTheBundleSlug(): void
    {
        $manifest = $this->manifest();

        self::assertSame('candy-msteams', $manifest['name'] ?? null, 'the manifest name must equal the bundle directory slug');
        self::assertIsString($manifest['version'] ?? null);
        self::assertNotSame('', (string) ($manifest['description'] ?? ''), 'the loader surfaces the description verbatim');
    }

    public function testEveryEntrypointFileExistsAndItsClassResolves(): void
    {
        $manifest = $this->manifest();
        self::assertIsArray($manifest['entrypoints'] ?? null);
        $channel = $manifest['entrypoints']['channel'] ?? null;
        self::assertIsArray($channel, 'the manifest must declare at least the channel entrypoint');

        foreach ($channel as $entry) {
            self::assertIsArray($entry);
            $file = (string) ($entry['file'] ?? '');
            $class = (string) ($entry['class'] ?? '');
            self::assertNotSame('', $file, 'an entrypoint must name its file');
            self::assertNotSame('', $class, 'an entrypoint must name its class');

            $path = \dirname(__DIR__) . '/' . $file;
            self::assertFileExists($path, "entrypoint file {$file} is missing");

            // The entrypoint self-bootstrap law: requiring the file registers
            // the bundle autoloader, after which the named class must exist.
            require_once $path;
            self::assertTrue(
                \class_exists($class, true),
                "entrypoint class {$class} does not resolve after loading {$file}",
            );
            self::assertTrue(
                \is_a($class, ChannelInterface::class, true),
                "entrypoint class {$class} is not a ChannelInterface — the loader would fatal on boot",
            );
        }
    }

    public function testTheChannelEntrypointIsTheClassThisBundleImplements(): void
    {
        $manifest = $this->manifest();
        $entry = $manifest['entrypoints']['channel'][0] ?? [];

        self::assertSame('src/MsTeamsChannel.php', $entry['file'] ?? null);
        self::assertSame(MsTeamsChannel::class, $entry['class'] ?? null, 'the manifest must point at the live entrypoint class');
    }
}
