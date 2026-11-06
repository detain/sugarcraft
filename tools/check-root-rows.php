<?php

/**
 * tools/check-root-rows.php — standing guard for the root-doc lib rows.
 *
 * The three root surfaces (README.md library table, docs/MATCHUPS.md mapping
 * table, docs/index.html lib-card tiles) each carry one row per library. Two
 * live incident classes proved them unguarded: a merge weld silently reverted
 * eleven README description cells to pre-drain text (rows read TRUE again with
 * stale prose), and several welds simply never applied their root-drain rows
 * at all (the lib's own docs said one thing, the root rows another). Neither
 * class reddens any existing generator check — gen-docs --check only proves
 * docs/lib pages match docs/_data, and the site-asset guard only proves the
 * links resolve.
 *
 * This tool pins the root rows against a seed corpus,
 * prompt_kit/root-rows-seed.json: per slug and root file, the row must
 * CONTAIN each positive needle and LACK each dead phrase. Needles are
 * captured post-drain, so a revert to pre-drain wording lands a dead phrase
 * or drops a needle and fails loudly. A row that moves legitimately must
 * re-pin its seed entry in the same commit (by-design reds, per the approved
 * proposal). The seed slug set is additionally held set-equal to the README
 * roster so a new library cannot skip registration.
 *
 * Fail-closed by construction: an unreadable, unparseable or structurally
 * invalid seed aborts with exit 2 rather than certifying nothing. Row
 * location is per-file and unique-count based — zero hits is "missing", more
 * than one is "ambiguous", and neither can pass vacuously.
 *
 * Usage: php tools/check-root-rows.php [--root=<dir>] [--seed=<file>]
 * Exit 0 = clean, 1 = findings listed, 2 = the check itself could not run.
 */

declare(strict_types=1);

final class CheckRootRows
{
    private const FILES = ['readme', 'matchups', 'index'];

