<?php

declare(strict_types=1);

/*
 * gen-doc-pages.php — render the allow-listed docs/*.md sources into chrome-bearing
 * HTML pages for sugarcraft.github.io, using league/commonmark directly (GFM tables
 * + heading permalinks). The repo's Markdown sources stay in place — the raw files
 * remain served — this tool only ADDS rendered siblings:
 *
 *   docs/GETTING-STARTED.md   -> docs/GETTING-STARTED.html
 *   docs/GLOSSARY.md          -> docs/GLOSSARY.html
 *   docs/KEYMAPS.md           -> docs/KEYMAPS.html
 *   docs/TRUST-BOUNDARY.md    -> docs/TRUST-BOUNDARY.html
 *   docs/cookbook/README.md   -> docs/cookbook/index.html
 *
 * The nav block is extracted VERBATIM from between the <!--nav:start--> /
 * <!--nav:end--> markers of docs/index.html at generation time (single sync-source;
 * check-docs-assets.php's navBlockIdentity leg trip-wires drift across all chrome
 * surfaces). Links inside rendered bodies are rewritten so they resolve on the
 * published site: sibling doc pages for the other rendered documents,
 * /lib/<slug>.html for repo components, and GitHub blob URLs for everything else
 * that only exists inside the repository tree.
 *
 * Usage:
 *   php tools/gen-doc-pages.php            # write the rendered pages
 *   php tools/gen-doc-pages.php --check    # exit 1 on drift (no writes)
 *   php tools/gen-doc-pages.php --root=PATH
 *
 * No environment variables are read (ToolsEnvRosterTest-friendly by design).
 */

namespace SugarCraft\Tools;

const USAGE = <<<TXT
gen-doc-pages.php — render the allow-listed docs/*.md sources into site pages.

Usage:
  php tools/gen-doc-pages.php [--check] [--root=PATH] [--can-render]

Flags:
  --check       Compare generated output against the files on disk; exit 1 on drift.
  --root=PATH   Repository root containing docs/ (default: the tools/ parent dir).
  --can-render  Resolve league/commonmark and exit without rendering: 0 when it is
                autoloadable here, 1 with the loud looked-in failure otherwise.
                This is the probe guards use to tell "cannot run here" from drift.
  --help        Show this text.

Exit codes:
  0  all pages are up to date (or were written, or --can-render resolved)
  1  drift detected in --check mode, a render/dependency failure, or --can-render
     found no autoloader
  2  usage error
TXT;

/**
 * Allow-listed render map. title/description feed each page's <head> metadata.
 * 'dir' is the output's depth prefix for relative chrome assets ('' = docs root,
 * '../' = docs/cookbook/).
 */
const DOC_PAGES = [
    [
        'src' => 'docs/GETTING-STARTED.md',
        'out' => 'docs/GETTING-STARTED.html',
        'dir' => '',
        'title' => 'Getting Started — SugarCraft',
        'description' => 'Install and run SugarCraft terminal apps, and build your own with the Model-Update-View runtime.',
    ],
    [
        'src' => 'docs/GLOSSARY.md',
        'out' => 'docs/GLOSSARY.html',
        'dir' => '',
        'title' => 'Glossary — SugarCraft',
        'description' => 'The vocabulary of the SugarCraft terminal UI ecosystem: runtimes, frames, zones, and protocols.',
    ],
    [
        'src' => 'docs/KEYMAPS.md',
        'out' => 'docs/KEYMAPS.html',
        'dir' => '',
        'title' => 'Keymaps — SugarCraft',
        'description' => 'Default key bindings for every candy-* terminal app, with cross-references to each README.',
    ],
    [
        'src' => 'docs/TRUST-BOUNDARY.md',
        'out' => 'docs/TRUST-BOUNDARY.html',
        'dir' => '',
        'title' => 'Trust Boundary — SugarCraft',
        'description' => 'What the SugarCraft terminal sanitizers defend, where the boundary sits, and what remains your duty.',
    ],
    [
        'src' => 'docs/cookbook/README.md',
        'out' => 'docs/cookbook/index.html',
        'dir' => '../',
        'title' => 'Cookbook — SugarCraft',
        'description' => 'Runnable recipes for embedding SugarCraft widgets — spinners, sparklines, programs within programs.',
    ],
];

const NAV_START = '<!--nav:start-->';
const NAV_END = '<!--nav:end-->';

const BLOB = 'https://github.com/detain/sugarcraft/blob/master/';

/** Rendered sibling documents (R3): same-name .md links point at their .html pages. */
const DOC_STEMS = ['GETTING-STARTED', 'GLOSSARY', 'KEYMAPS', 'TRUST-BOUNDARY'];

