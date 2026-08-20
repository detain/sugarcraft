<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use SugarCraft\Crush\Palette\PaletteAction;
use SugarCraft\Crush\Support\ContainedPath;

/**
 * Metadata for one command, used by BOTH command surfaces - the "/" popup
 * ({@see \SugarCraft\Crush\Renderer::renderSlashMenu()}) and the Ctrl+P
 * palette ({@see \SugarCraft\Crush\Renderer::renderPalette()}).
 *
 * Display data for a BUILT-IN row - it does not affect
 * {@see \SugarCraft\Crush\Chat::submit()}'s own dispatch chain, which stays the
 * single source of truth for what a built-in command does. A FILE-BASED row is
 * the other half of that sentence and no longer only display data: it carries
 * the prompt itself, and {@see expandTemplate()} is what `submit()` sends when
 * one is typed.
 *
 * A row is visible in the "/" popup unless $slashVisible is false, and
 * visible in the Ctrl+P palette exactly when it carries a $paletteAction -
 * the two flags are what let one registry feed two surfaces that legitimately
 * do not list identical sets (e.g. "New session" has no slash form).
 *
 * A row can also come from disk instead of {@see CommandRegistry::all()} -
 * {@see fromFile()} builds one from a user-authored `*.md` file (see
 * {@see CommandLoader}). Those rows carry a $template, which is what marks
 * them as file-based: a built-in's behaviour lives in `Chat::submit()`, a
 * file-based one's lives in its template body.
 */
final class CommandSpec
{
    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---\s*\n/s';

    /**
     * A command name is typed after "/" and used as an array key, so it is
     * restricted to path-safe characters. "/" itself is allowed as the
     * subdirectory namespace separator ("deploy/staging.md" -> "deploy/staging")
     * but a leading/trailing/doubled separator is not, which is what keeps a
     * traversal-shaped filename ("../../etc/passwd.md") from ever becoming a
     * command name.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*(\/[A-Za-z0-9][A-Za-z0-9_-]*)*$/';

    /**
     * ONE alternation over all four template forms, in ONE pass over the body.
     * The single-pass property is what {@see expandTemplate()}'s doc-block
     * argues for the two `$` forms, and it is load-bearing rather than tidy now
     * that two of the four reach a shell and a filesystem: text a pass
     * substitutes is invisible to the matcher, so an ARGUMENT whose value is
     * `` !`rm -rf ~` `` is prose in the prompt and never a command, and a
     * `$ARGUMENTS` written INSIDE `` !`…` `` is consumed by this pattern before
     * the `$` branch can see it — so user keystrokes cannot become part of a
     * command line at all.
     *
     * Group 1 is the `$` form, group 2 the shell command, group 3 the include
     * path. PCRE leaves an unmatched middle group as `''` and omits an unmatched
     * trailing one, and group 1 can never legitimately BE `''`, so testing
     * groups 3 then 2 then 1 distinguishes the three unambiguously.
     *
     * `!` + backticks: the command may not contain a backtick or a newline —
     * a template that wants either wants a script file, and an unterminated
     * `` !` `` must stay literal rather than swallowing the rest of the body.
     *
     * `@path`: root-RELATIVE references only, and only ones ending in a
     * `.extension`. The leading `(?!\/)` is what makes "root-relative" true —
     * an absolute `@/etc/passwd` does not match this pattern at all and stays
     * literal, so no read is even attempted — and the extension requirement,
     * spelled as a trailing `(?![A-Za-z0-9\/])` so the extension is the LAST
     * segment rather than any segment, is what keeps an ordinary `@alice`
     * mention and a directory reference like `@../../.ssh/id_rsa` out of the
     * include path. The lookbehind `(?<![^\s(\[])` allows the `@` only at the
     * start of the body or after whitespace, `(` or `[`, so an email address
     * never resolves as a file.
     *
     * FLAT QUANTIFIERS, NO NESTED ONE, and that is a fix rather than a style
     * choice. The first revision of this alternative spelled the directory part
     * `(?:[\w.\-]+\/)*`, a quantifier inside a quantifier, and PCRE's JIT
     * recurses per iteration: MEASURED on this host, a body of
     * `@` + `"a/"` × 22000 (44018 bytes) matched, and × 25000 (50018 bytes) made
     * `preg_replace_callback()` return null with "JIT stack limit exhausted".
     * A command file's body is repository-authored, so a body length that
     * defeats the scanner is a body length the repository picks — and the
     * failure delivered the UNSCANNED body, i.e. exactly the "left literal"
     * outcome {@see expandTemplate()} argues against. The flat character class
     * `[\w.\-\/]+` backtracks linearly instead of recursing; the same 50018-byte
     * body now scans without error. {@see expandTemplate()} additionally fails
     * closed if PCRE gives up for any other reason, because "the pattern cannot
     * fail" is not a property worth depending on.
     *
     * ONE MEASURED DIFFERENCE from the nested spelling, and it is a narrowing:
     * `@a.b/c` used to match its `a.b` prefix (a directory, reported back as
     * "no such file"); it is now left literal. Nothing between the `@` and the
     * final `.extension` is treated as an include any more.
     *
     * FENCED CODE BLOCKS ARE NOT EXEMPT, unlike
     * {@see \SugarCraft\Crush\Context\ImportResolver}'s `@`-imports, and that
     * is a deliberate seam rather than an oversight. A `` !` `` form is spelled
     * with backticks, so exempting inline backtick spans would exempt the syntax
     * from itself; and exempting only TRIPLE fences would make a template's
     * meaning depend on whether the author indented an example, which is a worse
     * surprise than the one it removes. The consequence to know: a command file
     * that DOCUMENTS this syntax inside a ``` block has that example run. It is
     * a presentational surprise and not a privilege one — the two gates in
     * {@see \SugarCraft\Crush\Chat::refuseCommandShell()} do not care where in
     * the body the form sat.
     */
    private const TEMPLATE_PATTERN = '/\$(\$|ARGUMENTS|[1-9])'
        . '|!`([^`\n]+)`'
        . '|(?<![^\s(\[])@(?!\/)([\w.\-\/]+\.[A-Za-z0-9]+)(?![A-Za-z0-9\/])/';

