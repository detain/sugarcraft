<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Pty\Libc;

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

    /**
     * Cache for {@see methodSinks()}. The cdef is a string built per call.
     *
     * @var list<string>|null
     */
    private static ?array $methodSinks = null;

    /**
     * Libc symbols whose FIRST parameter is a file descriptor, DERIVED from
     * candy-pty's own cdef rather than listed here.
     *
     * ## Why this is derived and the two lists above are not
     *
     * The function and static rosters are short, stable and spelled the same
     * everywhere. This one is neither: it is reached as a METHOD on an FFI
     * handle, and the receiver can be written `Libc::lib()->`, `$libc->`,
     * `self::libc()->` or anything else a class cares to name its accessor.
     *
     * Every hand-written attempt at this family has been a transcript of the
     * sites its author already had. The backlog entry commissioning this step
     * carried a `grep` alternation with `open|close|ioctl|fcntl|read|write|
     * dup|dup2` in it. MEASURED against this cdef: `read`, `write` and `dup2`
     * are not declared in it at all, while `grantpt`, `unlockpt`, `ptsname_r`,
     * `tcgetattr` and `tcsetattr` ARE fd-first and were absent from the
     * alternation. Five symbols missing and three imaginary, in the list
     * written by the entry whose whole subject is that hand-written lists of
     * this kind are incomplete.
     *
     * So the roster is read out of {@see Libc::cdef()}, which is a
     * declaration of exactly this fact and which candy-pty already keeps
     * loadable without the FFI runtime for introspection.
     *
     * ## What a new symbol in that cdef actually does here
     *
     * WHAT THIS PARAGRAPH USED TO SAY: "A symbol added there appears here on
     * its next commit, and the census then demands a judgement for every call
     * site of it." WHAT IS TRUE NOW: that was a claim about the DERIVATION,
     * and the derivation could not keep it. MEASURED through the shipped
     * parser, PHP 8.3.6: `fsync(int fildes)`, `dup3(int oldfd, int newfd)`
     * and `fchdir(int)` all appeared and none of them arrived -- the pattern
     * recognised the literal parameter name `fd` and dropped everything else
     * in silence, so the sentence was false for three realistic spellings,
     * two of which are POSIX's own. WHY IT STILL EARNS ITS PLACE: the
     * INTENTION was right and is the reason this method is derived at all,
     * and a reader who deletes the sentence deletes the reason.
     *
     * What holds today, in three parts rather than one:
     * a symbol whose first parameter is named in
     * {@see DESCRIPTOR_PARAMETER_NAMES} appears here on its next commit and
     * the census demands a judgement for every call site of it; one named in
     * {@see NON_DESCRIPTOR_PARAMETER_NAMES} is rejected; and one that is
     * neither -- or unnamed, or of an unrecognised type -- THROWS, which reds
     * the census rather than shrinking the roster behind it.
     *
     * @return list<string>
     */
    public static function methodSinks(): array
    {
        return self::$methodSinks ??= self::sinksFromCdef(Libc::cdef());
    }

    /**
     * First-parameter names that ARE a file descriptor.
     *
     * POSIX does not spell this one way. `fd` is the common form, but
     * `fildes` is what the standard's own prototypes for `fsync`,
     * `ftruncate` and `fstat` use, and the `dup`/`dup2` family uses
     * `oldfd`/`newfd`. A roster derived by matching the literal name `fd`
     * is a transcript of how the cdef in front of its author happened to be
     * spelled -- which is the failure this whole class is about, one level
     * up from the call sites.
     *
     * @var list<string>
     */
    public const DESCRIPTOR_PARAMETER_NAMES = [
        'dirfd', 'fd', 'fd1', 'fd2', 'filedes', 'fildes', 'newfd', 'oldfd', 'sockfd',
    ];

    /**
     * First-parameter names that are an `int` but NOT a descriptor.
     *
     * Taken from the vocabulary candy-pty's own cdef already uses for its
     * parameters rather than invented, so this list is a reading of the
     * declaration block and not a guess about future ones. `posix_openpt(int
     * flags)` and `waitpid(int pid, ...)` are the two live int-first
     * non-descriptors today; the rest appear in later positions of the same
     * declarations.
     *
     * A name in NEITHER list is the point of the pair: see
     * {@see sinksFromCdef()}.
     *
     * @var list<string>
     */
    public const NON_DESCRIPTOR_PARAMETER_NAMES = [
        'arg', 'buf', 'buflen', 'cmd', 'flags', 'options', 'path', 'pid',
        'request', 'speed', 'status', 'termios_p', 'when',
    ];

    /**
     * Parse a C declaration block for functions whose first parameter is a
     * file descriptor.
     *
     * Split out from {@see methodSinks()} so a control fixture can push a
     * cdef whose answer is known through the same parser -- including the
     * near-misses that must NOT come back: a path-first `open()`, a
     * `pid`-first `waitpid()`, a pointer-first `openpty()`.
     *
     * Block comments are stripped first. candy-pty's cdef carries prose
     * inside them, and a census that reads a comment as a declaration is the
     * same class of error as one that reads a doc-comment as a call.
     *
     * ## WHAT THIS USED TO DO, AND WHY THAT WAS A HOLE
     *
     * WHAT IT SAID: one regex, `name(int fd` , collecting the names it
     * matched. WHAT IS TRUE NOW: that pattern answers a question about the
     * SPELLING of a parameter and was being read as an answer about its
     * MEANING. MEASURED through the shipped parser, PHP 8.3.6, against a
     * synthetic block declaring `fsync(int fildes)`, `close(int fd)`,
     * `dup3(int oldfd, int newfd)` and `fchdir(int)`: it returned `['close']`
     * and dropped the other three in silence. Two of those are POSIX's own
     * spellings and candy-pty already declares `dup`. WHY THE OLD SHAPE STILL
     * EARNS A MENTION: it was right that the roster must be DERIVED and never
     * hand-listed -- that part is unchanged and is the reason this method
     * exists. It was the derivation's alphabet that was too narrow, which is
     * the same defect the census documents at its call sites.
     *
     * ## And a declaration it cannot classify is a FAILURE, not a skip
     *
     * The first parameter is classified against two lists --
     * {@see DESCRIPTOR_PARAMETER_NAMES} and
     * {@see NON_DESCRIPTOR_PARAMETER_NAMES} -- and an `int`-typed first
     * parameter that is in neither, or that has no name at all, throws. So
     * does a declaration whose parameter list will not parse. A guard that
     * quietly ignores what it cannot read has a hole shaped exactly like the
     * next defect; here that hole would be an entire libc sink absent from
     * the roster, and therefore every call site of it absent from the census,
     * with nothing anywhere going red.
     *
     * Resolving that throw is one word in one of the two lists. The exception
     * message says which lists and quotes the declaration.
     *
     * @throws \RuntimeException when a declaration cannot be classified
     * @return list<string>
     */
    public static function sinksFromCdef(string $cdef): array
    {
        $stripped = preg_replace('#/\*.*?\*/#s', ' ', $cdef) ?? $cdef;

        $names = [];
        foreach (explode(';', $stripped) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || !str_contains($statement, '(')) {
                continue;
            }

            if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*)\)\s*$/', $statement, $m) !== 1) {
                throw new \RuntimeException(
                    'this cdef declaration could not be parsed, so whether it takes a file '
                    . "descriptor first is UNKNOWN and the roster below it is incomplete:\n  "
                    . $statement . ';',
                );
            }

            if (self::firstParameterIsADescriptor($m[2], $statement)) {
                $names[$m[1]] = true;
            }
        }

        $out = array_keys($names);
        sort($out);

        return $out;
    }

    /**
     * Is the first parameter of a parsed parameter list a file descriptor?
     *
     * @param  string $parameters the text between the parentheses
     * @param  string $statement  the whole declaration, for the message
     * @throws \RuntimeException when the answer is not knowable
     */
    private static function firstParameterIsADescriptor(string $parameters, string $statement): bool
    {
        $first = trim(explode(',', $parameters)[0]);

        // `f(void)` and `f()` take nothing.
        if ($first === '' || $first === 'void') {
            return false;
        }

        // A pointer is not a descriptor, whatever it points at. `char *buf`,
        // `void *termios_p`, `int *status` are all confidently not one, and
        // no descriptor is ever passed by pointer in this family.
        if (str_contains($first, '*')) {
            return false;
        }

        // An int-typed scalar WITH a name: the two lists decide.
        if (preg_match('/^(?:const\s+)?(?:unsigned\s+|signed\s+)?int\s+([A-Za-z_][A-Za-z0-9_]*)$/', $first, $m) === 1) {
            if (\in_array($m[1], self::DESCRIPTOR_PARAMETER_NAMES, true)) {
                return true;
            }
            if (\in_array($m[1], self::NON_DESCRIPTOR_PARAMETER_NAMES, true)) {
                return false;
            }

            throw new \RuntimeException(
                'this cdef declaration takes an int first, named "' . $m[1] . '", and that name is '
                . "in neither descriptor list, so whether it is a file descriptor is UNKNOWN:\n  "
                . $statement . ";\n"
                . 'Resolve it by adding "' . $m[1] . '" to DescriptorSinkScanner::'
                . 'DESCRIPTOR_PARAMETER_NAMES if it names a descriptor, or to '
                . 'NON_DESCRIPTOR_PARAMETER_NAMES if it does not. Guessing is the one option that '
                . 'is not available: a descriptor missed here is a libc sink absent from the '
                . 'roster and every call site of it absent from the census.',
            );
        }

        // An int-typed scalar with NO name, e.g. `int fchdir(int);`. The type
        // is right and the meaning is unreadable, which is precisely the case
        // that must not be dropped quietly.
        if (preg_match('/^(?:const\s+)?(?:unsigned\s+|signed\s+)?int$/', $first) === 1) {
            throw new \RuntimeException(
                'this cdef declaration takes an UNNAMED int first, so whether it is a file '
                . "descriptor cannot be read from the declaration at all:\n  " . $statement . ";\n"
                . 'Resolve it by naming the parameter in candy-pty\'s cdef.',
            );
        }

        // Some other scalar type -- a typedef such as `pid_t`, or a `long`.
        // Not knowable from the name alone, and a descriptor typedef is
        // exactly the shape that would slip past a rule keyed on `int`.
        throw new \RuntimeException(
            'this cdef declaration\'s first parameter is a scalar whose type this parser does not '
            . "know, so whether it is a file descriptor is UNKNOWN:\n  " . $statement . ";\n"
            . 'Resolve it by teaching DescriptorSinkScanner::firstParameterIsADescriptor() about '
            . 'the type, in whichever polarity is correct.',
        );
    }

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
            if ($kind === self::VARIABLE) {
                // Keyed on the RENDERED spelling, not on a lone variable
                // token, so `$this->fd` and `$tty[0]` are traced back too --
                // see lastAssignmentTo() for what only speaking `$x` cost.
                $assigned = self::lastAssignmentTo($tokens, $i, self::render($argument));
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
                // Slash-normalised. The census keys these paths into roster
                // rows spelled with slashes (`candy-core/src/...`), and on
                // Windows RecursiveDirectoryIterator canonicalises every
                // separator to a backslash -- which turned every scanned site
                // into a phantom unrostered key there. On POSIX, where
                // DIRECTORY_SEPARATOR IS '/', this is the identity; and PHP's
                // file functions accept slashes natively, so the normalised
                // form stays readable via file_get_contents() below.
                $paths[] = str_replace(\DIRECTORY_SEPARATOR, '/', $entry->getPathname());
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

        // THE METHOD SPELLING, and note what is NOT matched on: the receiver.
        // `Libc::lib()->close($fd)`, `$libc->close($fd)` and
        // `self::libc()->close($fd)` are all one shape here, because a census
        // that enumerates receiver spellings is a transcript of the receivers
        // its author had in hand -- and the backlog entry that commissioned
        // this arm demonstrated exactly that, with an alternation that could
        // not express `self::libc()->` and so missed four live sites in
        // candy-pty. Anything at all may be on the left of the arrow; the
        // ROSTER is where a call that turns out not to be a libc call gets
        // said so.
        $before     = self::previousSignificant($tokens, $i - 1);
        $beforeType = $before !== null && \is_array($tokens[$before]) ? $tokens[$before][0] : null;

        if (\in_array($beforeType, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return self::methodSinkAt($tokens, $i, $shortName, $open);
        }

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

        // The METHOD half of a `Foo::bar(...)` whose CLASS half was not a
        // static sink. Reached on the next iteration after the branch above
        // returned null, so a static sink can never be reported twice.
        if ($beforeType === \T_DOUBLE_COLON) {
            return self::methodSinkAt($tokens, $i, $shortName, $open);
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
        if (\in_array($beforeType, [
            \T_OBJECT_OPERATOR,
            \T_NULLSAFE_OBJECT_OPERATOR,
            \T_DOUBLE_COLON,
            \T_FUNCTION,
            \T_NEW,
        ], true)) {
            return null;
        }

        $paren = self::nextSignificant($tokens, $i + 1);
        if ($paren === null || $tokens[$paren] !== '(') {
            return null;
        }
        $open = $paren;

        return $shortName;
    }

    /**
     * A METHOD-shaped libc sink at $i, or null. Sets $open to its `(`.
     *
     * ## Arity, and why a zero-argument call is not "unparseable"
     *
     * A nullary `->close()` is one of the commonest calls in this tree -- a
     * stream wrapper, a pty handle, a recorder -- and is never libc's
     * `close`: every symbol in {@see methodSinks()} is DECLARED with at least
     * one parameter, so a nullary call of that name is a different method
     * that shares a word. (A count is deliberately not quoted here. It would
     * be a property of one worktree and it would rot. The generator is a
     * `grep -rEn` for a nullary arrow-call of the name over every library's
     * source directory; it is not spelled out as a literal command here
     * because the glob for that would close this doc-comment.) Skipping it is therefore a
     * CLASSIFICATION -- the parse succeeded and said "not this" -- and not
     * the silent shrug this class exists to refuse. The shrug would be
     * dropping a call whose argument could not be read; that still comes back
     * as {@see UNCLASSIFIED}, as it does for the function spelling.
     *
     * A one-argument `->close($x)` on something that is NOT an FFI handle is
     * reported and needs a roster row saying so. That is the intended cost:
     * the alternative is discriminating on the receiver, which is the trap
     * this whole arm was written to get out of.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @param  int|null                                 $open
     */
    private static function methodSinkAt(array $tokens, int $i, string $shortName, ?int &$open): ?string
    {
        if (!\in_array($shortName, self::methodSinks(), true)) {
            return null;
        }

        $paren = self::nextSignificant($tokens, $i + 1);
        if ($paren === null || $tokens[$paren] !== '(') {
            // A property read (`$libc->close`) or a first-class callable
            // reference, not a call.
            return null;
        }

        $first = self::nextSignificant($tokens, $paren + 1);
        if ($first !== null && $tokens[$first] === ')') {
            return null;
        }

        $open = $paren;

        // Reported with the arrow so a roster key, and the failure text that
        // prints it, says which spelling was found without the reader having
        // to open the file.
        return '->' . $shortName;
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
     * The right-hand side of the most recent assignment to the target
     * SPELLING before $before, or null.
     *
     * Deliberately a plain backwards walk over the whole file rather than a
     * scope-aware one: a false POSITIVE here costs a roster row and a
     * judgement, which is cheap, while a false negative is a silently
     * unexamined cast, which is the thing this class exists to stop
     * happening twice.
     *
     * ## Why the target is a rendered spelling and not a variable name
     *
     * WHAT THIS USED TO DO: match a single `T_VARIABLE` token by name, which
     * meant the trace-back only ever ran for an argument that was a lone
     * `$x`. WHAT IS TRUE NOW: MEASURED through the shipped scanner, PHP
     * 8.3.6, three sources differing only in where the cast was parked --
     *
     *   `$fd = (int) $stream;`        then the sink  -> INT_CAST_VIA_VARIABLE
     *   `$this->fd = (int) $stream;`  then the sink  -> VARIABLE (silent)
     *   `$tty[0] = (int) $stream;`    then the sink  -> VARIABLE (silent)
     *
     * -- so a cast parked in a PROPERTY or an ARRAY ELEMENT classified as the
     * benign shape. That is the same defect with a line break in it, which is
     * this class's own phrase for why the trace-back exists, and the array
     * spelling is the one the FIRST census of this family died of. It also
     * mattered for the shape the tree actually uses: rows in the census are
     * spelled `$this->fd` and `$this->anchorSlaveFd`, and for those the
     * recorded KIND -- the thing that catches a site whose spelling is
     * unchanged but whose meaning moved -- could not move at all.
     *
     * WHY THE OLD NARROWNESS EARNS A MENTION: matching one token is cheap and
     * cannot mis-bracket anything, and that is a real property this walk
     * gives up. The compensating rule is above: a false positive here is a
     * roster row, and a false negative is the defect.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @param  string                                   $target rendered, e.g. `$this->fd`
     * @return list<array{0:int,1:string,2:int}|string>|null
     */
    private static function lastAssignmentTo(array $tokens, int $before, string $target): ?array
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            if ($tokens[$i] !== '=') {
                continue;
            }
            if (self::renderedLeftHandSideEndingAt($tokens, $i - 1) !== $target) {
                continue;
            }
            $eq = $i;

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
     * The accessor chain immediately to the LEFT of an `=`, rendered.
     *
     * Walks back over the tokens an accessor chain may be made of and stops
     * at the first one that is not. `$this->fd` comes back as `$this->fd`;
     * `$tty[0]` as `$tty[0]`; a `list($a, $b) =` destructuring stops on its
     * comma and comes back as something that matches no argument spelling,
     * which is the intended answer rather than a special case.
     *
     * ## Why the shape test is {@see self::classify()} and not a rule of its own
     *
     * WHAT THIS USED TO SAY: "a chain must be rooted in a variable to be an
     * assignable spelling. Without this, a bare `FOO = 1` const-ish shape
     * would render as a name and could collide with a CONSTANT argument."
     *
     * WHAT IS TRUE NOW: the collision it names cannot happen, and MEASURING
     * it is what showed that. PHP 8.3.6, through the shipped scanner, source
     * `FOO = (int) $stream;` followed by a sink call on `FOO`: the reported
     * kind is CONSTANT. It is CONSTANT with this function deleted too. A
     * lone name classifies as CONSTANT on the ARGUMENT side, and scanSource()
     * only ever calls the trace-back when the argument classified VARIABLE,
     * so a constant argument does not reach this function to be collided
     * with. The root test also rejected `self::$fd`, which the argument side
     * DOES call VARIABLE -- so the two sides disagreed about what a traceable
     * spelling is, and the disagreement was silent in the direction that
     * costs a false negative.
     *
     * WHY THE RULE STILL EARNS ITS PLACE: something must still refuse a chain
     * this function mis-bracketed, and the honest form of "is this a spelling
     * the census would trace?" is the question the argument side already
     * answers. Asking classify() makes the two sides the SAME test by
     * construction: whatever spelling can be traced back is exactly whatever
     * spelling can be an argument, and neither can drift without the other.
     * A bare `FOO` is still refused here -- classify() calls it CONSTANT --
     * so the old rule's intent survives; only its hand-rolled second opinion
     * is gone.
     *
     * The constant branch is therefore DORMANT, not dead: it is REDUNDANT
     * with the caller's gate rather than unreachable in principle, and the
     * two are independent -- MEASURED, PHP 8.3.6, by mutation: widen the gate
     * to trace a CONSTANT argument and this refusal still stops it; delete
     * this refusal and the gate still stops it; remove BOTH and
     * {@see DescriptorSinkArgumentCensusTest::testABareConstantIsNeverTracedBack()}
     * goes red. Belt and braces, deliberately, because the cost is one
     * comparison and the failure it prevents is a silent misclassification.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function renderedLeftHandSideEndingAt(array $tokens, int $from): string
    {
        $chain = [];
        for ($i = $from; $i >= 0; $i--) {
            $tok = $tokens[$i];
            if (\is_array($tok) && \in_array($tok[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            if (!self::isAccessorToken($tok)) {
                break;
            }
            array_unshift($chain, $tok);
        }

        // The SAME question the argument side asks -- see the doc-block for
        // why this is classify() and not a rule of its own.
        if ($chain === [] || self::classify($chain) !== self::VARIABLE) {
            return '';
        }

        return self::render($chain);
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
