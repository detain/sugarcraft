<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Finds every child-process launch in a test file and says whether the child's
 * stderr is CAPTURED or left to fall through onto the PHPUnit process's own.
 *
 * A full `vendor/bin/phpunit` prints 62 `sugarcrush: ` lines (measured at
 * 62f4e5d1 on PHP 8.3.6 with `grep -ac 'sugarcrush: '` over the captured log -
 * `grep` without `-a` classifies that log as binary and prints NOTHING, which
 * is indistinguishable from a real zero). Some of those come from a child
 * whose stderr nobody plugged in, and for THOSE the line is not a finding
 * about `src/` at all - it is a spawn site that forgot a pipe.
 *
 * SILENCING IS THE WRONG DEFAULT and this scanner is deliberately not built to
 * support it: for most of these shapes the line IS the assertion, and a
 * `2>/dev/null` would delete the evidence the test exists to read. What is
 * required is that the line goes somewhere the TEST can read - a pipe, a file,
 * a `2>&1` merge - so it is available to be asserted on rather than dumped on
 * whoever is watching the suite.
 *
 * WHAT IT CANNOT SEE, said out loud because the number above is mostly NOT
 * this: an in-process `fwrite(\STDERR, ...)`. `src/Cli/NonInteractive.php`
 * alone has six of them, and a test that calls that code directly - as
 * `tests/Cli/NonInteractiveProviderFailureTest.php` (18 lines) and
 * `tests/Cli/NonInteractiveTest.php` (8) do - is writing on the suite's own
 * stderr with no child process anywhere in the picture. No amount of
 * per-spawn redirection touches one of those; they need a sink seam in
 * `src/`. This scanner is about the other kind, and reports honestly that
 * under `tests/Integration/` there is currently none of it.
 */
final class ChildStderrCaptureScanner
{
    /** Launch functions whose command is a shell string. */
    private const SHELL_SPAWNS = ['exec', 'shell_exec', 'passthru', 'system', 'popen'];

    /** The child's stderr reaches the test. */
    public const SHAPE_CAPTURED = 'captured';

    /** The child's stderr lands on the PHPUnit process's own. */
    public const SHAPE_INHERITED = 'inherited';

    /** The scanner could not resolve where fd 2 goes. */
    public const SHAPE_UNCLASSIFIED = 'unclassified';