    /**
     * @return list<string> one finding line per violation, empty when clean
     * @throws RuntimeException when the seed or a root file cannot be parsed
     */
    public static function derive(string $root, ?string $seedPath = null): array
    {
        $seedFile = $seedPath ?? $root . '/prompt_kit/root-rows-seed.json';
        $seed = self::loadSeed($seedFile);
        $readme = self::readFile($root . '/README.md');
        $matchups = self::readFile($root . '/docs/MATCHUPS.md');
        $index = self::readFile($root . '/docs/index.html');

        $findings = self::rosterFindings($readme, $seed);
        foreach ($seed['slugs'] as $slug => $entry) {
            /** @var array<string, array{needles: list<string>, dead: list<string>}> $entry */
            foreach (self::FILES as $file) {
                if (!isset($entry[$file])) {
                    continue;
                }
                $text = self::locateRow($file, (string) $slug, $readme, $matchups, $index, $findings);
                if ($text === null) {
                    continue;
                }
                $row = self::flatten($text);
                foreach ($entry[$file]['needles'] as $needle) {
                    if (!str_contains($row, self::flatten($needle))) {
                        $findings[] = sprintf('FAIL %s %s: absent: %s', $file, $slug, $needle);
                    }
                }
                foreach ($entry[$file]['dead'] as $dead) {
                    if (str_contains($row, self::flatten($dead))) {
                        $findings[] = sprintf('FAIL %s %s: dead-present: %s', $file, $slug, $dead);
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Advisory anti-rot leg (zz-guard-proposal.md :39): a seeded dead phrase that
     * occurs nowhere in any root file cannot fire from current text — it exists
     * only as a revert fossil, guarding a wording the repo no longer prints.
     *
     * WHY IT IS NEVER RED. The proposal specced this as a "retire the row" NOTE,
     * explicitly not a finding: keeping the belt-braces entry is a legitimate
     * curation choice (§7.4 — dead phrases accumulate per program-era), so a red
     * would tax every future reword with a forced seed edit. The notes ride
     * STDERR, so the STDOUT findings stream and the "checked N slug-rows" summary
     * stay parser-clean, and the exit code still derives ONLY from findings.
     * Mirrors the tools/gen-docs.php note channel (:486/:489): a warning and a
     * skip, never a fatal.
     *
     * Liveness is whole-file on purpose, unlike derive()'s row scope: a phrase
     * still printed anywhere is doing guard work somewhere, so only the
     * absent-everywhere case is vestigial.
     *
     * @return list<string> one "note:" line per vestigial dead entry, order-stable
     * @throws RuntimeException when the seed or a root file cannot be parsed
     */
    public static function advisories(string $root, ?string $seedPath = null): array
    {
        $seed = self::loadSeed($seedPath ?? $root . '/prompt_kit/root-rows-seed.json');
        $texts = array_map(
            [self::class, 'flatten'],
            [
                self::readFile($root . '/README.md'),
                self::readFile($root . '/docs/MATCHUPS.md'),
                self::readFile($root . '/docs/index.html'),
            ],
        );

        $notes = [];
        foreach ($seed['slugs'] as $slug => $entry) {
            foreach (self::FILES as $file) {
                if (!isset($entry[$file])) {
                    continue;
                }
                foreach ($entry[$file]['dead'] as $dead) {
                    $needle = self::flatten($dead);
                    if (array_filter($texts, static fn (string $text): bool => str_contains($text, $needle)) === []) {
                        $notes[] = sprintf('note: %s %s: retire-dead: %s', $file, $slug, $dead);
                    }
                }
            }
        }

        return $notes;
    }

    /**
     * @return array{slugs: array<string, array<string, array{needles: list<string>, dead: list<string>}>>}
     */
    private static function loadSeed(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('seed file not found: %s', $path));
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(sprintf('seed file unreadable: %s', $path));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('slugs', $decoded) || !is_array($decoded['slugs'])) {
            throw new RuntimeException(sprintf('seed is not a JSON object with a "slugs" object: %s', $path));
        }
        $slugs = [];
        foreach ($decoded['slugs'] as $slug => $entry) {
            if (!is_string($slug) || $slug === '' || !is_array($entry)) {
                throw new RuntimeException('seed entry must be an object keyed by slug');
            }
            if ($entry === []) {
                throw new RuntimeException(sprintf('seed entry for %s arms no root file', $slug));
            }
            $armed = [];
            foreach ($entry as $file => $claims) {
                if (!in_array($file, self::FILES, true)) {
                    throw new RuntimeException(sprintf('seed entry for %s uses unknown file key %s', $slug, (string) $file));
                }
                if (!is_array($claims)) {
                    throw new RuntimeException(sprintf('seed claims for %s/%s must be an object', $slug, $file));
                }
                $armed[$file] = [
                    'needles' => self::stringList($claims, 'needles', $slug, $file, true),
                    'dead' => self::stringList($claims, 'dead', $slug, $file, false),
                ];
            }
            $slugs[$slug] = $armed;
        }

        return ['slugs' => $slugs];
    }

    /**
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private static function stringList(array $claims, string $key, string $slug, string $file, bool $required): array
    {
        if (!array_key_exists($key, $claims)) {
            if ($required) {
                throw new RuntimeException(sprintf('seed entry for %s/%s has no "needles" list', $slug, $file));
            }

            return [];
        }
        $list = $claims[$key];
        if (!is_array($list) || ($list !== [] && array_keys($list) !== range(0, count($list) - 1))) {
            throw new RuntimeException(sprintf('seed "%s" for %s/%s must be a JSON array', $key, $slug, $file));
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException(sprintf('seed "%s" for %s/%s must hold non-blank strings', $key, $slug, $file));
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function readFile(string $path): string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(sprintf('root file unreadable: %s', $path));
        }

        return $raw;
    }

    /**
     * @param array{slugs: array<string, array<string, array{needles: list<string>, dead: list<string>}>>} $seed
     * @return list<string>
     */
    private static function rosterFindings(string $readme, array $seed): array
    {
        $derived = self::readmeSlugs($readme);
        $findings = [];
        foreach ($derived as $slug) {
            if (!array_key_exists($slug, $seed['slugs'])) {
                $findings[] = sprintf('FAIL roster %s: not-in-seed: %s', $slug, $slug);
            }
        }
        foreach (array_keys($seed['slugs']) as $slug) {
            if (!in_array($slug, $derived, true)) {
                $findings[] = sprintf('FAIL roster %s: not-in-readme: %s', $slug, $slug);
            }
        }

        return $findings;
    }

    /**
     * @return list<string> every slug listed in the README library table
     */
    private static function readmeSlugs(string $readme): array
    {
        $slugs = [];
        foreach (explode("\n", $readme) as $line) {
            if (preg_match('#^\| <img src="media/icons/([a-z0-9-]+)\.png"[^>]*> \| \*\*\[[^\]]*\]\([^)]*\)\*\* \| .*\|$#', $line, $m) === 1) {
                $slugs[] = $m[1];
            }
        }

        return $slugs;
    }

    /**
     * @param list<string> $findings appended in place with missing/ambiguous rows
     */
    private static function locateRow(string $file, string $slug, string $readme, string $matchups, string $index, array &$findings): ?string
    {
        $hits = match ($file) {
            'readme' => self::lineHits($readme, sprintf('media/icons/%s.png', $slug)),
            'matchups' => self::prefixedLineHits($matchups, sprintf('`%s/`', $slug)),
            default => self::cardTile($index, $slug, $findings),
        };
        if ($file === 'index') {
            return $hits;
        }
        $count = is_array($hits) ? count($hits) : 0;
        if ($count === 0) {
            $findings[] = sprintf('FAIL %s %s: missing: %s', $file, $slug, $slug);

            return null;
        }
        if ($count > 1) {
            $findings[] = sprintf('FAIL %s %s: ambiguous: %s', $file, $slug, $slug);

            return null;
        }

        return $hits[0];
    }

    /**
     * @return list<string>
     */
    private static function lineHits(string $text, string $anchor): array
    {
        $hits = [];
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, $anchor)) {
                $hits[] = $line;
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private static function prefixedLineHits(string $text, string $anchor): array
    {
        $hits = [];
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, '| **') && str_contains($line, $anchor)) {
                $hits[] = $line;
            }
        }

        return $hits;
    }