    /**
     * Seconds of wall clock that ALL of one template expansion's `` !`…` ``
     * forms share between them — a budget per EXPANSION, not per command.
     *
     * A per-command bound would multiply: a body with sixty `` !`sleep 30` ``
     * forms and a ten-second per-command timeout wedges the TUI for ten
     * minutes, and the TUI is single-threaded, so "wedged" means the frame does
     * not repaint and Ctrl+C is not read. One shared budget makes the worst case
     * the number written here whatever the body contains, and the forms that
     * arrive after it is spent are refused with a notice naming the budget
     * rather than silently dropped.
     *
     * SHORT AND NOT OPERATOR-CONFIGURABLE, which is deliberate and is the
     * opposite of the rule for a provider HTTP call: a completion legitimately
     * runs for tens of minutes, whereas this is a local command run
     * SYNCHRONOUSLY inside `submit()` before a prompt is sent, and every second
     * of it is a second the terminal is frozen. The frontmatter deliberately
     * cannot raise it: frontmatter is repository-authored, and a cloned
     * `review.md` that could set its own budget could freeze the app for as long
     * as it liked.
     */
    public const SHELL_BUDGET_SECONDS = 10;

    /**
     * Bytes any ONE substitution may contribute to the prompt — a `` !`…` ``
     * command's output or an `@file`'s contents.
     *
     * Bounded because the result is PROMPT TEXT that is paid for per token: an
     * unbounded `` !`find /` `` or an `@vendor/dump.sql` would silently spend
     * the context window (and the money) the conversation needed. The clip
     * announces itself, so a truncated substitution reads as truncated to both
     * the operator and the model instead of as a file that ends mid-line.
     */
    public const MAX_SUBSTITUTION_BYTES = 16384;

    /**
     * Bytes of a `` !`cmd` `` that a REFUSAL notice may quote back.
     *
     * A refused form is replaced by a sentence containing the command, so the
     * command text is what reaches the model in its place — and a template's
     * command is as long as its author made it. Bounded so a refusal cannot cost
     * more context than the substitution it declined would have; the operator
     * needs enough of the command to recognise which one was refused, not all of
     * a 40KB one-liner.
     */
    public const MAX_QUOTED_FORM_BYTES = 200;