/**
 * Locate a composer autoloader that provides league/commonmark. The monorepo
 * vendors are gitignored, so candidates are ordered within THIS tree only:
 * root vendor first, then the documented per-lib shape. RT-39: the former
 * machine-path candidates (`/home/sites/sugarcraft/...`) let a stale main
 * checkout's vendor win over the tree actually generating pages — a copied
 * scratch tree rendered against the WRONG bytes, or worse, silently green.
 * Fail LOUD — never silently skip pages.
 *
 * @return string the require()-able autoload file
 */
function findAutoloader(): string
{
    $candidates = [
        \dirname(__DIR__) . '/vendor/autoload.php',
        // The documented per-lib install shape: sugar-markdown is the package
        // that requires league/commonmark, so any checkout that ran its own
        // `composer install -d sugar-markdown` resolves here, worktree-relative.
        \dirname(__DIR__) . '/sugar-markdown/vendor/autoload.php',
    ];

    foreach ($candidates as $file) {
        if (!\is_file($file)) {
            continue;
        }
        require_once $file;
        if (\class_exists('League\\CommonMark\\CommonMarkConverter')) {
            return $file;
        }
    }

    \fwrite(
        \STDERR,
        "error: league/commonmark could not be autoloaded. Looked in:\n  - "
        . \implode("\n  - ", $candidates)
        . "\nRun `composer install -d sugar-markdown` (the package that requires league/commonmark), or a `composer install` at the repo root on a manifest that pulls it in, and re-run.\n",
    );
    exit(1);
}

/** The repo-component slug set (docs/_data/*.json names — same source gen-docs uses). */
function data_slugs(string $dataDir): array
{
    $slugs = [];
    foreach ((array) \glob($dataDir . '/*.json') as $file) {
        $slugs[] = \basename($file, '.json');
    }
    \sort($slugs);

    return $slugs;
}

/**
 * Extract the canonical nav block — the bytes strictly between the markers,
 * including the newline that follows <!--nav:start--> and the line break that
 * precedes <!--nav:end--> — exactly as tools/check-docs-assets.php compares it,
 * from the single sync-source docs/index.html.
 */
function nav_block(string $indexHtml): string
{
    $start = \strpos($indexHtml, NAV_START);
    $end = \strpos($indexHtml, NAV_END);
    if ($start === false || $end === false || $end < $start) {
        \fwrite(\STDERR, "error: docs/index.html is missing its nav:start/nav:end marker pair — cannot embed the canonical block.\n");
        exit(1);
    }
    if (\strpos(\substr($indexHtml, $end + \strlen(NAV_END)), NAV_START) !== false) {
        \fwrite(\STDERR, "error: docs/index.html carries more than one nav marker pair.\n");
        exit(1);
    }

    return \substr($indexHtml, $start + \strlen(NAV_START), $end - $start - \strlen(NAV_START));
}

/**
 * Rewrite one href so it resolves on the published site (see file docblock).
 * Fragments are preserved; scheme-bearing, fragment-only and existing-on-site
 * relative targets pass through untouched.
 *
 * @param array<string> $slugs
 */
function rewrite_href(string $href, array $slugs): string
{
    if ($href === '' || $href[0] === '#') {
        return $href;
    }
    if (\preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $href) === 1) { // http(s):, mailto:, …
        return $href;
    }

    [$path, $fragment] = \array_pad(\explode('#', $href, 2), 2, null);
    $frag = $fragment === null ? '' : '#' . $fragment;

    // Root-absolute cookbook pointer.
    if ($path === '/cookbook/README.md') {
        return '/cookbook/index.html' . $frag;
    }

    // Rendered sibling documents (R3) — served at the docs root.
    if (\preg_match('#^(\./)?(' . \implode('|', DOC_STEMS) . ')\.md$#', $path, $m) === 1) {
        return '/' . $m[2] . '.html' . $frag;
    }
    if ($path === 'MATCHUPS.md' || $path === './MATCHUPS.md') {
        return BLOB . 'docs/MATCHUPS.md' . $frag;
    }

    // Repo-component references (R1/R2): ../<slug>[/rest].
    if (\preg_match('#^\.\./([a-z0-9-]+)(?:/(.*))?$#', $path, $m) === 1) {
        $slug = $m[1];
        $rest = $m[2] ?? '';
        if (\in_array($slug, $slugs, true)) {
            if ($rest === '' || $rest === 'README.md') {
                // Generated lib pages carry no heading ids, so a fragment carried
                // over from a README anchor would always be inert: link the page.
                return '/lib/' . $slug . '.html';
            }

            return BLOB . $slug . '/' . $rest . $frag;
        }

        return BLOB . $slug . ($rest === '' ? '' : '/' . $rest) . $frag;
    }

    // Anything else above the docs root is repo-only (AGENTS.md, SECURITY.md,
    // .github/, tools/, docs/MATCHUPS.md from the site root, …).
    if (\preg_match('#^\.\./(.+)$#', $path, $m) === 1) {
        return BLOB . $m[1] . $frag;
    }
    if (\preg_match('#^docs/(.+)$#', $path, $m) === 1) {
        return BLOB . 'docs/' . $m[1] . $frag;
    }

    // './recipe.php', './admin/…' etc. — files that exist (and stay) in docs/.
    return $href;
}

