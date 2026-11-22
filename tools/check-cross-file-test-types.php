<?php

declare(strict_types=1);

/**
 * No test file uses a type that another test file declares as an EXTRA
 * top-level type, i.e. one that is not the type the host file is named after.
 *
 * WHY. Composer's PSR-4 autoloader finds a type only in the file named after
 * it, so an extra type resolves only once its host file has been loaded.
 * Serial phpunit loads every test file before it runs any, so a second test
 * file using that type works by luck. A ParaTest worker loads one test file per
 * dispatch and fails "Class not found" whenever the host file has not run in
 * that worker yet. sugar-async's FakeClock (declared in
 * AsyncCacheBoundedTest.php, used by AsyncCacheTest.php) went red that way at
 * four workers; candy-git's FixtureGit, sugar-mouse's ZoneRoutingModel and
 * sugar-pty's FixtureMaster had the same shape. An extra type used only inside
 * its own file is fine and is not reported.
 *
 * tools/check-one-type-per-file.php covers the PSR-4 `autoload` roots, where
 * every extra type is a bug; this covers the test roots, where only the
 * cross-file ones are.
 *
 * WHAT COUNTS. Test roots are each lib's `autoload-dev` PSR-4 directories plus
 * `tests/`. Declarations are top-level `class` / `interface` / `trait` /
 * `enum`, read with `token_get_all()`. A use is an identifier token that
 * resolves, through the user file's own namespace and `use` imports
 * (aliases and group imports included), to the extra type's fully qualified
 * name. Comments, strings, member and function names are not uses, and a
 * user that imports a different type of the same short name is not a user.
 *
 * Usage: php tools/check-cross-file-test-types.php [--root=<monorepo root>]
 * Exit 0 clean, 1 on any violation (each printed with the host, the type, and
 * its users), 2 on an unreadable manifest.
 */
