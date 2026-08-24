<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

/**
 * Finds every call to a function that CONSUMES A FILE DESCRIPTOR and reports
 * how its descriptor argument was spelled.
 *
 * ## Why the search is on the sink and not on the cast
 *
 * The first census of this defect family walked `T_INT_CAST` tokens and then
 * kept the hits "whose operand is a stream". That second step was written
 * against the operand shapes already in hand — `$this->stream` and `$stream` —
 * so an operand that was an ARRAY ELEMENT (`$tty[0]`) or a BARE CONSTANT
 * (`STDIN`, `STDOUT`) could not be expressed in its vocabulary and was dropped
 * in silence. It reported three sites; there were six. The classifier's
 * alphabet was a transcript of the cases it already knew, which is the failure
 * mode a census is least able to notice about itself.
 *
 * So this scanner inverts the search. It enumerates the SINKS — the calls that
 * take an `int` descriptor — and prints the first argument of each with a
 * classification. Operand shape is not what it searches on, so no operand
 * shape can hide from it.
 *
 * ## And anything it cannot name is reported, never skipped
 *
 * A shape this classifier has no word for comes back as
 * {@see self::UNCLASSIFIED} carrying the raw source text, and the census
 * treats that as a failure. A guard that quietly ignores what it cannot parse
 * has a hole shaped exactly like the next defect — which is, precisely, how
 * the first census came to report half the population as all of it.
 *
 * `intval()` is called out as a separate class rather than folded into
 * "variable", because it is a further blind spot of the original walk: it
 * produces the same wrong number as `(int)` and is not a `T_INT_CAST` token.
 */
final class DescriptorSinkScanner
{
    /**
     * Plain functions whose FIRST argument is a file descriptor.
     *
     * Discovered by grep over the tree rather than assumed. `posix_isatty()`
     * and `posix_ttyname()` also accept a stream RESOURCE, which is why
     * {@see self::STREAM_CONSTANT} and {@see self::VARIABLE} are legitimate
     * answers for them and not for the others.
     */
    public const FUNCTION_SINKS = ['posix_isatty', 'posix_ttyname', 'fcntl'];

    /**
     * `Class::method` sinks whose first argument is a file descriptor. These
     * are typed `int`, so a stream resource is not a legal answer for them.
     */
    public const STATIC_SINKS = ['TermiosFactory::open', 'SizeIoctl::query'];

    /** A literal integer — a genuine descriptor number. */
    public const LITERAL_INT = 'LITERAL_INT';

    /** `(int) <expr>` — on a stream this is the resource id, not a descriptor. */
    public const INT_CAST = 'INT_CAST';

    /** `intval(<expr>)` — identical hazard to INT_CAST, and not a T_INT_CAST token. */
    public const INTVAL = 'INTVAL';

    /** A bare `STDIN` / `STDOUT` / `STDERR` — the stream, not its descriptor. */
    public const STREAM_CONSTANT = 'STREAM_CONSTANT';

    /** Some other bare constant. */
    public const CONSTANT = 'CONSTANT';

    /** `$x`, `$this->x`, `$x[…]`, `$x->y()`, `Foo::bar()` — needs a judgement. */
    public const VARIABLE = 'VARIABLE';

    /**
     * A plain `$x` whose most recent assignment before the call was an
     * `(int)` cast or an `intval()`.
     *
     * This class exists because three of the six sites in the original
     * defect family were spelled this way — the cast sat one or two lines
     * above the sink, so a census that looked only at the argument saw an
     * innocent variable. Hiding a cast behind a local is not a different
     * defect, it is the same defect with a line break in it.
     */
    public const INT_CAST_VIA_VARIABLE = 'INT_CAST_VIA_VARIABLE';

    /** The classifier has no word for this shape. The census fails on it. */
    public const UNCLASSIFIED = 'UNCLASSIFIED';

    /**
     * Every token a callable's name can be, PHP 8. `T_STRING` alone is the
     * unqualified spelling only.
     */
    private const NAME_TOKENS = [
        \T_STRING,
        \T_NAME_QUALIFIED,
        \T_NAME_FULLY_QUALIFIED,
        \T_NAME_RELATIVE,
    ];

    private function __construct()
    {
    }

    /**
     * Scan one PHP source string.
     *
     * @return list<array{sink:string, kind:string, argument:string, line:int}>
     */
    public static function scanSource(string $source): array
    {
        $tokens = token_get_all($source);
        $count  = \count($tokens);
        $found  = [];

        for ($i = 0; $i < $count; $i++) {
            $sink = self::sinkAt($tokens, $i, $open);
            if ($sink === null) {
                continue;
            }

            $argument = self::firstArgumentTokens($tokens, $open);
            if ($argument === null) {
                // A sink whose call we could not even bracket. Reported, not
                // skipped -- see the class doc-block.
                $found[] = [
                    'sink'     => $sink,
                    'kind'     => self::UNCLASSIFIED,
                    'argument' => '<could not bracket the argument list>',
                    'line'     => self::lineAt($tokens, $i),
                ];
                continue;
            }

            $kind = self::classify($argument);
            if ($kind === self::VARIABLE && \count($argument) === 1) {
                /** @var array{0:int,1:string,2:int} $only */
                $only = $argument[0];
                $assigned = self::lastAssignmentTo($tokens, $i, $only[1]);
                if ($assigned !== null
                    && \in_array(self::classify($assigned), [self::INT_CAST, self::INTVAL], true)
                ) {
                    $kind = self::INT_CAST_VIA_VARIABLE;
                }
            }

            $found[] = [
                'sink'     => $sink,
                'kind'     => $kind,
                'argument' => self::render($argument),
                'line'     => self::lineAt($tokens, $i),
            ];
        }

        return $found;
    }

