<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

/**
 * Decides whether one shell command line is a plain read: a single simple
 * command from a short allowlist, with no way to write, chain, substitute,
 * background or expand into something else. Used by `Bash` to let a
 * read-only call join a parallel group ({@see ParallelSafeCall}).
 *
 * Conservative by construction. Anything the scanner cannot prove harmless is
 * a barrier, so a false "no" costs one serial call and a false "yes" would let
 * a write race its siblings. Concretely:
 *
 *  - Any shell metacharacter that could start a second command, a
 *    substitution, a redirection or a background job is refused outright,
 *    even inside quotes — `;`, `&`, `|`, `<`, `>`, `$`, backticks, parens,
 *    braces, backslashes, newlines, and glob characters (a file literally
 *    named `--output=x` would otherwise become an option).
 *  - Exactly one command word, from the allowlist, with per-tool argument
 *    refusals for the flags that write or execute (`find -exec`, `git diff
 *    --output`, `rg --pre`, `tail -f`).
 *  - `git` must name its subcommand first: a leading `-c core.pager=…` or
 *    `--exec-path` would be a way to run something.
 */
final class ReadOnlyBashClassifier
{
    /** Command words that only read, mapped to the argument refusals each one needs. */
    private const SIMPLE_READERS = ['ls', 'pwd', 'echo', 'which', 'cat', 'head', 'tail', 'wc', 'grep', 'rg', 'find'];

    /** git subcommands that do not write the worktree, the index or any ref. */
    private const GIT_READERS = ['status', 'log', 'diff', 'show'];

    /** Characters refused anywhere in the line, quoted or not. */
    private const FORBIDDEN_CHARACTERS = ";&|<>\$`(){}\\\n\r*?[]!#";

    /** Argument prefixes that make an otherwise read-only command write or run a program. */
    private const REFUSED_ARGUMENTS = [
        'find' => ['-exec', '-execdir', '-ok', '-okdir', '-delete', '-fprint', '-fls'],
        'rg' => ['--pre'],
        'tail' => ['-f', '-F', '--follow'],
        'git' => ['--output', '--ext-diff', '--textconv'],
    ];

    private function __construct()
    {
    }

    public static function isReadOnly(string $command): bool
    {
        if ($command === '' || strpbrk($command, self::FORBIDDEN_CHARACTERS) !== false) {
            return false;
        }

        $words = self::words($command);
        if ($words === null || $words === []) {
            return false;
        }

        $program = $words[0];
        if ($program === 'git') {
            if (!isset($words[1]) || !in_array($words[1], self::GIT_READERS, true)) {
                return false;
            }

            return self::argumentsAllowed('git', array_slice($words, 2));
        }

        if (!in_array($program, self::SIMPLE_READERS, true)) {
            return false;
        }

        return self::argumentsAllowed($program, array_slice($words, 1));
    }

    /**
     * A refused entry is either a word (`-exec`, `--output`, matched as a
     * prefix so `-execdir` and `--output=f` are caught too) or a single short
     * flag (`-f`, matched inside a bundle such as `-nf`).
     *
     * @param list<string> $arguments
     */
    private static function argumentsAllowed(string $program, array $arguments): bool
    {
        foreach ($arguments as $argument) {
            foreach (self::REFUSED_ARGUMENTS[$program] ?? [] as $refused) {
                if (strlen($refused) === 2 && $refused[0] === '-') {
                    if (str_starts_with($argument, '-') && !str_starts_with($argument, '--') && str_contains($argument, $refused[1])) {
                        return false;
                    }

                    continue;
                }
                if (str_starts_with($argument, $refused)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whitespace split that honours single and double quotes (quoted text is
     * literal, which the forbidden-character scan has already made safe).
     * Null for unbalanced quoting.
     *
     * @return list<string>|null
     */
    private static function words(string $command): ?array
    {
        $words = [];
        $word = '';
        $inWord = false;
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                } else {
                    $word .= $char;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $inWord = true;

                continue;
            }
            if ($char === ' ' || $char === "\t") {
                if ($inWord) {
                    $words[] = $word;
                    $word = '';
                    $inWord = false;
                }

                continue;
            }
            $word .= $char;
            $inWord = true;
        }

        if ($quote !== null) {
            return null;
        }
        if ($inWord) {
            $words[] = $word;
        }

        return $words;
    }
}
