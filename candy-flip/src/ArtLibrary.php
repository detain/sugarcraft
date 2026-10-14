<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

use InvalidArgumentException;
use RuntimeException;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Catalog over a directory of candy-top logo `.ansi` files, named
 * `logo-<slug>[-skinny][-anim]-<16|256|tc>-<WxH>.ansi`.
 *
 * Entries are grouped by slug WITHOUT the `-skinny`/`-anim` suffixes so all
 * variants of one design resolve through one name; the manifest
 * (`logos.jsonl`) is keyed by FILENAME when merging because its `slug`
 * field carries an '-anim' suffix on animated rows and would otherwise
 * split a design in two.
 *
 * Two pieces of state are mutated after construction, both deliberate and
 * documented: {@see mergeManifest()} annotates the description/tags sidecar
 * (a catalog enrichment, not artwork state), and {@see pick()} memoises
 * parsed art per path for the process lifetime — art files are immutable
 * documents, so a cached parse can never go stale under this library.
 */
final class ArtLibrary
{
    /**
     * Depth fallback order when the requested rung is absent: ADJACENT-FIRST
     * on the 16 → 256 → tc colour-depth line. Consumers paint each cell's SGR
     * string verbatim (candy-top's Surface::put writes the stored prefix
     * unchanged), so a request that cannot be met exactly should drift one
     * rung away, not jump two — a 16-colour terminal handed raw tc art prints
     * wrong, while the curated 256 downgrade still lands. From the middle rung
     * either neighbour is one step away; the richer 256→tc leg goes first
     * because up-grading never breaks a terminal that already speaks 256.
     */
    private const DEPTH_CASCADE = [
        'tc' => ['256', '16'],
        '256' => ['tc', '16'],
        '16' => ['256', 'tc'],
    ];

    private const FILE_PATTERN =
        '/^logo-(?<slug>.+?)(?<skinny>-skinny)?(?<anim>-anim)?-(?<depth>16|256|tc)-(?<w>\d+)x(?<h>\d+)\.ansi$/';

    /**
     * @phpstan-type Entry array{slug:string,path:string,depth:string,animated:bool,skinny:bool,width:int,height:int}
     * @phpstan-type Meta array{description:string,tags:list<string>}
     */

    /** @var list<Entry> sorted by path for a deterministic surface */
    private array $entries = [];

    /** @var array<string, string> "slug|skinny|depth|anim|static" => path */
    private array $index = [];

    /** @var array<string, Meta> per-slug manifest enrichment, first matching row wins */
    private array $meta = [];

    /** @var array<string, AnsiArt> process-lifetime parse memo, keyed by path */
    private array $parsed = [];

    private function __construct()
    {
    }

