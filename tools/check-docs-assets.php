<?php

declare(strict_types=1);

/**
 * tools/check-docs-assets.php — fail-closed guard for the docs site's asset
 * references (docs-fix-1023, maintainer persona proposal).
 *
 * The site grew three families of silent rot that no generator check saw:
 *
 *  a) icon set drift — `docs/lib/*.html` chrome links `docs/img/icons/<slug>.png`
 *     for every package, and CI compared the two icon directories only as a
 *     printed diff step; a missing file (candy-image.png) rendered as a broken
 *     image with nothing going red.
 *  b) dead demo GIFs — cards in `docs/index.html` and the hand-written bodies
 *     under `docs/_data/` point at raw.githubusercontent.com URLs for
 *     `<slug>/.vhs/*.gif`; when a tape was renamed or deleted the URL 404s.
 *  c) mis-relativised links — bodies are embedded into `docs/lib/<slug>.html`,
 *     so an `href="lib/other.html"` inside a body resolves to `lib/lib/…` and
 *     404s (the sibling-page convention is a bare `other.html`).
 *  d) the homepage VHS-demos stat numeral vs the count of `.vhs/*.gif` files.
 *  e) demo-GIF imgs on the generated detail pages losing their onerror crutch
 *     (or being hand-doubled) — every `<img>` whose src path ends in `.gif` on
 *     a `docs/lib/*.html` page must carry EXACTLY ONE `onerror=` attribute.
 *     The generator guarantees this (`guarantee_demo_gif_onerror`,
 *     tools/gen-docs.php), so the leg is green by construction — its job is to
 *     catch a generator regression or a hand-edit before pages.yml publishes.
 *  f) the search affordance on chrome-bearing detail pages: a page that carries
 *     the shared nav must also carry EXACTLY ONE visible trigger
 *     (`id="search-trigger"` — HTML ids are unique by definition, so a planted
 *     decoy outside the nav that doubles the id is broken chrome, not affordance),
 *     the modal DOM (`id="search-modal"`) and the `search.js` include. Redirect
 *     stubs (no nav) are out of scope — they render no chrome at all.
 *
 * Existence for (b)/(d) is measured on the filesystem: the CI job runs on a
 * fresh checkout, so a tracked file IS a file on disk, and this avoids
 * spawning git inside a tools/ script.
 *
 * Usage:
 *     php tools/check-docs-assets.php [--root=<dir>]
 *
 * Exit codes:
 *     0  every leg clean
 *     1  findings (each printed, one per line)
 *     2  fatal (bad --root)
 */
final class CheckDocsAssets
{
    private const RAW_GIF = 'https://raw.githubusercontent.com/detain/sugarcraft/master/';

    /**
     * @return list<string> human-readable findings, empty when the tree is clean
     */
    public static function derive(string $root): array
    {
        return array_merge(
            self::iconSetEquality($root),
            self::demoGifRefs($root),
            self::localLinks($root),
            self::vhsStat($root),
            self::demoGifCrutches($root),
            self::searchAffordance($root),
        );
    }

    /**
     * (e) every demo-GIF <img> on a generated detail page carries exactly one
     * onerror fallback crutch. Qualification mirrors the generator exactly: a
     * tag is a demo GIF when its src path (query/fragment stripped, lowercase)
     * ends in .gif — so chrome near-misses (icon PNGs, Codecov SVGs, a slug
     * NAMED gifview) can never false-fire, and non-gif imgs are nobody's
     * business. "Exactly one" covers both directions: the 122 crutches the
     * generator injects AND sugar-dashboard's 140 hand-authored ones must not
     * be doubled, and an injected one must not go missing.
     *
     * @return list<string>
     */
    public static function demoGifCrutches(string $root): array
    {
        $findings = [];
        foreach (glob("$root/docs/lib/*.html") ?: [] as $file) {
            $html = (string) file_get_contents($file);
            preg_match_all('~<img\b[^>]*>~i', $html, $tags);
            foreach ($tags[0] as $tag) {
                if (preg_match('~\bsrc="([^"]*)"~i', $tag, $src) !== 1) {
                    continue;
                }
                $path = strtolower((string) (preg_split('/[?#]/', $src[1], 2)[0] ?? ''));
                if (!str_ends_with($path, '.gif')) {
                    continue;
                }
                $n = preg_match_all('~\bonerror\s*=~i', $tag);
                if ($n !== 1) {
                    $findings[] = 'chrome: ' . self::relative($root, $file)
                        . ' gif img src="' . $src[1] . '" carries ' . $n . ' onerror attribute(s) (expected exactly 1)';
                }
            }
        }

        return $findings;
    }

