<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * The install provenance record a marketplace install leaves behind:
 * `<bundle>/.candy-code-source.json` — which marketplace the bundle came
 * from, what source it was fetched under, and the pinned commit it landed at.
 *
 * CC-new #12 (plan_comparison.md §3, C-EXT-4.a): no upstream spells this
 * file, so the shape is ours — `{marketplace, name, sourceType, repo, url,
 * sha, installedAt}`. It answers the question `bundles list` can otherwise
 * never answer: was this directory cloned here by an install, or did it
 * arrive with the repository? A bundle with no record was.
 *
 * The trust direction of the READ matters: the record lives INSIDE the bundle
 * directory, which for a project-tier bundle is repository-authored content —
 * bytes a clone delivered can claim any provenance they like. So {@see read()}
 * is not a truth oracle; it is a shape-checked view of what a directory
 * CLAIMS, gated only against escaping its boundary (the record is read inside
 * the tree it was named in, never through a link out of it). Whatever consumes
 * it for a decision — a refusal, a removal — must know the difference, and
 * {@see fromTrustedRecord()} is the only constructor that says the bytes came
 * out of this build's own install flow.
 */
final class BundleSource
{
    /** Where the record lives inside an installed bundle directory. */
    public const RECORD_RELATIVE_PATH = '.candy-code-source.json';

    private function __construct(
        public readonly string $marketplace,
        public readonly string $name,
        public readonly string $sourceType,
        public readonly string $repo,
        public readonly string $url,
        public readonly string $sha,
        public readonly int $installedAt,
    ) {
    }

    /** The record this build writes when it installs $entry from $marketplaceName. */
    public static function fromEntry(MarketplaceEntry $entry, string $marketplaceName): self
    {
        return new self(
            $marketplaceName,
            $entry->name,
            $entry->sourceType,
            $entry->repo,
            $entry->url,
            $entry->sha,
            time(),
        );
    }

    /**
     * Read the record a bundle directory carries, or null when there is no
     * readable, well-shaped one. $boundary is the tree the bundle belongs to
     * (a home, or a checkout): a record path that resolves outside it — an
     * absolute or linked-out `.candy-code-source.json` — is refused before
     * the filesystem is asked for bytes.
     */
    public static function read(string $bundleDir, string $boundary): ?self
    {
        $record = $bundleDir . '/' . self::RECORD_RELATIVE_PATH;

        if (!ContainedPath::below($record, $boundary)) {
            return null;
        }

        $raw = @file_get_contents($record);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $marketplace = $decoded['marketplace'] ?? null;
        $name = $decoded['name'] ?? null;
        $sourceType = $decoded['sourceType'] ?? null;

        if (!is_string($marketplace) || !is_string($name) || !MarketplaceEntry::isValidName($name)
            || !is_string($sourceType) || !in_array($sourceType, MarketplaceRegistry::ROW_SOURCE_TYPES, true)) {
            return null;
        }

        return new self(
            $marketplace,
            $name,
            $sourceType,
            is_string($decoded['repo'] ?? null) ? $decoded['repo'] : '',
            is_string($decoded['url'] ?? null) ? $decoded['url'] : '',
            is_string($decoded['sha'] ?? null) ? $decoded['sha'] : '',
            is_int($decoded['installedAt'] ?? null) ? $decoded['installedAt'] : 0,
        );
    }

    /**
     * The same data as {@see read()}, but from bytes this process itself
     * composed — the install flow's in-memory truth, never a re-read of a
     * directory a clone could have planted.
     */
    public static function fromTrustedRecord(string $marketplace, string $name, string $sourceType, string $repo, string $url, string $sha, int $installedAt): self
    {
        return new self($marketplace, $name, $sourceType, $repo, $url, $sha, $installedAt);
    }

    /**
     * Write this record into $bundleDir — 0600, atomically, only into a
     * directory the caller already resolved inside its boundary (the install
     * flow's copy step; this never creates the directory). Null means the
     * record is on disk; any other answer is the failure sentence.
     */
    public function write(string $bundleDir): ?string
    {
        if (!is_dir($bundleDir)) {
            return 'bundle directory "' . $bundleDir . '" does not exist';
        }

        try {
            AtomicJsonFile::new($bundleDir . '/' . self::RECORD_RELATIVE_PATH)
                ->withPermissions(0o600)
                ->write([
                    'marketplace' => $this->marketplace,
                    'name' => $this->name,
                    'sourceType' => $this->sourceType,
                    'repo' => $this->repo,
                    'url' => $this->url,
                    'sha' => $this->sha,
                    'installedAt' => $this->installedAt,
                ]);
        } catch (\Throwable $e) {
            return 'bundle source record could not be written: ' . $e->getMessage();
        }

        return null;
    }

    /** The one-line provenance an announce surface prints for this record. */
    public function describe(): string
    {
        $origin = match ($this->sourceType) {
            MarketplaceEntry::SOURCE_GITHUB => 'github:' . $this->repo,
            MarketplaceEntry::SOURCE_URL, MarketplaceEntry::SOURCE_GIT => $this->sourceType . ':' . $this->url,
            default => 'path',
        };
        $short = strlen($this->sha) >= 7 ? substr($this->sha, 0, 7) : $this->sha;

        return $this->marketplace . '/' . $this->name . ' (' . $origin . ($short === '' ? '' : ' @ ' . $short) . ')';
    }
}