final class CheckCrossFileTestTypes
{
    /** Identifier tokens that are never a class reference when they follow one of these. */
    private const NOT_A_TYPE_AFTER = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NAMESPACE, T_GOTO];

    /**
     * @return array{libs: int, files: int, problems: list<string>}
     */
    public static function derive(string $root): array
    {
        $libs = 0;
        $files = 0;
        $problems = [];

        foreach (self::libDirs($root) as $lib) {
            $libs++;
            $manifest = json_decode((string) @file_get_contents("$root/$lib/composer.json"), true);
            if (!is_array($manifest)) {
                fwrite(STDERR, "check-cross-file-test-types: cannot read $lib/composer.json\n");
                exit(2);
            }

            $parsed = [];
            foreach (self::testRoots("$root/$lib", $manifest) as $dir) {
                foreach (self::phpFiles($dir) as $file) {
                    if (isset($parsed[$file])) {
                        continue;
                    }
                    $raw = @file_get_contents($file);
                    if (false === $raw) {
                        fwrite(STDERR, "check-cross-file-test-types: cannot read $file\n");
                        exit(2);
                    }
                    $parsed[$file] = self::analyse($raw);
                }
            }
            $files += count($parsed);

            // Fully qualified name => host file, for every extra type in this lib.
            $extras = [];
            foreach ($parsed as $file => $info) {
                foreach ($info['declared'] as $name) {
                    if ($name !== basename($file, '.php')) {
                        $extras[ltrim($info['namespace'] . '\\' . $name, '\\')] = $file;
                    }
                }
            }
            if ($extras === []) {
                continue;
            }

            foreach ($extras as $fqn => $host) {
                $users = [];
                foreach ($parsed as $file => $info) {
                    if ($file !== $host && isset($info['refs'][$fqn])) {
                        $users[] = substr($file, strlen($root) + 1);
                    }
                }
                if ($users === []) {
                    continue;
                }
                $short = substr($fqn, (int) strrpos('\\' . $fqn, '\\'));
                $problems[] = sprintf(
                    '%s declares %s, which %s also use%s: %s; move it to its own autoloadable file (%s.php)',
                    substr($host, strlen($root) + 1),
                    $fqn,
                    count($users) === 1 ? '1 other file' : count($users) . ' other files',
                    count($users) === 1 ? 's' : '',
                    implode(', ', $users),
                    $short,
                );
            }
        }

        return ['libs' => $libs, 'files' => $files, 'problems' => $problems];
    }

    /**
     * The namespace, the top-level declared type names, and the set of fully
     * qualified names the source refers to.
     *
     * @return array{namespace: string, declared: list<string>, refs: array<string, true>}
     */
    public static function analyse(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $count = count($tokens);
        $namespace = '';
        /** @var array<string, string> lower-cased alias => fully qualified name */
        $imports = [];
        $declared = [];
        /** @var array<int, true> token indexes that are not references */
        $skip = [];
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;

                continue;
            }
            if ($t === '}') {
                $depth--;

                continue;
            }
            if (!is_array($t)) {
                continue;
            }

            if ($t[0] === T_NAMESPACE && isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $tokens[$i + 1][1];
                $imports = [];
                $skip[$i + 1] = true;

                continue;
            }

            // Top-level imports. A `use` inside a class body is a trait, and one
            // after a closure's parameter list is followed by `(`.
            if ($t[0] === T_USE && $depth === 0 && ($tokens[$i + 1] ?? null) !== '(') {
                $j = $i + 1;
                if (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_FUNCTION, T_CONST], true)) {
                    for (; $j < $count && $tokens[$j] !== ';'; $j++) {
                        $skip[$j] = true;
                    }
                    $i = $j;

                    continue;
                }
                $prefix = '';
                for (; $j < $count && $tokens[$j] !== ';'; $j++) {
                    $tok = $tokens[$j];
                    if (!is_array($tok) || !in_array($tok[0], $names, true)) {
                        if ($tok === '}') {
                            $prefix = '';
                        }

                        continue;
                    }
                    $skip[$j] = true;
                    if (($tokens[$j + 1] ?? null) === '{' || (is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === T_NS_SEPARATOR)) {
                        $prefix = ltrim($tok[1], '\\') . '\\'; // group import: `use A\B\{C, D as E};`

                        continue;
                    }
                    $fqn = $prefix . ltrim($tok[1], '\\');
                    $alias = substr($fqn, (int) strrpos('\\' . $fqn, '\\'));
                    if (is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === T_AS && is_array($tokens[$j + 2] ?? null)) {
                        $alias = $tokens[$j + 2][1];
                        $skip[$j + 2] = true;
                        $j += 2;
                    }
                    $imports[strtolower($alias)] = $fqn;
                }
                $i = $j;

                continue;
            }

            if ($depth === 0 && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $previous = $tokens[$i - 1] ?? null;
                if (!is_array($previous) || !in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    $next = $tokens[$i + 1] ?? null;
                    if (is_array($next) && $next[0] === T_STRING) {
                        $declared[] = $next[1];
                        $skip[$i + 1] = true;
                    }
                }
            }
        }

        $refs = [];
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (isset($skip[$i]) || !is_array($t) || !in_array($t[0], $names, true)) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], self::NOT_A_TYPE_AFTER, true)) {
                continue;
            }
            // `foo(` is a function call, `new Foo(` is not.
            if ($t[0] === T_STRING && ($tokens[$i + 1] ?? null) === '(' && !(is_array($previous) && $previous[0] === T_NEW)) {
                continue;
            }
            $refs[self::resolve($t, $namespace, $imports)] = true;
        }

        return ['namespace' => $namespace, 'declared' => $declared, 'refs' => $refs];
    }

    /**
     * @param array{0: int, 1: string} $token
     * @param array<string, string>    $imports
     */
    private static function resolve(array $token, string $namespace, array $imports): string
    {
        $name = $token[1];
        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($name, '\\');
        }
        $first = strtolower(explode('\\', $name, 2)[0]);
        if (isset($imports[$first])) {
            $rest = strpos($name, '\\') === false ? '' : substr($name, (int) strpos($name, '\\'));

            return $imports[$first] . $rest;
        }

        return ltrim($namespace . '\\' . $name, '\\');
    }

    /**
     * @return list<string>
     */
    public static function libDirs(string $root): array
    {
        $libs = [];
        foreach ((array) scandir($root) as $entry) {
            if (is_string($entry) && $entry !== '' && $entry[0] !== '.' && is_file("$root/$entry/composer.json") && is_dir("$root/$entry")) {
                $libs[] = $entry;
            }
        }
        sort($libs);

        return $libs;
    }

    /**
     * A lib's `autoload-dev` PSR-4 directories plus `tests/`.
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<string>
     */
    public static function testRoots(string $libDir, array $manifest): array
    {
        $dirs = [];
        if (is_dir("$libDir/tests")) {
            $dirs[] = "$libDir/tests";
        }
        $psr4 = $manifest['autoload-dev']['psr-4'] ?? [];
        foreach (is_array($psr4) ? $psr4 : [] as $paths) {
            foreach (is_array($paths) ? $paths : [$paths] as $path) {
                if (is_string($path) && $path !== '' && is_dir(rtrim("$libDir/$path", '/'))) {
                    $dirs[] = rtrim("$libDir/$path", '/');
                }
            }
        }

        return array_values(array_unique($dirs));
    }

    /**
     * @return list<string>
     */
    public static function phpFiles(string $dir): array
    {
        $files = [];
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ((array) scandir($current) as $entry) {
                if (!is_string($entry) || $entry === '.' || $entry === '..') {
                    continue;
                }
                $path = "$current/$entry";
                if (is_dir($path)) {
                    if ($entry !== 'vendor') {
                        $stack[] = $path;
                    }
                } elseif (str_ends_with($entry, '.php')) {
                    $files[] = $path;
                }
            }
        }
        sort($files);

        return $files;
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
        fwrite(STDERR, "check-cross-file-test-types: --root is not a directory: $root\n");
        exit(2);
    }

    $report = CheckCrossFileTestTypes::derive(rtrim($root, '/'));
    foreach ($report['problems'] as $problem) {
        fwrite(STDERR, "check-cross-file-test-types: $problem\n");
    }
    printf(
        "check-cross-file-test-types: %d libs, %d test files, %d problems\n",
        $report['libs'],
        $report['files'],
        count($report['problems']),
    );

    exit($report['problems'] === [] ? 0 : 1);
}
