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
 * PHPDoc paragraph and a method table (signature and summary). The output is
 * marked GENERATED in its first line and must never be hand-edited.
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
 * different sets. Target is at least 80 percent (plan section 5.3, item 6).
 *
 * PILOT SCOPE. Only the libs in ApiReference::ENABLED are built. Phase 4 widens
 * that list as a reviewed edit; nothing else changes.
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
     * Libs the generator may build. Pilot: sugar-core only (PD2 option A). A lib
     * is added here in the same PR that adds its body marker and fragment.
     */
    public const ENABLED = ['sugar-core'];

    /** The placeholder tools/gen-docs.php replaces with the fragment. Exactly one per body. */
    public const MARKER = '<!-- api-reference:generated -->';

    /** Target from plan_doc.md section 5.3, item 6. */
    public const TOP_COVERAGE_TARGET = 80.0;

    /** Visibilities that are not public API. A method with no keyword is public. */
    private const NON_PUBLIC = ['private', 'protected'];

    /** Modifiers printed before a method name. Visibility is never printed: every row is public. */
    private const SHOWN_MODIFIERS = ['static', 'abstract', 'final'];

    /**
     * What a published summary must not contain: audit and tracker IDs, and the
     * ansicode.txt citation, a file this repo does not carry. See refuseInternalReferences().
     */
    private const INTERNAL_REFERENCE = '~\b(?:sc|DX|sugar-core)-\d+\b|\bansicode\.txt\b~';

    public static function enabled(string $slug): bool
    {
        return in_array($slug, self::ENABLED, true);
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
        if (!self::enabled($slug)) {
            throw new \InvalidArgumentException(
                "gen-api-reference: $slug is not enabled (pilot scope is " . implode(', ', self::ENABLED) . ')'
            );
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
     * @return list<array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, backed: bool, methods: list<array{name: string, signature: string, summary: string}>}>
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
                    // Top level: an import, which a trait name in the same file resolves through.
                    // Inside a class body: a trait use, whose names are resolved now while the
                    // imports for this file are known.
                    if ($depth === 0) {
                        [$pairs, $i] = self::useStatement($tokens, $i + 1);
                        foreach ($pairs as [$name, $alias]) {
                            $imports[$alias] = ltrim($name, '\\');
                        }
                    } elseif ($depth === 1 && $current !== null && $current['open']) {
                        [$pairs, $i] = self::useStatement($tokens, $i + 1);
                        foreach ($pairs as [$name]) {
                            $current['traits'][] = self::resolveName($name, $imports, $namespace);
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
                        $name = self::nextSignificant($tokens, $i + 1);
                        if ($name !== null && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING) {
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
     * The `use` statement whose keyword precedes $from.
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: int} [raw name, alias] pairs, and the index of the closing `;`
     */
    private static function useStatement(array $tokens, int $from): array
    {
        $pairs = [];
        $words = [];
        for ($k = $from, $n = count($tokens); $k < $n; $k++) {
            $t = $tokens[$k];
            if ($t === '{') {
                throw new \RuntimeException('gen-api-reference: group and trait-adaptation `use` blocks are not supported; write one name per statement');
            }
            if ($t === ';' || $t === ',') {
                $pairs = self::useItem($words, $pairs);
                $words = [];
                if ($t === ';') {
                    return [$pairs, $k];
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
        if (isset($imports[$first])) {
            return $imports[$first] . substr($raw, strlen($first));
        }

        return ($namespace === '' ? '' : $namespace . '\\') . $raw;
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
        foreach ($index[$fqn]['traits'] as $traitFqn) {
            if (!isset($index[$traitFqn]) || $index[$traitFqn]['kind'] !== 'trait') {
                throw new \RuntimeException("gen-api-reference: $fqn uses $traitFqn, which is not a trait declared under $slug/src");
            }
        }

        // Each in-tree source is resolved before the merge, so an own method's {@inheritdoc}
        // can read the summary its nearest documented ancestor already resolved.
        $sources = [];
        foreach ([...$index[$fqn]['traits'], ...$index[$fqn]['parents']] as $sourceFqn) {
            if (!isset($index[$sourceFqn]) || in_array($sourceFqn, $trail, true)) {
                continue;
            }
            $sources[] = self::withInheritedMethods($index, $sourceFqn, $slug, [...$trail, $fqn]);
        }

        $methods = [];
        $names = [];
        foreach ($index[$fqn]['methods'] as $own) {
            $methods[] = [
                'name' => $own['name'],
                'signature' => $own['signature'],
                'summary' => $own['inherits'] ? self::inheritedSummary($own['name'], $own['summary'], $sources) : $own['summary'],
            ];
            $names[] = $own['name'];
        }
        foreach ($sources as $source) {
            foreach ($source as $method) {
                if (!in_array($method['name'], $names, true)) {
                    $methods[] = $method;
                    $names[] = $method['name'];
                }
            }
        }

        return $methods;
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
     * resolve for no reader, so the PHPDoc gets rewritten instead of published.
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
        }
    }

    /**
     * The first PHPDoc paragraph, as HTML. Tags (lines starting with @) end it.
     * Backtick spans become code, and the inline {@see X} and {@link X} forms
     * are reduced to X.
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
        $text = (string) preg_replace('/\{@(?:see|link)\s+([^}]*)\}/', '$1', $text);
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
     * @param list<array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, backed: bool, methods: list<array{name: string, signature: string, summary: string}>}> $types
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

        foreach ($byNamespace as $namespace => $group) {
            $out[] = '<h3>' . htmlspecialchars($namespace, ENT_QUOTES, 'UTF-8') . '</h3>';
            foreach ($group as $type) {
                $out[] = self::renderType($type);
            }
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * @param array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, backed: bool, methods: list<array{name: string, signature: string, summary: string}>} $type
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
            . '  enabled slugs: ' . implode(', ', ApiReference::ENABLED) . "\n";
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
