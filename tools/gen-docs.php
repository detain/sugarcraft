<?php

declare(strict_types=1);

/**
 * tools/gen-docs.php — single-source generator for the per-library detail
 * pages under docs/lib/<slug>.html.
 *
 * The 58 detail pages share one chrome (head meta, nav, footer, search
 * widget) that used to be hand-maintained per page — the root cause of a
 * class of broken-link / 404 / stale-icon bugs. This tool derives all of
 * that chrome from a single source of truth (docs/MATCHUPS.md + the slug)
 * and merges it with the genuinely hand-authored per-lib body content.
 *
 * It also OWNS the homepage's library/app counts: on every default run it patches
 * docs/index.html so the ~8 count tokens (meta/og/JSON-LD descriptions, hero prose,
 * the two .stat numerals, the two section headings) always equal the authoritative
 * MATCHUPS split (39 libraries / 19 apps), the same numbers the lib-page breadcrumbs
 * carry — killing the drift class where the homepage and the detail pages disagreed.
 *
 * Modes:
 *   php tools/gen-docs.php --extract    Rebuild the data store (docs/_data/<slug>.json
 *                                       + docs/_data/<slug>.body.html) from the current pages.
 *   php tools/gen-docs.php              Regenerate every docs/lib/<slug>.html from the data store
 *                                       and patch docs/index.html's counts to the MATCHUPS split.
 *   php tools/gen-docs.php --check      Generate/patch in-memory and fail (exit 1) if any page or
 *                                       the index.html counts on disk differ — a CI drift guard.
 *
 * Data model per slug:
 *   DERIVED   (never stored) — package, canonical/og:url/og:image, icon, source/packagist/
 *             issues/codecov URLs, type (library|app) + counts. Sourced from the slug and
 *             from which docs/MATCHUPS.md table the row lives in.
 *   METADATA  (docs/_data/<slug>.json) — the SEO/identity fields that vary per page but have
 *             no other authoritative home: title, description, ogTitle, ogDescription, emoji,
 *             displayName, tagline, detailMeta (tag chips), phpVersion, and an optional
 *             prefixOverride for pages whose chrome legitimately diverges (candy-core).
 *   BODY      (docs/_data/<slug>.body.html) — the hand-authored content fragment (lede →
 *             the Demos section close), stored verbatim so no content is ever lost.
 *
 * The generator applies these normalizations uniformly (they are the ONLY intended diffs
 * versus the previous hand-maintained pages):
 *   - drops the onerror="" icon-hiding crutch (every slug now has a real icon);
 *   - rewrites github.com/sugarcraft/<slug> tree/main + blob/main links to /master;
 *   - normalizes every "port of" chip label to owner/repo form derived from the URL;
 *   - fixes the breadcrumb type (library vs app) per MATCHUPS and adds the "N libraries/apps" count;
 *   - fixes the footer Contributing link (some pages point at github.com/sugarcraft/blob/... , a 404);
 *   - adds a per-lib Codecov badge (W14.3);
 *   - ends every file with a single trailing newline.
 */

const SLUG_RE = '[a-z0-9]+(?:-[a-z0-9]+)+';

$root    = dirname(__DIR__);
$docs    = $root . '/docs';
$libDir  = $docs . '/lib';
$dataDir = $docs . '/_data';
$matchups = $docs . '/MATCHUPS.md';
$index    = $docs . '/index.html';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['', '--extract', '--check'], true)) {
    fwrite(STDERR, "usage: gen-docs.php [--extract|--check]\n");
    exit(2);
}

$types = parse_matchups($matchups);           // slug => 'library' | 'app'
$nLib  = count(array_filter($types, static fn ($t) => $t === 'library'));
$nApp  = count(array_filter($types, static fn ($t) => $t === 'app'));

if ($mode === '--extract') {
    run_extract($libDir, $dataDir, $types);
    exit(0);
}

