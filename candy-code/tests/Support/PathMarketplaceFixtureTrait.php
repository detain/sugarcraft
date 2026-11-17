<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use SugarCraft\Code\Cli\NonInteractive;

/**
 * A path-typed marketplace carrying one installable plugin, added through the
 * same `marketplace add` verb a person runs. The bundle suites share it so the
 * fixture cannot drift between them. The using class supplies `$tempDir` and a
 * `verb(string ...)` dispatcher.
 */
trait PathMarketplaceFixtureTrait
{
    /**
     * @param string $sha the full 40-hex commit the marketplace pins itself at
     */
    private function addPathMarketplace(
        string $name,
        string $plugin,
        string $sha = '1111111111222222222233333333334444444444',
    ): string {
        $source = $this->tempDir . '/' . $name . '-src';
        mkdir($source . '/.claude-plugin', 0777, true);
        mkdir($source . '/plugins/' . $plugin . '/.claude-plugin', 0777, true);
        file_put_contents(
            $source . '/plugins/' . $plugin . '/.claude-plugin/plugin.json',
            json_encode(['name' => $plugin, 'version' => '1.0.0']),
        );
        file_put_contents($source . '/plugins/' . $plugin . '/UPSTREAM.txt', 'v1 for ' . $plugin);
        file_put_contents(
            $source . '/.claude-plugin/marketplace.json',
            json_encode(['name' => $name, 'owner' => ['name' => 'op'], 'plugins' => [
                ['name' => $plugin, 'source' => './plugins/' . $plugin],
            ]]),
        );

        mkdir($source . '/.git/refs/heads', 0777, true);
        file_put_contents($source . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($source . '/.git/refs/heads/main', $sha . "\n");

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('marketplace', 'add', $source));

        return $source;
    }
}
