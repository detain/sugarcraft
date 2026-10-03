<?php

declare(strict_types=1);

namespace SugarCraft\Crush\RepoMap;

/**
 * Definitions and references in one PHP file, read with PHP's own tokenizer
 * (`\PhpToken::tokenize()`). This is the first producer for the symbol-level
 * repo map (roadmap 5.5-1).
 *
 * It mirrors Aider's `RepoMap.get_tags_raw()`, which runs a tree-sitter tags
 * query per language. PHP needs no grammar binary because the engine ships a
 * tokenizer, so this map works with nothing installed. The universal-ctags
 * producer (5.5-2) covers other languages and is optional.
 *
 * WHAT COUNTS AS A DEFINITION
 *  - Named class-likes: class, interface, trait, enum. Anonymous `new class`
 *    and `Foo::class` are not definitions.
 *  - Functions. Inside a class-like body, they are methods. Closures and
 *    `fn` arrows are not definitions.
 *  - Class constants, enum cases, and properties declared in a class body.
 *    That includes constructor-promoted properties, which are the only
 *    declaration many readonly value classes have.
 *
 * WHAT COUNTS AS A REFERENCE. Every other identifier, as its last
 * namespace segment (`Foo\Bar\baz()` refers to `baz`), with these
 * exceptions:
 *  - reserved and built-in type words (`self`, `int`, `true` ...);
 *  - the names in `namespace` and `use` import statements, because an import
 *    restates a reference the body makes anyway, and counting both would
 *    double-weight every imported symbol;
 *  - named-argument labels (`f(name: 1)`);
 *  - `declare(...)` directives.
 *
 * Variables are never references, because they are local. Property fetches
 * (`$x->name`) are references, because the rank should link a file that
 * reads a property to the file that declares it.
 *
 * Lexical, not semantic. A reference is a bare name, as in Aider, because
 * resolving it would need the type inference this deliberately avoids.
 * PageRank only needs to know which files mention which names. A file the
 * tokenizer cannot fully parse (a syntax error mid-edit) still yields the
 * tags it can see, since tokenizing never throws on bad syntax.
 */
final class PhpSymbolExtractor
{
    /**
     * Files above this are skipped (generated fixtures, minified bundles).
     * They are not code anyone navigates by symbol, and tokenizing them
     * costs more than the whole rest of a typical project.
     */
    public const MAX_FILE_BYTES = 1_048_576;

    /** Lower-cased identifiers that are never a reference. */
    private const NOT_REFERENCES = [
        'self', 'static', 'parent', 'true', 'false', 'null',
        'int', 'float', 'bool', 'string', 'void', 'never', 'mixed', 'object',
        'iterable', 'resource', 'numeric', 'scalar', 'list',
    ];

    /** Modifiers that may precede a property or constant in a class body. */
    private const MEMBER_MODIFIERS = [
        \T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_STATIC, \T_READONLY, \T_VAR, \T_FINAL, \T_ABSTRACT,
    ];

    private function __construct() {}

    public static function new(): self
    {
        return new self();
    }

    /**
     * Tags for one file on disk, or [] for an unreadable or oversized file.
     *
     * @return list<Tag>
     */
    public function extractFile(string $absPath, string $relPath): array
    {
        $size = @\filesize($absPath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return [];
        }
        $source = @\file_get_contents($absPath);

        return $source === false ? [] : $this->extract($source, $relPath);
    }

    /**
     * Tags for PHP source text, in token order.
     *
     * @return list<Tag>
     */
    public function extract(string $source, string $relPath): array
    {
        $tokens = \array_values(\array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
        ));
        $count = \count($tokens);

