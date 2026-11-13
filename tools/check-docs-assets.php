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
 *  b) dead demo GIFs — cards in `docs/index.html`, `docs/libraries.html` and
 *     `docs/apps.html` and the hand-written bodies
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
 *  f) the search affordance on every chrome-bearing nav surface (the static
 *     homepage/libraries/apps/compare/404 pages plus generated detail pages): a page that
 *     carries the shared nav must also carry EXACTLY ONE visible trigger
 *     (`id="search-trigger"` — HTML ids are unique by definition, so a planted
 *     decoy outside the nav that doubles the id is broken chrome, not affordance),
 *     the modal DOM (`id="search-modal"`), and EXACTLY ONE include each of
 *     `js/search.js` and `js/nav.js`. Redirect stubs (no nav) are out of scope —
 *     they render no chrome at all.
 *  g) nav copy identity: every surface that carries the dropdown nav must hold
 *     exactly one `<!--nav:start-->` / `<!--nav:end-->` marker pair, and the
 *     block between them must be byte-identical across every copy (the five
 *     authored copies — index/libraries/apps/compare/404/gen-docs template/the two
 *     prefixOverride pages — are maintained in lockstep; the guard is the trip
 *     wire that keeps them there).
 *  h) sitemap set-equality: `docs/sitemap.xml` is hand-maintained, so a page
 *     can be published with chrome and never advertised, or a row can outlive
 *     its page. The leg derives the published universe — every `.html` under
 *     `docs/` that carries the nav container, except generator fragments under
 *     `docs/_data/` and the documented non-advertised roster — and demands
 *     exact two-way equality with the `<loc>` rows, printing both directions
 *     of the diff on failure.
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

    /** The published site origin every sitemap `<loc>` row must sit under. */
    private const SITE_BASE = 'https://sugarcraft.github.io/';

    /**
     * Chrome-bearing pages deliberately OUT of the advertised universe:
     * the key is the docs/-relative path, the value why it is not advertised.
     * A roster entry whose file exists but no longer carries nav chrome is
     * itself a finding — the roster must not lie about what it exempts.
     */
    private const SITEMAP_EXCLUSIONS = [
        '404.html' => 'the error surface is not advertised',
        'admin/sugar-pty.html' => 'off-nav auxiliary deep-link page, deliberately unsitemapped',
        'dev/sugar-pty.html' => 'off-nav auxiliary deep-link page, deliberately unsitemapped',
    ];

    /**
     * RT-43: the dead-path roster check in the CLI main reads the otherwise
     * private SITEMAP_EXCLUSIONS through this window (no visibility widening
     * of the const itself).
     *
     * @return array<string,string>
     */
    public static function sitemapExclusions(): array
    {
        return self::SITEMAP_EXCLUSIONS;
    }

    /**
     * RT-43 (repo-tooling-055): the legs enumerate the same corpus up to
     * 6-7 times (chrome membership per leg, the sitemap walk, the gif/link
     * passes). Reads are memoized PER derive() call — fixtures mutate
     * between calls, and every derive() re-derives from a clean slate, so
     * the cache can never freeze bytes across a mutation.
     *
     * @var array<string,string>
     */
    private static array $readCache = [];

    private static function readCached(string $path): string
    {
        return self::$readCache[$path] ??= (string) @file_get_contents($path);
    }

    /**
     * @return list<string> human-readable findings, empty when the tree is clean
     */
    public static function derive(string $root, ?array &$census = null): array
    {
        self::$readCache = [];
        $findings = array_merge(
            self::iconSetEquality($root),
            self::demoGifRefs($root),
            self::localLinks($root),
            self::vhsStat($root),
            self::demoGifCrutches($root),
            self::searchAffordance($root),
            self::navBlockIdentity($root),
            self::sitemapCoverage($root),
        );

        // RT-20: a findings-only answer is vacuously green on a tree the legs
        // never actually scanned (a path bug turns the real-tree arm into the
        // empty-dir arm). The census reports what the legs LOOKED AT, so the
        // caller can sight-pin non-trivial corpus sizes — ChildLifetimesToolTest
        // style (29 sites/11 findings). $sink absorbs the sitemap leg's
        // findings-by-ref during re-count; the merge above already carries them.
        if ($census !== null || func_num_args() > 1) {
            $sink = [];
            $census = [
                'chrome_pages'  => count(self::chromePages($root)),
                'icon_files'    => count(glob("$root/media/icons/*.png") ?: []),
                'site_icons'    => count(glob("$root/docs/img/icons/*.png") ?: []),
                'demo_gif_refs' => self::demoGifRefCensus($root),
                'sitemap_pages' => is_dir("$root/docs") ? count(self::sitemapUniverse($root, $sink)) : 0,
            ];
        }

        return $findings;
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
            $html = (string) self::readCached($file);
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
        foreach (self::chromePages($root) as $file) {
            $html = (string) self::readCached($file);
            $triggers = substr_count($html, 'id="search-trigger"');
            if ($triggers !== 1) {
                $findings[] = $triggers === 0
                    ? 'chrome: ' . self::relative($root, $file) . ' has no search trigger (id="search-trigger")'
                    : 'chrome: ' . self::relative($root, $file) . ' carries ' . $triggers . ' search trigger elements (expected exactly 1)';
            }
            if (!str_contains($html, 'id="search-modal"')) {
                $findings[] = 'chrome: ' . self::relative($root, $file) . ' has no search modal (id="search-modal")';
            }
            foreach ([
                'search' => '~src="[^"]*js/search\.js"~',
                'nav'    => '~src="[^"]*js/nav\.js"~',
            ] as $what => $re) {
                $count = preg_match_all($re, $html);
                if ($count === 0) {
                    $findings[] = 'chrome: ' . self::relative($root, $file) . " has no $what script";
                } elseif ($count > 1) {
                    $findings[] = 'chrome: ' . self::relative($root, $file) . " carries $count $what script includes (expected exactly 1)";
                }
            }
        }

        return $findings;
    }

    /**
     * Every page that renders the shared nav chrome: the static surfaces,
     * the generated detail pages, and the rendered documentation pages.
     * Keyed on the nav container, so chromeless redirect stubs fall out
     * without a name-based special case.
     *
     * @return list<string>
     */
    private static function chromePages(string $root): array
    {
        $pages = [
            "$root/docs/index.html",
            "$root/docs/libraries.html",
            "$root/docs/apps.html",
            "$root/docs/compare.html",
            "$root/docs/404.html",
        ];
        foreach (glob("$root/docs/lib/*.html") ?: [] as $page) {
            $pages[] = $page;
        }
        foreach (glob("$root/docs/*.html") ?: [] as $page) {
            $pages[] = $page;
        }
        $pages[] = "$root/docs/cookbook/index.html";

        return array_values(array_unique(array_filter(
            $pages,
            static fn (string $p): bool => is_file($p) && str_contains(self::readCached($p), '<div class="nav-links">'),
        )));
    }

    /**
     * (g) the nav is authored in five places (three static pages, the gen-docs
     * template, and the two prefixOverride pages); the marker pair delimits
     * the single source of truth, so every rendered page must carry it exactly
     * once and agree byte-for-byte between the markers.
     *
     * @return list<string>
     */
    public static function navBlockIdentity(string $root): array
    {
        $findings = [];
        $reference = null;
        $referenceFile = '';
        foreach (self::chromePages($root) as $file) {
            $html = (string) self::readCached($file);
            if (!str_contains($html, '<nav class="nav"')) {
                continue;
            }
            if (substr_count($html, '<!--nav:start-->') !== 1 || substr_count($html, '<!--nav:end-->') !== 1) {
                $findings[] = 'nav: ' . self::relative($root, $file) . ' has no nav markers (<!--nav:start--> / <!--nav:end--> pair, exactly once)';
                continue;
            }
            $start = strpos($html, '<!--nav:start-->');
            $end = strpos($html, '<!--nav:end-->') + strlen('<!--nav:end-->');
            $block = substr($html, $start, $end - $start);
            if ($reference === null) {
                $reference = $block;
                $referenceFile = self::relative($root, $file);
            } elseif ($block !== $reference) {
                $findings[] = 'nav: ' . self::relative($root, $file) . ' nav block differs from ' . $referenceFile . ' (all nav copies must be byte-identical between markers)';
            }
        }

        return $findings;
    }

    /**
     * (h) sitemap set-equality: `docs/sitemap.xml` is hand-maintained while the
     * page universe it describes is generated and grown by hand, so both drift
     * directions are silent — an unadvertised chrome page costs discoverability,
     * a stale row publishes a 404. The universe is every HTML file under docs/
     * that carries the shared nav container (the same discriminator the chrome
     * legs key on, so the redirect stubs and the `_data` fragments fall out
     * without name-based special cases), minus the documented exclusion roster.
     * Site paths compare exactly: docs/index.html is advertised as the bare
     * base URL (the root row), every other page as its docs/-relative path —
     * including the explicit `cookbook/index.html` spelling the sitemap uses.
     * Both halves of the diff print, naming each offender by file and by row.
     *
     * @return list<string>
     */
    public static function sitemapCoverage(string $root): array
    {
        $sitemap = "$root/docs/sitemap.xml";
        if (!is_file($sitemap)) {
            return ['sitemap: docs/sitemap.xml is missing'];
        }

        $findings = [];
        $rows = [];
        preg_match_all('~<loc>([^<]*)</loc>~', self::readCached($sitemap), $m);
        foreach ($m[1] as $loc) {
            if (!str_starts_with($loc, self::SITE_BASE)) {
                $findings[] = 'sitemap: <loc>' . $loc . '</loc> is outside the site base ' . self::SITE_BASE;
                continue;
            }
            $sitePath = substr($loc, strlen(self::SITE_BASE));
            if (isset($rows[$sitePath])) {
                $findings[] = 'sitemap: <loc>' . $loc . '</loc> duplicates an earlier row';
                continue;
            }
            $rows[$sitePath] = $loc;
        }

        $universe = self::sitemapUniverse($root, $findings);

        $missing = array_diff_key($universe, $rows);
        ksort($missing);
        foreach ($missing as $sitePath => $display) {
            $findings[] = 'sitemap: ' . $display . ' carries nav chrome but no <loc> row (expected '
                . self::SITE_BASE . $sitePath . ')';
        }

        $extra = array_diff_key($rows, $universe);
        foreach ($extra as $loc) {
            $findings[] = 'sitemap: row <loc>' . $loc . '</loc> matches no chrome page in the universe';
        }

        return $findings;
    }

    /**
     * The advertised page universe: site path => docs/-relative display path.
     * Roster entries that exist on disk but no longer carry nav chrome are
     * stale exemptions and append a finding through $findings.
     *
     * @param list<string> $findings
     * @return array<string,string>
     */
    private static function sitemapUniverse(string $root, array &$findings): array
    {
        $universe = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator("$root/docs", \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($walk as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.html')) {
                continue;
            }
            $display = self::relative($root, $file->getPathname());
            $sitePath = substr($display, strlen('docs/'));
            if (!str_contains(self::readCached($file->getPathname()), '<div class="nav-links">')) {
                continue;
            }
            if (isset(self::SITEMAP_EXCLUSIONS[$sitePath])) {
                continue;
            }
            $universe[$sitePath === 'index.html' ? '' : $sitePath] = $display;
        }

        // RT-43: the dead-path half of roster staleness lives in the CLI main
        // (scoped to the real repo root, where the roster is a claim about the
        // COMMITTED tree — synthetic fixture roots legitimately lack the
        // pages). Here stays the direction a fixture can exercise: an entry
        // whose page EXISTS but lost its nav chrome.
        foreach (self::SITEMAP_EXCLUSIONS as $sitePath => $why) {
            $file = "$root/docs/$sitePath";
            if (is_file($file) && !str_contains(self::readCached($file), '<div class="nav-links">')) {
                $findings[] = "sitemap: exclusion roster entry docs/$sitePath ($why) no longer carries nav chrome — the roster is stale";
            }
        }

        return $universe;
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
            $html = (string) self::readCached($file);
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
     * RT-20: how many distinct RAW-gif references the leg actually examined —
     * the findings list says nothing about scan depth (zero findings is also
     * what a zero-file walk looks like).
     *
     * @return int
     */
    public static function demoGifRefCensus(string $root): int
    {
        $refs = 0;
        foreach (self::docsHtmlFiles($root) as $file) {
            $html = (string) self::readCached($file);
            $pattern = '~' . preg_quote(self::RAW_GIF, '~') . '([^"\']+\.gif)~';
            $refs += count(array_unique(preg_match_all($pattern, $html, $m) ? $m[1] : []));
        }

        return $refs;
    }

    /**
     * (c) every local href/src in the static surfaces, the generated detail
     * pages and the rendered documentation pages must resolve, relative to
     * the file that carries it. Remote URLs, fragments, and
     * javascript:/mailto:/data: schemes are not assets.
     *
     * @return list<string>
     */
    public static function localLinks(string $root): array
    {
        $targets = [
            "$root/docs/index.html",
            "$root/docs/libraries.html",
            "$root/docs/apps.html",
            "$root/docs/compare.html",
            "$root/docs/404.html",
        ];
        foreach ((glob("$root/docs/lib/*.html") ?: []) as $page) {
            $targets[] = $page;
        }
        foreach ((glob("$root/docs/*.html") ?: []) as $page) {
            $targets[] = $page;
        }
        $targets[] = "$root/docs/cookbook/index.html";

        $findings = [];
        foreach (array_unique($targets) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $html = (string) self::readCached($file);
            $pattern = '~(?<![-\w])(?:href|src)="([^"]+)"~';
            foreach (array_unique((preg_match_all($pattern, $html, $m) ? $m[1] : [])) as $raw) {
                $path = self::localTarget($raw);
                if ($path === null) {
                    continue;
                }
                $base = (string) dirname($file);
                // Root-absolute nav hrefs ('/lib/x.html') resolve against docs/
                // itself — the site is served at the domain root, so a leading
                // '/' means "under docs/" regardless of the carrying page's depth.
                $resolved = $path === '' ? $base : ($path[0] === '/' ? "$root/docs$path" : "$base/$path");
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
        $html = self::readCached($index);
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
        if ($value[0] === '/') {
            // Root-absolute target: '/' alone (e.g. '/#apps') is the homepage.
            $trimmed = rtrim($value, '/');

            return $trimmed === '' ? '/index.html' : $trimmed;
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
        // RT-43: docs/_data/*.html are GENERATOR FRAGMENTS, never published
        // surfaces — scanning them cost whole-corpus reads for zero check
        // value. They are excluded by path before anything is read.
        $files = ["$root/docs/index.html", "$root/docs/libraries.html", "$root/docs/apps.html"];
        foreach (["$root/docs/lib/*.html"] as $glob) {
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

    // RT-43 (repo-tooling-048): SITEMAP_EXCLUSIONS "must not lie about the
    // tree" (its own header law) — enforced one way only until now: a roster
    // entry whose docs/ path had vanished was silent dead weight. On the real
    // repo root (the roster's referent; --root scratch trees are exempt, they
    // legitimately carry minimal corpora) every exclusion must name an
    // existing page, or the tool exits 2 naming the entry — the
    // IDLE_DEFERRAL-census model this repo already runs for rosters.
    if (realpath($root) === realpath(dirname(__DIR__))) {
        foreach (CheckDocsAssets::sitemapExclusions() as $sitePath => $why) {
            if (!is_file("$root/docs/$sitePath")) {
                fwrite(STDERR, "check-docs-assets: exclusion roster entry docs/$sitePath ($why) names a path that no longer exists — the roster lies about the tree\n");
                exit(2);
            }
        }
    }

    $findings = CheckDocsAssets::derive($root);
    foreach ($findings as $finding) {
        fwrite(STDOUT, "$finding\n");
    }

    if ($findings !== []) {
        fwrite(STDOUT, 'check-docs-assets: ' . count($findings) . " finding(s)\n");
        exit(1);
    }

    fwrite(STDOUT, "ok: icons, demo GIFs, local links, the VHS stat, page chrome and the sitemap are all in sync\n");
    exit(0);
}
