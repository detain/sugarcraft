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
 *
 * WHAT IT WRITES. Namespace headings, then one entry per type with its first
 * PHPDoc paragraph and a method table (signature and summary). The output is
 * marked GENERATED in its first line and must never be hand-edited.
 *
 * TOP-10 COVERAGE. The same measure tools/depth-score.php uses: take the ten
 * largest public types by source bytes, then count how many are named in the
 * fragment. Target is at least 80 percent (plan section 5.3, item 6).
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
 * Exit codes: 0 success or fresh, 1 stale fragment under --check, 2 usage error.
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
     * @throws \RuntimeException when the src tree is missing or unreadable
     */
    public static function generate(string $root, string $slug): string
    {
        return self::render($slug, self::collect($root, $slug));
    }

    /**
     * Top-10 coverage of a fragment: the ten largest public types by source
     * bytes (ties broken by name), and how many of them the HTML names.
     *
     * @param list<array{fqn: string, short: string, namespace: string, size: int}> $types
     * @return array{top: list<string>, covered: int, percent: float}
     */
    public static function coverage(array $types, string $html): array
    {
        $ranked = $types;
        usort($ranked, static function (array $a, array $b): int {
            $cmp = $b['size'] <=> $a['size'];

            return $cmp !== 0 ? $cmp : strcmp($a['fqn'], $b['fqn']);
        });
        $top = array_slice($ranked, 0, 10);
        $covered = 0;
        foreach ($top as $type) {
            if (preg_match('/\b' . preg_quote($type['short'], '/') . '\b/u', $html) === 1) {
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
     * @return list<array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, methods: list<array{signature: string, summary: string}>}>
     */
    public static function collect(string $root, string $slug): array
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

        $types = [];
        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::parse($source, strlen($source)) as $type) {
                $types[] = $type;
            }
        }
        usort($types, static fn (array $a, array $b): int => strcmp($a['fqn'], $b['fqn']));

        return $types;
    }

    /**
     * Declarations in one PHP file. Only top-level type declarations count;
     * anonymous classes, `X::class` and braced namespaces are rejected loudly
     * rather than skipped, since a silently dropped type would pass the
     * coverage check for the wrong reason.
     *
     * @return list<array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, methods: list<array{signature: string, summary: string}>}>
     */
    private static function parse(string $source, int $size): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $namespace = '';
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
                        if (!self::isInternal($current['doc'])) {
                            $done[] = self::finish($current, $size);
                        }
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
                    $current = [
                        'namespace' => $namespace,
                        'short' => $tokens[$name][1],
                        'kind' => $kind,
                        'modifiers' => $mods,
                        'doc' => $doc,
                        'open' => false,
                        'methods' => [],
                        'cases' => [],
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

        $name = self::nextSignificant($tokens, $at + 1);
        if ($name !== null && $tokens[$name] === '&') {
            $name = self::nextSignificant($tokens, $name + 1);
        }
        if ($name === null || !is_array($tokens[$name]) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tokens[$name][1]) !== 1) {
            throw new \RuntimeException('gen-api-reference: a function declaration has no readable name');
        }

        $sig = '';
        $parens = 0;
        for ($j = $name + 1, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            $txt = is_array($t) ? $t[1] : $t;
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($txt === '(') {
                $parens++;
            } elseif ($txt === ')') {
                $parens--;
            } elseif ($parens === 0 && ($txt === '{' || $txt === ';')) {
                break;
            }
            $sig .= is_array($t) ? $t[1] : $t;
        }
        $sig = trim((string) preg_replace('/\s+/', ' ', $sig));
        $sig = (string) preg_replace(['/\(\s+/', '/,?\s*\)/', '/\s+,/'], ['(', ')', ','], $sig);

        $prefix = [];
        foreach (self::SHOWN_MODIFIERS as $shown) {
            if (in_array($shown, $mods, true)) {
                $prefix[] = $shown;
            }
        }
        $type['methods'][] = [
            'signature' => implode(' ', $prefix) . ($prefix === [] ? '' : ' ') . $tokens[$name][1] . $sig,
            'summary' => self::summary($doc),
        ];
    }

    /**
     * @param array<string, mixed> $type
     * @return array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, methods: list<array{signature: string, summary: string}>}
     */
    private static function finish(array $type, int $size): array
    {
        $fqn = ($type['namespace'] === '' ? '' : $type['namespace'] . '\\') . $type['short'];
        $kind = $type['kind'];
        foreach (['abstract', 'final'] as $modifier) {
            if (in_array($modifier, $type['modifiers'], true) && $type['kind'] === 'class') {
                $kind = $modifier . ' ' . $kind;
            }
        }

        return [
            'fqn' => $fqn,
            'short' => $type['short'],
            'namespace' => $type['namespace'],
            'kind' => $kind,
            'size' => $size,
            'summary' => self::summary($type['doc']),
            'cases' => $type['cases'],
            'methods' => $type['methods'],
        ];
    }

    private static function isInternal(?string $doc): bool
    {
        return $doc !== null && preg_match('/^\s*\*?\s*@internal\b/m', $doc) === 1;
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
            $line = trim($line);
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
     * @param list<array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, methods: list<array{signature: string, summary: string}>}> $types
     */
    private static function render(string $slug, array $types): string
    {
        $out = [];
        $out[] = '<!-- GENERATED by tools/gen-api-reference.php from ' . $slug . '/src. Do not edit by hand. '
            . 'Regenerate with: php tools/gen-api-reference.php ' . $slug . ' -->';

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
     * @param array{fqn: string, short: string, namespace: string, kind: string, size: int, summary: string, cases: list<string>, methods: list<array{signature: string, summary: string}>} $type
     */
    private static function renderType(array $type): string
    {
        $id = 'api-' . strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $type['fqn']));
        $h = '<h4 id="' . $id . '"><code>' . htmlspecialchars($type['short'], ENT_QUOTES, 'UTF-8')
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
                $result = ApiReference::coverage(ApiReference::collect($root, $slug), $fragment);
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