    public function __construct(
        /** Command name without the leading "/", e.g. "compact". */
        public readonly string $name,
        /** One-line human-readable description shown in the popup/palette. */
        public readonly string $description,
        /** Grouping label shown in the Ctrl+P palette, e.g. "Session". */
        public readonly string $category,
        /**
         * The palette action {@see \SugarCraft\Crush\Chat} dispatches when
         * this row is picked in Ctrl+P; null for commands the palette does
         * not list (they are reachable by typing "/name" instead).
         */
        public readonly ?PaletteAction $paletteAction = null,
        /**
         * Palette row text when it should read differently from the bare
         * command name ("Switch session" vs "sessions"). Null falls back to
         * {@see label()}'s default, the name itself.
         */
        public readonly ?string $paletteLabel = null,
        /** Argument placeholder shown after the name, e.g. "<name>". */
        public readonly ?string $argumentHint = null,
        /** Keybind that also triggers this command, e.g. "Ctrl+C". */
        public readonly ?string $shortcut = null,
        /** Whether the "/" popup lists this row (false = palette-only). */
        public readonly bool $slashVisible = true,
        /**
         * Prompt-template body of a file-based command, i.e. everything after
         * the YAML frontmatter. Null for built-in rows, whose behaviour is
         * PHP in `Chat::submit()` rather than text.
         */
        public readonly ?string $template = null,
        /** Frontmatter `model:` - pins this command to one model. */
        public readonly ?string $model = null,
        /** Frontmatter `subtask: true` - run in an isolated subagent. */
        public readonly bool $subtask = false,
        /**
         * WHICH DISK TIER this row was read from: `'user'` for
         * `~/.sugar-crush/commands`, `'project'` for
         * `<root>/.sugar-crush/commands`, null for a built-in registry row or
         * for a spec an in-process caller built with {@see new()}.
         *
         * Carried because it is the ONLY thing that distinguishes a command the
         * operator wrote from one that arrived in a `git clone`, and
         * {@see \SugarCraft\Crush\Chat} has to make exactly that distinction
         * before it will let a `` !`…` `` form reach a shell. A boolean would
         * conflate the two answers this has to give — "the operator's own file"
         * and "no file at all" — and the second must not inherit the first's
         * treatment by accident.
         */
        public readonly ?string $tier = null,
    ) {}

    public static function new(
        string $name,
        string $description,
        string $category,
        ?PaletteAction $paletteAction = null,
        ?string $paletteLabel = null,
        ?string $argumentHint = null,
        ?string $shortcut = null,
        bool $slashVisible = true,
        ?string $template = null,
        ?string $model = null,
        bool $subtask = false,
        ?string $tier = null,
    ): self {
        return new self(
            $name,
            $description,
            $category,
            $paletteAction,
            $paletteLabel,
            $argumentHint,
            $shortcut,
            $slashVisible,
            $template,
            $model,
            $subtask,
            $tier,
        );
    }

