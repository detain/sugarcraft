<?php

declare(strict_types=1);

/**
 * Builds docs/_data/<slug>.api.html, the generated API reference fragment that
 * tools/gen-docs.php splices into a lib page's "API reference" section (PD2,
 * plan_doc.md section 9, option A).
 *
 * WHAT IT READS. Every top-level class, interface, trait and enum under
 * <slug>/src/, parsed with token_get_all(). Signatures come from the source
 * text, not from Reflection, so the generator never autoloads or runs a lib's
 * code and its output is a pure function of the committed tree. A type or
 * method whose PHPDoc carries `@internal` is not public API and is left out.
 * The public methods a type inherits are listed under it, as PHP exposes them:
 * those of each trait it `use`s, and those of its parent class, its extended
 * interfaces and its implemented interfaces when they are declared under the
 * same src tree. A method whose PHPDoc says `{@inheritdoc}` takes the summary of
 * the nearest documented one of those. A parent
 * outside the tree has no source here, so its methods are not listed.
 *
 * WHAT IT WRITES. Namespace headings, then one entry per type with its first
 * PHPDoc paragraph and a method table (signature and summary). A lib named in
 * ROWS_IN_NAMESPACE or TOP_TYPES_LISTED trims its page instead: a type outside
 * those rows is replaced by a per-sub-namespace count line, so one entry per
 * type holds only for a lib in neither list (see those constants' docblocks).
 * The output is marked GENERATED in its first line and must never be hand-edited.
 *
 * TWO SOURCES FOR THE TYPE LIST. The token parser builds the entries, and a
 * line-anchored scan of the same files counts the declarations. collect()
 * refuses to continue when the two disagree, so a type the parser cannot see
 * fails the run rather than leaving the page and the coverage figure without it.
 *
 * TOP-10 COVERAGE. A companion to the class coverage in tools/depth-score.php,
 * not the same figure. This generator takes the ten largest public types by
 * declaration span and counts how many of them the fragment gives a heading
 * (its anchor id). The public set comes from the line scan, not from the types
 * the fragment renders, so a heading the page drops still counts against the
 * target. depth-score ranks every first declaration, @internal
 * ones included, and counts names in the page body, so the two can grade
 * different sets. Target is at least 80 percent (plan section 5.3, item 6). A lib
 * with fewer than ten public types is measured over all of them.
 *
 * SCOPE. Every lib in ApiReference::ENABLED gets a fragment. A skeleton with no
 * public type yet, listed in ApiReference::NO_PUBLIC_CLASSES, gets none: its body
 * states that instead. A lib moves between the two lists in the PR that changes
 * whether it declares a public type.
 *
 * Usage:
 *   php tools/gen-api-reference.php sugar-core            writes docs/_data/sugar-core.api.html
 *   php tools/gen-api-reference.php sugar-core --check    exit 1 when the committed fragment is stale
 *   php tools/gen-api-reference.php sugar-core --report   print the top-10 coverage
 *   php tools/gen-api-reference.php --help
 *
 * Exit codes: 0 success, fresh fragment, or top-10 coverage at or above the target.
 * 1 a stale fragment under --check, or top-10 coverage below the target under --report.
 * 2 a usage error, or a refusal from the generator (a declaration the parser cannot
 * see, an unsafe summary, a colliding anchor), which prints its reason on stderr.
 */
final class ApiReference
{
    /**
     * Libs the generator builds a fragment for: every slug with a docs/_data page
     * and at least one public type. A lib is added here in the same PR that adds
     * its body marker and fragment.
     */
    public const ENABLED = [
        'candy-ansi-inspect',
        'candy-code',
        'candy-codeshot',
        'candy-codetime',
        'candy-code-web',
        'candy-files',
        'candy-flappy',
        'candy-gifview',
        'candy-git',
        'candy-git-server',
        'candy-image',
        'candy-kv',
        'candy-mail',
        'candy-mdview',
        'candy-minesweeper',
        'candy-scriptui',
        'candy-sql',
        'candy-ssh-launcher',
        'candy-starter',
        'candy-tetris',
        'candy-top',
        'candy-vcr',
        'sugar-animation',
        'sugar-ansi-parser',
        'sugar-async',
        'sugar-cellbuffer',
        'sugar-charts',
        'sugar-cli',
        'sugar-colorprofile',
        'sugar-constraints',
        'sugar-core',
        'sugar-dashboard',
        'sugar-datepicker',
        'sugar-diff',
        'sugar-flexbox',
        'sugar-focus',
        'sugar-forms',
        'sugar-fuzzy',
        'sugar-gallery',
        'sugar-ignore',
        'sugar-image',
        'sugar-input',
        'sugar-log',
        'sugar-markdown',
        'sugar-mcp-client',
        'sugar-metrics',
        'sugar-mouse',
        'sugar-navstack',
        'sugar-overlay',
        'sugar-panels',
        'sugar-plugins',
        'sugar-pty',
        'sugar-quickpick',
        'sugar-readline',
        'sugar-releases',
        'sugar-serve-protocol',
        'sugar-spring',
        'sugar-ssh-server',
        'sugar-style',
        'sugar-table',
        'sugar-testing',
        'sugar-toast',
        'sugar-treelist',
        'sugar-video',
        'sugar-vterm',
        'sugar-widgets',
    ];

    /**
     * Slugs with a docs/_data page and no public type yet: skeletons whose src/ holds
     * only a .gitkeep. No fragment is written for them; their body states that no
     * classes are published, in place of the API listing. A slug moves from here to
     * ENABLED in the PR that adds its first public type.
     */
    public const NO_PUBLIC_CLASSES = ['sugar-clipboard', 'sugar-codec', 'sugar-jwt', 'sugar-ws-rpc'];

    /**
     * Slugs whose page lists a row for each public type in one namespace only. A type in any other
     * sub-namespace is left out unless TOP_TYPES_LISTED keeps its row, and the page carries one index
     * line per sub-namespace that states its public type count. candy-code's full listing was 2.4 MB, over the 1 MiB page-weight budget; its
     * top-level namespace alone is about 0.1 MB. A slug here is an owner-visible reduction of the
     * page, so the omission is stated on the page itself rather than left silent.
     */
    public const ROWS_IN_NAMESPACE = ['candy-code' => 'SugarCraft\\Code'];

    /**
     * Slugs whose page also keeps a row for each of their N largest public types, wherever they sit,
     * so the top-10 coverage figure is read from rows and not from the namespace index. The size is
     * the one coverage() ranks by, so a type kept here is a type the figure counts.
     */
    public const TOP_TYPES_LISTED = ['candy-code' => 10];

    /** The placeholder tools/gen-docs.php replaces with the fragment. Exactly one per body. */
    public const MARKER = '<!-- api-reference:generated -->';

    /** Target from plan_doc.md section 5.3, item 6. */
    public const TOP_COVERAGE_TARGET = 80.0;

    /** Visibilities that are not public API. A method with no keyword is public. */
    private const NON_PUBLIC = ['private', 'protected'];

    /** Modifiers printed before a method name. Visibility is never printed: every row is public. */
    private const SHOWN_MODIFIERS = ['static', 'abstract', 'final'];

    /**
     * What a published summary must not contain: audit and tracker IDs (sc-23, DX-05,
     * sugar-core-024, lib-reviews-1102, the audit 15b-28 and audit 032 or audit R2 forms), and the ansicode.txt
     * citation, a file this repo does not carry. See refuseInternalReferences().
     */
    private const INTERNAL_REFERENCE = '~\b(?:sc|DX|sugar-core)-\d+\b|\blib-reviews-\d+\b|\b\d+b-\d+\b|\bansicode\.txt\b|\b[Aa]udits?\s+(?:[A-Za-z][A-Za-z-]*-)?[A-Za-z]?\d+[a-z]?(?:-\d+)?\b~';

