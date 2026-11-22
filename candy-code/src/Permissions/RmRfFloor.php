<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use SugarCraft\Code\ToolCall;

/**
 * The `rm -rf /` / `rm -rf ~` floor (R3): the one predicate that refuses a
 * recursive, forced `rm` of the filesystem root or a home directory.
 *
 * {@see PermissionGate} consults it unconditionally, in every mode, before
 * rules are considered, and answers a match with a Deny. It moved here from
 * the gate unchanged (CC4-1) so the predicate and its helpers sit in one
 * place. Two hooks that also look at `rm` are NOT copies of it and stay where
 * they are: {@see \SugarCraft\Code\Hooks\BuiltIn\ConfirmRemoveHook} asks about
 * a much wider match set, and
 * {@see \SugarCraft\Code\Hooks\BuiltIn\BashEscapeDenyHook} is an opt-in
 * workspace path jail with its own message.
 */
final class RmRfFloor
{
    private function __construct()
    {
    }

    /**
     * Detect the `rm -rf /` or `rm -rf ~` circuit-breaker pattern (R3).
     *
     * JUDGED ON THE WORDS BASH WILL PRODUCE, NOT ON THE RAW TEXT. Until audit
     * F-P1 this split the raw string on whitespace, treated only a raw
     * `-`-prefixed token as a flag and only the FIRST non-flag token as the
     * target, and compared that target literally against `/` and `~`. bash
     * removes quotes before `rm` sees its argv, so `rm '-rf' ~` (the flag read
     * as the target, which was not `/`), `rm -rf ./x /` (second target never
     * looked at), `rm -rf /*`, `rm -rf ~/` and `rm -rf $HOME` were all ALLOWED
     * under `bypass-permissions` — measured — and the first deletes `$HOME`,
     * which GNU `--preserve-root` does not protect. Each command line is now
     * tokenised by {@see ShellWords} (quote removal, escapes, `$'…'`, every
     * control operator, redirections pulled out of the operand list), and:
     *
     * - the command word is recognised by basename, case-insensitively, behind
     *   leading `NAME=value` assignments and the wrapper commands in
     *   {@see RM_PREFIX_COMMANDS} (with their option arguments): `/bin/rm`,
     *   `\rm`, `sudo -u root rm`, `env X=1 rm`, `timeout 5 rm`;
     * - flags are read after quote removal and ANYWHERE in the argv, since GNU
     *   getopt permutes (`rm ~ -rf` is `rm -rf ~`), and an unambiguous GNU
     *   long-option abbreviation counts (`--rec`, `--forc`);
     * - `--` ends options, so every later word is an OPERAND — a `-`-prefixed
     *   word after it is checked as a target AND, deliberately, still counted
     *   toward the flags, because the pre-tokeniser breaker counted it and this
     *   is a deny list that must never shrink (`rm -r -- -f /`);
     * - EVERY operand is checked, normalised by {@see isRootOrHomeOperand()}:
     *   `/`, `//`, `/.`, `/*`, `/tmp/..`, `~`, `~/`, `~/.`, `~user`, `$HOME`,
     *   `${HOME}`, `"$HOME"`, `$HOME/` all count.
     *
     * The old RAW-token pass is kept as well — every segment of the raw line,
     * split on `[;&|()\r\n]`, whitespace-split, one layer of matching quotes
     * stripped per token, judged by the same rules — and its verdict is OR-ed
     * in. That is what makes an unparseable line (an unterminated quote) still
     * refusable, and it guarantees this breaker never denies LESS than the
     * version it replaced: the tokenised pass honours `#` comments and skips
     * here-doc bodies, which a raw-text deny list never did.
     *
     * Command chains are checked command-by-command, and a NEWLINE separates
     * two commands exactly as `;` does — while the raw split lacked `\n` this
     * breaker allowed `echo hi\nrm -rf /` under `bypass-permissions` while
     * denying `echo hi && rm -rf /`. Measured, not reasoned.
     *
     * BE CLEAR ABOUT WHAT THIS IS NOT. Mode-independence makes it unswitchable,
     * not unevadable: it reads `arguments['command']` and performs no
     * expansion, so it is shell-text matching with the same ceiling
     * {@see PermissionRule}'s "HONEST LIMITS" block documents —
     * `$(echo rm) -rf /`, `x=-rf; rm $x /`, `bash -c 'rm -rf /'`, `eval`,
     * aliases and `find / -delete` are all past it. It is a guard rail against
     * an accident, and calling it a containment boundary (as a first draft of
     * that block did) would be exactly the overclaim that block exists to
     * refuse.
     *
     * Matches: rm -rf /, rm -fr /, rm -r -f /, rm --recursive --force /,
     * rm --rec --forc /, rm -rf --no-preserve-root /, rm -rf "/", rm -rf '/',
     * rm -rf ~, rm '-rf' ~, rm "-rf" /, rm $'-rf' /, rm ~ -rf, rm -rf ./x /,
     * rm -rf /*, rm -rf //, rm -rf /., rm -rf ~/, rm -rf $HOME, rm -rf ${HOME},
     * rm -rf "$HOME"/, rm -r -- -f /, sudo rm -rf /, SUDO RM -RF ~,
     * sudo -u root rm -rf /, /bin/rm -rf /, \rm -rf /, (rm -rf /)
     */
    public static function isRmRfRootOrHome(ToolCall $call): bool
    {
        // C-TOOL-6.k: typed input reaches the same floor. A ShellSend carries
        // no command line, so each DECODED LINE of its keystrokes is judged
        // through the very word test a shell command passes — typing
        // `rm -rf /` at a rescue shell trips the breaker even under
        // `bypass-permissions`. This is the roster's honest exception, not a
        // membership: ShellSend is not a shell-subject tool (its class
        // doc-block carries that argument); the breaker simply refuses to
        // care what program is holding the terminal when the words are the
        // self-destruct ones.
        if ($call->name === \SugarCraft\Code\Tools\BuiltIn\ShellSend::NAME) {
            $keys = $call->arguments['keys'] ?? null;
            if (!\is_string($keys) || $keys === '') {
                return false;
            }
            $decoded = \SugarCraft\Code\Tools\BuiltIn\PtyRun::decode($keys);
            foreach (\preg_split('/\r\n?|\n/', $decoded) ?: [] as $line) {
                if ($line !== '' && self::textTripsRmRfFloor($line)) {
                    return true;
                }
            }

            return false;
        }

        // The breaker covers every tool whose `command` is handed to a shell,
        // not just `Bash` — `PtyRun` spawns the same `/bin/sh -c $command`, so a
        // model that reaches for the interactive driver must not step around an
        // unswitchable floor. Roster: ShellSubjectTools (rv-php-2 single source),
        // pinned across all six sites by ShellSubjectToolsCompletenessTest.
        if (!ShellSubjectTools::covers($call->name)) {
            return false;
        }

        // Every text the call would put through the shell is judged — for
        // `PtyRun` that includes the decoded keystrokes, so `rm -rf /` typed at
        // a rescue shell trips the same floor as `Bash` running it directly.
        foreach (ShellSubjectTools::judgedShellTexts($call->name, $call->arguments) as $command) {
            if (self::textTripsRmRfFloor($command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One shell-bound text through the breaker: the quote-aware `ShellWords`
     * command walks first, and a `preg_split` segment fallback catches what
     * the parse cannot read (folded into the shared helper at C-TOOL-6.k so
     * the ShellSend line-judgement and the shell-subject text-judgement run
     * the SAME word floor — byte-identical semantics for Bash and PtyRun).
     */
    private static function textTripsRmRfFloor(string $command): bool
    {
        // S-T03: past the length door the full parse costs seconds, so a linear
        // pre-scan first asks whether any word could dequote to `rm` (bare,
        // quote-split like r''m, or backslashed). No such word means neither
        // walk below can match; any candidate still gets the full, unchanged
        // judgement — the floor never loosens, it only skips the provably idle.
        if (strlen($command) > PermissionGate::MAX_REASONED_COMMAND_BYTES
            && preg_match('/(?<![A-Za-z0-9_.-])r[\'"\\\\]*m(?![A-Za-z0-9_.-])/', $command) !== 1
        ) {
            return false;
        }

        foreach (ShellWords::parse($command)->commands as $words) {
            if (self::wordsAreRmRfRootOrHome($words)) {
                return true;
            }
        }

        foreach (preg_split('/[;&|()\r\n]+/', $command) ?: [] as $segment) {
            $tokens = array_map(
                self::stripMatchingQuotes(...),
                preg_split('/\s+/', trim($segment), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            );
            if (self::wordsAreRmRfRootOrHome($tokens)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wrapper commands that run their argument as a command, mapped to their
     * short options that consume the NEXT word (so `sudo -u root rm` skips
     * `root` rather than taking it for the command). The `int` is how many
     * positional words precede the wrapped command (`timeout 5 rm`). A best
     * effort, not a grammar of each tool: an unlisted option that takes an
     * argument leaves that argument as the "command word", which fails to match
     * `rm` — i.e. errs toward the old, narrower breaker, never toward a crash.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const RM_PREFIX_COMMANDS = [
        'sudo' => ['ugCDhprtUT', 0],
        'doas' => ['uC', 0],
        'env' => ['uCS', 0],
        'command' => ['', 0],
        'builtin' => ['', 0],
        'exec' => ['a', 0],
        'nohup' => ['', 0],
        'time' => ['fo', 0],
        'nice' => ['n', 0],
        'ionice' => ['cnp', 0],
        'stdbuf' => ['ioe', 0],
        'timeout' => ['sk', 1],
        '{' => ['', 0],
        '!' => ['', 0],
    ];

    /**
     * Is this one simple command (quote-removed words) an `rm` that combines
     * recursive + force flags, in any spelling/order/split, against at least
     * one root-or-home operand?
     *
     * @param list<string> $words
     */
    private static function wordsAreRmRfRootOrHome(array $words): bool
    {
        $count = count($words);
        $i = 0;

        while ($i < $count) {
            $word = $words[$i];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $word) === 1) {
                ++$i; // `FOO=bar rm …` — an assignment prefix
                continue;
            }
            $name = strtolower(basename(ltrim($word, '\\')));
            if (!isset(self::RM_PREFIX_COMMANDS[$name])) {
                break;
            }
            [$optionsWithArgument, $positionals] = self::RM_PREFIX_COMMANDS[$name];
            ++$i;
            while ($i < $count && str_starts_with($words[$i], '-') && $words[$i] !== '-') {
                $option = $words[$i++];
                if ($option === '--') {
                    break;
                }
                if (strlen($option) === 2 && $optionsWithArgument !== '' && str_contains($optionsWithArgument, $option[1])) {
                    ++$i;
                }
            }
            while ($positionals-- > 0 && $i < $count) {
                ++$i;
            }
        }

        if ($i >= $count || strtolower(basename(ltrim($words[$i], '\\'))) !== 'rm') {
            return false;
        }

        $recursive = false;
        $force = false;
        $endOfOptions = false;
        $operands = [];

        for (++$i; $i < $count; ++$i) {
            $word = $words[$i];

            if (!$endOfOptions && $word === '--') {
                $endOfOptions = true;
                continue;
            }

            if ($word !== '-' && str_starts_with($word, '-')) {
                $lower = strtolower($word);
                if (str_starts_with($lower, '--')) {
                    // GNU accepts any unambiguous prefix: `--rec`, `--forc`.
                    if (strlen($lower) >= 3 && str_starts_with('--recursive', $lower)) {
                        $recursive = true;
                    } elseif (strlen($lower) >= 3 && str_starts_with('--force', $lower)) {
                        $force = true;
                    }
                } else {
                    // Short flag cluster, e.g. -rf, -fr, -r, -f, -Rf (case-insensitive).
                    $flags = substr($lower, 1);
                    $recursive = $recursive || str_contains($flags, 'r');
                    $force = $force || str_contains($flags, 'f');
                }
                if (!$endOfOptions) {
                    continue;
                }
            }

            $operands[] = $word;
        }

        if (!$recursive || !$force) {
            return false;
        }

        foreach ($operands as $operand) {
            if (self::isRootOrHomeOperand($operand)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this (unexpanded) operand name the filesystem root or a home
     * directory — or everything directly inside one?
     *
     * Anchored at `/` or at a home spelling (`~`, `~user`, `$HOME`, `${HOME}`),
     * then walked segment by segment: empty and `.` segments vanish (`//`,
     * `/.`, `~/`), `..` climbs (and climbing above the anchor is no safer than
     * the anchor — `~/..` is every home), and a FINAL segment made only of glob
     * characters (`*`, `.*`, `?*`) names the anchor's whole contents, so it
     * counts as the anchor. A relative operand is never root-or-home here:
     * `./x/../..` depends on the cwd, which is the jails' question, not this
     * breaker's.
     */
    private static function isRootOrHomeOperand(string $operand): bool
    {
        if (preg_match('/^(?:~[A-Za-z0-9._-]*|\$HOME|\$\{HOME\})(?=\/|$)/i', $operand, $match) === 1) {
            $rest = substr($operand, strlen($match[0]));
        } elseif (str_starts_with($operand, '/')) {
            $rest = $operand;
        } else {
            return false;
        }

        $segments = explode('/', $rest);
        $last = count($segments) - 1;
        $depth = 0;
        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($index === $last && $depth === 0 && preg_match('/^[*?.]*[*?][*?.]*$/', $segment) === 1) {
                continue;
            }
            ++$depth;
        }

        return $depth === 0;
    }

    /**
     * Strip a single pair of matching surrounding quotes (`"/"` -> `/`, `'~'` -> `~`)
     * for the RAW-token pass of {@see isRmRfRootOrHome()} — the pass that has to
     * say something about a line {@see ShellWords} could not parse.
     */
    private static function stripMatchingQuotes(string $token): string
    {
        $length = strlen($token);
        if ($length >= 2) {
            $first = $token[0];
            $last = $token[$length - 1];
            if (($first === '"' || $first === "'") && $first === $last) {
                return substr($token, 1, -1);
            }
        }

        return $token;
    }
}
