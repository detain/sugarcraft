<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Loader;

/**
 * The class, interface, trait and enum names a PHP file declares, read from its
 * tokens so the file is never executed to find out.
 *
 * The loader needs this before `require_once`. A file that declares a type
 * another file already declared dies with an uncatchable compile error, which
 * takes the whole host process down with it. Checking first lets the loader
 * refuse that file as a row instead.
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

        return null;
    }

    /**
     * The fully qualified names $path declares. Anonymous classes and `Foo::class`
     * carry no name token after the keyword, so they are never listed.
     *
     * @return list<string>
     */
    public static function of(string $path): array
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

        $names = [];
        $namespace = '';
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$id] = $tokens[$i];
            $next = $tokens[$i + 1][0] ?? null;

            // `namespace\foo()` is a relative name in code, not a declaration.
            if ($id === T_NAMESPACE && $next !== T_NS_SEPARATOR) {
                $namespace = ($next === T_STRING || $next === T_NAME_QUALIFIED) ? $tokens[$i + 1][1] : '';
                continue;
            }

            if (\in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $next === T_STRING) {
                $names[] = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$i + 1][1];
            }
        }

        return $names;
    }
}