    /**
     * An `@internal` that reached a published summary. A summary is the first paragraph, and it
     * ends at the first line that starts with a tag, so a tag left inside it is mid-sentence:
     * the seam was meant to be hidden and is not, because isInternal() reads only line-leading
     * tags. Refused rather than published as a public row.
     */
    private const BARE_INTERNAL_TAG = '~@internal\b~';

    public static function enabled(string $slug): bool
    {
        return in_array($slug, self::ENABLED, true);
    }

    /** Every slug the generator knows: a fragment-bearing lib or a no-public-classes skeleton. */
    public static function known(string $slug): bool
    {
        return in_array($slug, self::ENABLED, true) || in_array($slug, self::NO_PUBLIC_CLASSES, true);
    }

    public static function fragmentPath(string $root, string $slug): string
    {
        return $root . '/docs/_data/' . $slug . '.api.html';
    }

    /**
     * The fragment for one lib, a pure function of <root>/<slug>/src.
     *
     * @throws \InvalidArgumentException when the slug is not enabled
     * @throws \RuntimeException when the src tree is missing, unreadable, or
     *                           its declarations do not match what the parser found
     */
    public static function generate(string $root, string $slug): string
    {
        if (in_array($slug, self::NO_PUBLIC_CLASSES, true)) {
            throw new \InvalidArgumentException(
                "gen-api-reference: $slug has no public classes, so it has no fragment; its body states that instead"
            );
        }

        return self::render($slug, self::collect($root, $slug));
    }

    /**
     * Top-10 coverage of a fragment: the ten largest public types by declaration
     * span (ties broken by name), and how many of them are covered. A type is
     * covered when the HTML gives it a heading and its source documents it.
     *
     * The heading's anchor id is the measure, not the name. Most public types are
     * already named in a lib's prose, so a name match would read 100 percent
     * whether or not the fragment carries the type at all.
     *
     * The heading alone is not enough. render() writes a heading for every public
     * type, documented or not, so a heading-only count reads 100 percent over an
     * undocumented API. The documented flag comes from the source for the same
     * reason the ranking does.
     *
     * The ranking comes from declaredPublic(), not from the types the fragment is
     * rendered from. A set measured against its own render can never fail: a type
     * the page dropped would also have dropped out of the ranking.
     *
     * @param list<array{fqn: string, size: int, documented: bool}> $ranked declaredPublic()'s output
     * @return array{top: list<string>, covered: int, percent: float}
     */
    public static function coverage(array $ranked, string $html): array
    {
        usort($ranked, static function (array $a, array $b): int {
            $cmp = $b['size'] <=> $a['size'];

            return $cmp !== 0 ? $cmp : strcmp($a['fqn'], $b['fqn']);
        });
        $top = array_slice($ranked, 0, 10);
        $covered = 0;
        foreach ($top as $type) {
            if ($type['documented'] && str_contains($html, 'id="' . self::anchor($type['fqn']) . '"')) {
                $covered++;
            }
        }
        $count = count($top);

        return [
            'top' => array_map(static fn (array $t): string => $t['fqn'], $top),
            'covered' => $covered,
            'percent' => $count === 0 ? 0.0 : round(100.0 * $covered / $count, 1),
        ];
    }

    /**
     * The public types the line scan finds, with the size of each span: the input
     * top-10 coverage ranks. A type the parser drops still counts here, because
     * scanTree() fails the run on any disagreement, and the page has to carry its
     * heading.
     *
     * @return list<array{fqn: string, size: int, documented: bool}>
     */
    public static function declaredPublic(string $root, string $slug): array
    {
        $tree = self::scanTree($root, $slug);
        $index = self::indexOf($tree['parsed']);

        $public = [];
        foreach ($tree['declared'] as $decl) {
            if ($decl['internal']) {
                continue;
            }
            $type = $index[$decl['fqn']];
            $public[] = [
                'fqn' => $decl['fqn'],
                'size' => $type['size'],
                'documented' => self::isDocumented($type['summary'], self::withInheritedMethods($index, $decl['fqn'], $slug)),
            ];
        }

        return $public;
    }

