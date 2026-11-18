<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Support;

use SugarCraft\Plugins\Loader\PluginCandidate;
use SugarCraft\Plugins\Manifest\PluginManifest;

/**
 * Builds on-disk plugins for the loader tests: a manifest, and the PHP files it names.
 *
 * Class names carry a per-call tag so two tests in one process never declare
 * the same class, and each file is a real `require_once` target.
 */
final class Plugins
{
    private const PLUGIN_SOURCE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace %1$s;

        use SugarCraft\Plugins\Plugin;
        use SugarCraft\Plugins\PluginContext;

        final class %2$s implements Plugin
        {
            public function name(): string
            {
                return '%3$s';
            }

            public function start(PluginContext $context): void
            {
            }

            public function dispose(): void
            {
            }
        }

        PHP;

    private static int $counter = 0;

    /** A fresh tag, unique within this process, for a namespace and class pair. */
    public static function tag(): string
    {
        return 'T' . \bin2hex(\random_bytes(4)) . 'n' . (++self::$counter);
    }

    /** A PHP file declaring a Plugin whose class is $namespace\$class. */
    public static function pluginSource(string $namespace, string $class, string $name): string
    {
        return \sprintf(self::PLUGIN_SOURCE, $namespace, $class, $name);
    }

    /**
     * A candidate reading the manifest in $dir, and counting its reads.
     *
     * @param int $reads incremented on every manifest read, so a test can prove none happened
     */
    public static function candidate(string $name, string $dir, int &$reads = 0): PluginCandidate
    {
        return new PluginCandidate(
            name: $name,
            dir: $dir,
            manifest: static function () use ($dir, &$reads): ?PluginManifest {
                $reads++;

                return PluginManifest::fromDir($dir);
            },
        );
    }
}