// generate / check
$slugs = data_slugs($dataDir);
if ($slugs === []) {
    fwrite(STDERR, "no data store found — run: php tools/gen-docs.php --extract\n");
    exit(1);
}

$drift = 0;
$written = 0;
foreach ($slugs as $slug) {
    if (!isset($types[$slug])) {
        fwrite(STDERR, "warning: $slug has no MATCHUPS row — skipping\n");
        continue;
    }
    $meta = json_decode((string) file_get_contents("$dataDir/$slug.json"), true, 512, JSON_THROW_ON_ERROR);
    $body = (string) file_get_contents("$dataDir/$slug.body.html");
    $html = generate_one($slug, $types[$slug], $meta, $body, $nLib, $nApp);
    $target = "$libDir/$slug.html";

    if ($mode === '--check') {
        $current = is_file($target) ? (string) file_get_contents($target) : '';
        if ($current !== $html) {
            fwrite(STDERR, "drift: $slug.html differs from generated output\n");
            $drift++;
        }
        continue;
    }

    file_put_contents($target, $html);
    $written++;
}

// index.html count patch — the generator OWNS the homepage's library/app counts
// so they can never drift from the authoritative MATCHUPS split. Every count
// token (meta/og/JSON-LD descriptions, the hero prose, the two .stat numerals,
// and the two section <h2> headings) is rewritten from the computed $nLib/$nApp.
// The patch is idempotent: it matches count POSITIONS, not specific values, so a
// second run is a no-op and --check catches any hand-edit that reintroduces drift.
if (is_file($index)) {
    $currentIndex = (string) file_get_contents($index);
    $patchedIndex = patch_index_counts($currentIndex, $nLib, $nApp);
    if ($mode === '--check') {
        if ($currentIndex !== $patchedIndex) {
            fwrite(STDERR, "drift: index.html counts differ from computed $nLib/$nApp\n");
            $drift++;
        }
    } elseif ($currentIndex !== $patchedIndex) {
        file_put_contents($index, $patchedIndex);
        $written++;
    }
}

if ($mode === '--check') {
    if ($drift > 0) {
        fwrite(STDERR, "$drift page(s) out of sync — run: php tools/gen-docs.php\n");
        exit(1);
    }
    fwrite(STDOUT, "ok: all " . count($slugs) . " pages + index.html counts match generated output\n");
    exit(0);
}

fwrite(STDOUT, "generated $written file(s) (libraries: $nLib, apps: $nApp)\n");
exit(0);

// ---------------------------------------------------------------------------
// MATCHUPS parsing
// ---------------------------------------------------------------------------

/**
 * Parse docs/MATCHUPS.md into a slug => type map. The "Charmbracelet libraries"
 * table yields libraries; the "## Apps" table yields apps. HTML-commented rows
 * (e.g. the TODO ConPTY-Windows row) are skipped, so candy-pty stays a library.
 *
 * @return array<string,string>
 */
function parse_matchups(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "cannot read $path\n");
        exit(1);
    }
    $type = null;
    $inComment = false;
    $map = [];
    foreach ($lines as $line) {
        if (str_contains($line, '<!--')) {
            $inComment = true;
        }
        if ($inComment) {
            if (str_contains($line, '-->')) {
                $inComment = false;
            }
            continue;
        }
        $t = trim($line);
        if (str_starts_with($t, '## ')) {
            $h = strtolower($t);
            if (str_contains($h, 'librar')) {
                $type = 'library';
            } elseif (str_contains($h, 'apps')) {
                $type = 'app';
            } else {
                $type = null;
            }
            continue;
        }
        if ($type === null || $t === '' || $t[0] !== '|') {
            continue;
        }
        // The Subdir column is the first `<slug>/` (backtick + trailing slash).
        if (preg_match('/`(' . SLUG_RE . ')\/`/', $t, $m)) {
            $map[$m[1]] = $type;
        }
    }
    return $map;
}

// ---------------------------------------------------------------------------
// Extraction
// ---------------------------------------------------------------------------

