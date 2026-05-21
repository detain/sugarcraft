<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * Groups small files by extension or MIME-type category to reduce
 * visual clutter in directory listings.
 *
 * Files below the byte threshold are collected into typed buckets;
 * large files are returned individually.
 *
 * Mirrors gum's file compaction logic.
 */
final class Compactor
{
    private const DEFAULT_THRESHOLD_BYTES = 1024; // 1 KB

    /** @var array<string, non-empty-string> */
    private const array EXTENSION_TO_CATEGORY = [
        // Images
        'png' => 'images',
        'jpg' => 'images',
        'jpeg' => 'images',
        'gif' => 'images',
        'webp' => 'images',
        'svg' => 'images',
        'ico' => 'images',
        'bmp' => 'images',
        'tiff' => 'images',
        // Documents
        'pdf' => 'docs',
        'doc' => 'docs',
        'docx' => 'docs',
        'xls' => 'docs',
        'xlsx' => 'docs',
        'ppt' => 'docs',
        'pptx' => 'docs',
        'odt' => 'docs',
        'ods' => 'docs',
        'rtf' => 'docs',
        'tex' => 'docs',
        // Code
        'php' => 'code',
        'js' => 'code',
        'ts' => 'code',
        'jsx' => 'code',
        'tsx' => 'code',
        'py' => 'code',
        'rb' => 'code',
        'go' => 'code',
        'rs' => 'code',
        'java' => 'code',
        'c' => 'code',
        'cpp' => 'code',
        'h' => 'code',
        'hpp' => 'code',
        'cs' => 'code',
        'swift' => 'code',
        'kt' => 'code',
        'scala' => 'code',
        'lua' => 'code',
        'r' => 'code',
        'sh' => 'code',
        'bash' => 'code',
        'zsh' => 'code',
        'fish' => 'code',
        'ps1' => 'code',
        'sql' => 'code',
        'html' => 'code',
        'css' => 'code',
        'scss' => 'code',
        'less' => 'code',
        'json' => 'code',
        'xml' => 'code',
        'yaml' => 'code',
        'yml' => 'code',
        'toml' => 'code',
        'ini' => 'code',
        'env' => 'code',
        'md' => 'code',
        'markdown' => 'code',
        // Audio
        'mp3' => 'audio',
        'wav' => 'audio',
        'flac' => 'audio',
        'ogg' => 'audio',
        'm4a' => 'audio',
        'aac' => 'audio',
        'wma' => 'audio',
        // Video
        'mp4' => 'video',
        'mkv' => 'video',
        'avi' => 'video',
        'mov' => 'video',
        'wmv' => 'video',
        'flv' => 'video',
        'webm' => 'video',
        // Archives
        'zip' => 'archives',
        'tar' => 'archives',
        'gz' => 'archives',
        'bz2' => 'archives',
        'xz' => 'archives',
        '7z' => 'archives',
        'rar' => 'archives',
        'tgz' => 'archives',
        // Data
        'csv' => 'data',
        'tsv' => 'data',
        'db' => 'data',
        'sqlite' => 'data',
        'mdb' => 'data',
        // Config
        'conf' => 'config',
        'cfg' => 'config',
        'properties' => 'config',
    ];

    /**
     * @param positive-int $thresholdBytes Files strictly below this size
     *                                       are candidates for compaction.
     *                                       Default: 1024 (1 KB).
     * @param positive-int $maxPerGroup Maximum number of small files to bucket
     *                                  together before starting a new bucket.
     */
    public function __construct(
        private readonly int $thresholdBytes = self::DEFAULT_THRESHOLD_BYTES,
        private readonly int $maxPerGroup = 50,
    ) {}

    /**
     * Compact a list of file paths.
     *
     * @param list<string> $paths Absolute file paths.
     * @return list<CompactedGroup> Groups of small files + individual large files.
     */
    public function compact(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        // Partition into small vs large
        $small = [];
        $large = [];
        foreach ($paths as $p) {
            if (is_file($p) && filesize($p) < $this->thresholdBytes) {
                $small[] = $p;
            } else {
                $large[] = $p;
            }
        }

        // Bucket small files by category
        $buckets = [];
        $bucketCounters = []; // track sub-bucket index per category
        foreach ($small as $p) {
            $category = $this->categoryFor($p);
            if (!isset($buckets[$category])) {
                $buckets[$category] = [];
                $bucketCounters[$category] = 0;
            }
            $buckets[$category][] = $p;

            // Split oversized buckets
            if (count($buckets[$category]) >= $this->maxPerGroup) {
                $bucket = $buckets[$category];
                $groups = array_chunk($bucket, $this->maxPerGroup);
                // Last chunk stays in bucket; each full chunk becomes a sub-bucket
                $lastFull = array_pop($groups);
                foreach ($groups as $g) {
                    $idx = $bucketCounters[$category]++;
                    $buckets[$category . '_' . $idx] = $g;
                }
                $buckets[$category] = $lastFull;
            }
        }

        $result = [];
        foreach ($buckets as $cat => $files) {
            $result[] = new CompactedGroup($cat, $files, true);
        }
        foreach ($large as $p) {
            $result[] = new CompactedGroup($p, [$p], false);
        }

        return $result;
    }

    /**
     * Return the category name for a file path.
     *
     * @param non-empty-string $path
     * @return non-empty-string
     */
    public function categoryFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        return self::EXTENSION_TO_CATEGORY[$ext] ?? 'other';
    }

    /**
     * Threshold in bytes used for compaction decisions.
     *
     * @return positive-int
     */
    public function threshold(): int
    {
        return $this->thresholdBytes;
    }
}

/**
 * A group of files produced by the Compactor.
 * `$isCompact` is true when this group was formed from small-file bucketing
 * and false when it represents a single large file.
 *
 * @param non-empty-string $label Category label for compact groups, or the
 *                                single file path for large files.
 * @param list<string> $paths Files in this group.
 * @param bool $isCompact True for small-file buckets, false for large files.
 */
final readonly class CompactedGroup
{
    /**
     * @param non-empty-string $label
     * @param list<string> $paths
     * @param bool $isCompact
     */
    public function __construct(
        public string $label,
        public array $paths,
        public bool $isCompact,
    ) {}

    public function count(): int
    {
        return count($this->paths);
    }

    public function totalSize(): int
    {
        $total = 0;
        foreach ($this->paths as $p) {
            if (is_file($p)) {
                $total += (int) filesize($p);
            }
        }
        return $total;
    }
}