/**
 * RT-34 (repo-tooling-037): the tag/attribute deny-list for every UNTRUSTED
 * HTML fragment this script embeds. The `docs/_data/<slug>.body.html` store
 * (and, on the narrative pages, the markdown converter's raw-HTML
 * passthrough) reach https://sugarcraft.github.io verbatim, so anyone who can
 * open a merged PR against those sources can otherwise plant script there —
 * no auth and no cookies on the target, but a fully trusted origin: phishing,
 * brand damage, drive-by. Parse-don't-validate at the render boundary: find
 * the residue, refuse the build, name it. The copies of this body across
 * gen-docs.php, gen-doc-pages.php and check-docs-assets.php are token-pinned
 * against each other by tools/tests/HtmlResidueDriftTest.php — the three
 * enforcement points cannot drift apart, and the committed corpus is
 * re-checked retroactively rather than assumed clean.
 *
 * @return list<string> human-readable findings; [] means the fragment is clean
 */
function html_residue_problems(string $html): array
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

/** Apply rewrite_href to every href="…" in a rendered body. */
function rewrite_body_hrefs(string $html, array $slugs): string
{
    return (string) \preg_replace_callback(
        '/href="([^"]*)"/',
        static fn(array $m): string => 'href="' . rewrite_href($m[1], $slugs) . '"',
        $html,
    );
}

/** Per-page chrome head (depth-corrected relative assets, generic social preview). */
function page_head(array $page): string
{
    $dir = $page['dir'];
    $title = \htmlspecialchars($page['title'], \ENT_QUOTES);
    $desc = \htmlspecialchars($page['description'], \ENT_QUOTES);
    $url = 'https://sugarcraft.github.io/' . \ltrim(\substr($page['out'], \strlen('docs/')), '/');

    return <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title}</title>
        <meta name="description" content="{$desc}">
        <meta property="og:title" content="{$title}">
        <meta property="og:description" content="{$desc}">
        <meta property="og:type" content="article">
        <meta property="og:url" content="{$url}">
        <meta property="og:image" content="https://sugarcraft.github.io/img/social-preview.png">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="https://sugarcraft.github.io/img/social-preview.png">
        <link rel="canonical" href="{$url}">
        <link rel="icon" type="image/svg+xml" href="{$dir}img/favicon.svg">
        <link rel="icon" type="image/png" href="{$dir}img/profile.png">
        <link rel="apple-touch-icon" href="{$dir}img/profile.png">
        <link rel="stylesheet" href="{$dir}css/style.css">
        <link rel="stylesheet" href="{$dir}css/search.css">
    <script>document.documentElement.className += " js";</script>
    </head>
    <body>

    <a class="skip-link" href="#main">Skip to main content</a>

    HTML;
}

/** Site footer + search modal + scripts, depth-corrected (byte-shape of index.html). */
function page_tail(string $dir): string
{
    return <<<HTML

    </main>

    <footer class="footer">
        <p>SugarCraft — MIT-licensed PHP terminal toolkits and apps. Built with <a href="https://www.php.net/">PHP</a>.</p>
        <p><a href="/compare.html">Compare</a> · <a href="/404.html">404</a> · <a href="https://github.com/sugarcraft">GitHub</a></p>
    </footer>

    <script src="{$dir}js/main.js"></script>

    <div id="search-modal" class="search-modal" role="dialog" aria-modal="true" aria-label="Search libraries and apps" aria-hidden="true">
        <div class="search-backdrop"></div>
        <div class="search-dialog">
            <div class="search-input-wrap">
                <svg class="search-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="search" id="search-input" class="search-input" placeholder="Search libraries and apps..." autocomplete="off" role="combobox" aria-expanded="false" aria-controls="search-results" aria-autocomplete="list" aria-label="Search libraries and apps">
                <kbd class="search-kbd">esc</kbd>
            </div>
            <ul id="search-results" class="search-results" role="listbox" aria-label="Search results"></ul>
            <div class="search-footer">
                <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
                <span><kbd>↵</kbd> open</span>
                <span><kbd>esc</kbd> close</span>
            </div>
        </div>
    </div>
    <script src="{$dir}js/search.js" defer></script>
    <script src="{$dir}js/nav.js" defer></script>
    </body>
    </html>
    HTML;
}