        $tags = [];
        $depth = 0;
        // Each open class-like: [name ('' when anonymous), body depth, type].
        $classes = [];
        // Depth of the innermost function body; members exist only above it.
        $functionDepths = [];
        // A class-like or function header is waiting for its `{` to open.
        $pendingClass = null;
        $pendingFunction = false;
        // Inside a constructor's parameter list, a modifier marks a promoted property.
        $promoting = false;
        $parenDepth = 0;
        $promotionParenDepth = -1;

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];

            if ($t->text === '(') {
                $parenDepth++;
                continue;
            }
            if ($t->text === ')') {
                if ($parenDepth === $promotionParenDepth) {
                    $promoting = false;
                    $promotionParenDepth = -1;
                }
                $parenDepth--;
                continue;
            }
            if ($t->text === '{' || $t->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pendingClass !== null) {
                    $classes[] = [$pendingClass[0], $depth, $pendingClass[1]];
                    $pendingClass = null;
                } elseif ($pendingFunction) {
                    $functionDepths[] = $depth;
                    $pendingFunction = false;
                }
                continue;
            }
            if ($t->text === '}') {
                if ($classes !== [] && $classes[\count($classes) - 1][1] === $depth) {
                    \array_pop($classes);
                }
                if ($functionDepths !== [] && $functionDepths[\count($functionDepths) - 1] === $depth) {
                    \array_pop($functionDepths);
                }
                $depth--;
                continue;
            }
            if ($t->text === ';' && $pendingFunction) {
                // An abstract or interface method has no body.
                $pendingFunction = false;
                continue;
            }

            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $inClassBody = $classes !== [] && $classes[\count($classes) - 1][1] === $depth
                && ($functionDepths === [] || \end($functionDepths) < $classes[\count($classes) - 1][1]);
            $scope = $classes === [] ? '' : $classes[\count($classes) - 1][0];

            switch (true) {
                case $t->is([\T_NAMESPACE, \T_USE]) && !$inClassBody && $functionDepths === [] && $prev?->text !== ')':
                    // A namespace or import statement. Skip to its end. A `use`
                    // inside a class body is a trait use (references), and a
                    // `use (...)` after a closure's `)` is a variable list, so
                    // both fall through to the default handling instead.
                    $i = $this->skipStatement($tokens, $i);
                    break;

                case $t->is(\T_DECLARE):
                    $i = $this->skipParenthesised($tokens, $i + 1);
                    break;

                case $t->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]):
                    if ($prev !== null && $prev->is(\T_DOUBLE_COLON)) {
                        break; // Foo::class
                    }
                    $type = match ($t->id) {
                        \T_INTERFACE => Tag::TYPE_INTERFACE,
                        \T_TRAIT => Tag::TYPE_TRAIT,
                        \T_ENUM => Tag::TYPE_ENUM,
                        default => Tag::TYPE_CLASS,
                    };
                    if ($next !== null && $next->is(\T_STRING) && !($prev !== null && $prev->is(\T_NEW))) {
                        $tags[] = Tag::definition($relPath, $next->line, $next->text, $type, $scope);
                        $pendingClass = [$next->text, $type];
                        $i++;
                    } else {
                        $pendingClass = ['', $type]; // new class(...) { ... }
                    }
                    break;

                case $t->is(\T_FUNCTION):
                    $j = $i + 1;
                    if (isset($tokens[$j]) && $tokens[$j]->text === '&') {
                        $j++;
                    }
                    $name = $tokens[$j] ?? null;
                    if ($name !== null && $this->isIdentifier($name) && ($tokens[$j + 1] ?? null)?->text === '(') {
                        $isMethod = $inClassBody;
                        $tags[] = Tag::definition($relPath, $name->line, $name->text, $isMethod ? Tag::TYPE_METHOD : Tag::TYPE_FUNCTION, $isMethod ? $scope : '');
                        if ($isMethod && \strtolower($name->text) === '__construct') {
                            $promoting = true;
                            $promotionParenDepth = $parenDepth + 1;
                        }
                        $i = $j;
                    }
                    // Named or closure: either way a body may follow.
                    $pendingFunction = true;
                    break;

                case $t->is(\T_FN):
                    // An arrow function has no braces; it is neither a definition nor a body.
                    break;

                case $t->is(\T_CONST) && ($inClassBody || ($classes === [] && $functionDepths === [])):
                    $i = $this->constantNames($tokens, $i, $relPath, $scope, $tags);
                    break;

                case $t->is(\T_CASE) && $inClassBody && ($classes[\count($classes) - 1][2] ?? '') === Tag::TYPE_ENUM:
                    if ($next !== null && $this->isIdentifier($next)) {
                        $tags[] = Tag::definition($relPath, $next->line, $next->text, Tag::TYPE_ENUM_CASE, $scope);
                        $i++;
                    }
                    break;

                case $t->is(\T_VARIABLE):
                    $isDeclaration = ($inClassBody && $parenDepth === 0 && $prev !== null && $this->endsMemberPrefix($tokens, $i))
                        || ($promoting && $parenDepth === $promotionParenDepth && $this->hasModifierBefore($tokens, $i));
                    if ($isDeclaration) {
                        $tags[] = Tag::definition($relPath, $t->line, \substr($t->text, 1), Tag::TYPE_PROPERTY, $scope);
                    }
                    break;

                case $this->isName($t):
                    $name = $this->lastSegment($t->text);
                    if ($name === '' || \in_array(\strtolower($name), self::NOT_REFERENCES, true)) {
                        break;
                    }
                    if ($next !== null && $next->text === ':' && $prev !== null && ($prev->text === '(' || $prev->text === ',')) {
                        break; // named argument label
                    }
                    if ($next !== null && $next->text === ':' && $prev !== null && ($prev->text === ';' || $prev->text === '{' || $prev->text === '}')) {
                        break; // goto label
                    }
                    $tags[] = Tag::reference($relPath, $t->line, $name);
                    break;
            }
        }

        return $tags;
    }

    /**
     * Collect the names in a class constant statement (`const A = 1, B = 2;`,
     * with an optional PHP 8.3 type). Returns the index of the closing `;`.
     *
     * @param list<\PhpToken> $tokens
     * @param list<Tag>       $tags
     */
    private function constantNames(array $tokens, int $i, string $relPath, string $scope, array &$tags): int
    {
        $count = \count($tokens);
        $nesting = 0;
        $expectName = true;
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t->text === ';' && $nesting === 0) {
                return $j;
            }
            if (\in_array($t->text, ['(', '['], true)) {
                $nesting++;
            } elseif (\in_array($t->text, [')', ']'], true)) {
                $nesting--;
            } elseif ($t->text === ',' && $nesting === 0) {
                $expectName = true;
            } elseif ($expectName && $this->isIdentifier($t) && ($tokens[$j + 1] ?? null)?->text === '=') {
                // The token before `=` is the name. A typed constant's type
                // comes first and is not followed by `=`.
                $tags[] = Tag::definition($relPath, $t->line, $t->text, Tag::TYPE_CONSTANT, $scope);
                $expectName = false;
            } elseif (!$expectName && $this->isName($t)) {
                $name = $this->lastSegment($t->text);
                if (!\in_array(\strtolower($name), self::NOT_REFERENCES, true)) {
                    $tags[] = Tag::reference($relPath, $t->line, $name);
                }
            }
        }

        return $count - 1;
    }

    /**
     * Whether the variable at $i is a property declaration in a class body:
     * walking back, only modifiers, a type, `?`, `|`, `&` or `(`/`)` from DNF
     * types are allowed before a statement boundary.
     *
     * @param list<\PhpToken> $tokens
     */
    private function endsMemberPrefix(array $tokens, int $i): bool
    {
        $sawModifier = false;
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if ($t->text === ';' || $t->text === '{' || $t->text === '}') {
                return $sawModifier;
            }
            if ($t->is(self::MEMBER_MODIFIERS)) {
                $sawModifier = true;
                continue;
            }
            if ($t->text === ']' || $t->is(\T_ATTRIBUTE)) {
                // An attribute group ends the prefix; whatever precedes it is the boundary.
                return $sawModifier;
            }
            if ($this->isName($t) || $t->is(\T_ARRAY) || $t->is(\T_CALLABLE) || \in_array($t->text, ['?', '|', '&', '(', ')'], true)) {
                continue;
            }

            return false;
        }

        return $sawModifier;
    }

    /**
     * Whether a constructor parameter at $i carries a visibility or readonly
     * modifier, which is what promotes it to a property.
     *
     * @param list<\PhpToken> $tokens
     */
    private function hasModifierBefore(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if ($t->text === ',' || $t->text === '(') {
                return false;
            }
            if ($t->is([\T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_READONLY])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index of the `;` (or the `{` of a group/bracketed body) that ends the
     * statement starting at $i.
     *
     * @param list<\PhpToken> $tokens
     */
    private function skipStatement(array $tokens, int $i): int
    {
        $count = \count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j]->text === ';') {
                return $j;
            }
            if ($tokens[$j]->text === '{') {
                if ($tokens[$i]->is(\T_NAMESPACE)) {
                    // `namespace Foo { ... }`: the body is ordinary code, so
                    // step back one token and let the main loop count the brace.
                    return $j - 1;
                }
                // `use Foo\{A, B};` group import: skip to its `}` then the `;`.
                for ($k = $j + 1; $k < $count; $k++) {
                    if ($tokens[$k]->text === '}') {
                        return isset($tokens[$k + 1]) && $tokens[$k + 1]->text === ';' ? $k + 1 : $k;
                    }
                }

                return $count - 1;
            }
        }

        return $count - 1;
    }

    /**
     * Index of the `)` that closes the parenthesis opening at $i (or $i itself
     * when there is none).
     *
     * @param list<\PhpToken> $tokens
     */
    private function skipParenthesised(array $tokens, int $i): int
    {
        if (($tokens[$i] ?? null)?->text !== '(') {
            return $i - 1;
        }
        $nesting = 0;
        $count = \count($tokens);
        for ($j = $i; $j < $count; $j++) {
            if ($tokens[$j]->text === '(') {
                $nesting++;
            } elseif ($tokens[$j]->text === ')' && --$nesting === 0) {
                return $j;
            }
        }

        return $count - 1;
    }

    private function isIdentifier(\PhpToken $t): bool
    {
        // PHP 8 lexes most keywords as their own tokens, but a method or
        // constant may still be NAMED with one (`function list()`, `const NEW`),
        // and those come back as T_STRING anyway.
        return $t->is(\T_STRING);
    }

    /** An identifier or a namespaced name (`Foo\\Bar`, `\\Foo`, `namespace\\Foo`). */
    private function isName(\PhpToken $t): bool
    {
        return $t->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE]);
    }

    private function lastSegment(string $name): string
    {
        $at = \strrpos($name, '\\');

        return $at === false ? $name : \substr($name, $at + 1);
    }
}
