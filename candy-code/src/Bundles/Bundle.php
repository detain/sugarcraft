<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

/**
 * One installed Claude-format plugin bundle: a directory carrying a
 * `.claude-plugin/plugin.json` manifest (a bare top-level `plugin.json` is
 * accepted too — the format's own README shows both shapes).
 *
 * A bundle is a CONTAINER of the surfaces candy-code already imports
 * individually — `skills/`, `commands/`, `agents/`, `.mcp.json` (or the
 * manifest's own `mcp`/`mcpServers` keys), hooks (paths OR an inline `hooks`
 * object) — and
 * this class is only the reader of that layout. Nothing here executes or
 * installs anything: {@see BundleDiscovery} finds bundles on disk, and each
 * surface is mapped onto its own existing loader by the wiring in
 * `Cli\Bootstrap`. Two of the five surfaces are refused by construction and
 * this class is where their SHAPE is pinned so the refusal can be asserted:
 * `hooksPaths()` (bundle hooks are never registered — a launch notice names
 * the decline) and `mcpFile()` (a bundle's MCP declaration is never
 * auto-started — it is offered to the operator through the existing
 * `mcp import` verb).
 *
 * Manifest fields candy-code does not know are ignored, which is what makes a
 * bundle written for the upstream tool load rather than trip.
 */
final class Bundle
{
    /** Where the format's own convention puts the manifest. */
    public const MANIFEST_RELATIVE_PATH = '.claude-plugin/plugin.json';

    /** The tolerated bare shape: `plugin.json` at the bundle root. */
    public const BARE_MANIFEST_RELATIVE_PATH = 'plugin.json';

    /** The directory bundles are installed under, inside home or a project. */
    public const INSTALLED_RELATIVE_PATH = '.candy-code/bundles';

    public function __construct(
        public readonly string $dir,
        public readonly string $name,
        public readonly string $tier,
        public readonly string $version = '',
        public readonly string $description = '',
        /** @param array<string, mixed> the manifest, parsed */
        public readonly array $manifest = [],
        /**
         * The tree whose OWNERSHIP every surface read under this bundle must
         * also satisfy — `$HOME` for a bundle found under the user's own
         * install root, null for a project bundle (the checkout anchor is
         * enough) and for a `pluginPaths` entry (the operator's explicit word,
         * trusted as given). Mirrors the second element of
         * {@see \SugarCraft\Code\Skills\ForeignSkillDiscovery::tiers()}
         * entries: it is what makes "the user's own tree" an established fact
         * rather than a guess about a `$HOME` nobody vouched for.
         */
        public readonly ?string $ownedBy = null,
    ) {
    }

    /**
     * Load the bundle rooted at $dir, or null when $dir is not a readable,
     * parseable bundle. Absent and malformed manifests both answer null —
     * {@see hasManifest()} is how the caller tells "not a bundle" (skip
     * quietly) from "broken bundle" (warn by name).
     */
    public static function fromDir(string $dir, string $tier, ?string $ownedBy = null): ?self
    {
        $manifestPath = self::manifestPath($dir);

        if ($manifestPath === null) {
            return null;
        }

        $raw = @file_get_contents($manifestPath);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $name = trim((string) ($decoded['name'] ?? ''));

        if ($name === '') {
            // The upstream name is cosmetic; the directory is what the
            // operator installed, so it carries the identity when the
            // manifest does not name itself.
            $name = basename($dir);
        }

        return new self(
            dir: $dir,
            name: $name,
            tier: $tier,
            version: trim((string) ($decoded['version'] ?? '')),
            description: trim((string) ($decoded['description'] ?? '')),
            manifest: $decoded,
            ownedBy: $ownedBy,
        );
    }