    /**
     * @return list<array{line:int,call:string,shape:string}>
     */
    public static function scan(string $source): array
    {
        $tokens = \token_get_all($source);
        $sites = [];

        foreach ($tokens as $i => $token) {
            // T_NAME_FULLY_QUALIFIED, not T_STRING, is what PHP 8's lexer
            // hands back for the `\proc_open(...)` spelling - and that is the
            // spelling `BinSugarcrushDispatchTest` uses throughout. A first
            // version of this scanner matched only T_STRING and reported that
            // file as having no spawn sites at all, which is exactly the
            // shape of a confident false green: the answer was already known
            // (its runBin() pipes fd 2) and the instrument said nothing.
            if (!\is_array($token)
                || !\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = \ltrim(\strtolower($token[1]), '\\');
            $isShell = \in_array($name, self::SHELL_SPAWNS, true);
            if (!$isShell && $name !== 'proc_open') {
                continue;
            }

            $open = self::next($tokens, $i);
            if ($open === null || self::tokenText($tokens[$open]) !== '(') {
                continue;
            }

            // `$this->exec(...)`, `Foo::exec(...)` and `function exec(...)`
            // are not the global launcher.
            $prev = self::prev($tokens, $i);
            if ($prev !== null && \is_array($tokens[$prev])
                && \in_array($tokens[$prev][0], [\T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $close = self::matching($tokens, $open, '(', ')');
            if ($close === null) {
                continue;
            }

            $sites[] = [
                'line' => $token[2],
                'call' => $name,
                'shape' => $isShell
                    ? self::classifyShell(self::text($tokens, $open, $close))
                    : self::classifyProcOpen($tokens, $open, $close),
            ];
        }

        return $sites;
    }

    /**
     * A shell command captures stderr when it says where fd 2 goes: `2>&1`
     * onto the stdout the caller is already reading, or `2>` a file it opens
     * afterwards. Anything else inherits.
     */
    private static function classifyShell(string $argumentList): string
    {
        return \str_contains($argumentList, '2>') ? self::SHAPE_CAPTURED : self::SHAPE_INHERITED;
    }

    /**
     * `proc_open()` captures stderr when its descriptor spec names fd 2 - as
     * a pipe or as a file. The spec is resolved rather than searched for:
     * either it is an inline array in argument 2, or argument 2 is a variable
     * whose nearest preceding assignment holds the array. Neither, and the
     * site is `unclassified` rather than assumed innocent.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function classifyProcOpen(array $tokens, int $open, int $close): string
    {
        // A `2>` in the command string redirects before the descriptor spec
        // is ever consulted, so it counts too.
        if (\str_contains(self::text($tokens, $open, $close), '2>')) {
            return self::SHAPE_CAPTURED;
        }

        $arguments = self::topLevelArguments($tokens, $open, $close);
        if (!isset($arguments[1])) {
            return self::SHAPE_UNCLASSIFIED;
        }

        [$from, $to] = $arguments[1];
        $first = self::next($tokens, $from - 1);
        if ($first === null || $first > $to) {
            return self::SHAPE_UNCLASSIFIED;
        }

        if (self::tokenText($tokens[$first]) === '[' || (\is_array($tokens[$first]) && $tokens[$first][0] === \T_ARRAY)) {
            return self::namesFdTwo(self::text($tokens, $from, $to))
                ? self::SHAPE_CAPTURED
                : self::SHAPE_INHERITED;
        }

        if (\is_array($tokens[$first]) && $tokens[$first][0] === \T_VARIABLE) {
            $spec = self::nearestAssignment($tokens, $first, $tokens[$first][1]);

            if ($spec === null) {
                return self::SHAPE_UNCLASSIFIED;
            }

            return self::namesFdTwo($spec) ? self::SHAPE_CAPTURED : self::SHAPE_INHERITED;
        }

        return self::SHAPE_UNCLASSIFIED;
    }

    private static function namesFdTwo(string $spec): bool
    {
        return \preg_match('/(^|[\[,\s])2\s*=>/', $spec) === 1;
    }

    /**
     * The source of the nearest `$var = ...;` before $before.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nearestAssignment(array $tokens, int $before, string $variable): ?string
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_VARIABLE || $tokens[$i][1] !== $variable) {
                continue;
            }

            $equals = self::next($tokens, $i);
            if ($equals === null || self::tokenText($tokens[$equals]) !== '=') {
                continue;
            }

            for ($j = $equals + 1, $n = \count($tokens); $j < $n; $j++) {
                if (\is_string($tokens[$j]) && $tokens[$j] === ';') {
                    return self::text($tokens, $equals + 1, $j);
                }
                if (\is_string($tokens[$j]) && $tokens[$j] === '[') {
                    $end = self::matching($tokens, $j, '[', ']');
                    if ($end === null) {
                        return null;
                    }
                    $j = $end;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * The token spans of the call's top-level arguments.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{0:int,1:int}>
     */
    private static function topLevelArguments(array $tokens, int $open, int $close): array
    {
        $args = [];
        $depth = 0;
        $start = $open + 1;

        for ($i = $open + 1; $i < $close; $i++) {
            $text = self::tokenText($tokens[$i]);
            if (\is_string($tokens[$i]) && \in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (\is_string($tokens[$i]) && \in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif (\is_string($tokens[$i]) && $text === ',' && $depth === 0) {
                $args[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        $args[] = [$start, $close - 1];

        return $args;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function tokenText(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function next(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = \count($tokens); $i < $n; $i++) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function prev(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function matching(array $tokens, int $openAt, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openAt, $n = \count($tokens); $i < $n; $i++) {
            if (!\is_string($tokens[$i])) {
                continue;
            }
            if ($tokens[$i] === $open) {
                $depth++;
            } elseif ($tokens[$i] === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function text(array $tokens, int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            $out .= self::tokenText($tokens[$i]);
        }

        return $out;
    }
}