/**
 * @param array<string,string> $types
 */
function run_extract(string $libDir, string $dataDir, array $types): void
{
    $files = glob("$libDir/*.html") ?: [];
    sort($files);
    $count = 0;
    foreach ($files as $file) {
        $slug = basename($file, '.html');
        $html = (string) file_get_contents($file);
        [$meta, $body] = extract_one($slug, $html);
        file_put_contents(
            "$dataDir/$slug.json",
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
        );
        file_put_contents("$dataDir/$slug.body.html", $body);
        $count++;
    }
    fwrite(STDOUT, "extracted $count page(s) into $dataDir\n");
    // report anything MATCHUPS knows about but has no page, and vice versa
    $pages = array_map(static fn ($f) => basename($f, '.html'), $files);
    foreach (array_diff(array_keys($types), $pages) as $missing) {
        fwrite(STDERR, "note: MATCHUPS row '$missing' has no docs/lib page\n");
    }
    foreach (array_diff($pages, array_keys($types)) as $orphan) {
        fwrite(STDERR, "note: page '$orphan' has no MATCHUPS row\n");
    }
}

/**
 * @return array{0:array<string,mixed>,1:string}
 */
function extract_one(string $slug, string $html): array
{
    $grab = static function (string $re) use ($html): string {
        return preg_match($re, $html, $m) ? $m[1] : '';
    };

    $title  = $grab('#<title>(.*?)</title>#s');
    $desc   = $grab('#<meta name="description" content="(.*?)">#s');
    $ogT    = $grab('#<meta property="og:title" content="(.*?)">#s');
    $ogD    = $grab('#<meta property="og:description" content="(.*?)">#s');
    $h2     = $grab('#<h2>(.*?)</h2>#s');
    $tagln  = $grab('#<p class="detail-sub">(.*?)</p>#s');
    $php    = $grab('#MIT-licensed · PHP (8\.[0-9]+\+)</div>#');

    // Split the h2 into an optional leading emoji and the display name. Display
    // names are ASCII CamelCase, so a leading non-ASCII run is the emoji.
    $emoji = '';
    $name  = $h2;
    if ($h2 !== '' && preg_match('/^([^\x00-\x7F][^ ]*) (.+)$/u', $h2, $m)) {
        $emoji = $m[1];
        $name  = $m[2];
    }

    $detailMeta = '';
    if (preg_match('#<div class="detail-meta">\n(.*?)\n        </div>#s', $html, $m)) {
        $detailMeta = $m[1];
    }

    // Body content: from the lede paragraph through the close of the Demos section.
    $ledePos = strpos($html, '<p class="lede" style="margin-top: 28px;">');
    $footPos = strpos($html, '<footer class="footer">');
    $body = ($ledePos !== false && $footPos !== false)
        ? rtrim(substr($html, $ledePos, $footPos - $ledePos)) . "\n"
        : '';

    $meta = [
        'title'          => $title,
        'description'    => $desc,
        'ogTitle'        => $ogT,
        'ogDescription'  => $ogD,
        'emoji'          => $emoji,
        'displayName'    => $name,
        'tagline'        => $tagln,
        'phpVersion'     => $php !== '' ? $php : '8.1+',
        'detailMeta'     => $detailMeta,
    ];

    // Preserve chrome that legitimately diverges from the shared template
    // (currently only candy-core, which carries a skip-link + ARIA nav +
    // twitter:image). Everything up to the first main <section> is compared
    // against the standard prefix; a real *content* mismatch is stored verbatim
    // so no accessibility / SEO markup is ever lost on regen. Whitespace-only
    // divergence (a few pages carry stray indentation in <head>) is NOT
    // preserved — the generator normalizes those to the shared clean template.
    $secPos = strpos($html, '<section class="section">');
    if ($secPos !== false) {
        $actualPrefix = rtrim(substr($html, 0, $secPos));
        $stdPrefix    = build_prefix($slug, $title, $desc, $ogT, $ogD);
        if (strip_indent($actualPrefix) !== strip_indent($stdPrefix)) {
            $meta['prefixOverride'] = $actualPrefix;
        }
    }

    return [$meta, $body];
}

