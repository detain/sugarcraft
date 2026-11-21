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
 *
 * `trustable` is the host's answer to whether this candidate may be loaded by
 * name trust at all. The library treats tier labels as opaque, so it cannot
 * tell a user-tier copy from a project-tier one; a host whose project tier
 * never inherits trust passes false for that tier, and the loader refuses the
 * candidate before reading its manifest. It defaults to false, so a host that
 * predates the flag is refused rather than trusted by omission.
 */
final readonly class PluginCandidate
{
    /**
     * @param \Closure(): ?PluginManifest $manifest  reads the manifest, or answers null when it has none
     * @param bool                        $trustable whether name trust may load this candidate; false refuses it
     */
    public function __construct(
        public string $name,
        public string $dir,
        private \Closure $manifest,
        public bool $trustable = false,
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