    /**
     * (f) chrome-bearing detail pages carry the complete search affordance:
     * exactly one visible trigger button, the modal DOM it opens, and the
     * script that binds them. The trigger is counted, not merely present —
     * mirroring the crutch leg's exactly-one semantics — because a second
     * `id="search-trigger"` anywhere on the page (decoy or duplicated chrome)
     * satisfies a substring check while breaking the id-uniqueness contract
     * search.js relies on. Scope is keyed on the shared nav
     * (`<div class="nav-links">`) so the 52 redirect stubs — which ship no
     * chrome by design — are excluded without a name-based special case.
     *
     * @return list<string>
     */
    public static function searchAffordance(string $root): array
    {
        $findings = [];
        foreach (glob("$root/docs/lib/*.html") ?: [] as $file) {
            $html = (string) file_get_contents($file);
            if (!str_contains($html, '<div class="nav-links">')) {
                continue;
            }
            $triggers = substr_count($html, 'id="search-trigger"');
            if ($triggers !== 1) {
                $findings[] = $triggers === 0
                    ? 'chrome: ' . self::relative($root, $file) . ' has no search trigger (id="search-trigger")'
                    : 'chrome: ' . self::relative($root, $file) . ' carries ' . $triggers . ' search trigger elements (expected exactly 1)';
            }
            foreach ([
                'search modal (id="search-modal")' => 'id="search-modal"',
                'search script (../js/search.js)'  => 'src="../js/search.js"',
            ] as $what => $needle) {
                if (!str_contains($html, $needle)) {
                    $findings[] = 'chrome: ' . self::relative($root, $file) . ' has no ' . $what;
                }
            }
        }

        return $findings;
    }

    /**
     * (a) media/icons/*.png and docs/img/icons/*.png must be the same set —
     * the former is the source of truth for the repo, the latter what the site
     * actually serves.
     *
     * @return list<string>
     */
    public static function iconSetEquality(string $root): array
    {
        $media = self::basenames("$root/media/icons/*.png");
        $docs = self::basenames("$root/docs/img/icons/*.png");

        $findings = [];
        foreach (array_diff($media, $docs) as $icon) {
            $findings[] = "icons: media/icons/$icon has no copy in docs/img/icons/";
        }
        foreach (array_diff($docs, $media) as $icon) {
            $findings[] = "icons: docs/img/icons/$icon has no source in media/icons/";
        }

        return $findings;
    }

    /**
     * (b) every raw.githubusercontent.com GIF frame reference under docs/ must
     * point at a file that exists in the repo.
     *
     * @return list<string>
     */
    public static function demoGifRefs(string $root): array
    {
        $findings = [];
        foreach (self::docsHtmlFiles($root) as $file) {
            $html = (string) file_get_contents($file);
            $pattern = '~' . preg_quote(self::RAW_GIF, '~') . '([^"\']+\.gif)~';
            foreach (array_unique((preg_match_all($pattern, $html, $m) ? $m[1] : [])) as $ref) {
                if (!is_file("$root/$ref")) {
                    $findings[] = 'gifs: ' . self::relative($root, $file) . " references missing $ref";
                }
            }
        }

        return $findings;
    }

