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
 *  i) body class and method claims: every `Name::method(` and `new Name(` a
 *     `docs/_data/<slug>.body.html` names must resolve to a class declared
 *     under a `<slug>/src/` tree (or a PHP built-in), and `Name::method(` must
 *     name a public method of that class, directly or through the traits and
 *     parents it pulls in. A bare PascalCase name alone in an inline `<code>`
 *     span (`<code>ResizeMsg</code>`, `<code>Renderer</code>`) is a class claim
 *     too. The bare-name rule and its exclusion list (test classes, env vars,
 *     key names, products, config directives, third-party names) are documented
 *     at BARE_CLASS_SHAPE and isNonClassName() below.
 *  j) body example claims: every `examples/<file>` path a body names must exist
 *     under the package it belongs to.
 *  k) body vhs claims: every `.vhs/<file>.tape|gif` a body names must exist.
 *     Legs (i)-(k) resolve a bare path against the body's own slug; a `<slug>/`
 *     prefix or a GitHub `…/<slug>/blob/master/` URL names another package.
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
     * Leg (i), bare names: an inline `<code>` span whose whole text is a
     * PascalCase identifier with at least one lowercase letter (`Renderer`,
     * `ResizeMsg`, `ITerm2`, `MySQL`) is a class claim, as a `Name::method()`
     * is. A single-word name is checked too, so an English word, key name or
     * option value written alone in a code span must resolve to a class or be
     * excluded by name below.
     *
     * Names that are not classes and are excluded from that check:
     *  - fixture and test classes (a `*Test` or `*Tests` suffix): they live
     *    under `<slug>/tests/`, which the class index never reads;
     *  - environment variables (all capitals with underscores, or a listed
     *    Windows variable);
     *  - keyboard key names (EXCLUDED_KEY_NAMES);
     *  - products, tools and protocols a body names as text (EXCLUDED_PRODUCT_NAMES);
     *  - configuration directives the body quotes from ssh/OS config files
     *    (EXCLUDED_CONFIG_NAMES);
     *  - names for things outside the monorepo, upstream Go identifiers and modes,
     *    a terminfo capability, PHP package interfaces and a TypeScript component
     *    (EXCLUDED_THIRD_PARTY_NAMES);
     *  - registered tool names and enum cases, constants and methods the monorepo
     *    declares (nonClassSymbols()).
     * A name the body imports or declares itself is illustrative, as in leg (i).
     */
    private const BARE_CLASS_SHAPE = '~^\\\\?[A-Z][A-Za-z0-9]*$~';

    /**
     * @var list<string> keyboard key names a body writes in code spans; `ReleaseA`
     *      is the Kitty release-event form of a key, the name KeyEvent documents
     */
    private const EXCLUDED_KEY_NAMES = [
        'PageUp', 'PageDown', 'PgUp', 'PgDn', 'ArrowUp', 'ArrowDown', 'ArrowLeft',
        'ArrowRight', 'WheelUp', 'WheelDown', 'ReleaseA',
    ];

    /** @var list<string> environment variables that are not all capitals */
    private const EXCLUDED_ENV_NAMES = ['ComSpec'];

    /** @var list<string> products, tools and protocols written as plain text */
    private const EXCLUDED_PRODUCT_NAMES = [
        'MySQL', 'MariaDB', 'ITerm2', 'WindowsTerminal', 'JetBrainsMono',
    ];

    /**
     * @var list<string> names a body writes for something outside the monorepo:
     *      an upstream Go identifier (charmbracelet vhs modes, bubbletea options,
     *      bubbles list and glamour renderer names), a PHP package's interface or
     *      class (psr/log, react/event-loop), a candy-code-web TypeScript component,
     *      a terminfo capability (`Tc`, read by infocmp), or an output string a body
     *      quotes. None is declared under a `<slug>/src/` tree, and the body names
     *      each one as the thing it is, not as a claim.
     */
    private const EXCLUDED_THIRD_PARTY_NAMES = [
        'RecordOnly', 'ReplayOnly', 'Passthrough', 'WithReporter', 'WithSignalHandler',
        'SetFilterText', 'TermRenderer', 'LoggerInterface', 'ExtUvLoop', 'StreamSelectLoop',
        'DiffView', 'HIcd', 'Tc',
    ];

    /** @var list<string> ssh and OS config directives quoted in code spans */
    private const EXCLUDED_CONFIG_NAMES = [
        'HostName', 'ProxyCommand', 'ProxyJump', 'LocalCommand', 'StrictHostKeyChecking',
        'UserKnownHostsFile', 'GlobalKnownHostsFile', 'IdentityAgent', 'IdentityFile',
        'CertificateFile', 'ForceCommand', 'ClientAliveInterval', 'ExecReload',
    ];

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
     * RT-34 (repo-tooling-037): the tag/attribute deny-list for every UNTRUSTED
     * HTML fragment the generators embed. The `docs/_data/<slug>.body.html`
     * store (and, on the narrative pages, the markdown converter's raw-HTML
     * passthrough) reach https://sugarcraft.github.io verbatim, so anyone who
     * can open a merged PR against those sources can otherwise plant script
     * there — no auth and no cookies on the target, but a fully trusted origin:
     * phishing, brand damage, drive-by. Parse-don't-validate at the render
     * boundary: find the residue, refuse the build, name it. The copies of
     * this body across gen-docs.php, gen-doc-pages.php and this file are
     * token-pinned against each other by tools/tests/HtmlResidueDriftTest.php
     * — the three enforcement points cannot drift apart, and the committed
     * corpus is re-checked retroactively rather than assumed clean.
     *
     * @return list<string> human-readable findings; [] means the fragment is clean
     */
    public static function htmlResidueProblems(string $html): array
    {
        $problems = [];
    // The repo's own canonical demo-GIF crutch (gen-docs.php's
    // DEMO_GIF_ONERROR, demanded by check-docs-assets' crutch leg) is the ONE
    // allow-listed attribute, and ONLY as the exact full literal — anything
    // merely shaped like an onerror= stays residue.
        $html = str_replace('onerror="this.parentElement.style.display=\'none\'"', 'ALLOWED_DEMO_CRUTCH', $html);
        if (preg_match_all('/<\s*(script|iframe|object|embed|form|base)\b/i', $html, $tags) === 1) {
            foreach (array_values(array_unique($tags[1])) as $tag) {
                $problems[] = 'disallowed tag <' . strtolower($tag) . '>';
            }
        }
        if (preg_match_all('/<\s*meta[^>]*http-equiv/is', $html) === 1) {
            $problems[] = 'disallowed meta http-equiv channel';
        }
        if (preg_match_all('/<[a-zA-Z][^>]*\son[a-zA-Z:]+\s*=/is', $html, $attrs) === 1) {
            foreach ($attrs[0] as $fragment) {
                if (preg_match('/\s(on[a-zA-Z:]+)\s*=/i', $fragment, $one) === 1) {
                    $problems[] = 'event handler attribute ' . strtolower($one[1]);
                }
            }
        }
        if (preg_match_all('/(href|src|action|xlink:href)\s*=\s*([\'"]?)\s*(javascript|vbscript)\s*:/i', $html, $urls) === 1) {
            foreach (array_values(array_unique($urls[3])) as $scheme) {
                $problems[] = 'scriptable URL scheme ' . strtolower($scheme) . ':';
            }
        }
        if (preg_match_all('/(href|src|action|xlink:href)\s*=\s*([\'"]?)\s*data\s*:\s*text\s*\/\s*html/is', $html) === 1) {
            $problems[] = 'scriptable URL scheme data:text/html';
        }

        return array_values(array_unique($problems));
    }

    /**
     * RT-34: the SECOND enforcement point — already-committed `docs/_data/
     * *.body.html` fragments re-pass the same deny-list retroactively, so
     * residue that landed before the gate (or via a path that skipped
     * gen-docs) reddens CI rather than sitting published. Scratch --root
     * trees legitimately carry minimal or absent corpora: the real-tree
     * floor lives in tools/tests/HtmlResidueDriftTest, not here.
     *
     * @return list<string>
     */
    private static function htmlResidue(string $root): array
    {
        $findings = [];
        foreach (glob("$root/docs/_data/*.body.html") ?: [] as $file) {
            foreach (self::htmlResidueProblems(self::readCached($file)) as $problem) {
                $findings[] = self::relative($root, $file) . ': ' . $problem;
            }
        }

        return $findings;
    }

    /**
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
            self::htmlResidue($root),
            self::bodyClaims($root),
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
     * (e) every demo-GIF <img> carries exactly one onerror fallback crutch,
     * in the shape that belongs to its surface:
     *  - generated detail pages (docs/lib/*.html): qualification mirrors the
     *    generator exactly — a tag is a demo GIF when its src path
     *    (query/fragment stripped, lowercase) ends in .gif — so chrome
     *    near-misses (icon PNGs, Codecov SVGs, a slug NAMED gifview) can
     *    never false-fire, and non-gif imgs are nobody's business.
     *    "Exactly one" covers both directions: the crutches the generator
     *    injects and sugar-dashboard's hand-authored ones must not be
     *    doubled, and an injected one must not go missing.
     *  - the hand-maintained catalogue pages (docs/index.html,
     *    docs/libraries.html, docs/apps.html — SD-S14): each hotlinked
     *    demo GIF must carry the hide-the-img crutch (pin below) — the
     *    generator's parent-hide literal is deliberately NOT used there
     *    because on a catalogue card it would delete the whole card, and
     *    must carry real width/height attributes (layout-stable even when
     *    the swap or hide fires).
     *
     * The pinned root literal; keep docs/*.{html hand copies} in sync with it.
     */
    private const ROOT_GIF_ONERROR = 'onerror="this.style.display=\'none\'"';

    /** @return list<string> */
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

        foreach (['docs/index.html', 'docs/libraries.html', 'docs/apps.html'] as $page) {
            $html = (string) self::readCached("$root/$page");
            preg_match_all('~<img\b[^>]*>~i', $html, $tags);
            foreach ($tags[0] as $tag) {
                if (preg_match('~\bsrc="([^"]*)"~i', $tag, $src) !== 1) {
                    continue;
                }
                $path = strtolower((string) (preg_split('/[?#]/', $src[1], 2)[0] ?? ''));
                if (!str_ends_with($path, '.gif') || !str_contains($src[1], 'raw.githubusercontent.com')) {
                    continue;
                }
                if (strpos($tag, self::ROOT_GIF_ONERROR) === false || preg_match_all('~\bonerror\s*=~i', $tag) !== 1) {
                    $findings[] = 'chrome: ' . $page . ' gif img src="' . $src[1]
                        . '" lacks the pinned hide-the-img crutch (exactly one ' . self::ROOT_GIF_ONERROR . ')';
                }
                if (preg_match('~\bwidth="\d+"~i', $tag) !== 1 || preg_match('~\bheight="\d+"~i', $tag) !== 1) {
                    $findings[] = 'chrome: ' . $page . ' gif img src="' . $src[1]
                        . '" lacks width/height attributes (layout shift on load failure)';
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
     * SD-S28: the two admin/dev pages under docs/admin/ + docs/dev/ ship the
     * nav chrome (they carry <div class="nav-links">) but predate the nav
     * marker pair, so they join a REDUCED roster only — their local links and
     * RAW-gif references are checked, the byte-identity leg is not. Folding
     * them into chromePages() would force marker-pair adoption and the
     * five-seat G-Guard re-pin; that sizing is an owner decision (P5).
     *
     * @return list<string>
     */
    private static function adminDevPages(string $root): array
    {
        return array_values(array_filter([
            "$root/docs/admin/sugar-pty.html",
            "$root/docs/dev/sugar-pty.html",
        ], 'is_file'));
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
        foreach (self::adminDevPages($root) as $page) {
            $targets[] = $page;
        }

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
     * (i)-(k) the mechanically checkable claims in the hand-written bodies
     * (`docs/_data/<slug>.body.html`), which reach the published site verbatim.
     * Every finding names the body and the claim, so a wrong claim is fixed in
     * the body, never by loosening the guard.
     *
     * Scope: leg (i) reads static calls (`Name::method(`) and constructors
     * (`new Name(`, in code only). An instance call (`$var->method(`) is not
     * read, so a method a body names on an object is not checked here.
     *
     * $census receives what the legs looked at (`bodies`, `claims`), so a caller
     * can pin a non-trivial corpus: no findings is vacuous on a tree the legs
     * never scanned (RT-20).
     *
     * @param array{bodies?: int, claims?: int} $census
     * @return list<string>
     */
    public static function bodyClaims(string $root, ?array &$census = null): array
    {
        // A direct caller may rewrite a body between calls, so this entry point
        // never trusts a memo an earlier call left behind (derive() resets it too).
        self::$readCache = [];
        $bodies = glob("$root/docs/_data/*.body.html") ?: [];
        $census = ['bodies' => count($bodies), 'claims' => 0];
        if ($bodies === []) {
            return [];
        }
        $index = self::classIndex($root);
        $nonClass = self::nonClassSymbols($root);

        $findings = [];
        foreach ($bodies as $file) {
            $rel = self::relative($root, $file);
            $slug = (string) preg_replace('~\.body\.html$~', '', basename($file));
            $html = self::readCached($file);

            // (i) `Name::method(` and `new Name(` — the static-call and
            // constructor shapes a body uses to name its API. The namespace
            // prefix is matched in any case, so a wrongly cased SugarCraft\
            // claim is still checked rather than silently dropped.
            // A body's own example classes and classes imported from outside
            // SugarCraft are illustrative or third-party, not claims about the
            // monorepo, and are skipped by rule.
            [$declared, $external, $imports] = self::bodyNames($html);
            $claims = [];
            preg_match_all('~(\\\\?(?:[A-Za-z_]\w*\\\\)*[A-Z][A-Za-z0-9_]*)::([A-Za-z_]\w*)\s*\(~', $html, $calls, PREG_SET_ORDER);
            foreach ($calls as $hit) {
                $claims[] = [$hit[1], $hit[2]];
            }
            // A constructor is a code claim only inside code: prose such as "a new
            // Session (opened by the loop)" names no class the body uses.
            preg_match_all('~<(pre|code)\b[^>]*>(.*?)</\1>~s', $html, $codeSpans, PREG_PATTERN_ORDER);
            preg_match_all('~\bnew\s+(\\\\?(?:[A-Za-z_]\w*\\\\)*[A-Z][A-Za-z0-9_]*)\s*\(~', implode("\n", $codeSpans[2]), $news, PREG_SET_ORDER);
            foreach ($news as $hit) {
                $claims[] = [$hit[1], null];
            }
            foreach (array_unique(array_map('serialize', $claims)) as $key) {
                [$name, $method] = unserialize($key);
                if (self::isIllustrativeName($name, $declared, $external, $imports)) {
                    continue;
                }
                $census['claims']++;
                $problem = self::undeclaredClaim($index, $name, $method, $imports);
                if ($problem !== null) {
                    $findings[] = "claims: $rel $problem";
                }
            }

            // (i) bare names: a PascalCase name alone in an inline code span
            // names a class too (`<code>ResizeMsg</code>`), so it must resolve
            // like a `Name::method()` claim. Only inline spans count; a `<pre>`
            // block is code that the static-call and constructor shapes above
            // already read. A class the snippet declares does not exempt the
            // prose span of that name: the span may be a SugarCraft claim, and
            // only a resolvable class settles it. An explicit import from outside
            // SugarCraft still does, since the `use` says which class is meant.
            foreach (self::bareCodeSpanNames($html) as $name) {
                if (self::isIllustrativeName($name, [], $external, $imports) || self::isNonClassName($name, $nonClass)) {
                    continue;
                }
                $census['claims']++;
                $problem = self::undeclaredClaim($index, $name, null, $imports);
                if ($problem !== null) {
                    $findings[] = "claims: $rel $problem";
                }
            }

            // (j) `examples/<file>` — a file path, so the extension is required
            // and a bare directory mention ("examples/") is not a claim.
            preg_match_all('~((?:[A-Za-z0-9_.-]+/)*)examples/([A-Za-z0-9_./-]+\.[A-Za-z0-9]+)~', $html, $examples, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($examples as $hit) {
                if (self::isFilesystemPlaceholder($html, $hit[0][1], $hit[1][0]) || self::isForeignUrl($html, $hit[0][1], $hit[1][0])) {
                    continue;
                }
                $census['claims']++;
                $path = self::claimOwner($hit[1][0], $slug) . '/examples/' . $hit[2][0];
                if (!is_file("$root/$path")) {
                    $findings[] = "claims: $rel names $path, which does not exist";
                }
            }

            // (k) `.vhs/<path>` — tapes and rendered GIFs both live beside the
            // package they demo. Any depth and any extension is a claim: a
            // subdirectory or a .png named here must exist as written.
            preg_match_all('~((?:[A-Za-z0-9_.-]+/)*)\.vhs/((?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.[A-Za-z0-9]+)~', $html, $vhs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($vhs as $hit) {
                if (self::isFilesystemPlaceholder($html, $hit[0][1], $hit[1][0]) || self::isForeignUrl($html, $hit[0][1], $hit[1][0])) {
                    continue;
                }
                $census['claims']++;
                $path = self::claimOwner($hit[1][0], $slug) . '/.vhs/' . $hit[2][0];
                if (!is_file("$root/$path")) {
                    $findings[] = "vhs: $rel names $path, which does not exist";
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * The bare class-shaped names a body writes alone in an inline code span
     * (`<code>ResizeMsg</code>`), with `<pre>` blocks removed first. A span with
     * anything else in it (`<code>Foo::bar()</code>`, `<code>php examples/x.php</code>`)
     * is not a bare name and is read by the other legs.
     *
     * @return list<string>
     */
    private static function bareCodeSpanNames(string $html): array
    {
        $prose = (string) preg_replace('~<pre\b[^>]*>.*?</pre>~s', '', $html);
        preg_match_all('~<code\b[^>]*>([^<]*)</code>~', $prose, $spans);

        $names = [];
        foreach ($spans[1] as $span) {
            $name = trim($span);
            if (preg_match(self::BARE_CLASS_SHAPE, $name) === 1 && preg_match('~[a-z]~', $name) === 1) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The names the monorepo itself declares as something other than a class:
     * every method, enum case and constant under a `<slug>/src/` tree, every
     * registered tool name a source file declares (see below), every class a
     * `<slug>/tests/` tree declares (a test fixture, never a library class), and
     * every package name (the Pascal form of a `<slug>/` directory, such as
     * `SugarSpring`). Keys are lowercase.
     *
     * Only a registered tool name counts as a string: the value of a `const NAME`
     * or a `name:` attribute argument (`#[BuiltInTool(name: 'AskUser')]`), read in
     * code only, so a docblock example does not register anything. A `name:`
     * named argument to a constructor or call (`new Foo(name: 'X')`) is not an
     * attribute and registers nothing. Any other quoted word is left checked, so a
     * body's phantom class that merely appears as a string in a source file is
     * still named. Excluding every quoted word would also excuse exactly that class.
     *
     * @return array<string,true>
     */
    private static function nonClassSymbols(string $root): array
    {
        $symbols = [];
        foreach (glob("$root/*/src", GLOB_ONLYDIR) ?: [] as $src) {
            foreach (self::phpFiles($src) as $path) {
                $text = self::readCached($path);
                $code = self::withoutComments($text);
                preg_match_all('~\bfunction\s+&?([A-Za-z_]\w*)\s*\(~', $text, $m);
                preg_match_all('~^\s*case\s+([A-Za-z_]\w*)\s*[=;]~m', $text, $c);
                preg_match_all('~\bconst\s+(?:[A-Za-z_]\w*\s+)?([A-Za-z_]\w*)\s*=~', $text, $k);
                preg_match_all('~\bconst\s+NAME\s*=\s*[\'"]([A-Za-z_][\w-]*)[\'"]~', $code, $tool);
                $attr = [];
                foreach (self::attributeBodies($code) as $body) {
                    preg_match_all('~\bname:\s*[\'"]([A-Za-z_][\w-]*)[\'"]~', $body, $named);
                    array_push($attr, ...$named[1]);
                }
                foreach ([$m[1], $c[1], $k[1], $tool[1], $attr] as $names) {
                    foreach ($names as $name) {
                        $symbols[strtolower($name)] = true;
                    }
                }
            }
        }
        // A package name is the library, not a class: `SugarSpring` names the
        // sugar-spring package, so it is never a class claim.
        foreach (glob("$root/*/composer.json") ?: [] as $manifest) {
            $pascal = str_replace(' ', '', ucwords(str_replace('-', ' ', basename(dirname($manifest)))));
            $symbols[strtolower($pascal)] = true;
        }
        foreach (glob("$root/*/tests", GLOB_ONLYDIR) ?: [] as $tests) {
            foreach (self::phpFiles($tests) as $path) {
                preg_match_all(
                    '~^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)~m',
                    self::readCached($path),
                    $decl,
                );
                foreach ($decl[1] as $name) {
                    $symbols[strtolower($name)] = true;
                }
            }
        }

        return $symbols;
    }

    /**
     * The argument text of every `#[...]` attribute in $code, which is code with
     * its comments already removed (withoutComments()). Nested brackets in an
     * argument (`#[Tags(['a'])]`) are kept whole.
     *
     * @return list<string>
     */
    private static function attributeBodies(string $code): array
    {
        preg_match_all('~#\[((?:[^\[\]]++|\[[^\[\]]*\])*)\]~', $code, $attrs);

        return $attrs[1];
    }

    /** $code with every comment token removed, so a docblock example is never read as code. */
    private static function withoutComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Every `.php` file under $dir, recursively.
     *
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * True for a bare code-span name that is not a class: a test or fixture
     * class, an environment variable, a key name, a product or tool name, a
     * configuration directive, or a name the monorepo declares as a method, enum
     * case, constant or string (see nonClassSymbols()). The fixed lists are the
     * constants above; every entry is one a body has written and the guard must
     * not read as a class claim.
     *
     * @param array<string,true> $nonClass from nonClassSymbols()
     */
    private static function isNonClassName(string $name, array $nonClass): bool
    {
        $bare = ltrim($name, '\\');
        if (str_ends_with($bare, 'Test') || str_ends_with($bare, 'Tests')) {
            return true;
        }
        if (preg_match('~^[A-Z][A-Z0-9_]*$~', $bare) === 1 || isset($nonClass[strtolower($bare)])) {
            return true;
        }
        foreach ([self::EXCLUDED_KEY_NAMES, self::EXCLUDED_ENV_NAMES, self::EXCLUDED_PRODUCT_NAMES, self::EXCLUDED_CONFIG_NAMES, self::EXCLUDED_THIRD_PARTY_NAMES] as $list) {
            foreach ($list as $excluded) {
                if (strcasecmp($excluded, $bare) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A root-absolute filesystem path (`/path/to/examples/x.sh`) is a placeholder
     * the reader fills in with their own checkout, not a claim about this tree.
     * A URL is not one: its first segment is a host (`github.com/...`), which
     * always carries a dot.
     */
    private static function isFilesystemPlaceholder(string $html, int $offset, string $prefix): bool
    {
        if ($offset === 0 || $html[$offset - 1] !== '/') {
            return false;
        }
        $first = explode('/', $prefix, 2)[0];

        return !str_contains($first, '.');
    }

    /**
     * A path inside an absolute URL whose owner is not a SugarCraft account
     * (`https://github.com/someone/else/blob/main/examples/x.py`) is a third-party
     * link, not a claim about a package here, so the example and vhs legs skip it.
     * The owner is the segment after the host: `sugarcraft` or `detain` (the repo
     * owner in the raw.githubusercontent.com form) keep the claim checked.
     */
    private static function isForeignUrl(string $html, int $offset, string $prefix): bool
    {
        if ($offset < 2 || substr($html, $offset - 2, 2) !== '//') {
            return false;
        }
        $segments = array_values(array_filter(explode('/', $prefix), static fn (string $p): bool => $p !== ''));

        return !in_array(strtolower($segments[1] ?? ''), ['sugarcraft', 'detain'], true);
    }

    /**
     * The class names a body declares in its code blocks, the short names it
     * imports from SugarCraft (every FQN imported under that short name, keyed
     * lowercase: one snippet's `Terminal` may be a different class from
     * another's), and the short names it imports from outside SugarCraft. Only
     * `<pre>` blocks count, so prose that happens to say "class Foo" never
     * exempts a claim.
     *
     * @return array{0: array<string,true>, 1: array<string,true>, 2: array<string,list<string>>}
     */
    private static function bodyNames(string $html): array
    {
        $declared = [];
        $external = [];
        $imports = [];
        preg_match_all('~<pre\b[^>]*>(.*?)</pre>~s', $html, $blocks);
        foreach ($blocks[1] as $code) {
            preg_match_all('~(?:^|[\n>])\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)~', $code, $decl);
            foreach ($decl[1] as $name) {
                $declared[$name] = true;
            }
            preg_match_all('~(?:^|[\n>])\s*use\s+\\\\?((?:[A-Za-z_]\w*\\\\)*)([A-Za-z_]\w*)(?:\s+as\s+([A-Za-z_]\w*))?\s*;~', $code, $uses, PREG_SET_ORDER);
            foreach ($uses as $hit) {
                if ($hit[1] === '') {
                    continue;
                }
                // `use X as Y` binds the name Y, so the claims in the snippet name X through Y.
                $bound = $hit[3] ?? $hit[2];
                $fqn = $hit[1] . $hit[2];
                if (self::isSugarcraftName($fqn)) {
                    $imports[strtolower($bound)][$fqn] = $fqn;
                } else {
                    $external[$bound] = true;
                }
            }
        }

        return [$declared, $external, array_map('array_values', $imports)];
    }

    /**
     * True when a claimed class name is outside the monorepo's claims: a fully
     * qualified name outside SugarCraft\, a bare name the body imports from
     * outside SugarCraft, or a bare class the body declares for its own example.
     * A SugarCraft\ name is never illustrative, even when the body declares a
     * class with the same short name, and neither is a bare name the body
     * imports from SugarCraft: that import says which class the name means, and
     * a same-named declaration in another snippet does not change it.
     *
     * @param array<string,true> $declared
     * @param array<string,true> $external
     * @param array<string,list<string>> $imports
     */
    private static function isIllustrativeName(string $name, array $declared, array $external, array $imports): bool
    {
        $trimmed = ltrim($name, '\\');
        if (self::isSugarcraftName($trimmed)) {
            return false;
        }
        if (str_contains($trimmed, '\\')) {
            return true;
        }
        $short = self::shortName($trimmed);
        if (isset($imports[strtolower($short)])) {
            return false;
        }

        return isset($declared[$short]) || isset($external[$short]);
    }

    /**
     * True for a name inside the SugarCraft\ namespace. PHP namespaces are
     * case-insensitive, so the prefix is compared folded.
     */
    private static function isSugarcraftName(string $name): bool
    {
        return str_starts_with(strtolower(ltrim($name, '\\')), 'sugarcraft\\');
    }

    /**
     * Resolves one claimed name against the index and reports why it fails, or
     * returns null when the claim holds.
     *
     * A qualified name, or one the body imports, names the FQN it spells (or the
     * FQNs its imports spell), so a wrong namespace or a method carried only by a
     * same-named class elsewhere is named. A bare name the body does not import is
     * matched by short name across every declaration, as it always was: qualifying
     * those snippets is a body-by-body edit the guard does not force.
     *
     * @param array{fqn: array<string, list<string>>, short: array<string, list<string>>} $index
     * @param array<string,list<string>> $imports lowercased short name → SugarCraft FQNs
     * @return ?string the finding's claim text, or null when the claim holds
     */
    private static function undeclaredClaim(array $index, string $name, ?string $method, array $imports): ?string
    {
        $written = ltrim($name, '\\');
        $short = self::shortName($written);
        $lower = strtolower($short);

        $options = str_contains($written, '\\') ? [$written] : ($imports[$lower] ?? []);
        if ($options !== []) {
            $known = array_values(array_filter(
                $options,
                static fn (string $fqn): bool => isset($index['fqn'][strtolower($fqn)]),
            ));
            if ($known === []) {
                return 'names class ' . implode(' or ', $options) . ', which no <slug>/src declares';
            }

            return self::methodProblem($index, $known, count($options) === 1 ? $options[0] : $short, $method);
        }

        $candidates = $index['short'][$lower] ?? [];
        if ($candidates !== []) {
            return self::methodProblem($index, $candidates, $short, $method);
        }

        $internal = self::internalClass($short);
        if ($internal === null) {
            return "names class $short, which no <slug>/src declares";
        }
        if ($method === null || method_exists($internal, $method)) {
            return null;
        }

        return "names $short::$method(), which PHP's $short does not define";
    }

    /**
     * Null when the claim holds: no method is claimed, or one of the declared
     * classes $fqns carries it. Otherwise the finding text, naming $display.
     *
     * @param list<string> $fqns
     */
    private static function methodProblem(array $index, array $fqns, string $display, ?string $method): ?string
    {
        if ($method === null) {
            return null;
        }
        foreach ($fqns as $fqn) {
            $visited = [];
            if (self::declaresMethod($index, $fqn, $method, $visited)) {
                return null;
            }
        }

        return "names $display::$method(), but no declaration of $display carries that method";
    }

    /**
     * Class declarations under every `<slug>/src/` tree. `fqn` maps a lowercase
     * fully qualified name to its declaring files; `short` maps a lowercase short
     * name to every FQN that declares it. The scan is top-level only (`*\/src`),
     * so a lib's vendor/ copies of sibling packages never count as declarations.
     *
     * @return array{fqn: array<string, list<string>>, short: array<string, list<string>>}
     */
    private static function classIndex(string $root): array
    {
        $fqn = [];
        $short = [];
        foreach (glob("$root/*/src", GLOB_ONLYDIR) ?: [] as $src) {
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($walk as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $path = $file->getPathname();
                $text = self::readCached($path);
                $ns = self::fileScope($text)['ns'];
                preg_match_all(
                    '~^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)~m',
                    $text,
                    $decl,
                );
                foreach (array_unique($decl[1]) as $name) {
                    $full = $ns === '' ? $name : "$ns\\$name";
                    $fqn[strtolower($full)][] = $path;
                    $short[strtolower($name)][$full] = $full;
                }
                // A façade binds its public name with class_alias(); the alias is a
                // real class name in the tree, so it is declared here too.
                foreach (self::classAliases($text, self::fileScope($text)) as $alias => $target) {
                    $fqn[strtolower($alias)][] = $path;
                    $short[strtolower(self::shortName($alias))][$alias] = $alias;
                }
            }
        }

        return ['fqn' => $fqn, 'short' => array_map('array_values', $short)];
    }

    /**
     * The `class_alias(Target::class, 'Alias')` bindings a file makes, as alias
     * FQN => target FQN, both resolved in the file's own namespace and imports.
     *
     * @param array{ns: string, uses: array<string,string>} $scope
     * @return array<string,string>
     */
    private static function classAliases(string $text, array $scope): array
    {
        $aliases = [];
        preg_match_all(
            '~class_alias\(\s*(\\\\?[A-Za-z_][\w\\\\]*)::class\s*,\s*[\'"]\\\\?([A-Za-z_][\w\\\\]*)[\'"]~',
            $text,
            $hits,
            PREG_SET_ORDER,
        );
        foreach ($hits as $hit) {
            $aliases[ltrim($hit[2], '\\')] = self::resolveName($hit[1], $scope);
        }

        return $aliases;
    }

    /**
     * The namespace and the `use` imports of one PHP file, so a name written in
     * it resolves to the FQN it means rather than to any same-named class.
     *
     * @return array{ns: string, uses: array<string,string>} uses keyed lowercase
     */
    private static function fileScope(string $text): array
    {
        $ns = '';
        if (preg_match('~^\s*namespace\s+([A-Za-z_][\w\\\\]*)\s*;~m', $text, $m) === 1) {
            $ns = $m[1];
        }
        $uses = [];
        preg_match_all('~^use\s+\\\\?([A-Za-z_][\w\\\\]*)(?:\s+as\s+([A-Za-z_]\w*))?\s*;~m', $text, $imports, PREG_SET_ORDER);
        foreach ($imports as $hit) {
            $alias = $hit[2] ?? self::shortName($hit[1]);
            $uses[strtolower($alias)] = $hit[1];
        }

        return ['ns' => $ns, 'uses' => $uses];
    }

    /**
     * Resolves a name as written in a file to the FQN it names there.
     *
     * @param array{ns: string, uses: array<string,string>} $scope
     */
    private static function resolveName(string $ref, array $scope): string
    {
        if (str_starts_with($ref, '\\')) {
            return substr($ref, 1);
        }
        if (str_contains($ref, '\\')) {
            return $ref;
        }
        $lower = strtolower($ref);
        if (isset($scope['uses'][$lower])) {
            return $scope['uses'][$lower];
        }

        return $scope['ns'] === '' ? $ref : $scope['ns'] . '\\' . $ref;
    }

    /**
     * True when the declaration of $fqn carries $method, directly or through a
     * trait or parent it names (each resolved in its own file's namespace and
     * imports). $visited breaks cycles.
     *
     * @param array{fqn: array<string, list<string>>, short: array<string, list<string>>} $index
     * @param array<string,true> $visited
     */
    private static function declaresMethod(array $index, string $fqn, string $method, array &$visited): bool
    {
        $short = self::shortName($fqn);
        $files = $index['fqn'][strtolower($fqn)] ?? [];
        if ($files === []) {
            // A parent the SugarCraft tree does not declare may be a PHP built-in
            // (`extends \RuntimeException`), and the method can come from there.
            return self::builtInDeclaresMethod($fqn, $method);
        }
        foreach ($files as $file) {
            if (isset($visited[$file])) {
                continue;
            }
            $visited[$file] = true;
            $text = self::readCached($file);
            if (self::declaresPublicMethod($text, $short, $method)) {
                return true;
            }
            // PHP supplies cases() on every enum and from()/tryFrom() on a backed
            // one, so no `function` line declares them.
            $implied = strtolower($method);
            if (in_array($implied, ['cases', 'from', 'tryfrom'], true)
                && preg_match('~\benum\s+' . preg_quote($short, '~') . '\b(\s*:)?~', $text, $enum) === 1
            ) {
                if ($implied === 'cases' || isset($enum[1])) {
                    return true;
                }
            }
            $scope = self::fileScope($text);
            foreach (self::classAliases($text, $scope) as $alias => $target) {
                if (strtolower($alias) === strtolower($fqn) && self::declaresMethod($index, $target, $method, $visited)) {
                    return true;
                }
            }
            foreach (array_unique(self::typeRefs($text)) as $ref) {
                if (self::declaresMethod($index, self::resolveName($ref, $scope), $method, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when the type $short declared in $text carries $method with public
     * visibility. A declaration with no modifier is public; a private or
     * protected one cannot be called as `Name::method()` from a body (PHP
     * fatals), so it does not satisfy a claim.
     *
     * Only the named type's own member level counts. The body is found by its
     * header and brace depth, so a method inside an anonymous class (`new class
     * { ... }`), a closure, or any other type the file declares never satisfies
     * a claim about $short. Method names compare case-insensitively, as PHP's do.
     */
    private static function declaresPublicMethod(string $text, string $short, string $method): bool
    {
        $tokens = \PhpToken::tokenize($text);
        $count = count($tokens);
        $wantedType = strtolower($short);
        $wantedMethod = strtolower($method);
        $depth = 0;
        $memberDepth = null;
        $awaitBody = false;

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if ($tok->id === T_CURLY_OPEN || $tok->id === T_DOLLAR_OPEN_CURLY_BRACES || $tok->text === '{') {
                if ($awaitBody) {
                    $memberDepth = $depth + 1;
                    $awaitBody = false;
                }
                $depth++;
                continue;
            }
            if ($tok->text === '}') {
                $depth--;
                if ($memberDepth !== null && $depth < $memberDepth) {
                    return false;
                }
                continue;
            }
            if ($memberDepth === null) {
                if ($tok->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                    $name = self::nextSignificant($tokens, $i + 1);
                    $awaitBody = $name !== null
                        && $tokens[$name]->id === T_STRING
                        && strtolower($tokens[$name]->text) === $wantedType;
                }
                continue;
            }
            if ($depth !== $memberDepth || $tok->id !== T_FUNCTION) {
                continue;
            }
            // A method may be named with a reserved word (`new()`, `match()`,
            // `list()`), which the tokenizer reads as a keyword, not T_STRING.
            $name = self::nextSignificant($tokens, $i + 1, '&');
            if ($name === null || preg_match('~^[A-Za-z_]\w*$~', $tokens[$name]->text) !== 1
                || strtolower($tokens[$name]->text) !== $wantedMethod
            ) {
                continue;
            }
            // The modifiers sit in the run of keywords directly before `function`.
            $modifiers = '';
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($tokens[$j]->isIgnorable()) {
                    continue;
                }
                if (!$tokens[$j]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT])) {
                    break;
                }
                $modifiers .= ' ' . strtolower($tokens[$j]->text);
            }
            if (!str_contains($modifiers, 'private') && !str_contains($modifiers, 'protected')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The index of the first token at or after $from that is neither whitespace
     * nor a comment, skipping any listed single-character tokens too; null at the
     * end of the file.
     *
     * @param list<\PhpToken> $tokens
     * @param string ...$skip single-character tokens to step over (`&` in `function &name`)
     */
    private static function nextSignificant(array $tokens, int $from, string ...$skip): ?int
    {
        for ($k = $from, $n = count($tokens); $k < $n; $k++) {
            if ($tokens[$k]->isIgnorable() || in_array($tokens[$k]->text, $skip, true)) {
                continue;
            }

            return $k;
        }

        return null;
    }

    /**
     * The types a file's declarations pull in: every parent and interface named
     * in a class, interface or enum header (a comma list after `implements`, or
     * after `extends` on an interface), and every trait a class body uses. A
     * top-level `use` is an import, not an inheritance edge, so it is not followed:
     * a class that merely imports a sibling does not carry the sibling's methods.
     *
     * @return list<string> names as written, a leading backslash kept (it marks a fully qualified name)
     */
    private static function typeRefs(string $text): array
    {
        $list = '(\\\\?[A-Za-z_][\w\\\\]*(?:\s*,\s*\\\\?[A-Za-z_][\w\\\\]*)*)';
        $refs = [];
        preg_match_all('~^[ \t]*(?:(?:abstract|final|readonly)[ \t]+)*(?:class|interface|enum)[ \t]+[A-Za-z_]\w*([^{;]*)~m', $text, $headers);
        foreach ($headers[1] as $header) {
            preg_match_all('~\b(?:extends|implements)\s+' . $list . '~', $header, $clauses);
            foreach ($clauses[1] as $names) {
                array_push($refs, ...preg_split('~\s*,\s*~', $names));
            }
        }
        preg_match_all('~^[ \t]+use[ \t]+' . $list . '[ \t]*[;{]~m', $text, $traits);
        foreach ($traits[1] as $names) {
            array_push($refs, ...preg_split('~\s*,\s*~', $names));
        }

        return $refs;
    }

    /**
     * True when $fqn names a PHP built-in class, interface or enum that carries
     * $method, so a SugarCraft class extending it inherits the method with no
     * SugarCraft declaration to find it in.
     */
    private static function builtInDeclaresMethod(string $fqn, string $method): bool
    {
        if (!class_exists($fqn, false) && !interface_exists($fqn, false) && !enum_exists($fqn, false)) {
            return false;
        }

        return (new \ReflectionClass($fqn))->isInternal() && method_exists($fqn, $method);
    }

    /** The PHP built-in class, interface or enum called $short, or null when there is none. */
    private static function internalClass(string $short): ?string
    {
        if (!class_exists($short, false) && !interface_exists($short, false) && !enum_exists($short, false)) {
            return null;
        }

        return (new \ReflectionClass($short))->isInternal() ? $short : null;
    }

    /**
     * The package a body's example or vhs path belongs to: the last path
     * segment that is not a URL scaffold (`github.com/sugarcraft/<slug>/blob/
     * master/`, `detain/sugarcraft/master/<slug>/`), else the body's own slug.
     */
    private static function claimOwner(string $prefix, string $bodySlug): string
    {
        $scaffold = ['.', 'github.com', 'sugarcraft', 'detain', 'blob', 'tree', 'master', 'main', 'raw.githubusercontent.com'];
        $parts = array_values(array_filter(explode('/', $prefix), static fn (string $p): bool => $p !== ''));
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if (!in_array($parts[$i], $scaffold, true)) {
                return $parts[$i];
            }
        }

        return $bodySlug;
    }

    private static function shortName(string $name): string
    {
        $parts = explode('\\', $name);

        return (string) end($parts);
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
        // SD-S28: the admin/dev chrome pages now share the RAW-gif leg too —
        // zero references today, but the leg must not stay blind to pages
        // that carry nav chrome.
        foreach (self::adminDevPages($root) as $page) {
            $files[] = $page;
        }
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
