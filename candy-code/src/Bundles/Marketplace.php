<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

use SugarCraft\Code\Support\ContainedPath;

/**
 * A parsed `.claude-plugin/marketplace.json` — the catalog file a marketplace
 * checkout carries, listing the plugins that marketplace offers.
 *
 * CC-new #12 (plan_comparison.md §3, C-EXT-4.a): Claude's marketplace format,
 * with no Go upstream to port, so no `Mirrors` cite applies. Parsing is the
 * only job this file does: it turns manifest bytes into a name, an owner, and
 * a list of {@see MarketplaceEntry} rows, refusing every row whose name or
 * source could not be honoured safely, and nothing in here installs, spawns,
 * or writes anything. The store of added marketplaces is
 * {@see MarketplaceRegistry}; the fetch leg belongs to the install verb.
 *
 * WHAT THE FORMAT ACTUALLY CARRIES, per the manifest shape this parser reads:
 * `name`, an `owner` (string, or an object with a `name`), and `plugins[]` —
 * each with a `name`, a `source`, and the optional `description`, `version`,
 * `keywords` (or `tags`), and `category`. A `source` is either a string path
 * relative to the checkout, or an object `{source: github, repo}` /
 * `{source: url|git, url}`. Unknown keys are ignored (format drift must not
 * lose the catalog); unknown SHAPES are refused row by row, because a row
 * whose name or source cannot be honoured is a row an installer must not see.
 *
 * THE CONTAINMENT, stated exactly: relative sources are resolved through
 * {@see ContainedPath::below()} against the checkout root the manifest was
 * read from, and both the manifest file itself and every relative source
 * must resolve STRICTLY inside that checkout. An absolute path is refused
 * without even asking the filesystem; a `../` walk, an absolute symlink, and
 * a symlink out of the tree all fail the same compare. That gate bounds
 * WHERE the bytes are; it does not vouch for them — the whole catalog is
 * network-fetched content, which is why reading it grants nothing and
 * {@see MarketplaceRegistry::installGate()} is the thing that decides.
 */
final class Marketplace
{
    /** Where a marketplace keeps its catalog inside a checkout. */
    public const MARKETPLACE_RELATIVE_PATH = '.claude-plugin/marketplace.json';

    /**
     * @param list<MarketplaceEntry> $entries
     * @param list<string> $refusals one sentence per row this manifest could not offer
     */
    private function __construct(
        public readonly string $name,
        public readonly string $owner,
        public readonly string $checkoutRoot,
        public readonly array $entries = [],
        public readonly array $refusals = [],
    ) {
    }

    /**
     * Read and parse the catalog of the checkout at $checkoutRoot.
     *
     * Null is the answer for every unreadable shape: no file, no JSON, an
     * array that is not a catalog (no valid name, no `plugins` list), and a
     * manifest path that will not resolve strictly inside the checkout it was
     * named by — a `.claude-plugin` symlinked elsewhere is a plant, not a
     * marketplace.
     */
    public static function read(string $checkoutRoot): ?self
    {
        $manifest = $checkoutRoot . '/' . self::MARKETPLACE_RELATIVE_PATH;

        if (!ContainedPath::below($manifest, $checkoutRoot)) {
            return null;
        }

        $raw = @file_get_contents($manifest);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? self::fromArray($decoded, $checkoutRoot) : null;
    }