    /**
     * Index every matching art file in `$dir`. Unparsable names are skipped
     * silently — a logo directory accumulates scratch files, and the catalog
     * should degrade to the valid subset rather than fail the whole app.
     *
     * @throws RuntimeException when the directory cannot be listed
     */
    public static function fromDir(string $dir, bool $includeSkinny = false): self
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("ArtLibrary: not a directory: '{$dir}'");
        }
        $names = scandir($dir);
        if ($names === false) {
            throw new RuntimeException("ArtLibrary: cannot list directory '{$dir}'");
        }

        $library = new self();
        sort($names);
        foreach ($names as $name) {
            if (preg_match(self::FILE_PATTERN, $name, $m) !== 1) {
                continue;
            }
            if ($m['skinny'] !== '' && !$includeSkinny) {
                continue;
            }
            $animated = $m['anim'] !== '';
            $skinny = $m['skinny'] !== '';
            $entry = [
                'slug' => $m['slug'],
                'path' => rtrim($dir, '/') . '/' . $name,
                'depth' => $m['depth'],
                'animated' => $animated,
                'skinny' => $skinny,
                'width' => (int) $m['w'],
                'height' => (int) $m['h'],
            ];
            $library->entries[] = $entry;
            // The skinny axis is part of the key: a skinny and a wide render of
            // the same (slug, depth, kind) are different artworks and must
            // never overwrite each other in the index.
            $library->index[$library->key($m['slug'], $skinny, $m['depth'], $animated)] = $entry['path'];
        }

        return $library;
    }

    /** Map a detected terminal colour profile onto this corpus's depth tags. */
    public static function profileTag(ColorProfile $profile): string
    {
        return match ($profile) {
            ColorProfile::TrueColor => 'tc',
            ColorProfile::Ansi256 => '256',
            default => '16', // Ansi, Ascii and NoTty all fall back to the 16-colour renders
        };
    }

    /**
     * Attach per-slug description/tags from a `logos.jsonl` manifest.
     * Tolerant by design: a missing/unreadable file or malformed rows is a
     * no-op, because the manifest is optional human metadata shipped
     * alongside the corpus, never a load-bearing dependency.
     *
     * @return $this for chaining
     */
    public function mergeManifest(string $jsonlPath): self
    {
        if (!is_file($jsonlPath) || !is_readable($jsonlPath)) {
            return $this;
        }
        $lines = file($jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $this;
        }

        // Filename => entry slug, so the manifest's '-anim'-suffixed slug
        // field is never trusted for grouping.
        $byName = [];
        foreach ($this->entries as $entry) {
            $byName[basename($entry['path'])] = $entry['slug'];
        }

        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !isset($row['filename']) || !is_string($row['filename'])) {
                continue;
            }
            $slug = $byName[$row['filename']] ?? null;
            if ($slug === null || isset($this->meta[$slug])) {
                continue; // unknown file (e.g. skinny variant excluded) or already enriched
            }
            $tags = [];
            if (isset($row['tags']) && is_array($row['tags'])) {
                foreach ($row['tags'] as $tag) {
                    if (is_string($tag)) {
                        $tags[] = $tag;
                    }
                }
            }
            $this->meta[$slug] = [
                'description' => isset($row['description']) && is_string($row['description'])
                    ? $row['description']
                    : '',
                'tags' => $tags,
            ];
        }

        return $this;
    }

    /** @return list<string> unique slugs, sorted */
    public function slugs(): array
    {
        $slugs = [];
        foreach ($this->entries as $entry) {
            $slugs[$entry['slug']] = true;
        }
        $list = array_keys($slugs);
        sort($list);

        return $list;
    }

    /** @return list<Entry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function description(string $slug): string
    {
        return $this->meta[$slug]['description'] ?? '';
    }

    /** @return list<string> */
    public function tags(string $slug): array
    {
        return $this->meta[$slug]['tags'] ?? [];
    }

    /**
     * Resolve a slug at a depth with the cascade:
     * preferred-kind@depth -> other-kind@depth ->
     * preferred-kind@adjacent-first other depth -> other-kind@same,
     * completing every rung of the wide render before the skinny axis is
     * consulted (a slug present in both forms always prefers NON-skinny;
     * the depth cascade never crosses the skinny axis).
     * "preferred kind" is animation when `$preferAnimated`, else static.
     * Returns null only when the slug is unknown entirely.
     */
    public function pick(string $slug, string $depthTag, bool $preferAnimated = true): ?AnsiArt
    {
        $path = $this->cascadePath($slug, $depthTag, $preferAnimated);
        if ($path === null) {
            return null;
        }

        return $this->parsed[$path] ??= AnsiArt::fromFile($path);
    }

    /**
     * Uniform over slugs that have ANY cascaded entry at the depth family.
     *
     * @param (callable(int):int)|null $rng picks an index in 0..n-1; injectable so callers/tests are deterministic
     */
    public function randomSlug(string $depthTag, bool $preferAnimated = true, ?callable $rng = null): ?string
    {
        if (!isset(self::DEPTH_CASCADE[$depthTag])) {
            throw new InvalidArgumentException("ArtLibrary: unknown depth tag '{$depthTag}' (expected tc|256|16)");
        }
        $candidates = [];
        foreach ($this->slugs() as $slug) {
            if ($this->cascadePath($slug, $depthTag, $preferAnimated) !== null) {
                $candidates[] = $slug;
            }
        }
        if ($candidates === []) {
            return null;
        }

        $rng ??= static fn (int $n): int => random_int(0, $n - 1);
        $index = $rng(count($candidates));
        if (!is_int($index) || $index < 0 || $index >= count($candidates)) {
            throw new InvalidArgumentException('ArtLibrary: rng must return an int in 0..' . (count($candidates) - 1));
        }

        return $candidates[$index];
    }

    /** @see randomSlug for the $rng contract */
    public function pickRandom(string $depthTag, bool $preferAnimated = true, ?callable $rng = null): ?AnsiArt
    {
        $slug = $this->randomSlug($depthTag, $preferAnimated, $rng);

        return $slug === null ? null : $this->pick($slug, $depthTag, $preferAnimated);
    }

    private function key(string $slug, bool $skinny, string $depth, bool $animated): string
    {
        return $slug . '|' . ($skinny ? 'skinny' : 'wide') . '|' . $depth . '|' . ($animated ? 'anim' : 'static');
    }

    /** @return string|null path of the first cascade hit, null when the slug has no entries */
    private function cascadePath(string $slug, string $depthTag, bool $preferAnimated): ?string
    {
        if (!isset(self::DEPTH_CASCADE[$depthTag])) {
            throw new InvalidArgumentException("ArtLibrary: unknown depth tag '{$depthTag}' (expected tc|256|16)");
        }
        $kinds = $preferAnimated ? [true, false] : [false, true];

        // Skinny axis LAST and never interleaved: the wide render is the
        // canonical form of a design, so any wide entry (or its depth cascade)
        // beats the narrow variant, which exists only for tight layouts. A
        // skinny-only slug still resolves — via the second pass below.
        foreach ([false, true] as $skinny) {
            // Exact depth first, per kind...
            foreach ($kinds as $animated) {
                $path = $this->index[$this->key($slug, $skinny, $depthTag, $animated)] ?? null;
                if ($path !== null) {
                    return $path;
                }
            }
            // ...then the remaining depths adjacent-first, per kind, staying
            // inside this skinny axis (a wide request never lands on skinny
            // art merely because it was closer in depth).
            foreach ($kinds as $animated) {
                foreach (self::DEPTH_CASCADE[$depthTag] as $depth) {
                    $path = $this->index[$this->key($slug, $skinny, $depth, $animated)] ?? null;
                    if ($path !== null) {
                        return $path;
                    }
                }
            }
        }

        return null;
    }
}
