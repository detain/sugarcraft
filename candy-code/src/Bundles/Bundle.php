<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

use SugarCraft\Code\Support\ContainedPath;

/**
 * One installed Claude-format plugin bundle: a directory carrying a
 * `.claude-plugin/plugin.json` manifest (a bare top-level `plugin.json` is
 * accepted too — the format's own README shows both shapes).
 *
 * A bundle is a CONTAINER of the surfaces candy-code knows how to read —
 * `skills/`, `commands/`, `agents/`, `.mcp.json` (or the manifest's own
 * `mcp`/`mcpServers` keys), hooks (paths OR an inline `hooks` object), and
 * `entrypoints` (per-kind PHP files the manifest names for in-process
 * loading) — and
 * this class is only the reader of that layout. Nothing here executes or
 * installs anything: {@see BundleDiscovery} finds bundles on disk, and each
 * surface is mapped onto its own existing loader by the wiring in
 * `Cli\Bootstrap`. `entrypoints()` keeps that discipline for the one surface
 * whose payload is CODE: the declaration is parsed here, and whether a named
 * file is ever required lives behind the trust gate in the bundle channel
 * loader — an untrusted bundle's entrypoint file is never touched. `hooksPaths()`
 * rides the same user-tier name allow-list: a trusted bundle's hook files
 * register through `Bootstrap::hooks()`, and an untrusted one still declines
 * with a launch notice. One surface is refused by construction and
 * this class is where its SHAPE is pinned so the refusal can be asserted:
 * `mcpFile()` (a bundle's MCP declaration is never
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
     * TRUST-GATED (code-chat §10 P1, owner reversal D1): {@see
     * \SugarCraft\Code\Cli\Bootstrap::hooks()} registers these files for a
     * bundle the user trusted by name, and only there — the entries are raw
     * manifest bytes, so the consumer re-asserts containment, the
     * private-bytes gate and loadability before anything is registered. An
     * untrusted bundle's hooks still decline with a launch notice naming it,
     * so the operator learns the bundle is not running whole rather than
     * wondering why its statusline vanished. An inline declaration answers
     * the manifest path, because the hooks ride inside plugin.json itself
     * (C-EXT-7); that path currently refuses itself at parse — the plugin.json
     * shape is not the hooks-file shape — and the notice says so (recorded
     * seam: an inline-hooks reader is deferred).
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
     * The manifest's `entrypoints` declaration: contributor KIND => the files
     * it names, each with the class it promises. This is a PARSE, not a load
     * — no bytes of any named file are read here. The decision whether an
     * entrypoint is ever required lives behind the trust gate of the bundle
     * channel loader, which refuses an untrusted bundle before its file is
     * touched; this method only answers what the manifest itself names.
     *
     * The law this shape serves: NOTHING IS LOADED THAT NO MANIFEST NAMES —
     * there is no directory sniffing. A top-level LIST (entries carrying no
     * kind) therefore answers nothing: an un-kinded declaration cannot be
     * dispatched, and guessing a kind would be the sniffing the law forbids.
     *
     * Shapes tolerated from the wild, mirroring {@see declared()}'s: per
     * kind, a single path string, a list of path strings, an object with
     * `file` (plus optional `class`), or a list of either. A path resolves
     * like every other manifest path (a leading '/' kept, anything else
     * relative to the bundle dir); an entry whose file is missing, is not a
     * file, or whose realpath falls outside the bundle dir — including
     * through a planted symlink — is DROPPED here rather than trusted later.
     * Unknown kinds pass through the reader: the registry refuses a kind no
     * consumer serves, and that refusal names it. The same realpath is kept
     * ONCE per kind however many shapes declare it — first declaration
     * wins, a repeat cannot re-class it — and a kind whose every declaration
     * fails to resolve disappears from the answer whole: there is nothing
     * left in it to dispatch.
     *
     * @return array<string, list<array{path: string, class: string}>>
     */
    public function entrypoints(): array
    {
        $declared = $this->manifest['entrypoints'] ?? null;

        if (!\is_array($declared) || array_is_list($declared)) {
            return [];
        }

        $kinds = [];

        foreach ($declared as $kind => $value) {
            $kind = \is_string($kind) ? trim($kind) : '';

            if ($kind === '') {
                continue;
            }

            foreach ($this->entrypointItems($value) as $item) {
                $path = $this->entrypointPath($item);

                if ($path === null) {
                    continue;
                }

                $entry = ['path' => $path, 'class' => $this->entrypointClass($item)];

                if (!\in_array($path, array_column($kinds[$kind] ?? [], 'path'), true)) {
                    $kinds[$kind][] = $entry;
                }
            }
        }

        return $kinds;
    }

    /**
     * One `entrypoints` kind value shaped into a list of items: a path
     * string, an object, or a list of either. A nested map (a list-less,
     * string-keyed array that is not one `{file, class}` shape) rides the
     * object arm and dies at the missing-`file` door — fail-closed, never
     * guessed into a second kind level.
     *
     * @return list<array<string, mixed>|string>
     */
    private function entrypointItems(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $items = [];

            foreach ($value as $item) {
                if (\is_string($item) || \is_array($item)) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        return [$value];
    }

    /**
     * The existing, contained realpath of one entrypoint item's `file`, or
     * null when it names nothing loadable: an empty or non-string file, a
     * miss on disk, a directory, or a path escaping the bundle dir. The
     * escape door is ContainedPath::below() comparing REALPATHS, so `..`
     * hops, absolute paths outside the bundle, and a symlink planted inside
     * pointing out all answer null before any file is opened.
     *
     * @param array<string, mixed>|string $item
     */
    private function entrypointPath(array|string $item): ?string
    {
        $file = trim(\is_string($item) ? $item : (string) ($item['file'] ?? ''));

        if ($file === '') {
            return null;
        }

        $candidate = str_starts_with($file, '/') ? $file : $this->dir . '/' . $file;
        $real = realpath($candidate);

        if ($real === false || !is_file($real)) {
            return null;
        }

        return ContainedPath::below($real, $this->dir) ? $real : null;
    }

    /**
     * The class an entrypoint item promises, '' when it names none — a class
     * is NEVER guessed from a file path, and the loader refuses a
     * class-less entry by name rather than inventing one.
     *
     * @param array<string, mixed>|string $item
     */
    private function entrypointClass(array|string $item): string
    {
        return \is_string($item) ? '' : trim((string) ($item['class'] ?? ''));
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