    /**
     * The lib-card tile summary paragraph, located only through the card
     * anchor — a bare href also occurs in the icon strip and the demo-card,
     * so matching on those would certify the wrong row. A second card
     * appearing before any summary paragraph is a fail-closed no-summary.
     *
     * @param list<string> $findings
     */
    private static function cardTile(string $index, string $slug, array &$findings): ?string
    {
        $anchor = sprintf('class="lib-card" href="lib/%s.html"', $slug);
        $hits = self::lineHits($index, $anchor);
        if (count($hits) === 0) {
            $findings[] = sprintf('FAIL index %s: missing: %s', $slug, $slug);

            return null;
        }
        if (count($hits) > 1) {
            $findings[] = sprintf('FAIL index %s: ambiguous: %s', $slug, $slug);

            return null;
        }
        $after = substr($index, (int) strpos($index, $anchor) + strlen($anchor));
        $summaryStart = strpos($after, '<p class="summary">');
        $nextCard = strpos($after, 'class="lib-card"');
        if ($summaryStart === false || ($nextCard !== false && $nextCard < $summaryStart)) {
            $findings[] = sprintf('FAIL index %s: no-summary: %s', $slug, $slug);

            return null;
        }
        $body = substr($after, $summaryStart + strlen('<p class="summary">'));
        $end = strpos($body, '</p>');
        if ($end === false) {
            $findings[] = sprintf('FAIL index %s: no-summary: %s', $slug, $slug);

            return null;
        }

        return self::stripTags(substr($body, 0, $end));
    }

    private static function stripTags(string $text): string
    {
        $out = strip_tags($text);

        return html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function flatten(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($text));

        return $collapsed === null ? trim($text) : $collapsed;
    }

    public static function countRows(array $seedSlugs): int
    {
        $count = 0;
        foreach ($seedSlugs as $entry) {
            $count += count($entry);
        }

        return $count;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === realpath(__FILE__)) {
    $root = dirname(__DIR__);
    $seedPath = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--root=')) {
            $root = (string) substr($arg, 7);
        } elseif (str_starts_with($arg, '--seed=')) {
            $seedPath = (string) substr($arg, 7);
        } else {
            fwrite(STDERR, sprintf("check-root-rows: unknown argument '%s'\n", $arg));
            exit(2);
        }
    }
    try {
        $seedInfo = json_decode((string) @file_get_contents($seedPath ?? $root . '/prompt_kit/root-rows-seed.json'), true);
        $findings = CheckRootRows::derive($root, $seedPath);
        $rowCount = is_array($seedInfo) && is_array($seedInfo['slugs'] ?? null)
            ? CheckRootRows::countRows($seedInfo['slugs'])
            : 0;
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'check-root-rows: ' . $e->getMessage() . "\n");
        exit(2);
    }
    foreach ($findings as $finding) {
        fwrite(STDOUT, $finding . "\n");
    }
    // Advisory notes on STDERR, never findings on STDOUT and never the exit code:
    // derive() above already read the same seed and root files, so advisories()
    // cannot throw where derive() did not — the same inputs, the same pre-read
    // double shape the row-count line already relies on.
    foreach (CheckRootRows::advisories($root, $seedPath) as $note) {
        fwrite(STDERR, $note . "\n");
    }
    fwrite(STDOUT, sprintf("checked %d slug-rows; %d findings\n", $rowCount, count($findings)));
    exit($findings === [] ? 0 : 1);
}