    /**
     * Scan every `.php` file under $dir.
     *
     * @return list<array{sink:string, kind:string, argument:string, file:string, line:int}>
     */
    public static function scanTree(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        $walk  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var list<string> $paths */
        $paths = [];
        foreach ($walk as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $paths[] = $entry->getPathname();
            }
        }
        // Sorted so the census's output is a function of the tree and not of
        // the filesystem's iteration order.
        sort($paths);

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            foreach (self::scanSource($source) as $hit) {
                $found[] = $hit + ['file' => $path];
            }
        }

        return $found;
    }

    /**
     * The sink name at $i, or null. Sets $open to the index of its `(`.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function sinkAt(array $tokens, int $i, ?int &$open): ?string
    {
        $open = null;
        $tok  = $tokens[$i];
        if (!\is_array($tok) || !\in_array($tok[0], self::NAME_TOKENS, true)) {
            return null;
        }

        // `\posix_isatty(...)` and `\SugarCraft\Pty\SizeIoctl::query(...)`
        // are ONE token each in PHP 8 (T_NAME_FULLY_QUALIFIED), not a
        // T_NS_SEPARATOR followed by a T_STRING. Matching only T_STRING would
        // therefore have missed the leading-backslash spelling entirely --
        // which is how candy-pty's own `\posix_isatty($fd)` guard is written,
        // i.e. the single most important call in this family. The alphabet of
        // a census is its coverage; this is that lesson applied to itself.
        $shortName = self::shortName($tok[1]);

        // Class::method(...)
        $doubleColon = self::nextSignificant($tokens, $i + 1);
        if ($doubleColon !== null
            && \is_array($tokens[$doubleColon])
            && $tokens[$doubleColon][0] === \T_DOUBLE_COLON
        ) {
            $method = self::nextSignificant($tokens, $doubleColon + 1);
            if ($method !== null && \is_array($tokens[$method]) && $tokens[$method][0] === \T_STRING) {
                $name = $shortName . '::' . $tokens[$method][1];
                if (\in_array($name, self::STATIC_SINKS, true)) {
                    $paren = self::nextSignificant($tokens, $method + 1);
                    if ($paren !== null && $tokens[$paren] === '(') {
                        $open = $paren;

                        return $name;
                    }
                }
            }

            return null;
        }

        if (!\in_array($shortName, self::FUNCTION_SINKS, true)) {
            return null;
        }

        // Not a method call, a declaration, or a `new`.
        // T_NULLSAFE_OBJECT_OPERATOR is here for the same reason as
        // T_OBJECT_OPERATOR: `$libc?->posix_isatty($s)` means exactly what
        // `$libc->posix_isatty($s)` means, and only the second was excluded.
        // MEASURED, PHP 8.3.6: before this line the nullsafe spelling was
        // reported as a plain FUNCTION sink classified VARIABLE, i.e. an
        // unjudged roster row for a call to somebody else's method. It failed
        // safe -- a spurious row reds the census rather than hiding a site --
        // but the two spellings must answer the same, so it is pinned below.
        $before = self::previousSignificant($tokens, $i - 1);
        if ($before !== null && \is_array($tokens[$before])
            && \in_array($tokens[$before][0], [
                \T_OBJECT_OPERATOR,
                \T_NULLSAFE_OBJECT_OPERATOR,
                \T_DOUBLE_COLON,
                \T_FUNCTION,
                \T_NEW,
            ], true)
        ) {
            return null;
        }

        $paren = self::nextSignificant($tokens, $i + 1);
        if ($paren === null || $tokens[$paren] !== '(') {
            return null;
        }
        $open = $paren;

        return $shortName;
    }

    /** The last segment of a possibly-qualified name. */
    private static function shortName(string $name): string
    {
        $at = strrpos($name, '\\');

        return $at === false ? $name : substr($name, $at + 1);
    }

    /**
     * The tokens of the first argument, significant only, or null if the call
     * could not be bracketed.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{0:int,1:string,2:int}|string>|null
     */
    private static function firstArgumentTokens(array $tokens, int $open): ?array
    {
        $depth = 0;
        $out   = [];
        $count = \count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $tok = $tokens[$i];
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                $depth--;
                if ($depth === 0) {
                    return $out;
                }
            } elseif ($tok === ',' && $depth === 1) {
                return $out;
            }

            if (self::isSignificant($tok)) {
                $out[] = $tok;
            }
        }

        return null;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $argument
     */
    private static function classify(array $argument): string
    {
        if ($argument === []) {
            return self::UNCLASSIFIED;
        }

        $first = $argument[0];

        if (\is_array($first) && $first[0] === \T_INT_CAST) {
            return self::INT_CAST;
        }

        if (\is_array($first) && \in_array($first[0], self::NAME_TOKENS, true)
            && strtolower(self::shortName($first[1])) === 'intval'
            && isset($argument[1]) && $argument[1] === '('
        ) {
            return self::INTVAL;
        }

        if (\count($argument) === 1) {
            if (\is_array($first) && $first[0] === \T_LNUMBER) {
                return self::LITERAL_INT;
            }
            if (\is_array($first) && $first[0] === \T_VARIABLE) {
                return self::VARIABLE;
            }
            if (\is_array($first) && \in_array($first[0], self::NAME_TOKENS, true)) {
                return \in_array(self::shortName($first[1]), ['STDIN', 'STDOUT', 'STDERR'], true)
                    ? self::STREAM_CONSTANT
                    : self::CONSTANT;
            }

            return self::UNCLASSIFIED;
        }

        // A leading `-` on a literal, e.g. a sentinel -1.
        if ($first === '-' && \count($argument) === 2
            && \is_array($argument[1]) && $argument[1][0] === \T_LNUMBER
        ) {
            return self::LITERAL_INT;
        }

        // $x->y, $x->y(), $x[…], $x::y, Foo::bar(), Foo::CONST — an ACCESSOR
        // CHAIN rooted in a variable or a name is a value this census cannot
        // judge on syntax alone, so it goes to the roster for a judgement.
        //
        // The chain is checked token by token rather than by its root alone.
        // Rooting the test in the first token would swallow `$a ? 1 : 2` and
        // `$a + 1` as "a variable", and an operator this classifier has no
        // word for is exactly the thing it must not quietly absorb — see the
        // class doc-block.
        if (\is_array($first) && \in_array($first[0], [\T_VARIABLE, ...self::NAME_TOKENS], true)) {
            foreach ($argument as $tok) {
                if (!self::isAccessorToken($tok)) {
                    return self::UNCLASSIFIED;
                }
            }

            return self::VARIABLE;
        }

        return self::UNCLASSIFIED;
    }

    /**
     * The right-hand side of the most recent assignment to $name before
     * $before, or null.
     *
     * Deliberately a plain backwards walk over the whole file rather than a
     * scope-aware one: a false POSITIVE here costs a roster row and a
     * judgement, which is cheap, while a false negative is a silently
     * unexamined cast, which is the thing this class exists to stop
     * happening twice.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{0:int,1:string,2:int}|string>|null
     */
    private static function lastAssignmentTo(array $tokens, int $before, string $name): ?array
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            $tok = $tokens[$i];
            if (!\is_array($tok) || $tok[0] !== \T_VARIABLE || $tok[1] !== $name) {
                continue;
            }
            $eq = self::nextSignificant($tokens, $i + 1);
            if ($eq === null || $tokens[$eq] !== '=') {
                continue;
            }

            $rhs   = [];
            $depth = 0;
            $count = \count($tokens);
            for ($j = $eq + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                } elseif ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                } elseif ($t === ';' && $depth === 0) {
                    break;
                }
                if (self::isSignificant($t)) {
                    $rhs[] = $t;
                }
            }

            return $rhs;
        }

        return null;
    }

    /**
     * True for the tokens an accessor chain is allowed to be made of.
     *
     * @param array{0:int,1:string,2:int}|string $token
     */
    private static function isAccessorToken($token): bool
    {
        if (\is_string($token)) {
            return \in_array($token, ['(', ')', '[', ']'], true);
        }

        return \in_array($token[0], [
            \T_VARIABLE,
            \T_STRING,
            \T_NAME_QUALIFIED,
            \T_NAME_FULLY_QUALIFIED,
            \T_NAME_RELATIVE,
            \T_LNUMBER,
            \T_CONSTANT_ENCAPSED_STRING,
            \T_OBJECT_OPERATOR,
            \T_NULLSAFE_OBJECT_OPERATOR,
            \T_DOUBLE_COLON,
            \T_NS_SEPARATOR,
        ], true);
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $argument
     */
    private static function render(array $argument): string
    {
        $out = '';
        foreach ($argument as $tok) {
            $out .= \is_array($tok) ? $tok[1] : $tok;
        }

        return trim($out);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function lineAt(array $tokens, int $i): int
    {
        $tok = $tokens[$i];

        return \is_array($tok) ? $tok[2] : 0;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = \count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (self::isSignificant($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function previousSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if (self::isSignificant($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function isSignificant($token): bool
    {
        if (!\is_array($token)) {
            return true;
        }

        return !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true);
    }
}
