<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Loader;

use SugarCraft\Plugins\Manifest\Entrypoint;
use SugarCraft\Plugins\Manifest\PluginManifest;

/**
 * One installed plugin the host may consider loading: its name, its directory,
 * and a way to read its manifest.
 *
 * The manifest is read lazily through the closure, so a name the user has not
 * trusted never has its `plugin.json` opened: the loader asks only for names
 * that pass the trust list. The host builds one candidate per discovered
 * plugin; the reader stays in the host's hands.
 */
final readonly class PluginCandidate
{
    /**
     * @param \Closure(): ?PluginManifest $manifest reads the manifest, or answers null when it has none
     */
    public function __construct(
        public string $name,
        public string $dir,
        private \Closure $manifest,
    ) {
    }

    /** The manifest, read on each call; the loader calls this once per candidate. */
    public function manifest(): ?PluginManifest
    {
        return ($this->manifest)();
    }

    /** @return array<string, list<Entrypoint>> */
    public function entrypoints(): array
    {
        return $this->manifest()?->entrypoints() ?? [];
    }
}
