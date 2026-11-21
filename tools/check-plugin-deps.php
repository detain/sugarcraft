<?php

declare(strict_types=1);

/**
 * Dependency-direction guard for the plugin layer (plan_plugin.md 3.3, 6.5).
 *
 * WHY. The plugin libraries are the contract the bundles build on, so they
 * must never reach back into the host that loads them. `sugar-plugins` and
 * `sugar-serve-protocol` are the candy-code-free side of the split: if either
 * one names `sugarcraft/candy-code` in its manifest or a `SugarCraft\Code\`
 * symbol in its PHP, the host leaks into the contract and the two copies of a
 * wire rule drift silently. Every other gate in `tools-guards` reads only
 * paths and manifests, so nothing else would catch that direction.
 *
 * WHAT COUNTS. A roster lib fails when its `composer.json` lists
 * `sugarcraft/candy-code` under `require` or `require-dev` (case-insensitive),
 * or when any `*.php` under it (vendor excluded) names a `SugarCraft\Code`
 * class, namespace or function, or a fully-qualified `\Code\` one. The scan is
 * over tokens, so comments are never judged: a doc-comment that mentions the
 * host namespace is allowed by construction. String literals are judged, so a
 * `'SugarCraft\\Code\\Foo'` passed to `class_exists()` cannot hide the reference.
 *
 * THE BUNDLES. `candy-discord` and `candy-msteams` sit on the roster with no
 * allowance. Phase 1c-ii gave each its own `lang/` catalog and dropped the
 * host's `Lang` as its last use, so a bundle that requires candy-code or imports
 * `SugarCraft\Code\Lang` again reds like any other plugin lib.
 *
 * Usage: php tools/check-plugin-deps.php [--root=<monorepo root>]
 * Exit 0 clean, 1 on any violation, 2 when a roster lib or its manifest is
 * unreadable (fail-closed: a missing lib must not pass as a clean one).
 */
final class CheckPluginDeps
{
    /** Libs that must stay independent of candy-code. */
    public const ROSTER = ['sugar-plugins', 'sugar-serve-protocol', 'candy-discord', 'candy-msteams'];

    private const HOST_PACKAGE = 'sugarcraft/candy-code';

    /**
     * @param list<string> $roster
     *
     * @return array{libs: int, files: int, problems: list<string>}
     */
    public static function derive(string $root, array $roster = self::ROSTER): array
    {
        $libs = 0;
        $files = 0;
        $problems = [];

        foreach ($roster as $lib) {
            $libs++;
            $manifest = json_decode((string) @file_get_contents("$root/$lib/composer.json"), true);
            if (!is_array($manifest)) {
                fwrite(STDERR, "check-plugin-deps: cannot read $lib/composer.json\n");
                exit(2);
            }

            foreach (['require', 'require-dev'] as $section) {
                foreach (array_keys(is_array($manifest[$section] ?? null) ? $manifest[$section] : []) as $package) {
                    if (strtolower((string) $package) === self::HOST_PACKAGE) {
                        $problems[] = "$lib/composer.json lists " . self::HOST_PACKAGE . " in $section — a plugin lib must not depend on the host";
                    }
                }
            }

            foreach (self::phpFiles("$root/$lib") as $file) {
                $files++;
                $raw = @file_get_contents($file);
                if (false === $raw) {
                    fwrite(STDERR, "check-plugin-deps: cannot read $file\n");
                    exit(2);
                }
                foreach (self::hostReferences($raw) as [$line, $name]) {
                    $problems[] = sprintf(
                        '%s:%d references %s — a plugin lib must not reach into candy-code',
                        substr($file, strlen($root) + 1),
                        $line,
                        $name,
                    );
                }
            }
        }

        return ['libs' => $libs, 'files' => $files, 'problems' => $problems];
    }

    /**
     * Host-namespace references in one PHP source, as [line, written name].
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function hostReferences(string $source): array
    {
        $hits = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;

            if (in_array($id, self::nameTokens(), true)) {
                if (self::isHostName($text)) {
                    $hits[] = [$line, $text];
                }

                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $value = stripslashes(substr($text, 1, -1));
                if (preg_match('/(^|\\\\)SugarCraft\\\\Code(\\\\|$)/i', $value) === 1) {
                    $hits[] = [$line, $text];
                }
            }
        }

        return $hits;
    }

    /**
     * @return list<int>
     */
    private static function nameTokens(): array
    {
        $ids = [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        if (defined('T_NAME_RELATIVE')) {
            $ids[] = T_NAME_RELATIVE;
        }

        return $ids;
    }

    /**
     * A name is candy-code's when it is `SugarCraft\Code…` written in any form,
     * or a fully-qualified `\Code…`. A relative `Code\Foo` inside a plugin's own
     * namespace resolves to that namespace, not to the host, so it is allowed.
     */
    private static function isHostName(string $name): bool
    {
        $lower = strtolower(ltrim($name, '\\'));
        if ($lower === 'sugarcraft\\code' || str_starts_with($lower, 'sugarcraft\\code\\')) {
            return true;
        }

        return str_starts_with($name, '\\') && ($lower === 'code' || str_starts_with($lower, 'code\\'));
    }

    /**
     * Every `*.php` under a lib, vendor and hidden directories excluded.
     *
     * @return list<string>
     */
    public static function phpFiles(string $dir): array
    {
        $files = [];
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ((array) scandir($current) as $entry) {
                if (!is_string($entry) || $entry === '.' || $entry === '..' || $entry[0] === '.') {
                    continue;
                }
                $path = "$current/$entry";
                if (is_dir($path) && !is_link($path)) {
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
        fwrite(STDERR, "check-plugin-deps: --root is not a directory: $root\n");
        exit(2);
    }

    $report = CheckPluginDeps::derive($root);
    foreach ($report['problems'] as $problem) {
        fwrite(STDERR, "check-plugin-deps: $problem\n");
    }
    printf(
        "check-plugin-deps: %d libs, %d php files, %d problems\n",
        $report['libs'],
        $report['files'],
        count($report['problems']),
    );

    exit($report['problems'] === [] ? 0 : 1);
}