/** Render every allow-listed page to its final bytes. @return array<string,string> out-path => content */
function render_all(string $root, array $slugs, string $navBlock): array
{
    $environment = new \League\CommonMark\Environment\Environment(
        [
            'heading_permalink' => [
                'symbol' => '#',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'aria_hidden' => true,
            ],
        ],
    );
    $environment->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
    $environment->addExtension(new \League\CommonMark\Extension\GithubFlavoredMarkdownExtension());
    $environment->addExtension(new \League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension());
    $converter = new \League\CommonMark\MarkdownConverter($environment);

    $pages = [];
    foreach (DOC_PAGES as $page) {
        $source = $root . '/' . $page['src'];
        if (!\is_file($source)) {
            \fwrite(\STDERR, "error: allow-listed source missing: {$page['src']}\n");
            exit(1);
        }
        $body = $converter->convert(\file_get_contents($source))->getContent();
        $body = rewrite_body_hrefs($body, $slugs);
        // RT-34: this body lands verbatim inside <article> on the published
        // narrative page, and CommonMark passes raw HTML through — the
        // deny-list refuses residue naming the source file.
        $residue = html_residue_problems($body);
        if ($residue !== []) {
            \fwrite(\STDERR, "gen-doc-pages: {$page['src']} converts to disallowed raw-HTML residue: "
                . implode('; ', $residue) . " — scrub the source or move the behaviour into the chrome\n");
            exit(1);
        }
        $pages[$page['out']] =
            page_head($page)
            . NAV_START . $navBlock . NAV_END . "\n\n"
            . "<main id=\"main\">\n\n<article class=\"prose\">\n\n" . $body . "\n</article>\n"
            . page_tail($page['dir']);
    }

    return $pages;
}

// --------------------------------------------------------------------------- main

$args = \array_slice($argv, 1);
$check = false;
$canRender = false;
$root = \dirname(__DIR__);

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo USAGE . "\n";
        exit(0);
    }
    if ($arg === '--check') {
        $check = true;
        continue;
    }
    if ($arg === '--can-render') {
        $canRender = true;
        continue;
    }
    if (\str_starts_with($arg, '--root=')) {
        $candidate = \realpath(\substr($arg, 7));
        if ($candidate === false || !\is_dir($candidate . '/docs')) {
            \fwrite(\STDERR, "error: --root must name a directory containing docs/\n");
            exit(2);
        }
        $root = $candidate;
        continue;
    }
    \fwrite(\STDERR, USAGE . "\n");
    exit(2);
}

// The probe: resolve exactly what a render would resolve, sharing this file's
// own findAutoloader() so the answer cannot drift from the real dependency
// path. Success prints and exits 0; failure falls into findAutoloader()'s
// loud looked-in exit(1) — the same text an actual render would print.
if ($canRender) {
    findAutoloader();
    echo "ok: league/commonmark is autoloadable here\n";
    exit(0);
}

findAutoloader();

$indexHtml = \file_get_contents($root . '/docs/index.html');
if ($indexHtml === false) {
    \fwrite(\STDERR, "error: cannot read {$root}/docs/index.html\n");
    exit(1);
}

$slugs = data_slugs($root . '/docs/_data');
if ($slugs === []) {
    \fwrite(\STDERR, "error: no docs/_data/*.json slug definitions found under {$root}\n");
    exit(1);
}

$navBlock = nav_block($indexHtml);
$pages = render_all($root, $slugs, $navBlock);

$drift = [];
foreach ($pages as $rel => $content) {
    $file = $root . '/' . $rel;
    if ($check) {
        if (!\is_file($file) || \file_get_contents($file) !== $content) {
            $drift[] = $rel;
        }
        continue;
    }
    $dir = \dirname($file);
    if (!\is_dir($dir) && !\mkdir($dir, 0755, true) && !\is_dir($dir)) {
        \fwrite(\STDERR, "error: cannot create {$dir}\n");
        exit(1);
    }
    if (\file_put_contents($file, $content) === false) {
        \fwrite(\STDERR, "error: cannot write {$rel}\n");
        exit(1);
    }
}

if ($check) {
    if ($drift !== []) {
        \fwrite(\STDERR, "error: rendered pages are out of date:\n  - " . \implode("\n  - ", $drift) . "\nRun: php tools/gen-doc-pages.php\n");
        exit(1);
    }
    echo "ok: " . \count($pages) . " rendered doc pages match their Markdown sources\n";
    exit(0);
}

echo "ok: wrote " . \count($pages) . " rendered doc pages\n";
exit(0);