    /**
     * A type is documented when its PHPDoc has a summary or its method table has
     * one documented row. A type with neither renders as a bare heading.
     *
     * @param list<array{name: string, signature: string, summary: string}> $methods
     */
    private static function isDocumented(string $summary, array $methods): bool
    {
        if ($summary !== '') {
            return true;
        }
        foreach ($methods as $method) {
            if ($method['summary'] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every parsed type keyed by FQN, so a lookup by a declared name cannot miss.
     *
     * @param list<array<string, mixed>> $parsed
     * @return array<string, array<string, mixed>>
     */
    private static function indexOf(array $parsed): array
    {
        $index = [];
        foreach ($parsed as $type) {
            $index[$type['fqn']] = $type;
        }

        return $index;
    }

    /**
     * Both views of one lib's src tree, cross-checked: the line scan's declarations
     * and the token parser's types. Sizes come from the parser, which measures a
     * span by matching braces; a text scan would end a class at the first column-zero
     * brace, and a heredoc can hold one.
     *
     * @return array{declared: list<array{fqn: string, internal: bool}>, parsed: list<array{fqn: string, size: int, internal: bool}>}
     */
    private static function scanTree(string $root, string $slug): array
    {
        $declared = [];
        $parsed = [];
        foreach (self::sourceFiles($root, $slug) as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::declaredSpans($source) as $span) {
                $declared[] = $span;
            }
            foreach (self::parse($source) as $type) {
                $parsed[] = $type;
            }
        }
        self::checkDeclarations($slug, $declared, $parsed);

        return ['declared' => $declared, 'parsed' => $parsed];
    }

    /**
     * @return list<string> the PHP files under <root>/<slug>/src, sorted
     */
    private static function sourceFiles(string $root, string $slug): array
    {
        if (!self::known($slug)) {
            throw new \InvalidArgumentException("gen-api-reference: $slug is not a known lib (add it to ApiReference::ENABLED or NO_PUBLIC_CLASSES)");
        }
        $src = $root . '/' . $slug . '/src';
        if (!is_dir($src)) {
            throw new \RuntimeException("gen-api-reference: $src is not a directory");
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, backed: bool, undeclaredTraits: list<string>, methods: list<array{name: string, signature: string, summary: string}>}>
     */
    public static function collect(string $root, string $slug): array
    {
        $parsed = self::scanTree($root, $slug)['parsed'];
        $index = self::indexOf($parsed);

        $types = [];
        foreach ($parsed as $type) {
            if ($type['internal']) {
                continue;
            }
            $methods = self::withInheritedMethods($index, $type['fqn'], $slug);
            self::refuseInternalReferences($type['fqn'], $type['summary'], $methods);
            $types[] = [
                'fqn' => $type['fqn'],
                'short' => $type['short'],
                'namespace' => $type['namespace'],
                'kind' => $type['kind'],
                'size' => $type['size'],
                'summary' => $type['summary'],
                'cases' => $type['cases'],
                'backed' => $type['backed'],
                'undeclaredTraits' => self::undeclaredTraits($index, $type['fqn']),
                'methods' => $methods,
            ];
        }
        usort($types, static fn (array $a, array $b): int => strcmp($a['fqn'], $b['fqn']));

        return $types;
    }

    /**
     * The line-anchored scan and the token parser must agree on every declared
     * type, internal ones included, and on which of them are public. A mismatch
     * names both sides of the gap.
     *
     * @param list<array{fqn: string, internal: bool}> $declared from declaredSpans()
     * @param list<array{fqn: string, size: int, internal: bool}> $parsed from parse()
     */
    private static function checkDeclarations(string $slug, array $declared, array $parsed): void
    {
        $declaredNames = array_column($declared, 'fqn');
        $parsedNames = array_column($parsed, 'fqn');
        sort($declaredNames, SORT_STRING);
        sort($parsedNames, SORT_STRING);
        if ($declaredNames !== $parsedNames) {
            throw new \RuntimeException(sprintf(
                'gen-api-reference: %s/src declares %d type(s) but the parser found %d; declared but not parsed: [%s]; parsed but not declared: [%s]',
                $slug,
                count($declaredNames),
                count($parsedNames),
                implode(', ', array_values(array_diff($declaredNames, $parsedNames))),
                implode(', ', array_values(array_diff($parsedNames, $declaredNames)))
            ));
        }

        $publicScan = self::publicNames($declared);
        $publicParse = self::publicNames($parsed);
        if ($publicScan !== $publicParse) {
            throw new \RuntimeException(sprintf(
                'gen-api-reference: %s/src marks different types @internal in the line scan and the parser; scan-only public: [%s]; parser-only public: [%s]',
                $slug,
                implode(', ', array_values(array_diff($publicScan, $publicParse))),
                implode(', ', array_values(array_diff($publicParse, $publicScan)))
            ));
        }
    }

    /**
     * @param list<array{fqn: string, internal: bool}> $types
     * @return list<string> the non-internal FQNs, sorted
     */
    private static function publicNames(array $types): array
    {
        $names = [];
        foreach ($types as $type) {
            if (!$type['internal']) {
                $names[] = $type['fqn'];
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Every top-level type declaration in one file, from a line-anchored scan that
     * shares no code with the token parser. PSR-12 puts a top-level declaration at
     * column zero, so the name and the @internal flag come from the text alone.
     *
     * @return list<array{fqn: string, internal: bool}>
     */
    private static function declaredSpans(string $source): array
    {
        $namespace = preg_match('/^namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/m', $source, $m) === 1 ? $m[1] : '';
        preg_match_all(
            '/^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m',
            $source,
            $hits,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        $spans = [];
        foreach ($hits as $hit) {
            // The docblock must be the last thing before the declaration, past any attributes.
            $before = substr($source, 0, $hit[0][1]);
            // Same line-anchored test as parse(): prose that mentions @internal mid-sentence is not the tag.
            // Attributes may nest brackets and quote strings that hold brackets, so the chain is
            // matched as balanced groups, not up to the first `]`.
            $internal = preg_match('~/\*\*((?:(?!\*/).)*)\*/\s*(?:#(?<attr>\[(?:[^\[\]"\']++|"(?:[^"\\\\]|\\\\.)*+"|\'(?:[^\'\\\\]|\\\\.)*+\'|(?&attr))*\])\s*)*$~s', $before, $doc) === 1
                && self::isInternal($doc[1]);
            $spans[] = [
                'fqn' => ($namespace === '' ? '' : $namespace . '\\') . $hit[1][0],
                'internal' => $internal,
            ];
        }

        return $spans;
    }

    /**
     * Declarations in one PHP file, internal ones included (collect() drops them).
     * Only top-level type declarations count; anonymous classes, `X::class` and
     * braced namespaces are rejected loudly rather than skipped, since a silently
     * dropped type would pass the coverage check for the wrong reason.
     *
     * @return list<array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, backed: bool, internal: bool, traits: list<string>, parents: list<string>, methods: list<array{name: string, signature: string, summary: string}>}>
     */
    private static function parse(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        // Byte offset of each token, so a type's size is its own span and not its file's.
        $offsets = [];
        $pos = 0;
        foreach ($tokens as $tok) {
            $offsets[] = $pos;
            $pos += strlen(is_array($tok) ? $tok[1] : $tok);
        }
        $namespace = '';
        $imports = [];
        $depth = 0;
        $doc = null;
        $mods = [];
        $current = null;
        $done = [];

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];

            if (!is_array($tok)) {
                if ($tok === '{') {
                    if ($current !== null && !$current['open'] && $depth === 0) {
                        $current['open'] = true;
                    }
                    $depth++;
                    $doc = null;
                    $mods = [];
                } elseif ($tok === '}') {
                    $depth--;
                    if ($current !== null && $current['open'] && $depth === 0) {
                        $done[] = self::finish($current, $offsets[$i] + 1 - $current['start']);
                        $current = null;
                    }
                    $doc = null;
                    $mods = [];
                } elseif ($tok === ';') {
                    $doc = null;
                    $mods = [];
                }
                continue;
            }

            [$id, $text] = $tok;
            switch ($id) {
                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $depth++;
                    break;

                case T_DOC_COMMENT:
                    $doc = $text;
                    break;

                case T_PUBLIC:
                case T_PROTECTED:
                case T_PRIVATE:
                case T_STATIC:
                case T_ABSTRACT:
                case T_FINAL:
                case T_READONLY:
                    $mods[] = strtolower($text);
                    break;

                case T_USE:
                    // A method named `use` (`function use()`, `->use()`, `Type::use()`) is a name, not a statement.
                    $prev = self::previousSignificant($tokens, $i - 1);
                    if ($prev !== null && is_array($tokens[$prev])
                        && in_array($tokens[$prev][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                        break;
                    }
                    // Top level: an import, which a trait name in the same file resolves through.
                    // Inside a class body: a trait use, whose names are resolved now while the
                    // imports for this file are known.
                    if ($depth === 0) {
                        [$pairs, $i] = self::useStatement($tokens, $i + 1);
                        foreach ($pairs as [$name, $alias]) {
                            $imports[$alias] = ltrim($name, '\\');
                        }
                    } elseif ($depth === 1 && $current !== null && $current['open']) {
                        [$pairs, $i, $adapts] = self::useStatement($tokens, $i + 1);
                        foreach ($pairs as [$name]) {
                            $current['traits'][] = self::resolveName($name, $imports, $namespace);
                        }
                        foreach ($adapts as $adapt) {
                            $current['adapts'][] = [
                                'trait' => $adapt['trait'] === null ? null : self::resolveName($adapt['trait'], $imports, $namespace),
                                'method' => $adapt['method'],
                                'insteadof' => array_map(static fn (string $n): string => self::resolveName($n, $imports, $namespace), $adapt['insteadof']),
                                'visibility' => $adapt['visibility'],
                                'alias' => $adapt['alias'],
                            ];
                        }
                    }
                    $doc = null;
                    $mods = [];
                    break;

                case T_NAMESPACE:
                    $next = self::nextSignificant($tokens, $i + 1);
                    if ($next !== null && $tokens[$next] === '{') {
                        throw new \RuntimeException('gen-api-reference: braced namespace blocks are not supported; use a `namespace X;` statement');
                    }
                    if ($next !== null && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_NAME_QUALIFIED, T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                        // A braced block would otherwise leave its types one level deeper than
                        // the parser looks, and they would vanish without a word.
                        $after = self::nextSignificant($tokens, $next + 1);
                        if ($after !== null && $tokens[$after] === '{') {
                            throw new \RuntimeException('gen-api-reference: braced namespace blocks are not supported; use a `namespace X;` statement');
                        }
                        $namespace = ltrim($tokens[$next][1], '\\');
                        $imports = [];
                        $i = $next;
                    }
                    $doc = null;
                    $mods = [];
                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case T_ENUM:
                    $prev = self::previousSignificant($tokens, $i - 1);
                    $isAnon = $prev !== null && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_NEW, T_DOUBLE_COLON], true);
                    if ($isAnon || $depth !== 0 || $current !== null) {
                        break;
                    }
                    $name = self::nextSignificant($tokens, $i + 1);
                    if ($name === null || !is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
                        break;
                    }
                    $kind = match ($id) {
                        T_INTERFACE => 'interface',
                        T_TRAIT => 'trait',
                        T_ENUM => 'enum',
                        default => 'class',
                    };
                    // `enum Name: string` is backed; `enum Name implements X` is not.
                    $after = self::nextSignificant($tokens, $name + 1);
                    $current = [
                        'namespace' => $namespace,
                        'short' => $tokens[$name][1],
                        'kind' => $kind,
                        'modifiers' => $mods,
                        'doc' => $doc,
                        'backed' => $kind === 'enum' && $after !== null && $tokens[$after] === ':',
                        'start' => $offsets[$i],
                        'open' => false,
                        'methods' => [],
                        'cases' => [],
                        'traits' => [],
                        'adapts' => [],
                        'parents' => self::parentsOf($tokens, $name, $imports, $namespace),
                    ];
                    $i = $name;
                    $doc = null;
                    $mods = [];
                    break;

                case T_FUNCTION:
                    if ($current !== null && $current['open'] && $depth === 1) {
                        self::addMethod($current, $tokens, $i, $doc, $mods);
                    }
                    $doc = null;
                    $mods = [];
                    break;

                case T_CASE:
                    if ($current !== null && $current['open'] && $depth === 1 && $current['kind'] === 'enum') {
                        // PHP keywords are legal case names (`case Default`), and they tokenize as T_DEFAULT
                        // etc., so the name is judged by its text, as addMethod() does, not by its token type.
                        $name = self::nextSignificant($tokens, $i + 1);
                        if ($name !== null && is_array($tokens[$name]) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tokens[$name][1]) === 1) {
                            $current['cases'][] = $tokens[$name][1];
                        }
                    }
                    break;
            }
        }

        if ($current !== null) {
            throw new \RuntimeException('gen-api-reference: unterminated declaration of ' . $current['short']);
        }

        return $done;
    }

    /**
     * The `use` statement whose keyword precedes $from. Three forms: an import (`use A\B;`),
     * a group import (`use A\{B, C as D};`), and a trait use, which may carry an adaptation
     * block (`use T { m as n; A::m insteadof B; }`). A group import's names are expanded
     * under their prefix; a trait's names come back raw, for the caller to resolve while the
     * file's imports are known.
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: int, 2: list<array{trait: ?string, method: string, insteadof: list<string>, visibility: ?string, alias: ?string}>}
     *         [raw name, alias] pairs, the index of the closing `;` (or of the adaptation block's `}` when no `;` follows it), and the adaptations
     */
    private static function useStatement(array $tokens, int $from): array
    {
        $pairs = [];
        $words = [];
        for ($k = $from, $n = count($tokens); $k < $n; $k++) {
            $t = $tokens[$k];
            if ($t === '{') {
                $close = self::matchingBrace($tokens, $k);
                $prefix = self::groupPrefix($words);
                if ($prefix !== null) {
                    foreach (self::splitBy($tokens, $k + 1, $close, ',') as $item) {
                        foreach (self::useItem($item, []) as [$name, $alias]) {
                            $pairs[] = [$prefix . '\\' . $name, $alias];
                        }
                    }
                    $semi = self::nextSignificant($tokens, $close + 1);
                    if ($semi === null || $tokens[$semi] !== ';') {
                        throw new \RuntimeException('gen-api-reference: a group `use` must end with `;`');
                    }

                    return [$pairs, $semi, []];
                }
                $pairs = self::useItem($words, $pairs);
                $adapts = [];
                foreach (self::splitBy($tokens, $k + 1, $close, ';') as $entry) {
                    if ($entry !== []) {
                        $adapts[] = self::adaptation($entry);
                    }
                }
                $after = self::nextSignificant($tokens, $close + 1);

                return [$pairs, $after !== null && $tokens[$after] === ';' ? $after : $close, $adapts];
            }
            if ($t === ';' || $t === ',') {
                $pairs = self::useItem($words, $pairs);
                $words = [];
                if ($t === ';') {
                    return [$pairs, $k, []];
                }
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $words[] = $t;
        }

        throw new \RuntimeException('gen-api-reference: unterminated use statement');
    }

    /** The index of the `}` that closes the `{` at $open. */
    private static function matchingBrace(array $tokens, int $open): int
    {
        $depth = 0;
        for ($k = $open, $n = count($tokens); $k < $n; $k++) {
            if ($tokens[$k] === '{') {
                $depth++;
            } elseif ($tokens[$k] === '}' && --$depth === 0) {
                return $k;
            }
        }

        throw new \RuntimeException('gen-api-reference: unterminated brace in a use statement');
    }

    /**
     * The namespace prefix of a group import: the name written before `\{`, or null when the
     * brace opens a trait adaptation block instead.
     *
     * @param list<mixed> $words
     */
    private static function groupPrefix(array $words): ?string
    {
        $last = $words[count($words) - 1] ?? null;
        if (!is_array($last) || $last[0] !== T_NS_SEPARATOR) {
            return null;
        }
        $name = self::firstName(array_slice($words, 0, -1));

        return $name === null ? null : ltrim($name, '\\');
    }

    /**
     * The tokens between $from and $to (exclusive) split at each plain `$sep`, with
     * whitespace and comments dropped from every piece.
     *
     * @return list<list<mixed>>
     */
    private static function splitBy(array $tokens, int $from, int $to, string $sep): array
    {
        $pieces = [[]];
        for ($k = $from; $k < $to; $k++) {
            $t = $tokens[$k];
            if ($t === $sep) {
                $pieces[] = [];
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $pieces[count($pieces) - 1][] = $t;
        }

        return $pieces;
    }

    /**
     * One adaptation entry: `[Trait::]method insteadof Other[, More]` or `[Trait::]method as [visibility] [alias]`.
     *
     * @param list<mixed> $words the significant tokens of the entry
     * @return array{trait: ?string, method: string, insteadof: list<string>, visibility: ?string, alias: ?string}
     */
    private static function adaptation(array $words): array
    {
        $texts = array_map(static fn (mixed $w): string => is_array($w) ? $w[1] : $w, $words);
        $trait = null;
        $at = 0;
        if (count($texts) > 2 && $texts[1] === '::') {
            $trait = $texts[0];
            $at = 2;
        }
        $method = $texts[$at] ?? '';
        $keyword = strtolower($texts[$at + 1] ?? '');
        if ($method === '' || !in_array($keyword, ['insteadof', 'as'], true)) {
            throw new \RuntimeException('gen-api-reference: unsupported trait adaptation: ' . implode(' ', $texts));
        }
        if ($keyword === 'insteadof') {
            $excluded = [];
            foreach (array_slice($texts, $at + 2) as $text) {
                if ($text !== ',') {
                    $excluded[] = $text;
                }
            }

            return ['trait' => $trait, 'method' => $method, 'insteadof' => $excluded, 'visibility' => null, 'alias' => null];
        }
        $rest = array_slice($texts, $at + 2);
        $visibility = null;
        if ($rest !== [] && in_array(strtolower($rest[0]), ['public', 'protected', 'private'], true)) {
            $visibility = strtolower(array_shift($rest));
        }

        return ['trait' => $trait, 'method' => $method, 'insteadof' => [], 'visibility' => $visibility, 'alias' => $rest[0] ?? null];
    }

    /** The first name token in $words, or null when there is none. */
    private static function firstName(array $words): ?string
    {
        foreach ($words as $word) {
            if (is_array($word) && in_array($word[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                return $word[1];
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $words the significant tokens of one comma-separated item
     * @param list<array{0: string, 1: string}> $pairs
     * @return list<array{0: string, 1: string}>
     */
    private static function useItem(array $words, array $pairs): array
    {
        $first = $words[0] ?? null;
        if (is_array($first) && in_array($first[0], [T_FUNCTION, T_CONST], true)) {
            return $pairs;
        }
        $name = null;
        $alias = null;
        $afterAs = false;
        foreach ($words as $word) {
            if (!is_array($word)) {
                continue;
            }
            if ($word[0] === T_AS) {
                $afterAs = true;
                continue;
            }
            if ($afterAs) {
                $alias = $word[1];
                $afterAs = false;
                continue;
            }
            if ($name === null && in_array($word[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                $name = $word[1];
            }
        }
        if ($name === null) {
            return $pairs;
        }
        $pos = strrpos($name, '\\');
        $pairs[] = [$name, $alias ?? ($pos === false ? $name : substr($name, $pos + 1))];

        return $pairs;
    }

    /**
     * The FQN a name refers to at the point it is written: a leading backslash is
     * absolute, an imported first segment expands through the import, and anything
     * else is relative to the namespace.
     *
     * @param array<string, string> $imports alias => FQN
     */
    private static function resolveName(string $raw, array $imports, string $namespace): string
    {
        if (str_starts_with($raw, '\\')) {
            return substr($raw, 1);
        }
        $first = strstr($raw, '\\', true);
        if ($first === false) {
            $first = $raw;
        }
        // PHP matches an alias without regard to case, so `extends base` after `use Demo\Lib\Base;` is Base.
        foreach ($imports as $alias => $fqn) {
            if (strcasecmp((string) $alias, $first) === 0) {
                return $fqn . substr($raw, strlen($first));
            }
        }

        return ($namespace === '' ? '' : $namespace . '\\') . $raw;
    }

    /**
     * The traits a type uses, directly or through an in-tree parent or trait, that have no
     * declaration under src/. Their methods are not listed, and a method table alone would
     * read as the type's whole API, so each type names them.
     *
     * @param array<string, array<string, mixed>> $index every parsed type, keyed by FQN
     * @param list<string> $trail the types already on the path, a guard against an inheritance cycle
     * @return list<string>
     */
    private static function undeclaredTraits(array $index, string $fqn, array $trail = []): array
    {
        $found = [];
        foreach ($index[$fqn]['traits'] as $traitName) {
            $traitFqn = self::declaredFqn($index, $traitName);
            if ($traitFqn === null) {
                $found[] = $traitName;
            } elseif (!in_array($traitFqn, $trail, true)) {
                $found = [...$found, ...self::undeclaredTraits($index, $traitFqn, [...$trail, $fqn])];
            }
        }
        foreach ($index[$fqn]['parents'] as $parentName) {
            $parentFqn = self::declaredFqn($index, $parentName);
            if ($parentFqn !== null && !in_array($parentFqn, $trail, true)) {
                $found = [...$found, ...self::undeclaredTraits($index, $parentFqn, [...$trail, $fqn])];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The public methods of one type, as PHP exposes them: its own, then those
     * of each trait it uses, then those of its parent class or extended
     * interfaces. A method earlier in that order wins over a later one of the
     * same name, which is how PHP resolves an override. A parent declared outside
     * src/ has no source in this tree, so only the members of in-tree parents are
     * known and listed. An implemented interface is a parent here too: PHP exposes
     * its methods on the class, and a `{@inheritdoc}` on the class reads from it.
     *
     * @param array<string, array<string, mixed>> $index every parsed type, keyed by FQN
     * @param list<string> $trail the types already on the path, a guard against an inheritance cycle
     * @return list<array{name: string, signature: string, summary: string}>
     */
    private static function withInheritedMethods(array $index, string $fqn, string $slug, array $trail = []): array
    {
        // PHP resolves class and trait names case-insensitively, so a `use base;` or `extends base`
        // names the type declared as `Base`. Each name resolves to its declared FQN before any lookup.
        $traits = [];
        foreach ($index[$fqn]['traits'] as $traitName) {
            // A trait declared outside src/ has no entry in the index and contributes no rows, as an
            // out-of-tree parent does. A name that is declared here but is not a trait is a mistake.
            $traitFqn = self::declaredFqn($index, $traitName);
            if ($traitFqn === null) {
                continue;
            }
            if ($index[$traitFqn]['kind'] !== 'trait') {
                throw new \RuntimeException("gen-api-reference: $fqn uses $traitName, which is declared under $slug/src but is not a trait");
            }
            $traits[] = $traitFqn;
        }
        $parents = [];
        foreach ($index[$fqn]['parents'] as $parentName) {
            // A parent declared outside src/ has no entry in the index and contributes no rows.
            $parentFqn = self::declaredFqn($index, $parentName);
            if ($parentFqn !== null) {
                $parents[] = $parentFqn;
            }
        }

        // Each in-tree source is resolved before the merge, so an own method's {@inheritdoc}
        // can read the summary its nearest documented ancestor already resolved.
        $traitLists = [];
        foreach ($traits as $traitFqn) {
            if (!in_array($traitFqn, $trail, true)) {
                $traitLists[$traitFqn] = self::withInheritedMethods($index, $traitFqn, $slug, [...$trail, $fqn]);
            }
        }
        $sources = array_values(self::adaptTraits($traitLists, $index[$fqn]['adapts'], $index));
        foreach ($parents as $parentFqn) {
            if (!in_array($parentFqn, $trail, true)) {
                $sources[] = self::withInheritedMethods($index, $parentFqn, $slug, [...$trail, $fqn]);
            }
        }

        // Method names compare without case, as PHP matches an override to the method it replaces.
        $methods = [];
        $names = [];
        foreach ($index[$fqn]['methods'] as $own) {
            $methods[] = [
                'name' => $own['name'],
                'signature' => $own['signature'],
                'summary' => $own['inherits'] ? self::inheritedSummary($own['name'], $own['summary'], $sources) : $own['summary'],
            ];
            $names[] = strtolower($own['name']);
        }
        foreach ($sources as $source) {
            foreach ($source as $method) {
                if (!in_array(strtolower($method['name']), $names, true)) {
                    $methods[] = $method;
                    $names[] = strtolower($method['name']);
                }
            }
        }

        return $methods;
    }

    /**
     * Applies a class's trait adaptations to the public methods of each trait it uses, as PHP
     * does before the merge: `T::m insteadof U` drops m from U, `m as alias` adds a copy named
     * alias, and `m as private|protected` hides m. A copy under a non-public visibility is not
     * listed, since every row is public.
     *
     * @param array<string, list<array{name: string, signature: string, summary: string}>> $lists keyed by trait FQN
     * @param list<array{trait: ?string, method: string, insteadof: list<string>, visibility: ?string, alias: ?string}> $adapts
     * @param array<string, array<string, mixed>> $index
     * @return array<string, list<array{name: string, signature: string, summary: string}>>
     */
    private static function adaptTraits(array $lists, array $adapts, array $index): array
    {
        // Aliases are copied from the trait's methods as they were declared, and removals are applied
        // after, so `B::m insteadof A` does not stop `A::m as n` from finding A's m.
        $removals = [];
        $copies = [];
        foreach ($adapts as $adapt) {
            if ($adapt['insteadof'] !== []) {
                foreach ($adapt['insteadof'] as $excludedName) {
                    $excluded = self::declaredFqn($index, $excludedName);
                    if ($excluded !== null) {
                        $removals[$excluded][] = $adapt['method'];
                    }
                }
                continue;
            }
            $targets = $adapt['trait'] === null ? array_keys($lists) : [self::declaredFqn($index, $adapt['trait'])];
            $hidden = in_array($adapt['visibility'], ['private', 'protected'], true);
            foreach ($targets as $target) {
                if ($target === null || !isset($lists[$target])) {
                    continue;
                }
                foreach ($lists[$target] as $method) {
                    if (strcasecmp($method['name'], $adapt['method']) !== 0) {
                        continue;
                    }
                    if ($adapt['alias'] !== null) {
                        if (!$hidden) {
                            $copies[$target][] = self::renamed($method, $adapt['alias']);
                        }
                    } elseif ($hidden) {
                        $removals[$target][] = $adapt['method'];
                    }
                }
            }
        }
        foreach ($removals as $target => $names) {
            if (isset($lists[$target])) {
                foreach ($names as $name) {
                    $lists[$target] = self::without($lists[$target], $name);
                }
            }
        }
        foreach ($copies as $target => $added) {
            foreach ($added as $copy) {
                $lists[$target][] = $copy;
            }
        }

        return $lists;
    }

    /**
     * @param list<array{name: string, signature: string, summary: string}> $methods
     * @return list<array{name: string, signature: string, summary: string}>
     */
    private static function without(array $methods, string $name): array
    {
        return array_values(array_filter($methods, static fn (array $m): bool => strcasecmp($m['name'], $name) !== 0));
    }

    /**
     * A copy of one method under another name: the name is replaced where it opens the
     * signature's parameter list, and the summary carries over unchanged.
     *
     * @param array{name: string, signature: string, summary: string} $method
     * @return array{name: string, signature: string, summary: string}
     */
    private static function renamed(array $method, string $alias): array
    {
        $at = strpos($method['signature'], $method['name'] . '(');
        $signature = $at === false
            ? $method['signature']
            : substr_replace($method['signature'], $alias, $at, strlen($method['name']));

        return ['name' => $alias, 'signature' => $signature, 'summary' => $method['summary']];
    }
    /**
     * The key under which a type name is indexed: the name as written when it matches a declaration
     * exactly, otherwise the declaration it matches without regard to case, or null when none does.
     *
     * @param array<string, array<string, mixed>> $index
     */
    private static function declaredFqn(array $index, string $name): ?string
    {
        if (isset($index[$name])) {
            return $name;
        }
        foreach (array_keys($index) as $declared) {
            if (strcasecmp($declared, $name) === 0) {
                return $declared;
            }
        }

        return null;
    }

    /**
     * The summary of a method whose PHPDoc carries {@inheritdoc}: the nearest documented
     * ancestor's summary, then any prose the method adds after the tag. An ancestor that
     * documents the method only by a bare tag resolves to '' and is passed over, so the
     * lookup reaches the first real summary. With none, the row keeps the method's own
     * prose and no tag or stray dash is printed.
     *
     * @param list<list<array{name: string, signature: string, summary: string}>> $sources each in-tree ancestor's resolved methods, nearest first
     */
    private static function inheritedSummary(string $name, string $own, array $sources): string
    {
        $parent = '';
        foreach ($sources as $source) {
            foreach ($source as $method) {
                if ($method['summary'] !== '' && strcasecmp($method['name'], $name) === 0) {
                    $parent = $method['summary'];
                    break 2;
                }
            }
        }

        return trim($parent === '' ? $own : ($own === '' ? $parent : $parent . ' ' . $own));
    }

    /**
     * @param array<string, mixed> $type
     * @param list<string> $mods
     */
    private static function addMethod(array &$type, array $tokens, int $at, ?string $doc, array $mods): void
    {
        if (self::isInternal($doc)) {
            return;
        }
        if ($type['kind'] !== 'interface') {
            foreach (self::NON_PUBLIC as $vis) {
                if (in_array($vis, $mods, true)) {
                    return;
                }
            }
        }

        $byRef = false;
        $name = self::nextSignificant($tokens, $at + 1);
        // PHP 8.1+ tokenizes `&` before a name as T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, an array token.
        if ($name !== null && (is_array($tokens[$name]) ? $tokens[$name][1] : $tokens[$name]) === '&') {
            // PHP writes a by-reference method as `function &name`; the sigil belongs to the name.
            $byRef = true;
            $name = self::nextSignificant($tokens, $name + 1);
        }
        if ($name === null || !is_array($tokens[$name]) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tokens[$name][1]) !== 1) {
            throw new \RuntimeException('gen-api-reference: a function declaration has no readable name');
        }

        $sig = self::signatureTail($tokens, $name + 1);

        $prefix = [];
        foreach (self::SHOWN_MODIFIERS as $shown) {
            if (in_array($shown, $mods, true)) {
                $prefix[] = $shown;
            }
        }
        $type['methods'][] = [
            'name' => $tokens[$name][1],
            'signature' => implode(' ', $prefix) . ($prefix === [] ? '' : ' ')
                . ($byRef ? '&' : '') . $tokens[$name][1] . $sig,
            'summary' => self::summary($doc),
            'inherits' => self::inheritsDoc($doc),
        ];
    }

    /**
     * The printed tail of a signature, from just after the name to the body or the
     * semicolon: the parameter list and any return type. Spacing is rebuilt token by
     * token. A pattern over the joined text would also rewrite a string literal in a
     * default value, so `' ,'` would print as `','`.
     *
     * @param list<mixed> $tokens
     */
    private static function signatureTail(array $tokens, int $from): string
    {
        $pieces = [];
        $gap = false;
        $parens = 0;
        for ($j = $from, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            $txt = is_array($t) ? $t[1] : $t;
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $gap = true;
                continue;
            }
            if ($txt === '(') {
                $parens++;
            } elseif ($txt === ')') {
                $parens--;
            } elseif ($parens === 0 && ($txt === '{' || $txt === ';')) {
                break;
            }
            // A trailing comma before the closing parenthesis is not printed.
            if ($txt === ')' && end($pieces) === ',') {
                array_pop($pieces);
            }
            // One space where the source had a gap, none inside the parentheses or before a comma.
            if ($gap && $pieces !== [] && !in_array($txt, [')', ','], true) && end($pieces) !== '(') {
                $pieces[] = ' ';
            }
            $pieces[] = $txt;
            $gap = false;
        }

        return implode('', $pieces);
    }

    /**
     * @param array<string, mixed> $type
     * @return array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, backed: bool, internal: bool, traits: list<string>, parents: list<string>, methods: list<array{name: string, signature: string, summary: string, inherits: bool}>}
     */
    private static function finish(array $type, int $size): array
    {
        $fqn = ($type['namespace'] === '' ? '' : $type['namespace'] . '\\') . $type['short'];
        // PHP's own order: `final readonly class`. Only classes take these words.
        $words = [];
        foreach (['abstract', 'final', 'readonly'] as $modifier) {
            if (in_array($modifier, $type['modifiers'], true) && $type['kind'] === 'class') {
                $words[] = $modifier;
            }
        }

        return [
            'fqn' => $fqn,
            'short' => $type['short'],
            'namespace' => $type['namespace'],
            'kind' => implode(' ', [...$words, $type['kind']]),
            'size' => $size,
            'summary' => self::summary($type['doc']),
            'cases' => $type['cases'],
            'backed' => $type['backed'],
            'internal' => self::isInternal($type['doc']),
            'traits' => $type['traits'],
            'adapts' => $type['adapts'],
            'parents' => $type['parents'],
            'methods' => $type['methods'],
        ];
    }

    /**
     * The names a type header extends or implements: one parent for a class, a list for
     * an interface, and the interfaces a class or enum implements. Each name resolves
     * while the file's imports are known, as a trait name does, so a parent imported
     * under an alias still reaches its declaration. Only the names after an `extends` or
     * `implements` keyword count, so the `string` in `enum Name: string` does not.
     *
     * @param list<mixed> $tokens
     * @param array<string, string> $imports
     * @return list<string>
     */
    private static function parentsOf(array $tokens, int $from, array $imports, string $namespace): array
    {
        $parents = [];
        $inHeritage = false;
        for ($k = $from + 1, $n = count($tokens); $k < $n && $tokens[$k] !== '{'; $k++) {
            $t = $tokens[$k];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_EXTENDS || $t[0] === T_IMPLEMENTS) {
                $inHeritage = true;
            } elseif ($inHeritage && in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                $parents[] = self::resolveName($t[1], $imports, $namespace);
            }
        }

        return $parents;
    }

    private static function isInternal(?string $doc): bool
    {
        // The leading run covers `/**` so the one-line form `/** @internal */` counts too.
        return $doc !== null && preg_match('~^[\s/*]*@internal\b~m', $doc) === 1;
    }

    private static function inheritsDoc(?string $doc): bool
    {
        return $doc !== null && preg_match('/\{@inheritdoc\}/i', $doc) === 1;
    }

    /**
     * Refuses a published summary that carries an internal reference: a tracker or audit ID
     * (sc-23, DX-05, sugar-core-024) or a file outside the repo (ansicode.txt). Those IDs
     * resolve for no reader, so the PHPDoc gets rewritten instead of published. A bare
     * `@internal` is refused too: it means the tag is not line-leading, so the type or method
     * it marks would publish as a public row.
     *
     * @param list<array{name: string, signature: string, summary: string}> $methods
     */
    private static function refuseInternalReferences(string $fqn, string $summary, array $methods): void
    {
        $texts = [$summary];
        foreach ($methods as $method) {
            $texts[] = $method['summary'];
        }
        foreach ($texts as $text) {
            if (preg_match(self::INTERNAL_REFERENCE, $text, $hit) === 1) {
                throw new \RuntimeException(
                    "gen-api-reference: the published PHPDoc of $fqn carries the internal reference '{$hit[0]}'; "
                    . 'rewrite that docblock so the page does not print it'
                );
            }
            if (preg_match(self::BARE_INTERNAL_TAG, $text, $hit) === 1) {
                throw new \RuntimeException(
                    "gen-api-reference: the published PHPDoc of $fqn carries a bare '{$hit[0]}' outside a line-leading tag; "
                    . 'move the tag to the start of its own line, or reword the sentence so the page does not print it'
                );
            }
        }
    }

    /**
     * The first PHPDoc paragraph, as HTML. Tags (lines starting with @) end it.
     * Backtick spans become code, the inline {@code X} form becomes a backtick span,
     * and the inline {@see X} and {@link X} forms are reduced to X. Tags match in any
     * letter case, as PHPDoc readers do, so {@See X} is not printed as raw text.
     */
    private static function summary(?string $doc): string
    {
        if ($doc === null) {
            return '';
        }
        $para = [];
        foreach (preg_split('/\r\n|\r|\n/', $doc) ?: [] as $line) {
            $line = trim($line);
            if ($line === '/**' || $line === '*/' || $line === '') {
                if ($para !== []) {
                    break;
                }
                continue;
            }
            $line = (string) preg_replace(['#^/\*\*\s*#', '#\s*\*/$#', '#^\*\s?#'], ['', '', ''], $line);
            // Source-side annotations such as `// @rename-keep` are for tooling, not readers.
            $line = trim((string) preg_replace('~\s*//\s*@[A-Za-z][\w-]*~', '', $line));
            if ($line === '') {
                if ($para !== []) {
                    break;
                }
                continue;
            }
            if (str_starts_with($line, '@')) {
                break;
            }
            $para[] = $line;
        }

        $text = implode(' ', $para);
        $text = (string) preg_replace('/\{@code\s+([^}]*)\}/i', '`$1`', $text);
        $text = (string) preg_replace('/\{@(?:see|link)\s+([^}]*)\}/i', '$1', $text);
        $text = (string) preg_replace('/\{@inheritdoc\}/i', '', $text);
        $html = htmlspecialchars(trim((string) preg_replace('/\s+/', ' ', $text)), ENT_QUOTES, 'UTF-8');

        return (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    }

    /**
     * The id a type's heading carries. Two names that flatten to the same id
     * would send an in-page link to the wrong heading, so render() refuses them.
     */
    private static function anchor(string $fqn): string
    {
        return 'api-' . strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $fqn));
    }

    /**
     * The fqns of the $n largest types, as keys, in coverage()'s order: size descending, then name.
     *
     * @param list<array{fqn: string, size: int}> $types
     * @return array<string, true>
     */
    private static function largestTypes(array $types, int $n): array
    {
        if ($n <= 0) {
            return [];
        }
        usort($types, static function (array $a, array $b): int {
            $cmp = $b['size'] <=> $a['size'];

            return $cmp !== 0 ? $cmp : strcmp($a['fqn'], $b['fqn']);
        });
        $largest = [];
        foreach (array_slice($types, 0, $n) as $type) {
            $largest[$type['fqn']] = true;
        }

        return $largest;
    }

    /**
     * @param list<array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, backed: bool, undeclaredTraits: list<string>, methods: list<array{name: string, signature: string, summary: string}>}> $types
     */
    private static function render(string $slug, array $types): string
    {
        $anchors = [];
        foreach ($types as $type) {
            $id = self::anchor($type['fqn']);
            if (isset($anchors[$id])) {
                throw new \RuntimeException('gen-api-reference: ' . $anchors[$id] . ' and ' . $type['fqn'] . ' both take the anchor id ' . $id . '; rename one');
            }
            $anchors[$id] = $type['fqn'];
        }

        $out = [];
        $out[] = '<!-- GENERATED by tools/gen-api-reference.php from ' . $slug . '/src. Do not edit by hand. '
            . 'Regenerate with: php tools/gen-api-reference.php ' . $slug . ' -->';
        // The method tables are complete only for the parents under src/. Without this line a
        // reader checking a type for an inherited method (getMessage() on an exception) finds
        // nothing and concludes the method does not exist.
        $out[] = '<p>Only methods declared in <code>' . $slug . '/src</code>, or inherited from a parent declared there, '
            . 'are listed. A type that extends a class outside that tree, such as <code>\RuntimeException</code>, '
            . 'does not show the methods of that class.</p>';

        $byNamespace = [];
        foreach ($types as $type) {
            $byNamespace[$type['namespace']][] = $type;
        }
        ksort($byNamespace, SORT_STRING);

        $listed = self::ROWS_IN_NAMESPACE[$slug] ?? null;
        $pinned = self::largestTypes($types, self::TOP_TYPES_LISTED[$slug] ?? 0);
        // namespace => [public types in it, types with a row on this page]
        $omitted = [];
        foreach ($byNamespace as $namespace => $group) {
            $shown = [];
            foreach ($group as $type) {
                if ($listed === null || $namespace === $listed || isset($pinned[$type['fqn']])) {
                    $shown[] = $type;
                }
            }
            if ($shown !== []) {
                $out[] = '<h3>' . htmlspecialchars($namespace, ENT_QUOTES, 'UTF-8') . '</h3>';
                foreach ($shown as $type) {
                    $out[] = self::renderType($type);
                }
            }
            if (count($shown) < count($group)) {
                $omitted[$namespace] = [count($group), count($shown)];
            }
        }

        if ($omitted !== []) {
            $out[] = '<h3>Sub-namespaces not listed row by row</h3>';
            $out[] = '<p>A type named here has no row on this page. Each line states how many public types its '
                . 'namespace holds in all, so a count here is the full count.</p>';
            $out[] = '<ul>';
            foreach ($omitted as $namespace => [$total, $shown]) {
                $out[] = '<li><code>' . htmlspecialchars($namespace, ENT_QUOTES, 'UTF-8') . '</code>: ' . $total
                    . ' public ' . ($total === 1 ? 'type' : 'types')
                    . ($shown > 0 ? ", $shown of them with a row above" : '') . '</li>';
            }
            $out[] = '</ul>';
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * @param array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, backed: bool, undeclaredTraits: list<string>, methods: list<array{name: string, signature: string, summary: string}>} $type
     */
    private static function renderType(array $type): string
    {
        $h = '<h4 id="' . self::anchor($type['fqn']) . '"><code>' . htmlspecialchars($type['short'], ENT_QUOTES, 'UTF-8')
            . '</code> <small>' . htmlspecialchars($type['kind'], ENT_QUOTES, 'UTF-8') . '</small></h4>';
        $parts = [$h];
        if ($type['summary'] !== '') {
            $parts[] = '<p>' . $type['summary'] . '</p>';
        }
        if ($type['cases'] !== []) {
            $cases = array_map(
                static fn (string $c): string => '<code>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</code>',
                $type['cases']
            );
            $parts[] = '<p>Cases: ' . implode(' · ', $cases) . '</p>';
        }
        if ($type['kind'] === 'enum') {
            // PHP supplies these methods, so no declaration in src/ carries them and the table below cannot.
            $provided = '<code>cases()</code>';
            if ($type['backed']) {
                $provided .= ', <code>from()</code> and <code>tryFrom()</code>';
            }
            $parts[] = '<p>PHP also provides ' . $provided . '.</p>';
        }
        if ($type['undeclaredTraits'] !== []) {
            // The methods a trait adds are real API, so a missing table row must not read as a missing method.
            $names = array_map(
                static fn (string $t): string => '<code>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</code>',
                $type['undeclaredTraits']
            );
            $parts[] = '<p>Not listed: the methods of ' . implode(', ', $names)
                . (count($names) === 1 ? ', a trait' : ', traits') . ' declared outside src/.</p>';
        }
        if ($type['methods'] !== []) {
            $rows = [];
            foreach ($type['methods'] as $method) {
                $rows[] = '<tr><td><code>' . htmlspecialchars($method['signature'], ENT_QUOTES, 'UTF-8')
                    . '</code></td><td>' . $method['summary'] . '</td></tr>';
            }
            $parts[] = "<table class=\"api-table\">\n<thead><tr><th>Method</th><th>Summary</th></tr></thead>\n<tbody>\n"
                . implode("\n", $rows) . "\n</tbody>\n</table>";
        }

        return implode("\n", $parts);
    }

    /** @param list<mixed> $tokens */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($k = $from, $n = count($tokens); $k < $n; $k++) {
            $t = $tokens[$k];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $k;
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function previousSignificant(array $tokens, int $from): ?int
    {
        for ($k = $from; $k >= 0; $k--) {
            $t = $tokens[$k];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $k;
        }

        return null;
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit((static function (array $argv): int {
        $usage = "usage: gen-api-reference.php <slug> [--check|--report]\n"
            . "  <slug> is a lib in ApiReference::ENABLED (a fragment) or NO_PUBLIC_CLASSES (a note, no fragment)\n";
        if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            fwrite(STDOUT, $usage);

            return 0;
        }
        $args = array_slice($argv, 1);
        $slug = $args[0] ?? '';
        $flags = array_slice($args, 1);
        $check = in_array('--check', $flags, true);
        $report = in_array('--report', $flags, true);
        if ($slug === '' || preg_match('/^(sugar|candy)-[a-z0-9-]+$/', $slug) !== 1
            || array_diff($flags, ['--check', '--report']) !== []
            || ($check && $report)) {
            fwrite(STDERR, $usage);

            return 2;
        }

        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            fwrite(STDERR, "gen-api-reference: cannot resolve the monorepo root\n");

            return 2;
        }

        if (in_array($slug, ApiReference::NO_PUBLIC_CLASSES, true)) {
            // No fragment to write or compare: the body states the absence. A fragment left
            // behind from a lib that once had classes would be spliced without being checked.
            $stale = is_file(ApiReference::fragmentPath($root, $slug));
            // The skeleton list is a claim about src/, so the claim is checked here: a public
            // type added under a listed skeleton must fail the check, not pass it as "ok".
            try {
                $declared = ApiReference::declaredPublic($root, $slug);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                fwrite(STDERR, $e->getMessage() . "\n");

                return 2;
            }
            if ($declared !== []) {
                fwrite(STDERR, "drift: $slug declares " . count($declared) . " public type(s) but is listed in ApiReference::NO_PUBLIC_CLASSES; move it to ENABLED and write its fragment and body marker\n");

                return 1;
            }
            if ($report) {
                fwrite(STDOUT, "$slug: no public classes, so no top-10 figure\n");

                return 0;
            }
            if ($stale) {
                fwrite(STDERR, "drift: " . ApiReference::fragmentPath($root, $slug) . " exists but $slug has no public classes; delete it\n");

                return 1;
            }
            if ($check) {
                fwrite(STDOUT, "ok: $slug has no public classes and no fragment\n");

                return 0;
            }
            fwrite(STDERR, "gen-api-reference: $slug has no public classes, so it has no fragment to write\n");

            return 2;
        }

        try {
            $fragment = ApiReference::generate($root, $slug);
            if ($report) {
                $result = ApiReference::coverage(ApiReference::declaredPublic($root, $slug), $fragment);
                fwrite(STDOUT, sprintf(
                    "%s: top-10 coverage %d/%d (%.1f%%), target %.0f%%\n  top-10 by size: %s\n",
                    $slug,
                    $result['covered'],
                    count($result['top']),
                    $result['percent'],
                    ApiReference::TOP_COVERAGE_TARGET,
                    implode(', ', $result['top'])
                ));

                return $result['percent'] >= ApiReference::TOP_COVERAGE_TARGET ? 0 : 1;
            }

            $target = ApiReference::fragmentPath($root, $slug);
            if ($check) {
                $current = is_file($target) ? (string) file_get_contents($target) : '';
                if ($current !== $fragment) {
                    fwrite(STDERR, "drift: $target is stale; run php tools/gen-api-reference.php $slug\n");

                    return 1;
                }
                fwrite(STDOUT, "ok: $target matches the generated fragment\n");

                return 0;
            }

            if (@file_put_contents($target, $fragment) === false) {
                fwrite(STDERR, "gen-api-reference: cannot write $target\n");

                return 2;
            }
            fwrite(STDOUT, "gen-api-reference: wrote $target\n");

            return 0;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 2;
        }
    })($argv));
}
