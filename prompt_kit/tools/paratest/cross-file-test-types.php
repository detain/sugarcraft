<?php

declare(strict_types=1);

/*
 * ParaTest pre-flight: find types declared as an EXTRA top-level type inside a
 * test file (not the type the file is named after) that another file in the same
 * lib's tests/ uses. Serial phpunit loads every test file up front, so such a use
 * resolves by luck; a ParaTest worker loads one file per dispatch, so it is
 * "Class not found" whenever the host file has not run in that worker yet
 * (sugar-async FakeClock, 2026-11-21).
 *
 * usage: php cross-file-test-types.php <repo-root> [lib ...]
 */

[$self, $root] = array_pad($argv, 2, null);
$root = rtrim((string) realpath((string) $root), '/');
$libs = array_slice($argv, 2) ?: array_map('basename', glob("$root/{sugar,candy}-*", GLOB_ONLYDIR | GLOB_BRACE));
$total = 0;
foreach ($libs as $lib) {
    $dir = "$root/$lib/tests";
    if (!is_dir($dir)) {
        continue;
    }
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
    $extra = []; // FQN => host file
    $nsOf = static function (string $src): string {
        return preg_match('/^namespace\s+([^;{\s]+)/m', $src, $m) ? $m[1] : '';
    };
    foreach ($files as $file) {
        $tokens = token_get_all(file_get_contents($file));
        $ns = $nsOf(file_get_contents($file));
        $depth = 0;
        foreach ($tokens as $i => $t) {
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            } elseif ($depth === 0 && is_array($t) && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $prev = $tokens[$i - 1] ?? null;
                for ($j = $i - 1; $j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j--);
                if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_DOUBLE_COLON) {
                    continue; // Foo::class
                }
                for ($k = $i + 1; isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE; $k++);
                if (!is_array($tokens[$k] ?? null) || $tokens[$k][0] !== T_STRING) {
                    continue; // new class
                }
                $name = $tokens[$k][1];
                if ($name !== basename($file, '.php')) {
                    $extra[ltrim($ns . '\\' . $name, '\\')] = $file;
                }
            }
        }
    }
    foreach ($extra as $fqn => $host) {
        $pos = strrpos($fqn, '\\');
        $name = $pos === false ? $fqn : substr($fqn, $pos + 1);
        $hostNs = $pos === false ? '' : substr($fqn, 0, $pos);
        $users = [];
        foreach ($files as $file) {
            if ($file === $host) {
                continue;
            }
            $src = '';
            foreach (token_get_all(file_get_contents($file)) as $tok) {
                if (!is_array($tok) || !in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $src .= is_array($tok) ? $tok[1] : $tok; // comments do not load classes
                }
            }
            if (!preg_match('/\b' . preg_quote($name, '/') . '\b/', $src)) {
                continue;
            }
            // same namespace + short name, or the FQN / a `use` of it, and the user does not declare its own type of that name
            $sameNs = $nsOf($src) === $hostNs;
            $byFqn = str_contains($src, $fqn);
            $declaresOwn = preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+' . preg_quote($name, '/') . '\b/m', $src);
            $importsOther = preg_match('/^use\s+([^;\s]+\\\\' . preg_quote($name, '/') . ')\s*;/m', $src, $um) && ltrim($um[1], '\\') !== $fqn;
            if (($sameNs || $byFqn) && !$declaresOwn && !$importsOther) {
                $users[] = substr($file, strlen("$root/"));
            }
        }
        if ($users !== []) {
            $total++;
            printf("%s: %s (declared in %s) used by %d other file(s): %s\n", $lib, $name, substr($host, strlen("$root/")), count($users), implode(', ', array_slice($users, 0, 3)) . (count($users) > 3 ? ', …' : ''));
        }
    }
}
fprintf(STDERR, "%d cross-file extra test types\n", $total);
exit($total > 0 ? 1 : 0);
