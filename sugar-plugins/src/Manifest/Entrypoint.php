<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Manifest;

/**
 * One PHP file a plugin manifest names for in-process loading, under a kind.
 *
 * The path is already a contained realpath (see {@see PluginManifest}), and
 * the class is the one the manifest promises. A class is never guessed from
 * the file name; an empty class is refused by the loader by name.
 */
final readonly class Entrypoint
{
    public function __construct(
        public string $kind,
        public string $path,
        public string $class,
    ) {
    }
}