// ---------------------------------------------------------------------------
// Generation
// ---------------------------------------------------------------------------

/**
 * @param array<string,mixed> $meta
 */
function generate_one(string $slug, string $type, array $meta, string $body, int $nLib, int $nApp): string
{
    $name = (string) $meta['displayName'];

    $prefix = isset($meta['prefixOverride'])
        ? (string) $meta['prefixOverride']
        : build_prefix($slug, (string) $meta['title'], (string) $meta['description'], (string) $meta['ogTitle'], (string) $meta['ogDescription']);

    // Breadcrumb carries the authoritative MATCHUPS split (39 libraries / 19
    // apps). The count is DERIVED from $nLib/$nApp (never hardcoded), and the
    // same generator run patches index.html's counts to match (see
    // patch_index_counts below), so the lib pages and the homepage can no longer
    // drift apart on the number.
    $plural = $type === 'app' ? 'apps' : 'libraries';
    $count  = $type === 'app' ? $nApp : $nLib;
    $breadcrumb = '<p style="color: var(--ink-2); margin: 0 0 16px 0;">'
        . '<a href="../#' . $plural . '">← All ' . $count . ' ' . $plural . '</a></p>';

    $h2 = $meta['emoji'] !== '' ? $meta['emoji'] . ' ' . $name : $name;

    $detailMeta = normalize_port_label((string) $meta['detailMeta']);

    $codecov = '<p class="detail-badge" style="margin: 16px 0 0;">'
        . '<a href="https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=' . $slug . '">'
        . '<img src="https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=' . $slug . '" '
        . 'alt="' . $name . ' code coverage" loading="lazy"></a></p>';

    $content = normalize_self_links(rtrim($body));

    $footer = build_footer($slug, (string) $meta['phpVersion']);

    return $prefix
        . "\n\n<section class=\"section\">\n  <div class=\"container\">\n    "
        . $breadcrumb . "\n\n"
        . "    <div class=\"detail-header\">\n"
        . '      <img class="detail-icon" src="../img/icons/' . $slug . '.png" alt="' . $name . "\">\n"
        . "      <div>\n"
        . '        <h2>' . $h2 . "</h2>\n"
        . '        <p class="detail-sub">' . $meta['tagline'] . "</p>\n"
        . "        <div class=\"detail-meta\">\n"
        . $detailMeta . "\n"
        . "        </div>\n"
        . "      </div>\n"
        . "    </div>\n"
        . '    ' . $codecov . "\n\n"
        . '    ' . $content . "\n\n"
        . $footer . "\n\n"
        . search_tail() . "\n";
}

function build_prefix(string $slug, string $title, string $desc, string $ogTitle, string $ogDesc): string
{
    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title}</title>
        <meta name="description" content="{$desc}">
        <meta property="og:title" content="{$ogTitle}">
        <meta property="og:description" content="{$ogDesc}">
        <meta property="og:image" content="https://sugarcraft.github.io/img/icons/{$slug}.png">
        <link rel="canonical" href="https://sugarcraft.github.io/lib/{$slug}.html">
        <meta property="og:type" content="website">
        <meta property="og:url" content="https://sugarcraft.github.io/lib/{$slug}.html">
        <meta name="twitter:card" content="summary_large_image">
        <link rel="icon" type="image/png" href="../img/icons/{$slug}.png">
        <link rel="stylesheet" href="../css/style.css">
            <link rel="stylesheet" href="../css/search.css">
        </head>
        <body>

        <nav class="nav"><div class="nav-inner">
            <a class="brand" href="../"><span class="logo-mark"></span>SugarCraft</a>
            <div class="nav-links">
                <a href="../#libraries">Libraries</a>
                <a href="../#apps">Apps</a>
                <a href="../#demos">Demos</a>
                <a href="../#quickstart">Quickstart</a>
                <a href="../compare.html">Compare</a>
                <a href="https://github.com/sugarcraft" class="btn btn-ghost">GitHub →</a>
            </div>
        </div></nav>
        HTML;
}