    /**
     * The parsed manifest of $dir, or [] when there is no readable, decodable
     * one. The installer's name lookup rides this; {@see fromDir()} keeps its
     * own stricter read (no manifest OR no decode means no bundle AT ALL —
     * discovery skips, the installer refuses with a named reason).
     *
     * @return array<string, mixed>
     */
    public static function readManifest(string $dir): array
    {
        $manifestPath = self::manifestPath($dir);

        if ($manifestPath === null) {
            return [];
        }

        $raw = @file_get_contents($manifestPath);

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** True when $dir carries either manifest shape, readable or not. */
    public static function hasManifest(string $dir): bool
    {
        return self::manifestPath($dir) !== null;
    }

    public static function manifestPath(string $dir): ?string
    {
        foreach ([self::MANIFEST_RELATIVE_PATH, self::BARE_MANIFEST_RELATIVE_PATH] as $relative) {
            if (is_file($dir . '/' . $relative)) {
                return $dir . '/' . $relative;
            }
        }

        return null;
    }

    /** @return list<string> the `skills` directories this bundle contributes, existing only */
    public function skillsPaths(): array
    {
        return $this->surfacePaths('skills', 'skills');
    }

    /** @return list<string> the `commands` directories (markdown command trees), existing only */
    public function commandsPaths(): array
    {
        return $this->surfacePaths('commands', 'commands');
    }

    /** @return list<string> the `agents` directories (preset markdown), existing only */
    public function agentsPaths(): array
    {
        return $this->surfacePaths('agents', 'agents');
    }

    /**
     * The bundle's MCP config file, when it ships or declares one. DECLINED AT
     * THE WIRE: discovery surfaces the path so the launch notice can name it,
     * and the operator may import it deliberately through `candy-code mcp
     * import`; nothing in candy-code starts servers from here.
     *
     * Reads BOTH manifest spellings Claude-format bundles use in the wild —
     * `mcp` and `mcpServers` (C-EXT-7): a string or list names files, and an
     * INLINE server object (a non-path value) answers the manifest path
     * itself, which is where the declaration actually lives.
     */
    public function mcpFile(): ?string
    {
        foreach ([...$this->declared('mcp'), ...$this->declared('mcpServers')] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        $default = $this->dir . '/.mcp.json';

        if (is_file($default)) {
            return $default;
        }

        if ($this->hasInlineSurface('mcpServers')) {
            return self::manifestPath($this->dir);
        }

        return null;
    }

    /**
     * Every path in the bundle that would register hooks — a `hooks` manifest
     * entry, an INLINE `hooks` object, or the conventional `hooks/` directory.
     * DECLINED AT THE WIRE: bundle hooks are never registered with the hook
     * manager; a launch notice names the decline so the operator learns the
     * bundle is not running whole rather than wondering why its statusline
     * vanished. An inline declaration answers the manifest path, because the
     * hooks ride inside plugin.json itself (C-EXT-7).
     *
     * @return list<string>
     */
    public function hooksPaths(): array
    {
        $paths = $this->declared('hooks');

        foreach (['/hooks', '/hooks.json', '/hooks/hooks.json'] as $suffix) {
            $candidate = $this->dir . $suffix;

            if ((str_ends_with($suffix, '.json') ? is_file($candidate) : is_dir($candidate))
                && !\in_array($candidate, $paths, true)) {
                $paths[] = $candidate;
            }
        }

        if ($this->hasInlineSurface('hooks')) {
            $manifest = self::manifestPath($this->dir);

            if ($manifest !== null && !\in_array($manifest, $paths, true)) {
                $paths[] = $manifest;
            }
        }

        return $paths;
    }

    /**
     * @return list<string> existing directories named by one manifest key,
     *                      falling back to the conventional `<dir>/<fallback>`
     */
    private function surfacePaths(string $manifestKey, string $fallback): array
    {
        $declared = $this->declared($manifestKey);

        if ($declared !== []) {
            return array_values(array_filter($declared, is_dir(...)));
        }

        $conventional = $this->dir . '/' . $fallback;

        return is_dir($conventional) ? [$conventional] : [];
    }

    /**
     * Resolve one manifest field to absolute paths, tolerating the shapes the
     * format uses in the wild: a single string, or a list of strings. Unknown
     * or non-path values yield nothing.
     *
     * @return list<string>
     */
    private function declared(string $key): array
    {
        $value = $this->manifest[$key] ?? null;
        $entries = match (true) {
            \is_string($value) => [$value],
            \is_array($value) => array_values(array_filter($value, 'is_string')),
            default => [],
        };

        $paths = [];

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $paths[] = str_starts_with($entry, '/') ? $entry : $this->dir . '/' . $entry;
        }

        return $paths;
    }

    /**
     * True when a manifest key carries an INLINE surface — a value that is not
     * a path (or a list of paths) but the declaration itself: Claude-format
     * bundles ship `hooks` and `mcpServers` as objects inside plugin.json.
     * A plain string, a list of all-strings (paths), null, '' and false all
     * answer false; an object, or a list holding any non-string, answers true.
     */
    private function hasInlineSurface(string $key): bool
    {
        $value = $this->manifest[$key] ?? null;

        if (\is_array($value)) {
            return \array_filter($value, 'is_string') !== $value;
        }

        return !\is_string($value) && $value !== null && $value !== false;
    }
}