    /**
     * (c) every local href/src in the homepage and the generated detail pages
     * must resolve, relative to the file that carries it. Remote URLs,
     * fragments, and javascript:/mailto:/data: schemes are not assets.
     *
     * @return list<string>
     */
    public static function localLinks(string $root): array
    {
        $targets = ["$root/docs/index.html"];
        foreach ((glob("$root/docs/lib/*.html") ?: []) as $page) {
            $targets[] = $page;
        }

        $findings = [];
        foreach ($targets as $file) {
            if (!is_file($file)) {
                continue;
            }
            $html = (string) file_get_contents($file);
            $pattern = '~(?<![-\w])(?:href|src)="([^"]+)"~';
            foreach (array_unique((preg_match_all($pattern, $html, $m) ? $m[1] : [])) as $raw) {
                $path = self::localTarget($raw);
                if ($path === null) {
                    continue;
                }
                $base = (string) dirname($file);
                $resolved = $path === '' ? $base : "$base/$path";
                if (!file_exists($resolved)) {
                    $findings[] = 'links: ' . self::relative($root, $file) . " points at missing '" . $raw . "'";
                }
            }
        }

        return $findings;
    }

    /**
     * (d) the homepage "VHS demos" stat must equal the number of rendered
     * demo GIFs in the tree (the same count CI's vhs job keeps fresh).
     *
     * @return list<string>
     */
    public static function vhsStat(string $root): array
    {
        $index = "$root/docs/index.html";
        if (!is_file($index)) {
            return [];
        }
        $html = (string) file_get_contents($index);
        if (preg_match('~<span class="num">(\d+)</span><span class="label">VHS demos</span>~', $html, $m) !== 1) {
            return ['gifs: docs/index.html has no parseable "VHS demos" stat'];
        }

        $onDisk = count((glob("$root/*/.vhs/*.gif") ?: []));
        if ((int) $m[1] !== $onDisk) {
            return ["gifs: docs/index.html advertises {$m[1]} VHS demos, tree carries $onDisk"];
        }

        return [];
    }

    /**
     * The path part of one href/src value, or null when it is not a local
     * filesystem target. A trailing '/' is stripped ("../" becomes ".."), so a
     * directory anchor resolves against its base like any other target.
     */
    public static function localTarget(string $raw): ?string
    {
        $value = preg_split('/[?#]/', $raw, 2)[0] ?? '';
        if ($value === '' || $value[0] === '#') {
            return null;
        }
        if (preg_match('~^(?:[a-z]+:)?//~i', $value) === 1) {
            return null; // scheme:// or protocol-relative //host
        }
        foreach (['mailto:', 'javascript:', 'data:'] as $scheme) {
            if (stripos($value, $scheme) === 0) {
                return null;
            }
        }

        return rtrim($value, '/');
    }

    /**
     * Every HTML file under docs/ that may embed demo references: the
     * homepage, the generated pages, and the editable data store.
     *
     * @return list<string>
     */
    private static function docsHtmlFiles(string $root): array
    {
        $files = ["$root/docs/index.html"];
        foreach (["$root/docs/lib/*.html", "$root/docs/_data/*.html"] as $glob) {
            foreach (glob($glob) ?: [] as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * @return list<string> sorted basenames of the glob's matches
     */
    private static function basenames(string $glob): array
    {
        $names = array_map('basename', glob($glob) ?: []);
        sort($names);

        return $names;
    }

    private static function relative(string $root, string $file): string
    {
        return str_starts_with($file, "$root/") ? substr($file, strlen($root) + 1) : $file;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === realpath(__FILE__)) {
    $root = dirname(__DIR__);

    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, '--root=')) {
            $root = substr((string) $arg, 7);
        }
    }

    if (!is_dir($root)) {
        fwrite(STDERR, "check-docs-assets: --root is not a directory: $root\n");
        exit(2);
    }

    $findings = CheckDocsAssets::derive($root);
    foreach ($findings as $finding) {
        fwrite(STDOUT, "$finding\n");
    }

    if ($findings !== []) {
        fwrite(STDOUT, 'check-docs-assets: ' . count($findings) . " finding(s)\n");
        exit(1);
    }

    fwrite(STDOUT, "ok: icons, demo GIFs, local links, the VHS stat and page chrome are all in sync\n");
    exit(0);
}