function build_footer(string $slug, string $php): string
{
    return <<<HTML
        <footer class="footer">
            <div class="container">
                <div><span class="logo-mark"></span> <strong>SugarCraft</strong> · MIT-licensed · PHP {$php}</div>
                <div>
                    <a href="https://github.com/sugarcraft">GitHub</a> ·
                    <a href="https://packagist.org/packages/sugarcraft/{$slug}">Packagist</a> ·
                    <a href="https://www.interserver.net/">InterServer</a> ·
                    <a href="https://github.com/detain/sugarcraft/blob/master/CONTRIBUTING.md">Contributing</a> ·
                    <a href="https://github.com/sugarcraft/{$slug}/issues">Issues</a>
                </div>
            </div>
        </footer>
        HTML;
}

function search_tail(): string
{
    return <<<'HTML'
        <script src="../js/main.js"></script>
        <div id="search-modal" class="search-modal" role="dialog" aria-modal="true" aria-label="Search libraries" aria-hidden="true">
            <div class="search-backdrop"></div>
            <div class="search-dialog">
                <div class="search-input-wrap">
                    <svg class="search-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input type="search" id="search-input" class="search-input" placeholder="Search libraries and apps..." autocomplete="off" aria-autocomplete="list" aria-controls="search-results">
                    <kbd class="search-kbd">esc</kbd>
                </div>
                <ul id="search-results" class="search-results" role="listbox"></ul>
                <div class="search-footer">
                    <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
                    <span><kbd>↵</kbd> open</span>
                    <span><kbd>esc</kbd> close</span>
                </div>
            </div>
        </div>
        <script src="../js/search.js" defer></script>
        </body>
        </html>
        HTML;
}

// ---------------------------------------------------------------------------
// Normalizations
// ---------------------------------------------------------------------------

/**
 * Rewrite this repo's own tree/main + blob/main deep links to /master (the
 * sugarcraft/* mirrors default to master). Scoped to github.com/sugarcraft/<slug>
 * so upstream links (e.g. charmbracelet/x/tree/main/ansi, whose default IS main)
 * are left untouched.
 */
function normalize_self_links(string $s): string
{
    return preg_replace(
        '#(https://github\.com/sugarcraft/' . SLUG_RE . '/)(tree|blob)/main/#',
        '${1}${2}/master/',
        $s
    );
}

/**
 * Normalize every "port of" accent chip's link text to owner/repo form derived
 * from its URL, so bare labels (harmonica, TakaTime, x/ansi) become
 * charmbracelet/harmonica, Rtarun3606k/TakaTime, charmbracelet/x/ansi. Special
 * accent chips (pioneering, extracted-from, façade, …) carry no "port of <a>"
 * and are left verbatim.
 */
function normalize_port_label(string $meta): string
{
    return preg_replace_callback(
        '#(<span class="tag-chip accent">port of <a href=")([^"]+)(" style="color: inherit;">)([^<]*)(</a>)#',
        static function (array $m): string {
            $label = preg_replace('#^https?://github\.com/#', '', $m[2]);
            $label = preg_replace('#/(tree|blob)/[^/]+/?#', '/', (string) $label);
            $label = trim((string) $label, '/');
            return $m[1] . $m[2] . $m[3] . $label . $m[5];
        },
        $meta
    ) ?? $meta;
}

// ---------------------------------------------------------------------------
// index.html count patch
// ---------------------------------------------------------------------------

