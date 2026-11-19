<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Lang;

/**
 * Keeps Lang::RESERVED in step with every monorepo library's Lang::NAMESPACE.
 * The two plugin bundles are skipped by name as well as by their plugin.json
 * marker: they are not libraries, and a reserved namespace would refuse their
 * own catalog. Any other directory is checked even if it carries a plugin.json,
 * so a future library cannot escape the check by adding one.
 *
 * T is first-registration-wins, so a plugin that claimed a library's namespace
 * before the library registered would silently shadow that catalog. Reading
 * the sources keeps this honest without loading every library.
 */
final class ReservedNamespaceDriftTest extends TestCase
{
    /** Plugin bundles whose catalogs are claimed through Lang::claim, not reserved. */
    private const PLUGIN_BUNDLES = ['candy-discord', 'candy-msteams'];

    public function testEveryLibraryNamespaceIsReserved(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/sugar-core/src/Lang.php')) {
            $this->markTestSkipped('Split-repo clone: the monorepo libraries are not beside sugar-plugins.');
        }

        $namespaces = [];
        foreach (glob($root . '/{sugar,candy}-*/src/Lang.php', GLOB_BRACE) ?: [] as $file) {
            $lib = basename(dirname($file, 2));
            if (in_array($lib, self::PLUGIN_BUNDLES, true) && is_file(dirname($file, 2) . '/plugin.json')) {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (preg_match("/const\s+NAMESPACE\s*=\s*'([^']+)'/", $source, $match) === 1) {
                $namespaces[$match[1]] = $lib;
            }
        }

        // A glob that matched nothing would make the check below vacuously green.
        $this->assertArrayHasKey('core', $namespaces);
        $this->assertArrayHasKey('code', $namespaces);

        $reserved = (new \ReflectionClassConstant(Lang::class, 'RESERVED'))->getValue();
        $missing = [];
        foreach ($namespaces as $namespace => $lib) {
            if (!in_array($namespace, $reserved, true)) {
                $missing[] = "$namespace ($lib)";
            }
        }

        $this->assertSame([], $missing, 'Library NAMESPACE values missing from Lang::RESERVED.');
    }
}
