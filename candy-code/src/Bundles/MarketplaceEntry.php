<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

/**
 * ONE plugin row of a marketplace manifest, with the provenance the row can
 * honestly carry: where it came from (source type, repo, URL, or a path
 * resolved inside the checkout) and — once the registry stamps it — the pinned
 * commit the checkout was read at.
 *
 * CC-new #12 (plan_comparison.md §3, C-EXT-4.a): the marketplace vocabulary is
 * Claude's, but no Go upstream implements it, so there is nothing to mirror
 * and no port cite to make. What IS mirrored is this package's own rules: the
 * name grammar is the bundle-name rule
 * {@see \SugarCraft\Code\Cli\Subcommands} guards installs with (see
 * {@see isValidName()}), and an entry is a value, not a record — it never
 * touches disk. The file half of the vocabulary is
 * {@see Marketplace} (parsing) and {@see MarketplaceRegistry} (the store).
 *
 * The provenance fields are why install gating can refuse: an entry from a
 * marketplace with no pinned commit is refused at
 * {@see MarketplaceRegistry::installGate()}, and the sha a row does carry
 * rides to {@see BundleSource} so an installed bundle keeps a record of the
 * commit it came from.
 */
final class MarketplaceEntry
{
    /** Source spellings the manifest format allows. */
    public const SOURCE_PATH = 'path';

    public const SOURCE_GITHUB = 'github';

    public const SOURCE_URL = 'url';

    public const SOURCE_GIT = 'git';

    /**
     * @param string $name bundle name as the manifest spells it (already validated)
     * @param string $sourceType one of the SOURCE_* constants
     * @param string $path resolved checkout path for a {@see SOURCE_PATH} row; '' for remote rows
     * @param string $repo `owner/name` for a {@see SOURCE_GITHUB} row; '' otherwise
     * @param string $url https clone URL for {@see SOURCE_URL}/{@see SOURCE_GIT} rows; '' otherwise
     * @param string $sha pinned commit, '' until {@see withPinnedSha()} stamps it
     * @param list<string> $keywords lowercased keywords (the manifest's `keywords`, else its `tags`)
     */
    private function __construct(
        public readonly string $name,
        public readonly string $sourceType,
        public readonly string $path = '',
        public readonly string $repo = '',
        public readonly string $url = '',
        public readonly string $sha = '',
        public readonly string $description = '',
        public readonly string $version = '',
        public readonly string $category = '',
        public readonly array $keywords = [],
    ) {
    }

    /** A relative-path source resolved inside the checkout the manifest lives in. */
    public static function local(string $name, string $path, string $description = '', string $version = ''): self
    {
        return new self($name, self::SOURCE_PATH, $path, description: $description, version: $version);
    }

    /** A `{source: github, repo: owner/name}` remote row. */
    public static function github(string $name, string $repo, string $description = '', string $version = ''): self
    {
        return new self($name, self::SOURCE_GITHUB, repo: $repo, description: $description, version: $version);
    }

    /** An `{source: url, url: https://...}` remote row. */
    public static function remoteUrl(string $name, string $url, string $description = '', string $version = ''): self
    {
        return new self($name, self::SOURCE_URL, url: $url, description: $description, version: $version);
    }

    /** A `{source: git, url: https://...}` remote row — same floor, named differently. */
    public static function remoteGit(string $name, string $url, string $description = '', string $version = ''): self
    {
        return new self($name, self::SOURCE_GIT, url: $url, description: $description, version: $version);
    }

    /**
     * The bundle-name rule, in one place: what {@see Marketplace} validates
     * manifest rows with and what `candy-code bundles install` guards its
     * destination directory with. Dots, dashes and underscores are legal;
     * the traversal shapes are not.
     */
    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $name) && $name !== '.' && $name !== '..';
    }

    /** The source key the allowlist checks against: the repo, or the URL. */
    public function sourceKey(): string
    {
        return strtolower($this->repo !== '' ? $this->repo : $this->url);
    }

    /**
     * This entry with the registry's pinned commit stamped onto it — the only
     * mutation the type allows, because a pin is added by the trust flow, not
     * by the manifest bytes.
     */
    public function withPinnedSha(string $sha): self
    {
        return new self(
            $this->name,
            $this->sourceType,
            $this->path,
            $this->repo,
            $this->url,
            $sha,
            $this->description,
            $this->version,
            $this->category,
            $this->keywords,
        );
    }

    /** This entry with its category set (manifests spell it in several places). */
    public function withCategory(string $category): self
    {
        return new self(
            $this->name,
            $this->sourceType,
            $this->path,
            $this->repo,
            $this->url,
            $this->sha,
            $this->description,
            $this->version,
            $category,
            $this->keywords,
        );
    }

    /** This entry with its keyword list replaced. */
    public function withKeywords(array $keywords): self
    {
        return new self(
            $this->name,
            $this->sourceType,
            $this->path,
            $this->repo,
            $this->url,
            $this->sha,
            $this->description,
            $this->version,
            $this->category,
            $keywords,
        );
    }
}
