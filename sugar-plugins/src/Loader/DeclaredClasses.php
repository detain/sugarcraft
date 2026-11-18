<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Loader;

/**
 * The class, interface, trait, enum and top-level function names a PHP file
 * declares, read from its tokens so the file is never executed to find out.
 *
 * The loader needs this before `require_once`. A file that declares a type or a
 * function another file already declared dies with an uncatchable compile error,
 * which takes the whole host process down with it. Checking first lets the loader
 * refuse that file as a row instead.
 *
 * Constants are deliberately not listed: redeclaring one is a warning at runtime,
 * not a fatal, so it cannot take the host down and needs no pre-require refusal.
 */
final class DeclaredClasses
{
    /**
     * The first name $path declares that another file (or the engine itself)
     * already declares, or null when every name is free.
     */
    public static function declaredElsewhere(string $path): ?string
    {
        foreach (self::of($path) as $name) {
            $declared = \class_exists($name, false)
                || \interface_exists($name, false)
                || \trait_exists($name, false)
                || \enum_exists($name, false);

            if (!$declared) {
                continue;
            }

            // An engine type has no file, so it never matches $path and collides too.
            if ((new \ReflectionClass($name))->getFileName() !== $path) {
                return $name;
            }
        }

        foreach (self::functionsOf($path) as $name) {
            if (!\function_exists($name)) {
                continue;
            }

            // An engine function has no file either, so it collides as well.
            if ((new \ReflectionFunction($name))->getFileName() !== $path) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The fully qualified class-like names $path declares. Anonymous classes and
     * `Foo::class` carry no name token after the keyword, so they are never listed.
     *
     * @return list<string>
     */
    public static function of(string $path): array
    {
        return self::scan($path)[0];
    }

    /**
     * The fully qualified names of the functions $path declares at top level.
     * Methods, closures and declarations inside a plain block (an `if`, a function
     * body) are left out: a guarded declaration cannot fatal, and a method is not a
     * global name.
     *
     * @return list<string>
     */
    public static function functionsOf(string $path): array
    {
        return self::scan($path)[1];
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [class-like names, top-level function names]
     */
    private static function scan(string $path): array
    {
        $source = \is_file($path) ? (string) \file_get_contents($path) : '';

        $tokens = [];
        foreach (\token_get_all($source) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                    continue;
                }
                $tokens[] = [$token[0], $token[1]];
            } else {
                $tokens[] = [$token, $token];
            }
        }

        $types = [];
        $functions = [];
        $namespace = '';
        // One entry per open brace: true when it opens a braced namespace block,
        // whose contents are still top level. Only plain braces push $plainOpen.
        $braces = [];
        $plainOpen = 0;
        $namespaceBlockPending = false;
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$id] = $tokens[$i];
            $next = $tokens[$i + 1][0] ?? null;

            // `namespace\foo()` is a relative name in code, not a declaration.
            if ($id === T_NAMESPACE && $next !== T_NS_SEPARATOR) {
                $named = $next === T_STRING || $next === T_NAME_QUALIFIED;
                $namespace = $named ? $tokens[$i + 1][1] : '';
                $after = $named ? ($tokens[$i + 2][0] ?? null) : $next;
                $namespaceBlockPending = $after === '{';
                continue;
            }

            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $block = $id === '{' && $namespaceBlockPending;
                $namespaceBlockPending = false;
                $braces[] = $block;
                if (!$block) {
                    $plainOpen++;
                }
                continue;
            }

            if ($id === '}') {
                if (\array_pop($braces) === false) {
                    $plainOpen--;
                }
                continue;
            }

            if (\in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $next === T_STRING) {
                $types[] = self::qualify($namespace, $tokens[$i + 1][1]);
            }

            if ($id === T_FUNCTION && $plainOpen === 0) {
                $j = $i + 1;
                if (\in_array($tokens[$j][0] ?? null, [T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                    $j++;
                }
                if (($tokens[$j][0] ?? null) === T_STRING) {
                    $functions[] = self::qualify($namespace, $tokens[$j][1]);
                }
            }
        }

        return [$types, $functions];
    }

    private static function qualify(string $namespace, string $name): string
    {
        return ($namespace === '' ? '' : $namespace . '\\') . $name;
    }
}
