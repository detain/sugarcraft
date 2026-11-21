<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Provenance;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Core\Util\PathJail;

/**
 * The install provenance record a marketplace install leaves inside a bundle
 * directory: which marketplace the bundle came from, what source it was fetched
 * under, and the pinned commit it landed at.
 *
 * The record's file name belongs to the host and is passed in on every call.
 * Install trees already carry records under the name their host chose, so the
 * name is data here, not a constant. The host also owns what counts as a valid
 * bundle name and a valid source type, and passes both checks in.
 *
 * The trust direction of the READ matters: the record lives INSIDE the bundle
 * directory, which for a project-tier bundle is repository-authored content, so
 * bytes a clone delivered can claim any provenance they like. {@see read()} is
 * not a truth oracle; it is a shape-checked view of what a directory CLAIMS,
 * gated only against escaping its boundary. Whatever consumes it for a decision
 * must know the difference, and {@see fromTrustedRecord()} is the only
 * constructor that says the bytes came out of the host's own install flow.
 */
final class SourceRecord
{
    private function __construct(
        public readonly string $marketplace,
        public readonly string $name,
        public readonly string $sourceType,
        public readonly string $repo,
        public readonly string $url,
        public readonly string $sha,
        public readonly int $installedAt,
        public readonly string $version = '',
    ) {
    }

    /**
     * The same fields {@see read()} returns, built from values this process
     * composed itself. Only the host's install flow may call it: a re-read of a
     * bundle directory must go through {@see read()}, never through this.
     *
     * $version is the resolved version the install landed at. It is optional so
     * a host with no version to report (candy-code's marketplace installs) can
     * leave it empty; a record written before the field existed reads back as ''.
     */
    public static function fromTrustedRecord(string $marketplace, string $name, string $sourceType, string $repo, string $url, string $sha, int $installedAt, string $version = ''): self
    {
        return new self($marketplace, $name, $sourceType, $repo, $url, $sha, $installedAt, $version);
    }

    /**
     * Read the record named $fileName inside $bundleDir, or null when there is no
     * readable, well-shaped one. $boundary is the tree the bundle belongs to: a
     * record path that resolves outside it (an absolute or linked-out file) is
     * refused before the filesystem is asked for bytes.
     *
     * @param callable(string): bool $isValidName the host's bundle-name rule
     * @param list<string> $sourceTypes the source types the host accepts
     */
    public static function read(string $bundleDir, string $boundary, string $fileName, callable $isValidName, array $sourceTypes): ?self
    {
        $record = $bundleDir . '/' . $fileName;

        if (!PathJail::below($record, $boundary)) {
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

        if (!is_string($marketplace) || !is_string($name) || !$isValidName($name)
            || !is_string($sourceType) || !in_array($sourceType, $sourceTypes, true)) {
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
            is_string($decoded['version'] ?? null) ? $decoded['version'] : '',
        );
    }

    /**
     * Write this record to $fileName inside $bundleDir: 0600, atomically, and only
     * into a directory the caller already resolved inside its boundary. This never
     * creates the directory. Null means the record is on disk; any other answer is
     * the failure sentence.
     */
    public function write(string $bundleDir, string $fileName): ?string
    {
        if (!is_dir($bundleDir)) {
            return 'bundle directory "' . $bundleDir . '" does not exist';
        }

        try {
            AtomicJsonFile::new($bundleDir . '/' . $fileName)
                ->withPermissions(0o600)
                ->write([
                    'marketplace' => $this->marketplace,
                    'name' => $this->name,
                    'sourceType' => $this->sourceType,
                    'repo' => $this->repo,
                    'url' => $this->url,
                    'sha' => $this->sha,
                    'installedAt' => $this->installedAt,
                    'version' => $this->version,
                ]);
        } catch (\Throwable $e) {
            return 'bundle source record could not be written: ' . $e->getMessage();
        }

        return null;
    }
}