/**
 * Rewrite every library/app COUNT token on the homepage from the computed
 * $nLib/$nApp so index.html can never drift from the authoritative MATCHUPS
 * split. The generator is the single owner of these numbers.
 *
 * Every replacement matches the count POSITION (surrounding literal text), not
 * the current value, so this is idempotent — a second pass produces byte-for-byte
 * the same output, which is what makes --check a reliable drift guard. Both the
 * numeral forms (meta description, og:description, JSON-LD, the .stat spans, the
 * "(N packages)" totals) and the spelled-out forms (hero prose, the two section
 * <h2> headings) are driven from the same counts.
 */
function patch_index_counts(string $html, int $nLib, int $nApp): string
{
    $nTotal = $nLib + $nApp;
    $wLib   = number_to_words($nLib);
    $wApp   = number_to_words($nApp);
    $wTotal = number_to_words($nTotal);

    // Numeral "<lib> libraries and <app> apps" — meta description, og:description, JSON-LD.
    $html = preg_replace(
        '#\d+( libraries and )\d+( apps)#',
        $nLib . '${1}' . $nApp . '${2}',
        $html
    ) ?? $html;

    // Numeral "(<total> packages)" total — meta description + JSON-LD.
    $html = preg_replace(
        '#\(\d+ packages\)#',
        '(' . $nTotal . ' packages)',
        $html
    ) ?? $html;

    // Hero prose, spelled out: "… ecosystem — <lib>\n libraries and <app> apps
    // (<total> packages total)". The interior whitespace group is preserved verbatim.
    $html = preg_replace_callback(
        '#(TUI ecosystem — )[a-z-]+(\s+libraries and )[a-z-]+( apps \()[a-z-]+( packages total\))#',
        static fn (array $m): string => $m[1] . $wLib . $m[2] . $wApp . $m[3] . $wTotal . $m[4],
        $html
    ) ?? $html;

    // Hero .stat numerals.
    $html = preg_replace(
        '#(<span class="num">)\d+(</span><span class="label">libraries</span>)#',
        '${1}' . $nLib . '${2}',
        $html
    ) ?? $html;
    $html = preg_replace(
        '#(<span class="num">)\d+(</span><span class="label">apps</span>)#',
        '${1}' . $nApp . '${2}',
        $html
    ) ?? $html;

    // Section headings, spelled out + capitalized.
    $html = preg_replace(
        '#<h2>[A-Z][a-z-]+( libraries, one ecosystem\.</h2>)#',
        '<h2>' . ucfirst($wLib) . '${1}',
        $html
    ) ?? $html;
    $html = preg_replace(
        '#<h2>[A-Z][a-z-]+( apps built on the stack\.</h2>)#',
        '<h2>' . ucfirst($wApp) . '${1}',
        $html
    ) ?? $html;

    return $html;
}

/**
 * Spell out an integer 0-999 in lowercase English (hyphenated, e.g. 39 →
 * "thirty-nine"). Used for the homepage's prose/heading count tokens; ucfirst()
 * capitalizes where a heading needs it. Falls back to the decimal string outside
 * the covered range (the counts are ~58, well inside it).
 */
function number_to_words(int $n): string
{
    if ($n < 0 || $n > 999) {
        return (string) $n;
    }
    $ones = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
        'seventeen', 'eighteen', 'nineteen',
    ];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    if ($n < 20) {
        return $ones[$n];
    }
    if ($n < 100) {
        $t = $tens[intdiv($n, 10)];
        $o = $n % 10;
        return $o === 0 ? $t : $t . '-' . $ones[$o];
    }
    $h = $ones[intdiv($n, 100)] . ' hundred';
    $r = $n % 100;
    return $r === 0 ? $h : $h . ' ' . number_to_words($r);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Strip per-line leading whitespace so two markup blocks can be compared for
 * content equality regardless of indentation quirks.
 */
function strip_indent(string $s): string
{
    return preg_replace('/^[ \t]+/m', '', $s) ?? $s;
}

/**
 * @return list<string>
 */
function data_slugs(string $dataDir): array
{
    $files = glob("$dataDir/*.json") ?: [];
    $slugs = array_map(static fn ($f) => basename($f, '.json'), $files);
    sort($slugs);
    return $slugs;
}
