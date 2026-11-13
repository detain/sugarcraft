<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Support;

/**
 * CT-S32: the auto-detect lane for `push <file> [duration] [tags]`.
 *
 * Typing project and language on every manual push is the friction that
 * keeps the file out of the habit. The two facts are already implied by
 * where the file lives and what it is called; this derives them, loudly
 * defaulting to 'unknown' rather than inventing a label the exports
 * would repeat forever. Explicit arguments always outrank detection —
 * the CLI only consults this when a slot is absent.
 */
final class Detect
{
    /**
     * Lowercased extension → language label. Names (not upstream ids) match
     * what `push php` users already type; unmapped extensions fall to
     * 'unknown' rather than guessing.
     */
    public const LANGUAGES = [
        'php' => 'php', 'js' => 'js', 'mjs' => 'js', 'cjs' => 'js', 'jsx' => 'js',
        'ts' => 'ts', 'tsx' => 'ts', 'py' => 'python', 'pyi' => 'python',
        'go' => 'go', 'rs' => 'rust', 'rb' => 'ruby',
        'c' => 'c', 'h' => 'c', 'cpp' => 'c++', 'cc' => 'c++', 'cxx' => 'c++',
        'hpp' => 'c++', 'hh' => 'c++',
        'java' => 'java', 'kt' => 'kotlin', 'kts' => 'kotlin', 'swift' => 'swift',
        'sh' => 'sh', 'bash' => 'sh', 'zsh' => 'sh', 'fish' => 'fish',
        'ps1' => 'powershell', 'pl' => 'perl', 'pm' => 'perl', 'lua' => 'lua',
        'r' => 'r', 'scala' => 'scala', 'dart' => 'dart', 'sql' => 'sql',
        'html' => 'html', 'htm' => 'html', 'css' => 'css', 'scss' => 'scss',
        'sass' => 'sass', 'less' => 'less', 'vue' => 'vue', 'svelte' => 'svelte',
        'ex' => 'elixir', 'exs' => 'elixir', 'heex' => 'elixir', 'erl' => 'erlang',
        'nim' => 'nim', 'zig' => 'zig', 'ml' => 'ocaml', 'mli' => 'ocaml',
        'hs' => 'haskell', 'tf' => 'terraform', 'md' => 'markdown',
        'json' => 'json', 'yml' => 'yaml', 'yaml' => 'yaml', 'toml' => 'toml',
        'xml' => 'xml', 'ini' => 'ini', 'txt' => 'text',
    ];

    /** The language an extension names; 'unknown' when the map is silent. */
    public static function language(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return self::LANGUAGES[$ext] ?? 'unknown';
    }

    /**
     * The repository the file belongs to: the nearest ancestor carrying a
     * `.git` (directory or file — worktrees use the file form), else the
     * first segment of the path relative to the working directory (the
     * directory itself when the file sits in it), else 'unknown'. An
     * unrelated absolute path's first segment is filesystem geography, not
     * a project name, so it never becomes a label.
     */
    public static function project(string $file, string|false|null $cwd): string
    {
        $absolute = $file;
        if ($absolute !== '' && !self::looksAbsolute($absolute)) {
            $root = is_string($cwd) && $cwd !== '' ? rtrim($cwd, '/\\') : '.';
            $absolute = $root . '/' . ltrim(str_replace('\\', '/', $absolute), '/');
        }
        $absolute = str_replace('\\', '/', $absolute);

        $dir = dirname($absolute);
        for ($hops = 0; $hops < 64; $hops++) {
            if ($dir === '' || $dir === '/' || $dir === '.') {
                break;
            }
            if (file_exists($dir . '/.git')) {
                return basename($dir);
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        if (is_string($cwd) && $cwd !== '') {
            $root = rtrim(str_replace('\\', '/', $cwd), '/');
            if ($root !== '' && str_starts_with($absolute, $root . '/')) {
                $rel = substr($absolute, strlen($root) + 1);
                $segments = array_values(array_filter(explode('/', $rel), static fn(string $s): bool => $s !== '' && $s !== '.'));
                if (count($segments) > 1) {
                    return $segments[0];
                }

                return basename($root) !== '' ? basename($root) : 'unknown';
            }
        }

        // Nothing related to the working directory, and no repository above
        // the file: the first segment of an unrelated absolute path is
        // filesystem geography ('home'), not a project name. Say unknown.
        return 'unknown';
    }

    private static function looksAbsolute(string $path): bool
    {
        return $path[0] === '/'
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