    /**
     * Build a row from a user-authored command file: YAML frontmatter
     * (`description`, `argument-hint`, `model`, `subtask`) plus a template
     * body. Frontmatter is optional - a bare markdown file is a valid command
     * whose body is the whole prompt.
     *
     * Everything here is user-controlled input, so it fails closed: any
     * unreadable file, unparseable YAML, wrongly-typed frontmatter value,
     * unsafe command name, or empty template raises instead of yielding a
     * half-built row. {@see CommandLoader::loadFromDirectory()} catches and
     * skips, so one bad file cannot take the whole directory down with it.
     *
     * @param string $path Absolute path to the `.md` file.
     * @param string $name Command name (without the leading "/"), derived by
     *                     the caller from the file's path.
     * @param ?string $tier `'user'` or `'project'` — see {@see $tier}. Supplied
     *                      by the caller, never by the file.
     */
    public static function fromFile(string $path, string $name, ?string $tier = null): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException("Unsafe command name: $name");
        }

        // Check first so a missing path throws cleanly instead of emitting a
        // PHP warning from file_get_contents before we throw.
        if (!is_file($path)) {
            throw new \RuntimeException("Command file not found: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read command file: $path");
        }

        if (preg_match(self::FRONTMATTER_PATTERN, $content, $matches) === 1) {
            try {
                $meta = Yaml::parse($matches[1]);
            } catch (ParseException $e) {
                throw new \InvalidArgumentException("Malformed frontmatter in $path: {$e->getMessage()}", 0, $e);
            }
            $body = substr($content, strlen($matches[0]));
        } else {
            $meta = [];
            $body = $content;
        }

        // `--- \n null \n ---` parses to a scalar, not a map.
        if (!is_array($meta)) {
            throw new \InvalidArgumentException("Frontmatter must be a YAML mapping in $path");
        }

        $template = trim($body);
        if ($template === '') {
            throw new \InvalidArgumentException("Command file has an empty template body: $path");
        }

        return new self(
            name: $name,
            description: self::stringField($meta, 'description', $path) ?? "Custom command: $name",
            category: 'Custom',
            argumentHint: self::stringField($meta, 'argument-hint', $path),
            template: $template,
            model: self::stringField($meta, 'model', $path),
            subtask: self::boolField($meta, 'subtask', $path),
            // NOT read from the frontmatter, and that is the whole point: the
            // tier is what decides whether this file's `` !`…` `` forms may run
            // a shell, so it is supplied by the loader that knows WHICH
            // DIRECTORY the file came out of. A `tier: user` line in a cloned
            // repository's `*.md` would otherwise be a one-line self-promotion.
            tier: $tier,
        );
    }

    /**
     * The row's display text in the Ctrl+P palette.
     */
    public function label(): string
    {
        return $this->paletteLabel ?? $this->name;
    }

    /**
     * Whether this row came from a command file rather than
     * {@see CommandRegistry::all()}.
     */
    public function isFileBased(): bool
    {
        return $this->template !== null;
    }

    /**
     * The prompt this file-based command sends, with its argument placeholders
     * filled in. Returns null for a built-in row, which has no template and
     * whose behaviour is PHP in {@see \SugarCraft\Crush\Chat::dispatchCommand()}.
     *
     * TWO PLACEHOLDER FORMS, and they are fed from two DIFFERENT readings of the
     * same keystrokes, which is why both parameters exist rather than one:
     *
     *  - `$ARGUMENTS` is everything typed after the command name, VERBATIM —
     *    quotes, doubled spaces and all, apart from the SURROUNDING whitespace
     *    that {@see \SugarCraft\Crush\Chat::expandCustomCommand()} trims off
     *    before calling this (the draft as a whole was already trimmed by
     *    `submit()`, so trimming here only removes the run of spaces between the
     *    name and the first argument). A template that says "Fix: $ARGUMENTS"
     *    wants the sentence back the way it was written, so re-joining split
     *    tokens with single spaces would be a quiet rewrite of the user's prose.
     *  - `$1` … `$9` are the tokens {@see \SugarCraft\Crush\CommandParser::parse()}
     *    produced, i.e. shell-quote split and UNQUOTED, so `/deploy "us east" prod`
     *    puts `us east` in `$1` and `prod` in `$2`. Only nine, matching every
     *    other tool that spells positional arguments this way; `$10` is `$1`
     *    followed by a literal `0`, exactly as in `sh`.
     *
     * A MISSING POSITIONAL EXPANDS TO THE EMPTY STRING rather than staying
     * literal. Both answers are defensible and this one is chosen because the
     * output is a PROMPT: a leftover `$2` reaching the model is an implementation
     * token leaking into the conversation, where it reads as a filename or a
     * variable the model is expected to know. An empty slot reads as an omission,
     * which is what it is. The same rule already applies to `$ARGUMENTS` with no
     * arguments at all, so the two cannot disagree.
     *
     * `$$` IS THE ESCAPE, producing one literal `$`. A doubling rule rather than
     * a backslash because the body is markdown headed for a model — backslashes
     * there already mean something to both markdown and to whatever the prompt
     * is quoting, while `$$` collides with nothing this class emits. A literal
     * `$1` is therefore written `$$1`, and a `$` not followed by a placeholder
     * (`$PATH`, `$(date)`, a bare `$`) is left ALONE, so an ordinary shell
     * snippet inside a template survives untouched.
     *
     * ONE PASS OVER THE WHOLE BODY, not a pass per placeholder and not a pass per
     * line, and that is the substantive decision here:
     *
     *  - Per placeholder (`$1` first, then `$ARGUMENTS`, or the reverse) means
     *    the text a pass SUBSTITUTES is visible to the next pass. An argument
     *    containing the characters `$ARGUMENTS` would then be re-expanded — user
     *    input becoming template syntax, which is the injection shape this
     *    whole class fails closed against elsewhere. A single alternation makes
     *    replaced text unreachable to the matcher by construction, so the
     *    "which order?" question has no answer BECAUSE it has no meaning here.
     *  - Per line would make the result depend on where the author happened to
     *    break lines, and nothing in the syntax spans or respects a newline.
     *
     * ARGUMENTS ARE NOT APPENDED when the template names no placeholder: the
     * body is sent unchanged. Silently tacking them on the end would land them
     * after whatever closing instruction the author wrote, changing what that
     * instruction applies to — a template that wants arguments says where they go.
     *
     * TWO MORE FORMS, `` !`cmd` `` and `@path`, and NEITHER IS RESOLVED HERE.
     * This class holds the two MECHANISMS ({@see runShellSubstitution()} and
     * {@see includeFile()}) and none of the POLICY, because the policy needs
     * things a value object read off disk cannot have: the launch's one
     * {@see \SugarCraft\Crush\Permissions\PermissionGate}, and the answer to
     * "did the operator trust THIS project". So $directive is the seam, and a
     * caller that supplies none gets both forms REFUSED with a visible notice.
     *
     * REFUSED RATHER THAN LEFT LITERAL, which is the less obvious of the two
     * answers and is chosen for the model's sake: a body of `` !`gh pr merge 42` ``
     * left literal is a prompt that asks a tool-using model to run that command
     * itself, so an ungated substitution would come back as a gated TOOL CALL of
     * the same command and the refusal would have bought nothing. A bracketed
     * "was not run" sentence cannot be mistaken for an instruction.
     *
     * THE SHELL BUDGET IS SPENT HERE, not inside $directive: this method holds
     * {@see SHELL_BUDGET_SECONDS} for the whole expansion, charges each
     * `` !`…` `` call the wall time it actually took, and passes what REMAINS to
     * the next one. A form that arrives with nothing left is refused HERE,
     * without calling $directive at all — so a body of forty `` !`sleep 30` ``
     * costs the budget once and not forty times. That check has to be on this
     * side of the seam and not inside the resolver, which is a security property
     * and not an optimisation: {@see \SugarCraft\Crush\Chat}'s resolver reaches
     * {@see \SugarCraft\Crush\Permissions\PermissionGate::evaluate()}, which
     * MUTATES Auto mode's circuit-breaker counters, and a strike committed for a
     * command that was never going to run is the thing that method's own
     * doc-block forbids. MEASURED before this check existed: a template of
     * `` !`burn` !`b` !`c` !`d` `` whose first form spent the whole budget called
     * the resolver four times, i.e. banked three strikes for three commands that
     * did not run. `@path` reads are NOT charged: they are a bounded read of a
     * local file, and charging them would let a large include silently starve a
     * later command of its timeout.
     *
     * A SCAN THAT FAILS DISCARDS THE BODY. `preg_replace_callback()` answers null
     * when PCRE gives up (see {@see TEMPLATE_PATTERN} for the measured JIT-stack
     * case that made this reachable), and the previous spelling fell back to the
     * raw template — which is the "left literal" outcome the paragraph above
     * rejects, arrived at by accident and for the one input a hostile repository
     * controls directly: the body's length. So a failed scan returns a notice
     * IN PLACE OF the whole body. The cost is stated rather than hidden: that
     * notice is what the turn sends, so a broken command file spends one turn
     * saying it was broken. That is the cheap direction to be wrong in — the
     * other one delivers an unexamined repository-authored body, `` !`…` ``
     * forms and all, to a tool-using model.
     *
     * @param string       $arguments  everything after the command name, as typed
     * @param list<string> $positional the parsed tokens; index 0 is `$1`
     * @param ?callable(string,string,float):string $directive resolves the two
     *        non-`$` forms. Called as `("shell"|"include", $payload,
     *        $secondsRemaining)` and must return the text to substitute —
     *        including, for anything it declines, the notice explaining why.
     *        Null refuses both forms.
     */
    public function expandTemplate(string $arguments, array $positional = [], ?callable $directive = null): ?string
    {
        if ($this->template === null) {
            return null;
        }

        $budget = (float) self::SHELL_BUDGET_SECONDS;

        $expanded = preg_replace_callback(
            self::TEMPLATE_PATTERN,
            function (array $m) use ($arguments, $positional, $directive, &$budget): string {
                // Groups 3 then 2 then 1: see TEMPLATE_PATTERN for why an empty
                // string distinguishes them.
                $include = $m[3] ?? '';
                if ($include !== '') {
                    return $directive === null
                        ? self::refusedForm('@' . $include, 'included')
                        : (string) $directive('include', $include, $budget);
                }

                $shell = $m[2] ?? '';
                if ($shell !== '') {
                    if ($directive === null) {
                        return self::refusedForm('!`' . self::abbreviateForm($shell) . '`', 'run');
                    }

                    // The budget is spent: refuse without consulting $directive
                    // — see the doc-block's paragraph on why this check lives on
                    // this side of the seam. Same sentence
                    // runShellSubstitution() would have produced, from the one
                    // method that writes it, so the two cannot drift.
                    if ($budget <= 0.0) {
                        return self::shellBudgetSpentNotice($shell);
                    }

                    // Charged on the WALL CLOCK rather than on a claimed
                    // duration, because the resolver may have spent the time
                    // anywhere — in the gate, in the process, in a retry — and
                    // the budget exists to bound how long the terminal is
                    // frozen, which is wall time by definition.
                    $started = microtime(true);
                    $substituted = (string) $directive('shell', $shell, $budget);
                    $budget = max(0.0, $budget - (microtime(true) - $started));

                    return $substituted;
                }

                if ($m[1] === '$') {
                    return '$';
                }
                if ($m[1] === 'ARGUMENTS') {
                    return $arguments;
                }

                return $positional[(int) $m[1] - 1] ?? '';
            },
            $this->template,
        );

        // FAIL CLOSED, not back to the raw body — see the doc-block. The error
        // name is quoted because the operator cannot otherwise tell a body too
        // large to scan from one this class rejected for its content.
        if ($expanded === null) {
            return sprintf(
                '[/%s was not sent: its %d-byte template could not be scanned for the $, !`…` and @… '
                . 'forms (PCRE: %s), and a command file body that has not been scanned is not sent — '
                . 'its !`…` and @… forms would reach the model as literal instructions. Shorten the file.]',
                $this->name,
                \strlen($this->template),
                preg_last_error_msg(),
            );
        }

        return $expanded;
    }

    /**
     * Run one `` !`cmd` `` and hand back what it printed, bounded in BOTH
     * directions: $timeoutSeconds of wall clock and
     * {@see MAX_SUBSTITUTION_BYTES} of output.
     *
     * THE MECHANISM ONLY. Whether this command is ALLOWED to run is decided by
     * the caller before it gets here ({@see \SugarCraft\Crush\Chat}'s tier +
     * permission-gate check); reaching this method means the answer was yes.
     *
     * `proc_open()` WITH AN ARGUMENT ARRAY, `['bash', '-c', $command]`, not a
     * command string. A string spawns the platform shell to parse it and THEN
     * `bash`, so `proc_terminate()` on timeout kills the outer `sh` and leaves
     * `bash` and its children running — the same trap
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer} documents. The array form
     * execs `bash` directly, so the signal reaches the process whose output we
     * are waiting on. It is still `bash -c` and not a bare exec: a template's
     * `` !`git log --oneline | head -5` `` is shell syntax and is meant to be.
     *
     * STDERR IS INCLUDED ONLY ON A NON-ZERO EXIT, matching
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Bash}: a command that succeeded
     * while printing progress to stderr should not have that progress land in
     * the prompt, and a command that FAILED is useless in a prompt without the
     * reason.
     *
     * A TIMEOUT KEEPS WHAT WAS PRINTED and says so. The partial output is
     * usually the useful half (`!`npm test`` that hung after 200 lines), and
     * discarding it would leave the operator with a notice and no evidence.
     *
     * $timeoutSeconds BOUNDS THE WHOLE CALL AND NOT JUST THE READING. A command
     * that closes or redirects its own output hands us EOF immediately and then
     * runs on, so the wait for the child to EXIT is bounded by the same deadline
     * as the wait for its bytes — see the comment on the polling loop for the
     * measurement that says why.
     *
     * @param string  $command        the text between the backticks, verbatim
     * @param ?string $cwd            directory to run in; null means the
     *                                process's own, whatever that is
     * @param float   $timeoutSeconds what is LEFT of this expansion's budget —
     *                                see {@see expandTemplate()}. Zero or less
     *                                means the budget is gone and nothing runs.
     */
    public function runShellSubstitution(string $command, ?string $cwd, float $timeoutSeconds): string
    {
        // KEPT even though {@see expandTemplate()} now checks the budget before
        // it calls its resolver: this is a public mechanism, and a bound a caller
        // has to remember to apply is not a bound.
        if ($timeoutSeconds <= 0.0) {
            return self::shellBudgetSpentNotice($command);
        }

        $pipes = [];
        $process = @proc_open(
            ['bash', '-c', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );

        if (!\is_resource($process)) {
            return sprintf('[!`%s` was not run: the shell could not be started]', self::abbreviateForm($command));
        }

        $streams = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
        }

        $captured = [1 => '', 2 => ''];
        // PER FD, not one running total, and that distinction is the whole
        // correctness of the "bytes dropped" notice below. Stderr's overflow is
        // a drop from THE SUBSTITUTION only when stderr is part of the
        // substitution, and it usually is not: on a zero exit the entire stderr
        // buffer is discarded, overflow and all, for a completely different
        // reason. One shared counter reported the discarded buffer's overflow as
        // the delivered text's drop count — MEASURED:
        // `head -c 40000 /dev/zero | tr "\0" y 1>&2; echo ok` returned
        // `ok` + "[23616 bytes dropped: one substitution may contribute 16384]"
        // for a three-byte substitution from which nothing was dropped.
        $overflow = [1 => 0, 2 => 0];
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while ($streams !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                $timedOut = true;
                break;
            }

            // Capped at 200ms per wait so the deadline is re-checked often
            // enough that a command producing no output at all still expires on
            // time rather than on its first byte.
            $slice = min($remaining, 0.2);
            $read = array_values($streams);
            $write = [];
            $except = [];
            if (@stream_select($read, $write, $except, (int) $slice, (int) round(($slice - (int) $slice) * 1000000)) === false) {
                break;
            }

            foreach ($streams as $fd => $stream) {
                if (!\in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        unset($streams[$fd]);
                    }

                    continue;
                }
                $room = self::MAX_SUBSTITUTION_BYTES - \strlen($captured[$fd]);
                if ($room > 0) {
                    $captured[$fd] .= substr($chunk, 0, $room);
                }
                $overflow[$fd] += max(0, \strlen($chunk) - max(0, $room));
            }
        }

        // THE READ LOOP CAN END WITH THE CHILD STILL RUNNING, and the budget has
        // to cover that too. A command that redirects its own output —
        // `make -j8 > build.log 2>&1`, which a command file that only wants the
        // exit code writes without thinking about it — closes both pipes at
        // once, so `$streams` empties on the first iteration and the loop above
        // has nothing left to wait on. `proc_close()` then WAITS for the
        // process. MEASURED on this host before this wait existed:
        // `printf %s early; exec 1>&- 2>&-; sleep 6` given a 0.3-second budget
        // returned in 6.00 seconds. `SHELL_BUDGET_SECONDS` is documented as a
        // bound on how long the single-threaded TUI is frozen, so a bound that
        // covered only the OUTPUT and not the EXIT was a bound on the wrong
        // thing.
        //
        // Polled rather than waited on, because there is no portable way to wait
        // for a child with a deadline in PHP; 10ms is short enough that the
        // usual case (already exited) adds one sleep and long enough that a
        // ten-second budget costs at most a thousand `waitpid` calls. The exit
        // code survives the polling: PHP caches it on the first
        // `proc_get_status()` that reaps the child, so `proc_close()` below
        // still returns the real status rather than -1 (measured on PHP 8.3).
        while (!$timedOut && (proc_get_status($process)['running'] ?? false) === true) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;

                break;
            }
            usleep(10000);
        }

        if ($timedOut) {
            // SIGTERM, then SIGKILL: a `bash -c` blocked in a syscall may ignore
            // the first, and proc_close() WAITS, so closing without having
            // actually killed the child is how a bounded call becomes unbounded.
            proc_terminate($process);
            usleep(50000);
            if ((proc_get_status($process)['running'] ?? false) === true) {
                proc_terminate($process, 9);
            }
        }

        foreach ([$pipes[1], $pipes[2]] as $stream) {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
        $exitCode = proc_close($process);

        // The one place that decides whether stderr is part of the answer, so
        // the drop accounting below can ask instead of assuming.
        $stderrIsIncluded = !$timedOut && $exitCode !== 0;

        $text = rtrim($captured[1], "\n");
        if ($stderrIsIncluded) {
            $stderr = trim($captured[2]);
            $text = rtrim($text . "\n" . $stderr, "\n")
                . sprintf("\n[!`%s` exited %d]", self::abbreviateForm($command), $exitCode);
        }
        if ($timedOut) {
            $text = rtrim($text . sprintf(
                "\n[!`%s` was killed after %s seconds — the remainder of this command file's "
                . '%d-second shell budget]',
                self::abbreviateForm($command),
                rtrim(rtrim(number_format($timeoutSeconds, 1, '.', ''), '0'), '.'),
                self::SHELL_BUDGET_SECONDS,
            ), "\n");
        }

        // THE ASSEMBLED STRING GETS THE AUTHORITATIVE CLIP, the way
        // {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput} does for a
        // ToolResult: the two capture buffers above are each bounded, so a
        // FAILING command could otherwise contribute MAX_SUBSTITUTION_BYTES of
        // stdout plus as much again of stderr — twice the number this class
        // documents as one substitution's share.
        $dropped = $overflow[1] + ($stderrIsIncluded ? $overflow[2] : 0);
        if (\strlen($text) > self::MAX_SUBSTITUTION_BYTES) {
            $dropped += \strlen($text) - self::MAX_SUBSTITUTION_BYTES;
            $text = substr($text, 0, self::MAX_SUBSTITUTION_BYTES);
        }
        if ($dropped > 0) {
            $text .= sprintf(
                "\n[%d bytes dropped: one substitution may contribute %d]",
                $dropped,
                self::MAX_SUBSTITUTION_BYTES,
            );
        }

        return ltrim($text, "\n");
    }

    /**
     * Read one `@path` and hand back its bytes, bounded to
     * {@see MAX_SUBSTITUTION_BYTES}.
     *
     * CONTAINED IN $root, through the one {@see ContainedPath} predicate the
     * rest of this feature uses rather than a fourth prefix compare — which is
     * what stops `@../../.ssh/id_rsa.pub`, and a symlink under $root pointing at
     * the same, from becoming prompt text. The reference itself is
     * root-RELATIVE by construction: {@see TEMPLATE_PATTERN} does not match a
     * leading `/`, so an absolute path never reaches this method.
     *
     * THE BOUNDARY IS THE CHECKOUT FOR BOTH TIERS, deliberately, and it is the
     * asymmetry with `` !`…` `` worth stating: `@path` exists to pull a
     * repository file into a prompt, so the checkout is the whole domain of the
     * feature, and a user-tier command that wants a file from the operator's
     * HOME can say `` !`cat ~/notes.md` `` and go through the permission gate
     * for it. Widening this to `$HOME` for the user tier would give the WEAKER
     * check (a path compare, no gate) the WIDER reach.
     *
     * `is_file()` IS TESTED BEFORE CONTAINMENT so the notice is true: an absent
     * file reported as "resolves outside the project" is exactly the
     * measured-on-A-written-as-B defect this project keeps finding. That stat
     * happens outside $root for an escaping reference and is not a read — no
     * byte of the file is opened until the compare has passed. The price is that
     * the absent-file notice cannot claim WHERE it looked, only what it composed
     * the reference against, and it is worded accordingly.
     *
     * @param string $reference the path as written after the `@`
     * @param string $root      the checkout the reference must stay inside
     */
    public function includeFile(string $reference, string $root): string
    {
        $resolved = rtrim($root, '/') . '/' . $reference;

        if (!is_file($resolved)) {
            // "RELATIVE TO", not "under". This branch is reached BEFORE the
            // containment compare (see the doc-block for why), so it also
            // catches `@../secret.txt` when the target happens not to exist —
            // and that composed path is not under $root at all. Saying "no such
            // file under <root>" there would be the measured-on-A-written-as-B
            // defect this method's own doc-block claims to avoid, one branch up.
            return sprintf(
                '[@%s was not included: it does not name an existing file relative to %s]',
                $reference,
                $root,
            );
        }

        if (!ContainedPath::within($resolved, $root)) {
            return sprintf(
                '[@%s was not included: it resolves outside %s, and an included file becomes prompt text]',
                $reference,
                $root,
            );
        }

        $content = @file_get_contents($resolved, false, null, 0, self::MAX_SUBSTITUTION_BYTES + 1);
        if ($content === false) {
            return sprintf('[@%s was not included: it could not be read]', $reference);
        }

        if (\strlen($content) > self::MAX_SUBSTITUTION_BYTES) {
            return substr($content, 0, self::MAX_SUBSTITUTION_BYTES)
                . sprintf("\n[@%s truncated: one substitution may contribute %d bytes]", $reference, self::MAX_SUBSTITUTION_BYTES);
        }

        return $content;
    }

    /**
     * A command clipped to {@see MAX_QUOTED_FORM_BYTES} for quoting inside a
     * refusal notice.
     *
     * PUBLIC because the refusals are written on BOTH sides of the seam this
     * class draws — the two here, and {@see \SugarCraft\Crush\Chat}'s tier and
     * permission-gate ones — and one bound written twice is one bound that
     * drifts. The ellipsis is spelled out so a clipped command cannot be
     * mistaken for the whole of a shorter one.
     */
    public static function abbreviateForm(string $command): string
    {
        return \strlen($command) <= self::MAX_QUOTED_FORM_BYTES
            ? $command
            : substr($command, 0, self::MAX_QUOTED_FORM_BYTES) . '…(clipped)';
    }

    /**
     * The notice a non-`$` form gets when no resolver was supplied.
     *
     * $verb is "run" or "included" so the sentence names what did not happen to
     * the form the reader is looking at, rather than announcing that something
     * generically did not happen.
     */
    /**
     * The sentence a `` !`cmd` `` gets when this expansion's shell budget is
     * already spent.
     *
     * ONE WRITER, TWO CALLERS: {@see expandTemplate()} refuses before consulting
     * its resolver (so a refused form cannot move a permission gate's counters)
     * and {@see runShellSubstitution()} refuses again if a caller hands it a
     * non-positive budget directly. Two spellings of the same refusal would
     * drift, and the notice names {@see SHELL_BUDGET_SECONDS}, which is the
     * figure most likely to be edited.
     */
    private static function shellBudgetSpentNotice(string $command): string
    {
        return sprintf(
            '[!`%s` was not run: this command file had %d seconds of shell time for the whole '
            . 'expansion and an earlier !`…` in the same file used all of it]',
            self::abbreviateForm($command),
            self::SHELL_BUDGET_SECONDS,
        );
    }

    private static function refusedForm(string $form, string $verb): string
    {
        return sprintf(
            '[%s was not %s: this command was expanded without a resolver for shell and file forms, '
            . 'so neither can be gated and neither is performed]',
            $form,
            $verb,
        );
    }

    /**
     * A frontmatter value that must be a string when present. A YAML list or
     * map here means the author mis-wrote the file; coercing it with (string)
     * would silently produce "Array", so reject it instead.
     *
     * @param array<mixed> $meta
     */
    private static function stringField(array $meta, string $key, string $path): ?string
    {
        $value = $meta[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException("Frontmatter '$key' must be a string in $path");
        }

        return (string)$value;
    }

    /** @param array<mixed> $meta */
    private static function boolField(array $meta, string $key, string $path): bool
    {
        $value = $meta[$key] ?? false;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Frontmatter '$key' must be a boolean in $path");
        }

        return $value;
    }
}