    /**
     * Parse an already-decoded manifest. Split out from {@see read()} so a
     * caller that assembled the array another way (a test, a future API)
     * still runs through the same refusal rules.
     */
    public static function fromArray(array $manifest, string $checkoutRoot): ?self
    {
        $name = $manifest['name'] ?? null;

        if (!is_string($name) || !MarketplaceEntry::isValidName($name)) {
            return null;
        }

        $owner = $manifest['owner'] ?? '';

        if (is_array($owner)) {
            $owner = $owner['name'] ?? '';
        }

        $plugins = $manifest['plugins'] ?? null;

        if (!is_array($plugins)) {
            return null;
        }

        $entries = [];
        $refusals = [];

        foreach ($plugins as $raw) {
            $entry = self::entryFrom($raw, $checkoutRoot, $refusals);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return new self(
            $name,
            is_string($owner) ? trim($owner) : '',
            $checkoutRoot,
            $entries,
            $refusals,
        );
    }

    /** The entry for a plugin name, if this catalog offers one. */
    public function entry(string $entryName): ?MarketplaceEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $entryName) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * One row of `plugins[]`, or null with the reason appended to
     * $refusals. The refusals are named by the row the manifest spelled, so
     * a marketplace with one bad row still offers every row that is sound.
     */
    private static function entryFrom(mixed $raw, string $checkoutRoot, array &$refusals): ?MarketplaceEntry
    {
        if (!is_array($raw)) {
            return null;
        }

        $name = $raw['name'] ?? null;

        if (!is_string($name) || !MarketplaceEntry::isValidName($name)) {
            $refusals[] = 'plugin ' . json_encode($name) . ' was refused: not a valid bundle name';

            return null;
        }

        $source = $raw['source'] ?? null;
        $description = is_string($raw['description'] ?? null) ? $raw['description'] : '';
        $version = is_string($raw['version'] ?? null) ? $raw['version'] : '';

        if (is_string($source)) {
            $resolved = self::resolveRelative($source, $checkoutRoot);

            if ($resolved === null) {
                $refusals[] = 'plugin "' . $name . '" was refused: source "'
                    . $source . '" does not resolve inside the checkout';

                return null;
            }

            $entry = MarketplaceEntry::local($name, $resolved, $description, $version);
        } elseif (is_array($source)) {
            $entry = self::entryFromObjectSource($name, $source, $refusals, $description, $version);

            if ($entry === null) {
                return null;
            }
        } else {
            $refusals[] = 'plugin "' . $name . '" was refused: no usable source';

            return null;
        }

        $category = $raw['category'] ?? null;

        if (is_string($category) && $category !== '') {
            $entry = $entry->withCategory($category);
        }

        $keywords = $raw['keywords'] ?? $raw['tags'] ?? null;

        if (is_array($keywords)) {
            $normalised = [];

            foreach (array_values($keywords) as $keyword) {
                if (is_string($keyword) && trim($keyword) !== '') {
                    $normalised[] = strtolower(trim($keyword));
                }
            }

            $entry = $entry->withKeywords($normalised);
        }

        return $entry;
    }

    /**
     * `{source: github, repo: owner/name}` or
     * `{source: url|git, url: https://...}`. The URL floor is the plan's
     * R13 rule for everything in this family: https only — `ssh://`, `git://`
     * and `http://` have no business naming a bundle source, and neither does
     * a URL with userinfo baked into it.
     */
    private static function entryFromObjectSource(string $name, array $source, array &$refusals, string $description, string $version): ?MarketplaceEntry
    {
        $kind = $source['source'] ?? null;

        if ($kind === 'github') {
            $repo = $source['repo'] ?? null;

            if (is_string($repo) && self::isValidRepo($repo)) {
                return MarketplaceEntry::github($name, $repo, $description, $version);
            }

            $refusals[] = 'plugin "' . $name . '" was refused: a github source needs an "owner/name" repo';

            return null;
        }

        if ($kind === 'url' || $kind === 'git') {
            $url = $source['url'] ?? null;

            if (is_string($url) && self::isHttpsUrl($url)) {
                return $kind === 'git'
                    ? MarketplaceEntry::remoteGit($name, $url, $description, $version)
                    : MarketplaceEntry::remoteUrl($name, $url, $description, $version);
            }

            $refusals[] = 'plugin "' . $name . '" was refused: a ' . $kind . ' source needs an https URL';

            return null;
        }

        $refusals[] = 'plugin "' . $name . '" was refused: unknown source type ' . json_encode($kind);

        return null;
    }

    /**
     * Resolve a string source to a directory STRICTLY inside the checkout.
     * Absolute spellings are refused before the filesystem is asked; the
     * compare is what stops `../` walks and planted links alike, because a
     * link's realpath is its target's home, not the tree that carries it.
     */
    private static function resolveRelative(string $source, string $checkoutRoot): ?string
    {
        if ($source === '' || str_starts_with($source, '/') || str_contains($source, '\\')) {
            return null;
        }

        $candidate = realpath($checkoutRoot . '/' . $source);

        if ($candidate === false || !is_dir($candidate)) {
            return null;
        }

        return ContainedPath::below($candidate, $checkoutRoot) ? $candidate : null;
    }

    /** `owner/name`, both halves carrying a legal bundle name. */
    private static function isValidRepo(string $repo): bool
    {
        $parts = explode('/', $repo);

        return count($parts) === 2
            && MarketplaceEntry::isValidName($parts[0])
            && MarketplaceEntry::isValidName($parts[1]);
    }

    /** https, a non-empty path at minimum, no backslashes, no userinfo. */
    private static function isHttpsUrl(string $url): bool
    {
        if (!str_starts_with($url, 'https://') || str_contains($url, '\\') || str_contains($url, " ")) {
            return false;
        }

        $rest = substr($url, strlen('https://'));

        if ($rest === '') {
            return false;
        }

        $authority = explode('/', $rest, 2)[0];

        return $authority !== '' && !str_starts_with($authority, '@');
    }
}
