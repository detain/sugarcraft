<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * A quote-aware split of a shell command line into simple commands and words —
 * the shape bash itself sees AFTER quote removal and BEFORE any expansion.
 *
 * WHY THIS EXISTS. Every deny-list over shell text in this package used to
 * match the RAW string, and bash strips quotes before `rm` ever sees its argv:
 * `rm '-rf' ~` is byte-for-byte `rm -rf ~` to the process, yet a matcher that
 * only treats a raw `-`-prefixed token as a flag (the step-0 breaker in
 * {@see PermissionGate}) or wants `\s-` directly before the flag
 * ({@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook}) let it through.
 * Audit F-P1 measured both. The fix is to judge the words bash will produce,
 * which needs a tokeniser rather than another regex.
 *
 * What it does:
 * - quote removal for `'…'`, `"…"` (with the four backslash escapes bash
 *   honours inside double quotes), `$'…'` (ANSI-C, escapes decoded) and `$"…"`;
 * - unquoted backslash escapes (`\rm` → `rm`) and `\<newline>` continuation;
 * - splitting into simple commands on every unquoted control operator — `;`,
 *   `;;`, `&`, `&&`, `|`, `|&`, `||`, newline/CR, and the subshell parentheses
 *   `(` / `)` — recorded in {@see $operators};
 * - pulling redirections OUT of the word list (`2>/dev/null` is not an operand
 *   of `rm`) and recording each with its operator, optional fd and target in
 *   {@see $redirections}; here-doc bodies are skipped, not parsed as commands;
 * - recording every command / process substitution (`$(`, backtick, `<(`,
 *   `>(`) in {@see $substitutions}, its raw text kept inside the word it sits in;
 * - `#` comments at the start of a word;
 * - flagging, per word, whether bash may turn it into something OTHER than
 *   its literal text ({@see $expandable}: an unquoted glob or brace
 *   character, any `$` expansion, any substitution), and recording every
 *   `${…}` / `$[…]` expansion in {@see $parameterExpansions} — the two facts a
 *   fail-closed ALLOW check needs and quote removal throws away.
 *
 * What it deliberately does NOT do: any expansion. `~`, `$HOME`, `${HOME}`,
 * globs and substitutions stay literal in the words, because the deciding
 * callers must not depend on the gate's own environment (and executing a
 * substitution to see what it yields is out of the question). A consumer that
 * cares about a substitution must look at {@see $substitutions} and decide —
 * for a fail-closed ALLOW check (plan mode's "read-only Bash") the only sound
 * answer to a substitution or an incomplete parse is "not provably safe".
 *
 * {@see $complete} is FALSE when the line is not something bash would run as
 * written: an unterminated quote, substitution or here-doc, a trailing lone
 * backslash, or a redirection operator with no target. The words are still
 * returned as a best effort; callers that deny on a match should keep their
 * raw-text fallback for that case, callers that allow on a match must not.
 *
 * Reserved words (`if`, `then`, `{`, `!`, `do` …) are NOT interpreted: they
 * come back as ordinary words at the head of a command. That is the honest
 * extent of a tokeniser that is not a shell grammar.
 *
 * {@see PermissionGate::tokenizeSingleCommand()} is a different tool for a
 * different question — it REFUSES any metacharacter because its caller GRANTS
 * on a match; this class SPLITS on them because its callers deny on a match.
 */
final readonly class ShellWords
{
    /**
     * @param list<list<string>> $commands Each simple command's words after
     *        quote removal, with redirections removed. A command consisting only
     *        of redirections (`> f`) is present as an empty list so the indices
     *        in {@see $redirections} stay meaningful.
     * @param list<array{command: int, fd: ?string, op: string, target: ?string}> $redirections
     *        Every redirection, keyed to the index of the command it belongs to.
     *        `fd` is the explicit descriptor (`2` in `2>f`), `target` the
     *        quote-removed word after the operator (the delimiter for `<<`).
     * @param list<string> $substitutions The opener of every substitution in
     *        source order: `$(`, `` ` ``, `<(` or `>(`.
     * @param list<string> $operators Every control operator in source order.
     * @param bool $complete See the class docblock.
     * @param list<list<bool>> $expandable Parallel to {@see $commands}: TRUE
     *        for a word bash may rewrite before the command sees it — it holds
     *        an UNQUOTED `*`, `?`, `[` or `{` (pathname or brace expansion) or
     *        any `$` expansion / substitution, quoted or not. Quote removal is
     *        what makes this necessary: `find . '{-delete,}'` and
     *        `find . {-delete,}` produce the same word here, and only the
     *        second hands find a `-delete`. A caller that judges ARGUMENTS
     *        (rather than only the command name) cannot judge a flagged word.
     * @param list<string> $parameterExpansions The opener of every `${…}` and
     *        `$[…]` in source order. Recorded separately from
     *        {@see $substitutions} because they are not substitutions — and
     *        yet they can run one: an array subscript or a substring offset is
     *        evaluated ARITHMETICALLY, `${x@P}` is prompt-expanded, and both
     *        execute a `$(…)` that sits inside a variable's VALUE, which
     *        `${x:=…}` can assign on the same line. No text check can see a
     *        substitution that only exists after an assignment, so a
     *        fail-closed caller has to refuse the operator itself.
     * @param list<string> $sources Parallel to {@see $commands}: each simple
     *        command's RAW source text — quotes, redirections and
     *        substitutions intact, the control operator that ended it
     *        excluded. The words are what the program receives; the source is
     *        what the user wrote, and a permission glob may be written against
     *        either (`Bash(git commit -m "*")` names quotes, `Bash(* > /tmp/*)`
     *        names a redirection the word list no longer carries). Splitting
     *        happens HERE, on unquoted operators only, so `echo "a;b"` stays one
     *        source where a regex split on `;` produced two halves of nothing.
     */
    private function __construct(
        public array $commands,
        public array $redirections,
        public array $substitutions,
        public array $operators,
        public bool $complete,
        public array $expandable = [],
        public array $parameterExpansions = [],
        public array $sources = [],
    ) {
    }

    /**
     * Tokenise `$line`. Never throws; malformed input yields `complete: false`.
     */
    public static function parse(string $line): self
    {
        $length = strlen($line);
        $commands = [];
        $redirections = [];
        $substitutions = [];
        $operators = [];
        $complete = true;

        $words = [];
        $wordFlags = [];
        $expandable = [];
        $parameterExpansions = [];
        $sources = [];
        // Byte offset where the current simple command's source text starts.
        $segmentStart = 0;
        $hasRedirect = false;
        $current = '';
        $inWord = false;
        // Whether bash may rewrite $current before the command sees it — see
        // the $expandable constructor parameter.
        $expands = false;
        // Whether any byte of $current came from quoting/escaping — a quoted
        // `'2'>f` is a word followed by `>f`, never an fd number.
        $quoted = false;
        /** @var ?int $pendingRedirect index into $redirections awaiting a target */
        $pendingRedirect = null;
        /** @var list<array{delimiter: string, stripTabs: bool}> $pendingHeredocs */
        $pendingHeredocs = [];

        $endWord = static function () use (&$words, &$wordFlags, &$current, &$inWord, &$quoted, &$expands, &$pendingRedirect, &$redirections, &$pendingHeredocs): void {
            if (!$inWord) {
                return;
            }
            if ($pendingRedirect !== null) {
                $redirections[$pendingRedirect]['target'] = $current;
                $op = $redirections[$pendingRedirect]['op'];
                if ($op === '<<' || $op === '<<-') {
                    $pendingHeredocs[] = ['delimiter' => $current, 'stripTabs' => $op === '<<-'];
                }
                $pendingRedirect = null;
            } else {
                $words[] = $current;
                $wordFlags[] = $expands;
            }
            $current = '';
            $inWord = false;
            $quoted = false;
            $expands = false;
        };
        $endCommand = static function (string $source) use (&$words, &$wordFlags, &$expandable, &$sources, &$hasRedirect, &$commands, &$pendingRedirect, &$complete, $endWord): void {
            $endWord();
            if ($pendingRedirect !== null) {
                // `echo >; ls` — bash: syntax error near unexpected token.
                $complete = false;
                $pendingRedirect = null;
            }
            if ($words !== [] || $hasRedirect) {
                $commands[] = $words;
                $expandable[] = $wordFlags;
                $sources[] = trim($source);
            }
            $words = [];
            $wordFlags = [];
            $hasRedirect = false;
        };

        for ($i = 0; $i < $length; ++$i) {
            $char = $line[$i];
            $next = $line[$i + 1] ?? '';

            if ($char === '\\') {
                if ($i + 1 >= $length) {
                    // A trailing backslash asks bash for another line.
                    $complete = false;
                    continue;
                }
                ++$i;
                if ($next === "\n") {
                    continue; // line continuation
                }
                $current .= $next;
                $inWord = true;
                $quoted = true;
                continue;
            }

            if ($char === "'") {
                $close = strpos($line, "'", $i + 1);
                if ($close === false) {
                    $complete = false;
                    $close = $length;
                }
                $current .= substr($line, $i + 1, $close - $i - 1);
                $i = $close;
                $inWord = true;
                $quoted = true;
                continue;
            }

            if ($char === '"') {
                $current .= self::readDoubleQuoted($line, $i, $substitutions, $complete, $expands, $parameterExpansions);
                $inWord = true;
                $quoted = true;
                continue;
            }

            if ($char === '$') {
                // bash removes `\<newline>` before it looks at what follows the
                // `$`, so `$\⏎{x}` IS `${x}` and `$\⏎(cmd)` IS `$(cmd)`. Look
                // past continuations, or the expansion reads as a literal `$`.
                $at = self::skipContinuations($line, $i + 1);
                $after = $line[$at] ?? '';
                if ($after === "'") {
                    $i = $at - 1;
                    $current .= self::readAnsiC($line, $i, $complete);
                    $inWord = true;
                    $quoted = true;
                    continue;
                }
                if ($after === '"') {
                    $i = $at; // `$"…"` is a translatable "…"
                    $current .= self::readDoubleQuoted($line, $i, $substitutions, $complete, $expands, $parameterExpansions);
                    $inWord = true;
                    $quoted = true;
                    continue;
                }
                $expands = true;
                if ($after === '(' || $after === '{' || $after === '[') {
                    $current .= '$' . self::readDollarGroup($line, $i, $at, $substitutions, $parameterExpansions, $complete);
                    $inWord = true;
                    continue;
                }
                $current .= '$';
                $inWord = true;
                continue;
            }

            if ($char === '`') {
                $substitutions[] = '`';
                $start = $i;
                if (!self::skipBacktick($line, $i)) {
                    $complete = false;
                }
                $current .= substr($line, $start, $i - $start + 1);
                $inWord = true;
                $expands = true;
                continue;
            }

            if (($char === '<' || $char === '>') && $next === '(') {
                $substitutions[] = $char . '(';
                $start = $i;
                ++$i;
                if (!self::skipBalanced($line, $i)) {
                    $complete = false;
                }
                $current .= substr($line, $start, $i - $start + 1);
                $inWord = true;
                $expands = true;
                continue;
            }

            if ($char === '<' || $char === '>' || ($char === '&' && $next === '>')) {
                $fd = null;
                if ($inWord && !$quoted && $current !== '' && ctype_digit($current) && $pendingRedirect === null) {
                    $fd = $current;
                    $current = '';
                    $inWord = false;
                } else {
                    $endWord();
                }
                if ($pendingRedirect !== null) {
                    $complete = false; // `> >f`
                }
                $op = self::readRedirectOperator($line, $i);
                $redirections[] = ['command' => count($commands), 'fd' => $fd, 'op' => $op, 'target' => null];
                $hasRedirect = true;
                $pendingRedirect = count($redirections) - 1;
                continue;
            }

            if ($char === ';' || $char === '&' || $char === '|' || $char === '(' || $char === ')'
                || $char === "\n" || $char === "\r"
            ) {
                $op = $char;
                $opStart = $i;
                if (($char === ';' && $next === ';') || ($char === '&' && $next === '&')
                    || ($char === '|' && ($next === '|' || $next === '&'))
                ) {
                    $op .= $next;
                    ++$i;
                }
                $endCommand(substr($line, $segmentStart, $opStart - $segmentStart));
                $operators[] = $op;
                if ($char === "\n" && $pendingHeredocs !== []) {
                    if (!self::skipHeredocBodies($line, $i, $pendingHeredocs)) {
                        $complete = false;
                    }
                    $pendingHeredocs = [];
                }
                $segmentStart = $i + 1;
                continue;
            }

            if ($char === ' ' || $char === "\t") {
                $endWord();
                continue;
            }

            if ($char === '#' && !$inWord) {
                $eol = strpos($line, "\n", $i);
                $i = ($eol === false ? $length : $eol) - 1;
                continue;
            }

            if ($char === '*' || $char === '?' || $char === '[' || $char === '{') {
                // Unquoted, so bash may glob or brace-expand this word: the
                // literal text is no longer what the command receives.
                $expands = true;
            }
            $current .= $char;
            $inWord = true;
        }

        $endCommand(substr($line, $segmentStart));
        if ($pendingHeredocs !== []) {
            // `cat <<EOF` with no body line at all.
            $complete = false;
        }

        return new self($commands, $redirections, $substitutions, $operators, $complete, $expandable, $parameterExpansions, $sources);
    }

    /**
     * The index of the first byte at or after `$i` that is not part of a
     * `\<newline>` line continuation.
     */
    private static function skipContinuations(string $line, int $i): int
    {
        while (($line[$i] ?? '') === '\\' && ($line[$i + 1] ?? '') === "\n") {
            $i += 2;
        }

        return $i;
    }

    /**
     * `$i` sits on a `$` whose group opener — `(`, `{` or `[` — is at `$at`
     * (past any continuations). Skips the balanced group, records it, and
     * returns its text from the opener on, leaving `$i` on the closer (or the
     * last byte).
     *
     * A `$(` is a substitution. A `${` or `$[` is a parameter / arithmetic
     * expansion, recorded in {@see $parameterExpansions}; a substitution
     * NESTED inside one (`${x:-$(cmd)}`, a backtick in a default) runs as
     * surely as a bare one, so it is recorded in {@see $substitutions} too —
     * before this a `$(` inside `${…}` was skipped with the braces and a
     * consumer of {@see $substitutions} never saw it.
     *
     * @param list<string> $substitutions
     * @param list<string> $parameterExpansions
     */
    private static function readDollarGroup(
        string $line,
        int &$i,
        int $at,
        array &$substitutions,
        array &$parameterExpansions,
        bool &$complete,
    ): string {
        $opener = $line[$at];
        $i = $at;
        if ($opener === '(') {
            $substitutions[] = '$(';
        } else {
            $parameterExpansions[] = '$' . $opener;
        }
        $balanced = $opener === '['
            ? self::skipBracket($line, $i)
            : self::skipBalanced($line, $i);
        if (!$balanced) {
            $complete = false;
        }
        $group = substr($line, $at, $i - $at + 1);
        if ($opener !== '(') {
            $body = str_replace("\\\n", '', $group);
            if (str_contains($body, '$(')) {
                $substitutions[] = '$(';
            }
            if (str_contains($body, '`')) {
                $substitutions[] = '`';
            }
        }

        return $group;
    }

    /**
     * `$i` sits on the `[` of a `$[…]`; advance it to the matching `]`.
     */
    private static function skipBracket(string $line, int &$i): bool
    {
        $length = strlen($line);
        $depth = 0;
        for (; $i < $length; ++$i) {
            if ($line[$i] === '[') {
                ++$depth;
            } elseif ($line[$i] === ']' && --$depth === 0) {
                return true;
            }
        }
        $i = $length - 1;

        return false;
    }

    /**
     * `$i` sits on the opening `"`; leaves it on the closing one (or past the
     * end). Inside double quotes a backslash only escapes `$`, backtick, `"`,
     * `\` and newline — any other backslash is a literal byte, as in bash.
     *
     * `$expands` is raised for any `$` expansion or backtick inside — quoted
     * text is still expanded, just not split or globbed.
     *
     * @param list<string> $substitutions
     * @param list<string> $parameterExpansions
     */
    private static function readDoubleQuoted(
        string $line,
        int &$i,
        array &$substitutions,
        bool &$complete,
        bool &$expands,
        array &$parameterExpansions,
    ): string {
        $length = strlen($line);
        $out = '';
        for (++$i; $i < $length; ++$i) {
            $char = $line[$i];
            if ($char === '"') {
                return $out;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $next = $line[$i + 1];
                if ($next === "\n") {
                    ++$i;
                    continue;
                }
                if (str_contains('$`"\\', $next)) {
                    $out .= $next;
                    ++$i;
                    continue;
                }
                $out .= $char;
                continue;
            }
            if ($char === '$') {
                $expands = true;
                $at = self::skipContinuations($line, $i + 1);
                $after = $line[$at] ?? '';
                if ($after === '(' || $after === '{' || $after === '[') {
                    $out .= '$' . self::readDollarGroup($line, $i, $at, $substitutions, $parameterExpansions, $complete);
                    continue;
                }
                $out .= $char;
                continue;
            }
            if ($char === '`') {
                $expands = true;
                $substitutions[] = '`';
                $start = $i;
                if (!self::skipBacktick($line, $i)) {
                    $complete = false;
                }
                $out .= substr($line, $start, $i - $start + 1);
                continue;
            }
            $out .= $char;
        }

        $complete = false;

        return $out;
    }

    /**
     * `$i` sits on the `$` of `$'…'`; leaves it on the closing `'`. The C
     * escapes are decoded because bash decodes them: `rm $'\x2drf' /` hands rm
     * a literal `-rf`.
     */
    private static function readAnsiC(string $line, int &$i, bool &$complete): string
    {
        $length = strlen($line);
        $raw = '';
        for ($i += 2; $i < $length; ++$i) {
            $char = $line[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $raw .= $char . $line[++$i];
                continue;
            }
            if ($char === "'") {
                return stripcslashes($raw);
            }
            $raw .= $char;
        }

        $complete = false;

        return stripcslashes($raw);
    }

    /**
     * `$i` sits on an opening `(` or `{`; advance it to the matching closer,
     * skipping quoted spans and escapes. Returns false (with `$i` at the last
     * byte) when the closer never comes.
     */
    private static function skipBalanced(string $line, int &$i): bool
    {
        $length = strlen($line);
        $open = $line[$i];
        $close = $open === '(' ? ')' : '}';
        $depth = 0;
        for (; $i < $length; ++$i) {
            $char = $line[$i];
            if ($char === '\\') {
                ++$i;
                continue;
            }
            if ($char === "'") {
                $end = strpos($line, "'", $i + 1);
                if ($end === false) {
                    break;
                }
                $i = $end;
                continue;
            }
            if ($char === '"') {
                for (++$i; $i < $length && $line[$i] !== '"'; ++$i) {
                    if ($line[$i] === '\\') {
                        ++$i;
                    }
                }
                continue;
            }
            if ($char === $open) {
                ++$depth;
            } elseif ($char === $close && --$depth === 0) {
                return true;
            }
        }
        $i = $length - 1;

        return false;
    }

    /**
     * `$i` sits on an opening backtick; advance it to the closing one.
     */
    private static function skipBacktick(string $line, int &$i): bool
    {
        $length = strlen($line);
        for (++$i; $i < $length; ++$i) {
            if ($line[$i] === '\\') {
                ++$i;
                continue;
            }
            if ($line[$i] === '`') {
                return true;
            }
        }
        $i = $length - 1;

        return false;
    }

    /**
     * `$i` sits on the first byte of a redirection operator (`<`, `>` or the
     * `&` of `&>`); returns the operator and leaves `$i` on its last byte.
     */
    private static function readRedirectOperator(string $line, int &$i): string
    {
        foreach (['&>>', '&>', '<<<', '<<-', '<<', '<>', '<&', '>>', '>|', '>&', '<', '>'] as $op) {
            if (substr_compare($line, $op, $i, strlen($op)) === 0) {
                $i += strlen($op) - 1;

                return $op;
            }
        }

        return $line[$i]; // unreachable: the caller only enters on `<`, `>` or `&>`
    }

    /**
     * `$i` sits on the newline that ends a line carrying here-doc operators;
     * skip each body in order through its delimiter line, leaving `$i` on the
     * newline that ends the last delimiter (or the last byte). A body is data
     * for the command's stdin, not commands — parsing it as commands would
     * make every deny-list fire on the text of a heredoc.
     *
     * @param list<array{delimiter: string, stripTabs: bool}> $heredocs
     */
    private static function skipHeredocBodies(string $line, int &$i, array $heredocs): bool
    {
        $length = strlen($line);
        foreach ($heredocs as $heredoc) {
            while (true) {
                if ($i + 1 >= $length) {
                    $i = $length - 1;

                    return false;
                }
                $start = $i + 1;
                $eol = strpos($line, "\n", $start);
                $end = $eol === false ? $length : $eol;
                $text = rtrim(substr($line, $start, $end - $start), "\r");
                if ($heredoc['stripTabs']) {
                    $text = ltrim($text, "\t");
                }
                $i = $eol === false ? $length - 1 : $eol;
                if ($text === $heredoc['delimiter']) {
                    break;
                }
                if ($eol === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Output targets an inert redirection may name: writing to them changes no
     * file.
     */
    public const INERT_OUTPUT_TARGETS = ['/dev/null', '/dev/stdout', '/dev/stderr'];

    /**
     * Does this redirection leave every file alone and open nothing beyond
     * the command's own descriptors? Judged on the operator AND the target,
     * because the checks this replaced judged spacing.
     *
     * - `2>&1`, `>&2`, `3<&0`, `2>&-`: fd duplication / closing, no file.
     * - `>`, `>>`, `>|`, `&>`, `&>>` (and `>& word`, which bash reads as
     *   `&> word`) only onto {@see INERT_OUTPUT_TARGETS} — so `cmd 2>/dev/null`
     *   is inert and `cmd 2> f` is not, in any spacing.
     * - `<` reads a file, so any target — except bash's `/dev/tcp/…` and
     *   `/dev/udp/…`, which open a network connection rather than a file.
     * - `<<<` feeds a word to stdin; the word was already parsed, and any
     *   substitution in it is already in {@see $substitutions}.
     * - NOT inert: `<>` opens read-WRITE (creating the file), and `<<`/`<<-`
     *   here-doc bodies are expanded by bash (`$(…)` in a body runs) but are
     *   skipped, unparsed, by {@see parse()} — so nothing has looked at them.
     *
     * Shared by Plan mode's read-only Bash check and the fail-closed `Allow`
     * arm of {@see PermissionRule}; both GRANT on a true answer.
     *
     * @param array{command: int, fd: ?string, op: string, target: ?string} $redirection
     */
    public static function isInertRedirection(array $redirection): bool
    {
        $target = $redirection['target'] ?? '';

        return match ($redirection['op']) {
            '>&' => preg_match('/^(?:\d+-?|-)$/', $target) === 1
                || in_array($target, self::INERT_OUTPUT_TARGETS, true),
            '<&' => preg_match('/^(?:\d+-?|-)$/', $target) === 1,
            '>', '>>', '>|', '&>', '&>>' => in_array($target, self::INERT_OUTPUT_TARGETS, true),
            '<' => !str_starts_with($target, '/dev/tcp/') && !str_starts_with($target, '/dev/udp/'),
            '<<<' => true,
            default => false,
        };
    }

    public function hasSubstitution(): bool
    {
        return $this->substitutions !== [];
    }

    public function hasRedirection(): bool
    {
        return $this->redirections !== [];
    }

    /**
     * The commands with redirections removed, each re-joined with single
     * spaces and one command per line — quote-free text for the regex
     * heuristics that predate this class and are still written against a
     * flat string.
     */
    public function dequoted(): string
    {
        return implode("\n", array_map(
            static fn (array $words): string => implode(' ', $words),
            $this->commands,
        ));
    }
}
