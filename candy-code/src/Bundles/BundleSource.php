<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

use SugarCraft\Plugins\Provenance\SourceRecord;

/**
 * The marketplace install provenance record: `<bundle>/.candy-code-source.json`.
 *
 * CC-new #12 (plan_comparison.md §3, C-EXT-4.a): no upstream spells this
 * file, so the shape is ours — `{marketplace, name, sourceType, repo, url,
 * sha, installedAt}`. It answers the question `bundles list` can otherwise
 * never answer: was this directory cloned here by an install, or did it
 * arrive with the repository? A bundle with no record was.
 *
 * The record value and its read/write live in {@see SourceRecord} (sugar-plugins),
 * which takes the file name and the validity rules as arguments. This class is
 * the candy-code side of that: it names the file, supplies the marketplace
 * vocabulary (name rule, source types), and builds and describes records from
 * {@see MarketplaceEntry} rows.
 *
 * The trust direction of the READ still applies: a record read back through
 * {@see read()} is a claim the directory makes. Only {@see fromEntry()} builds a
 * record from this build's own install flow.
 */
final class BundleSource
{
    /** Where the record lives inside an installed bundle directory. */
    public const RECORD_RELATIVE_PATH = '.candy-code-source.json';

    /** The record this build writes when it installs $entry from $marketplaceName. */
    public static function fromEntry(MarketplaceEntry $entry, string $marketplaceName): SourceRecord
    {
        return SourceRecord::fromTrustedRecord(
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
     * (a home, or a checkout): a record path that resolves outside it is refused
     * before the filesystem is asked for bytes.
     */
    public static function read(string $bundleDir, string $boundary): ?SourceRecord
    {
        return SourceRecord::read(
            $bundleDir,
            $boundary,
            self::RECORD_RELATIVE_PATH,
            MarketplaceEntry::isValidName(...),
            MarketplaceRegistry::ROW_SOURCE_TYPES,
        );
    }

    /**
     * Write $record into $bundleDir under the candy-code record name. Null means
     * the record is on disk; any other answer is the failure sentence.
     */
    public static function write(SourceRecord $record, string $bundleDir): ?string
    {
        return $record->write($bundleDir, self::RECORD_RELATIVE_PATH);
    }

    /** The one-line provenance an announce surface prints for this record. */
    public static function describe(SourceRecord $record): string
    {
        $origin = match ($record->sourceType) {
            MarketplaceEntry::SOURCE_GITHUB => 'github:' . $record->repo,
            MarketplaceEntry::SOURCE_URL, MarketplaceEntry::SOURCE_GIT => $record->sourceType . ':' . $record->url,
            default => 'path',
        };
        $short = strlen($record->sha) >= 7 ? substr($record->sha, 0, 7) : $record->sha;

        return $record->marketplace . '/' . $record->name . ' (' . $origin . ($short === '' ? '' : ' @ ' . $short) . ')';
    }
}
