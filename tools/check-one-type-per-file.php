<?php

declare(strict_types=1);

/**
 * Every file under a lib's PSR-4 roots declares exactly the one type its path
 * names — nothing else at the top level.
 *
 * WHY. Composer's PSR-4 autoloader maps a class name to ONE file path. A second
 * type declared inside another class's file therefore resolves only if that
 * host file happens to be loaded first; asked for first (a direct
 * construction, an instanceof target, `class_exists()`), it is "class not
 * found". That is not theoretical in this tree: `sugar-crush`'s
 * `App\LayoutResetMsg`, declared inside `App.php`, died that way once a
 * regenerated shard plan put the unloaded case first, and `sugar-dash` carried
 * a `SortDirection` enum both in its own file and again inside `Sort.php` — a
 * hard "Cannot declare enum" fatal as soon as both loaded. The fix was to give
 * every type its own file in all 13 affected libs; this gate keeps it that way.
 *
 * WHAT COUNTS. Top-level `class` / `interface` / `trait` / `enum` declarations,
 * read with `token_get_all()` (never `class_exists()`, which is the thing that
 * fails). `Foo::class` and `new class` are not declarations. A file that
 * declares nothing (a functions file, a bootstrap) is not judged. Only PSR-4
 * roots are read: `classmap` and `files` autoloading have no one-type rule.
 *
 * Usage: php tools/check-one-type-per-file.php [--root=<monorepo root>]
 * Exit 0 clean, 1 on any violation (each printed as `lib/path.php declares X`),
 * 2 on an unreadable manifest.
 */
final class CheckOneTypePerFile
{
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
                fwrite(STDERR, "check-one-type-per-file: cannot read $lib/composer.json\n");
                exit(2);
            }

            foreach (self::psr4Roots("$root/$lib", $manifest) as $dir) {
                foreach (self::phpFiles($dir) as $file) {
                    $files++;
                    $expected = basename($file, '.php');
                    foreach (self::declaredTypes((string) file_get_contents($file)) as $name) {
                        if ($name !== $expected) {
                            $problems[] = sprintf(
                                '%s declares %s besides its PSR-4 symbol %s — move it to %s.php',
                                substr($file, strlen($root) + 1),
                                $name,
                                $expected,
                                $name,
                            );
                        }
                    }
                }
            }
        }

        return ['libs' => $libs, 'files' => $files, 'problems' => $problems];
    }

    /**
     * Names of the top-level types a PHP source declares, in order.
     *
     * @return list<string>
     */
    public static function declaredTypes(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $names = [];
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;

                continue;
            }
            if ($token === '}') {
                $depth--;

                continue;
            }
            if ($depth !== 0 || !is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }
            if ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $names[] = $tokens[$j][1];

                    break;
                }
            }
        }

        return $names;
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
     * @param array<string, mixed> $manifest
     *
     * @return list<string>
     */
    public static function psr4Roots(string $libDir, array $manifest): array
    {
        $psr4 = $manifest['autoload']['psr-4'] ?? [];
        $dirs = [];
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
                    $stack[] = $path;
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
        fwrite(STDERR, "check-one-type-per-file: --root is not a directory: $root\n");
        exit(2);
    }

    $report = CheckOneTypePerFile::derive($root);
    foreach ($report['problems'] as $problem) {
        fwrite(STDERR, "check-one-type-per-file: $problem\n");
    }
    printf(
        "check-one-type-per-file: %d libs, %d psr-4 files, %d problems\n",
        $report['libs'],
        $report['files'],
        count($report['problems']),
    );

    exit($report['problems'] === [] ? 0 : 1);
}
