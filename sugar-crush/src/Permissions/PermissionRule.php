<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\Edit\PatchParser;

/**
 * A rule that matches a tool call and specifies what action to take.
 *
 * THE PATTERN LANGUAGE LIVES HERE, and it did not before: this class was a
 * two-field DTO whose doc-block advertised `Bash(composer update *)` and
 * `Read(./.env)` while the only matcher in the package — a private
 * `PermissionGate::ruleMatches()`, deleted by this change and deliberately NOT
 * cited as a `{@see}` here, since a reference to a symbol that no longer exists
 * reads as a pointer and is a dead end — compared the TOOL NAME and nothing
 * else. Measured on the unfixed build, `Deny Bash(rm -rf *)` against
 * `Bash(command: "rm -rf /tmp/mine")` returned `allow`, and so did
 * `Deny Read(./.env)` against `Read(file_path: "./.env")`: the first ends with
 * `*`, so the name was prefix-matched against `Bash(rm -rf ` and missed; the
 * second was compared for equality against `Read` and missed. Both spellings
 * the documentation offered therefore denied NOTHING while reading as a
 * control. The grammar and the matching now live in one class so the sentence
 * that documents them and the code that enforces them cannot drift again.
 *
 * ## Grammar
 *
 *     pattern  := name-glob [ "(" argument-glob ")" ]
 *     name-glob     := fnmatch() pattern, matched against ToolCall::$name
 *     argument-glob := fnmatch() pattern, matched against the call's SUBJECT
 *                      argument (see SUBJECT_ARGUMENTS)
 *
 * `fnmatch()` rather than the old "trailing `*` means prefix" rule, and it is a
 * strict SUPERSET of it: `Bash*` is still a prefix match, a metacharacter-free
 * `Bash` is still an exact match, and `mcp__*__push` now works where it
 * previously matched nothing. `fnmatch()` is also the wildcard dialect the rest
 * of this package already speaks — {@see \SugarCraft\Crush\MCP\McpRouter},
 * {@see \SugarCraft\Crush\Skills\SkillRegistry},
 * {@see \SugarCraft\Crush\Agents\WorktreeManager} — so `*`, `?` and `[abc]`
 * mean here what they mean there. `FNM_PATHNAME` is deliberately NOT set, so a
 * single `*` crosses `/`: `Read(./secrets/*)` covers a nested path, which is the
 * reading a user writing a deny expects.
 *
 * The two degenerate spellings, both chosen so that no pattern can be silently
 * unmatchable:
 *
 * - `Tool()` and `Tool(   )` mean the bare `Tool` — an EMPTY argument glob is
 *   "any arguments", not "the empty argument". The alternative reading makes a
 *   rule that can never fire, which is the defect this class was rewritten for.
 * - A pattern with an unbalanced parenthesis (`Bash(rm -rf`, `Bash)`) is
 *   MALFORMED. {@see isWellFormedPattern()} answers false for it and
 *   {@see \SugarCraft\Crush\Cli\Bootstrap} warns on stderr and skips the entry,
 *   the same item-wise discipline it already applies to a missing `action`.
 *   Should one reach a gate anyway (a hand-built rule in a test, a future
 *   caller), it degrades to a NAME-only pattern that matches no real tool —
 *   never to a deny-everything, because a typo must not be able to brick a
 *   session, and never to an allow-everything either.
 *
 * ## What a pattern is ABOUT: the subject argument
 *
 * One argument per tool, named in {@see SUBJECT_ARGUMENTS}, so that
 * `Tool(<glob>)` means one thing rather than "whichever argument happens to
 * match". For every filesystem-reaching tool that argument is a PATH, which is
 * what makes `Read(./.env)`, `Write(dist/*)` and `Grep(/etc/*)` the same kind
 * of rule.
 *
 * ## HONEST LIMITS — EVERY argument-scoped deny is ADVISORY, shell and path both
 *
 * A pattern over a tool argument is a pattern over a SPELLING, and the same
 * capability has more spellings than a glob can enumerate. That is true of both
 * subject kinds, and an earlier draft of this block said it only of `Bash` —
 * which by omission implied a path deny was tight. It is not. Both halves,
 * measured:
 *
 * SHELL subjects (`Bash`). Closed: leading/trailing whitespace, whitespace RUNS
 * inside the command (`rm   -rf  x`), and hiding the command behind a
 * separator — `;`, `&`, `|` AND a bare NEWLINE, which is as much a shell
 * separator as `;` is and which the first cut of this class silently dropped
 * (it collapsed whitespace BEFORE splitting, so `\n` became a space and
 * `echo hi\nrm -rf x` walked past `Deny Bash(rm -rf *)`;
 * {@see matchesShellSubject()} now splits first for exactly that reason). NOT
 * closed, and not closable without executing the shell: `/bin/rm -rf x`,
 * `$(echo rm) -rf x`, `bash -c 'rm -rf x'`, `eval "rm -rf x"`,
 * `alias`/function indirection, `find . -delete`.
 *
 * PATH subjects (`Read`, `Edit`, `Write`, `Glob`, `Grep`, `Lsp`, and each
 * path of an `ApplyPatch` — {@see matchesPatch()}). Closed by
 * {@see matchesPathSubject()}: the `./` prefix, `//` runs, and `.`/`..`
 * segments, all normalised away on BOTH sides — so `Deny Read(./.env)` now also
 * covers `.env`, `.//.env` and `./foo/../.env`, which it did not before and
 * which are the spellings a model is likelier to emit than the documented one.
 * Additionally, for a RESTRICTIVE rule only, a relative pattern reads as "at
 * any depth", so that same deny covers `/home/u/proj/.env`. And when the caller
 * supplies the workspace root the tools resolve against (audit F-J3 — the live
 * hook chain does, from `HookContext::$projectRoot`), the call is ALSO read the
 * way the tool will read it: anchored at that root and resolved on disk. That
 * closes the two holes this paragraph used to list as open — an ABSOLUTE
 * pattern (`Deny Read(/proj/secret.txt)`) against a relative spelling
 * (`secret.txt`, `./secret.txt`, `sub/../secret.txt`), and a symlink
 * (`notes -> secret.txt`). STILL NOT closed: a hard link (a second name for
 * the inode, which no path resolution can map back), a bind mount, a path
 * swapped between this decision and the tool's open (the gate resolves, the
 * tool resolves again; a race between them is the path jails' problem, and
 * they resolve at use), and every call judged WITHOUT a root — a gate an
 * embedder builds without one matches spellings only. (The sub-agent gate is
 * handed the root since F-J3-rem(a); a declaration check needs none, having no
 * path to spell.)
 *
 * So treat any `Tool(...)` deny as a guard rail against the model doing
 * something by ACCIDENT, not as a containment boundary against something trying
 * to get past it. The boundaries that do not depend on a spelling are
 * {@see PermissionMode::Plan}, which refuses whole tool KINDS, and the path
 * jails, which resolve. Be precise about one that reads like a third and is
 * not: the unconditional `rm -rf /` breaker in {@see PermissionGate} is
 * mode-independent, but it reads `arguments['command']` and tokenises it
 * without expanding anything, so it is shell-text matching too: since audit
 * F-P1 it catches `/bin/rm -rf /` and `rm '-rf' ~`, and `bash -c 'rm -rf /'`
 * and `$(echo rm) -rf /` are still past it (both measured). (Its own
 * newline-separator hole — measured: under `bypass-permissions`,
 * `echo hi\nrm -rf /` was ALLOWED where `echo hi && rm -rf /` was denied — is
 * closed in this change alongside this class's.) Saying all of this here rather
 * than implying otherwise is the point: a control advertised as airtight and
 * not being airtight is worse than one documented as advisory.
 *
 * ## Asymmetry: a restrictive rule fires on the union, a permissive one on the
 * ## intersection
 *
 * The one rule that makes every fallback in this class safe by construction,
 * stated once and applied everywhere below:
 *
 * - `Deny` / `Ask` (they take capability away, or defer it to a human) fire
 *   when ANY reading of the call matches — whole command or any single segment
 *   of a chain, and they fire when the subject is UNKNOWABLE.
 * - `Allow` (it grants) fires only when EVERY reading matches, and never when
 *   the subject is unknowable.
 *
 * Without the asymmetry the segment handling would be a hole rather than a
 * hardening: `fnmatch('git *', 'git log && rm -rf /')` is TRUE, because `*` is
 * greedy, so a whole-command match is not evidence that a chain is safe.
 * `Allow Bash(git *)` therefore requires `git log` AND `rm -rf /` to both match
 * `git *`, and refuses. The cost, stated because it is real: a permissive
 * pattern that itself spans a shell separator (`Allow Bash(cd x && make)`)
 * never fires, since neither segment matches it. Spell a permissive rule
 * per-segment.
 *
 * Separators were not the only thing a greedy `*` swallows (audit F-P5): a
 * command substitution, a backtick, a process substitution and an output
 * redirection all sit INSIDE one segment, so `Allow Bash(git *)` granted
 * `git log $(id)` and `git log > ~/.bashrc`. The `Allow` arm therefore also
 * refuses — falls through to the mode — any line that holds one of those
 * outside single quotes, or does not parse, unless the pattern itself spells
 * the construct; see {@see allowCoversShellSubject()}.
 *
 * @see PermissionGate for where rules sit in the decision order (first match
 *      wins, after the unconditional `rm -rf /` breaker and before the mode).
 */
final class PermissionRule
{
    /**
     * The argument a `Tool(<glob>)` pattern is matched against, per tool.
     *
     * MEASURED FROM THE SCHEMAS, not assumed: every name below is a property of
     * that tool's `inputSchema()` in `src/Tools/BuiltIn/` and is listed in its
     * `required` array, so a real call always carries it and no rule
     * fail-closes on a routinely-absent argument.
     * {@see \SugarCraft\Crush\Tests\Permissions\PermissionRulePatternTest::testEverySubjectArgumentIsARequiredPropertyOfThatToolsSchema()}
     * re-derives that from `inputSchema()` at test time, so a schema rename
     * breaks a test instead of silently turning a deny back into a no-op.
     *
     * THE SUBJECT IS WHAT THE TOOL REACHES, NOT WHAT IT LOOKS FOR, which is why
     * `Glob` and `Grep` map to `path` and not to the `pattern` they search
     * with — even though `Deny Grep(*password*)` is the more tempting spelling.
     * A search regex is not a capability a gate can withhold: the same bytes
     * come back through `Read` on a path the caller may already read, so a
     * rule about the regex would look like a secrets control while being
     * trivially bypassable, and the path — which IS the reach — would then have
     * no spelling at all. The cost is that a Grep rule cannot talk about the
     * regex. That is the correct thing to lose.
     *
     * `doctor` is ABSENT on purpose rather than mapped to something: its schema
     * declares no properties at all, so it has no subject, and an
     * argument-scoped rule naming it resolves through the unknowable-subject
     * branch of {@see matches()}. `mcp__*` tools are absent for a different
     * reason with the same effect — their schemas are server-defined and
     * unknowable in this process.
     *
     * @var array<string, string>
     */
    public const SUBJECT_ARGUMENTS = [
        'Bash' => 'command',
        'Read' => 'file_path',
        'Edit' => 'file_path',
        'Write' => 'file_path',
        'Glob' => 'path',
        'Grep' => 'path',
        'Lsp' => 'path',
        'WebFetch' => 'url',
        'WebSearch' => 'query',
        'Skill' => 'name',
    ];

    /**
     * Tools whose subject argument is SHELL TEXT, and therefore gets the
     * segment treatment in {@see matchesShellSubject()}.
     *
     * A list rather than `$name === 'Bash'` so that the property being relied
     * on — "this string is parsed by a shell, so `;`/`&`/`|`/newline hide a
     * second command in it" — is named where a future tool can join it.
     *
     * @var list<string>
     */
    private const SHELL_SUBJECT_TOOLS = ['Bash'];

    /**
     * Tools whose subject argument is a FILESYSTEM PATH, and therefore gets the
     * normalisation treatment in {@see matchesPathSubject()}.
     *
     * The property being relied on, named for the same reason its shell sibling
     * above is: one file has MANY spellings (`./x`, `x`, `.//x`, `a/../x`,
     * `/abs/x`), so a pattern that matches one spelling of a path is not a rule
     * about that path. Derived from {@see SUBJECT_ARGUMENTS} by what the mapped
     * argument IS rather than by what its key is called — `Glob` and `Grep` map
     * to `path` and `Read`/`Edit`/`Write`/`Lsp` to `file_path`, and all six are
     * paths.
     *
     * The three that are in NEITHER list are the point of having lists at all:
     * `WebFetch`'s `url`, `WebSearch`'s `query` and `Skill`'s `name` are each
     * one opaque value with no separator and no alternative spellings this
     * process can enumerate, so they are matched literally. A url arguably has
     * spellings too (trailing slash, percent-encoding, a host's case) — it is
     * left literal because normalising it correctly is a URL parser's job, and
     * a half-normaliser here would be a claim of tightness that the
     * {@see \SugarCraft\Crush\Tools\BuiltIn\WebFetch} allowlist does not
     * need this class to make. The one exception is the `domain:` form
     * (`WebFetch(domain:github.com)`, audit F-P6), which does not normalise
     * the url but hands it to a real parser — {@see FetchTarget}, the same
     * one the tool dials by — and matches the host alone; see
     * {@see matchesFetchDomain()}.
     *
     * @var list<string>
     */
    private const PATH_SUBJECT_TOOLS = ['Read', 'Edit', 'Write', 'Glob', 'Grep', 'Lsp'];

    /**
     * How many symlinks {@see resolveOnDisk()} follows by hand before it stops —
     * Linux's `MAXSYMLINKS`, so a link loop ends where the kernel would end it.
     */
    private const MAX_SYMLINK_HOPS = 40;

    public function __construct(
        public readonly string $pattern,
        public readonly PermissionAction $action,
    ) {}

    /**
     * Is this pattern one the grammar above can parse?
     *
     * A thin `=== null` over {@see patternRejectionReason()} so there is one
     * grammar and not two. Called by
     * {@see \SugarCraft\Crush\Cli\Bootstrap::permissionRules()} so a typo is
     * reported on stderr rather than becoming a rule that quietly matches
     * nothing — which is precisely how the argument-scoped patterns in this
     * class's own documentation went unnoticed.
     */
    public static function isWellFormedPattern(string $pattern): bool
    {
        return self::patternRejectionReason($pattern) === null;
    }

    /**
     * WHY a pattern is rejected, in words a stderr line can use — or null when
     * it is not rejected.
     *
     * THE REASON EXISTS BECAUSE THE CALLER WAS ASSERTING THE WRONG ONE. The
     * first cut of the Bootstrap warning said "has an unbalanced parenthesis"
     * for every rejection, and measured, that was false for half of them:
     * `''` has no parenthesis at all and `'(rm *)'` has a balanced pair. Two
     * rejection reasons, one message naming one of them — the project's
     * recurring defect (a claim true of one domain written next to another)
     * inside a warning whose whole job is to tell a user what they got wrong.
     * Returning the reason from the class that owns the grammar is the only
     * shape in which the message cannot drift from the check again.
     */
    public static function patternRejectionReason(string $pattern): ?string
    {
        $open = strpos($pattern, '(');
        $closes = str_ends_with($pattern, ')');

        if ($open === false) {
            if ($pattern === '') {
                return 'is empty, so it names no tool';
            }

            // A trailing `)` with no opener is a typo, not a name.
            return $closes
                ? 'ends with `)` but never opens one, so its argument pattern has no start'
                : null;
        }

        if ($open === 0) {
            return 'starts with `(`, so it has no tool-name half to match a tool by';
        }

        if (!$closes) {
            // `strpos` gives the FIRST `(` and the close must be the LAST
            // character, so an argument glob may itself contain parentheses
            // (`Bash(echo (x))`).
            return 'opens `(` but does not end with `)`, so its argument pattern is unterminated';
        }

        return null;
    }

    /**
     * The tool-name half of the pattern — everything before the first `(` of a
     * well-formed argument-scoped pattern, or the whole pattern otherwise.
     */
    public function toolNamePattern(): string
    {
        if (!self::isWellFormedPattern($this->pattern)) {
            // Degrade to the literal, which matches no real tool name. See the
            // class doc-block on why a malformed pattern must not fail closed.
            return $this->pattern;
        }

        $open = strpos($this->pattern, '(');

        return $open === false ? $this->pattern : substr($this->pattern, 0, $open);
    }

    /**
     * The argument half, or null when this pattern is not argument-scoped —
     * which includes both a bare `Tool` and the degenerate `Tool()` spellings
     * the class doc-block defines as "any arguments".
     */
    public function argumentPattern(): ?string
    {
        if (!self::isWellFormedPattern($this->pattern)) {
            return null;
        }

        $open = strpos($this->pattern, '(');
        if ($open === false) {
            return null;
        }

        $inner = substr($this->pattern, $open + 1, -1);

        return trim($inner) === '' ? null : $inner;
    }

    /**
     * Glob a tool NAME against a name-half pattern.
     *
     * Static and public because {@see \SugarCraft\Crush\Cli\Bootstrap::tools()}
     * filters the model-facing tool set by name with the same dialect
     * (`allowedTools`/`disabledTools`), and two name matchers would be two
     * places for `mcp__git__*` to mean different things.
     */
    public static function matchesToolName(string $namePattern, string $toolName): bool
    {
        return fnmatch($namePattern, $toolName);
    }

    /**
     * The subject argument's NAME for a tool, or null when this process cannot
     * know it ({@see SUBJECT_ARGUMENTS}).
     */
    public static function subjectArgumentName(string $toolName): ?string
    {
        return self::SUBJECT_ARGUMENTS[$toolName] ?? null;
    }

    /**
     * Does this rule match a call?
     *
     * @param bool $argumentsKnown FALSE when the caller holds a
     *        {@see ToolDeclaration} rather than a real call, so the arguments
     *        are not merely absent but UNKNOWABLE. An argument-scoped rule then
     *        never matches, in either direction — see below.
     * @param string|null $projectRoot The workspace root the TOOL resolves a
     *        relative path against (`--root`). Only path subjects read it — see
     *        {@see matchesPathSubject()} — and null (or `''`) keeps the purely
     *        lexical matching every root-less caller had before audit F-J3.
     */
    public function matches(ToolCall $call, bool $argumentsKnown = true, ?string $projectRoot = null): bool
    {
        if ($call->name === self::PATCH_TOOL) {
            return $this->matchesPatch($call, $argumentsKnown, $projectRoot === '' ? null : $projectRoot);
        }

        if (!self::matchesToolName($this->toolNamePattern(), $call->name)) {
            return false;
        }

        $argumentPattern = $this->argumentPattern();
        if ($argumentPattern === null) {
            // A name-only rule; the name matched.
            return true;
        }

        // A DECLARATION IS NOT A CALL WITH MISSING ARGUMENTS, and this branch is
        // the bundle's central design decision: an argument-scoped rule does not
        // refuse a bare declaration, for any action.
        //
        // Both answers are defensible and this one is chosen on cost. Refusing
        // would mean a single `Deny Bash(rm -rf *)` in a user's config makes
        // EVERY workflow stage and agent preset that declares `Bash` unusable —
        // an over-block whose only fix is deleting the deny rule, and a security
        // control that gets deleted protects nothing. Not refusing leaves a
        // declaration through that a real call may still be denied for, which is
        // acceptable precisely because that per-call denial NOW WORKS: before
        // this class was rewritten it did not, and the same choice would have
        // been a fail-open. It also makes {@see PermissionGate::refuses()}'s
        // long-standing claim — "argument-sensitive rules cannot match a
        // declaration; left to the call site that has them" — true BY
        // CONSTRUCTION rather than by the accident of a broken matcher, and it
        // matches how the unconditional `rm -rf /` breaker already behaves on a
        // declaration for the identical reason.
        if (!$argumentsKnown) {
            return false;
        }

        $subjectName = self::subjectArgumentName($call->name);
        $subject = $subjectName === null ? null : ($call->arguments[$subjectName] ?? null);

        if (!is_string($subject)) {
            // UNKNOWABLE SUBJECT — an unmapped tool (`doctor`, any `mcp__*`), or
            // a mapped one whose subject argument is absent or not a string.
            // The class doc-block's asymmetry decides it: a restrictive rule
            // fires (fail closed, over-blocking that tool), a permissive one
            // does not (fail closed, falling through to the mode evaluator
            // rather than granting on a value nobody read).
            return $this->action !== PermissionAction::Allow;
        }

        return $this->matchesSubject(
            $subject,
            $argumentPattern,
            $call->name,
            $projectRoot === '' ? null : $projectRoot,
        );
    }

    /**
     * The one tool whose subject is SEVERAL paths: `ApplyPatch` (roadmap
     * 3.I-3) adds, changes, moves and deletes every file its patch names.
     */
    private const PATCH_TOOL = 'ApplyPatch';

    /**
     * The tools whose RESTRICTIVE rules also bind a patch. A patch is an
     * `Edit` and a `Write` by another name, so `Deny Edit(.env)` that let
     * `ApplyPatch` rewrite `.env` would be a deny with a second door.
     *
     * @var list<string>
     */
    private const PATCH_PROXY_TOOLS = ['Edit', 'Write'];

    /**
     * Does this rule match an `ApplyPatch` call?
     *
     * Two ways in. A rule naming `ApplyPatch` itself (`Deny ApplyPatch`,
     * `Allow ApplyPatch(src/*)`) binds it like any tool, its argument glob
     * read against each path. A restrictive rule naming `Edit` or `Write`
     * ({@see PATCH_PROXY_TOOLS}) binds it too, name-only or by path: the
     * patch writes files, and the user's `Deny Edit(.env)` is a statement
     * about `.env`, not about which tool spells the write. An `Allow` for
     * `Edit`/`Write` is NOT carried over — a grant covers what it names,
     * and a patch can also delete and move, which neither of them can.
     *
     * The class doc-block's asymmetry over the path list: a restrictive rule
     * fires when ANY path matches, an `Allow` only when EVERY path does. A
     * patch whose paths do not parse is an unknowable subject — a restrictive
     * argument-scoped rule fires (fail closed), a grant does not.
     */
    private function matchesPatch(ToolCall $call, bool $argumentsKnown, ?string $projectRoot): bool
    {
        $namePattern = $this->toolNamePattern();
        $named = self::matchesToolName($namePattern, self::PATCH_TOOL);
        if (!$named && $this->action !== PermissionAction::Allow) {
            foreach (self::PATCH_PROXY_TOOLS as $proxy) {
                if (self::matchesToolName($namePattern, $proxy)) {
                    $named = true;
                    break;
                }
            }
        }
        if (!$named) {
            return false;
        }

        $argumentPattern = $this->argumentPattern();
        if ($argumentPattern === null) {
            return true;
        }
        if (!$argumentsKnown) {
            return false;
        }

        $paths = PatchParser::paths($call->arguments['patch'] ?? null);
        if ($paths === null || $paths === []) {
            return $this->action !== PermissionAction::Allow;
        }

        foreach ($paths as $path) {
            $hit = $this->matchesPathSubject($path, $argumentPattern, $projectRoot);
            if ($this->action === PermissionAction::Allow && !$hit) {
                return false;
            }
            if ($this->action !== PermissionAction::Allow && $hit) {
                return true;
            }
        }

        return $this->action === PermissionAction::Allow;
    }

    /**
     * Match one subject string against the argument glob.
     *
     * THREE SUBJECT KINDS, dispatched on the tool name rather than on a bool,
     * because there are now three answers and a bool can only carry two:
     * shell text ({@see SHELL_SUBJECT_TOOLS}), a filesystem path
     * ({@see PATH_SUBJECT_TOOLS}), and everything else — a url, a search query,
     * a skill name — which is one opaque value and is matched literally after
     * whitespace normalisation.
     *
     * Whitespace is normalised — trimmed, and internal runs collapsed to one
     * space — in all three, so `rm   -rf  x` cannot walk past `rm -rf *`. That
     * widens every action's matching equally and is therefore safe in the
     * restrictive direction and, for `Allow`, a grant the user already spelled
     * out with single spaces; the alternative was a deny defeated by a double
     * space. The cost, stated because it is real and applies to paths too: a
     * file whose name genuinely contains a double space is matched under its
     * single-spaced spelling. That direction is safe for both actions — a deny
     * over-blocks, and an `Allow` written with the real double space simply
     * fails to fire — but it is a coercion, not an identity.
     */
    private function matchesSubject(
        string $subject,
        string $argumentPattern,
        string $toolName,
        ?string $projectRoot,
    ): bool {
        if (in_array($toolName, self::SHELL_SUBJECT_TOOLS, true)) {
            return $this->matchesShellSubject($subject, $argumentPattern);
        }

        if (in_array($toolName, self::PATH_SUBJECT_TOOLS, true)) {
            return $this->matchesPathSubject($subject, $argumentPattern, $projectRoot);
        }

        if ($toolName === 'WebFetch' && str_starts_with($argumentPattern, self::DOMAIN_PREFIX)) {
            return $this->matchesFetchDomain($subject, substr($argumentPattern, strlen(self::DOMAIN_PREFIX)));
        }

        return fnmatch($argumentPattern, self::collapseWhitespace($subject));
    }

    /**
     * The `domain:` form of a `WebFetch` argument pattern —
     * `WebFetch(domain:github.com)`, Claude Code's spelling, which
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} already
     * passes through from imported presets byte-identically.
     */
    private const DOMAIN_PREFIX = 'domain:';

    /**
     * Match a `WebFetch` URL's HOST against a `domain:` pattern (audit F-P6).
     *
     * WHY A HOST AND NOT THE URL GLOB: once `WebFetch` left the read-only
     * class, a user who trusts a site needs a way to say so, and a glob over
     * the whole URL is the wrong instrument for a grant —
     * `Allow WebFetch(https://github.com*)` also grants
     * `https://github.com.evil.example/` and `https://github.com@evil.example/`,
     * both of which dial `evil.example`, and even the slash-anchored spelling
     * is safe only while the glob and the URL parser agree on where a host
     * ends. A grant must not depend on that, so the host is read by
     * {@see FetchTarget}, the same parse the tool dials.
     *
     * The class doc-block's asymmetry again, twice:
     * - an UNPARSEABLE url (the tool would refuse it too) is an unknowable
     *   subject: a restrictive rule fires, a grant does not;
     * - a restrictive rule covers subdomains, a grant only what it spells —
     *   see {@see FetchTarget::matchesDomain()}.
     */
    private function matchesFetchDomain(string $url, string $domainPattern): bool
    {
        $target = FetchTarget::fromUrl(trim($url));
        if ($target === null) {
            return $this->action !== PermissionAction::Allow;
        }

        return $target->matchesDomain($domainPattern, includeSubdomains: $this->action !== PermissionAction::Allow);
    }

    /**
     * Match a SHELL subject: the whole command, and each command in a chain.
     *
     * The class doc-block's asymmetry decides the shape — union for
     * `Deny`/`Ask`, intersection for `Allow` — and the two arms read the
     * command differently because they fail in opposite directions.
     *
     * SPLIT FIRST, COLLAPSE PER SEGMENT, and that ORDER is the fix rather than
     * an implementation detail: collapsing whitespace before splitting turned
     * every newline into a space, so `echo hi\nrm -rf x` reached the matcher as
     * one command beginning `echo` and walked past `Deny Bash(rm -rf *)`.
     */
    private function matchesShellSubject(string $subject, string $argumentPattern): bool
    {
        $parsed = ShellWords::parse($subject);

        if ($this->action === PermissionAction::Allow) {
            return self::allowCoversShellSubject($parsed, $argumentPattern);
        }

        // UNION for the restrictive actions: ANY reading of ANY segment. The
        // whole-command reading is kept (unlike the Allow arm) because for a
        // deny a greedy `*` erring towards MORE matches is the safe direction,
        // and it is what makes a pattern that spans a separator
        // (`Deny Bash(* && rm *)`) work at all — a pattern no single segment
        // can match, since the split removed the `&&` it is written around.
        //
        // The raw `[;&|\r\n]` split is kept beside the quote-aware one because
        // it is the reading that still works when the line does NOT parse
        // (`ShellWords::$complete` false), and because over-splitting a quoted
        // `;` only ever adds readings — which for a deny is over-blocking, the
        // safe direction. The tokeniser's readings add the quote-removed words
        // (`'rm' -rf x` IS `rm -rf x` to the process) and the per-command
        // source text.
        $readings = [self::collapseWhitespace($subject)];
        foreach (preg_split('/[;&|\r\n]+/', $subject) ?: [] as $segment) {
            $readings[] = self::collapseWhitespace($segment);
        }
        foreach ($parsed->commands as $index => $words) {
            $readings[] = self::collapseWhitespace(implode(' ', $words));
            $readings[] = self::collapseWhitespace($parsed->sources[$index] ?? '');
        }

        foreach (array_unique($readings) as $reading) {
            if ($reading !== '' && fnmatch($argumentPattern, $reading)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `Allow` arm of {@see matchesShellSubject()}: FAIL CLOSED, so that a
     * grant written as `Bash(git *)` grants `git` commands and nothing the
     * glob's `*` happens to swallow (audit F-P5).
     *
     * Before this, the subject was split on `[;&|\r\n]` alone, and `*` matched
     * whatever stayed inside a segment. Measured under `dont-ask` with
     * `Allow Bash(git *)`: `git log $(python3 -c …)`, ``git log `id` `` and
     * `git log > /home/u/.bashrc` were all ALLOW — a command substitution runs
     * an arbitrary program BEFORE git starts, and a redirection writes a file
     * git never opens, so none of the three is "a git command" in any sense a
     * user granting `git *` meant. Four refusals now, in order:
     *
     * 1. A line {@see ShellWords} cannot parse completely (an unterminated
     *    quote or substitution, a dangling redirection) grants nothing: the
     *    split it would be judged by is a guess.
     * 2. A command / process substitution (`$(`, a backtick, `<(`, `>(`) or a
     *    `${…}` / `$[…]` expansion (which can run a substitution held in a
     *    variable's value) anywhere outside single quotes grants nothing —
     *    single-quoted text is literal, so `git log --format='$(x)'` is fine.
     * 3. A redirection that is not inert ({@see ShellWords::isInertRedirection()}:
     *    `2>/dev/null`, `2>&1` and `< file` are; `> file`, `>> file`, `<>`
     *    and a here-doc are not) grants nothing.
     * 4. EVERY simple command must match the glob — the intersection — split on
     *    UNQUOTED operators only, so `git commit -m "a; b"` is one command (the
     *    regex split made it two and refused it) while `git log && rm x` is
     *    still two and refused.
     *
     * "UNLESS THE RULE EXPLICITLY COVERS IT": refusals 2 and 3 are lifted for a
     * construct whose own operator text appears in the pattern — a user who
     * writes `Allow Bash(git log > /tmp/*)` or `Allow Bash(echo $(date))` asked
     * for exactly that. The segments are then matched against their RAW source
     * only, because the quote-removed word list has the redirection removed
     * and the quotes stripped: matched against it, `git log '>' /tmp/x 2> f`
     * would read `git log > /tmp/x` and grant the real `2> f` on the strength
     * of a quoted literal. Without such a construct either reading may grant —
     * the words (`'git' log` IS `git log`) or the source (`git commit -m "*"`
     * is a pattern written with quotes) — since both then describe one plain
     * program invocation and differ only in quoting.
     *
     * The honest limit: this is per RULE. `Allow Bash(git *)` plus
     * `Allow Bash(grep *)` does not grant `git log | grep x`, because rules are
     * first-match-wins and no one rule covers both commands; spell such a
     * pipeline as its own rule.
     */
    private static function allowCoversShellSubject(ShellWords $parsed, string $argumentPattern): bool
    {
        if (!$parsed->complete || $parsed->commands === []) {
            return false;
        }

        $rawOnly = false;
        foreach ([...$parsed->substitutions, ...$parsed->parameterExpansions] as $opener) {
            if (!str_contains($argumentPattern, $opener)) {
                return false;
            }
            $rawOnly = true;
        }
        foreach ($parsed->redirections as $redirection) {
            if (ShellWords::isInertRedirection($redirection)) {
                continue;
            }
            if (!str_contains($argumentPattern, $redirection['op'])) {
                return false;
            }
            $rawOnly = true;
        }

        foreach ($parsed->commands as $index => $words) {
            $source = self::collapseWhitespace($parsed->sources[$index] ?? '');
            if ($source !== '' && fnmatch($argumentPattern, $source)) {
                continue;
            }
            $dequoted = self::collapseWhitespace(implode(' ', $words));
            if (!$rawOnly && $dequoted !== '' && fnmatch($argumentPattern, $dequoted)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Match a PATH subject.
     *
     * THREE STEPS THAT ARE NOT THE SAME KIND OF THING, and keeping them apart is
     * what lets each go only where it is safe:
     *
     * 1. LEXICAL NORMALISATION of both the pattern and the subject
     *    ({@see normalisePath()}). This maps different spellings of the SAME
     *    path onto one — `./x`, `x`, `.//x` and `a/../x` are one file by
     *    definition, not by policy — so it applies to `Allow` as well. It is
     *    not a widening; it is the removal of a distinction that was never
     *    real. It is also what closes the embarrassment in this class's own
     *    advertised example: `Deny Read(./.env)` used to miss `.env`, and `.env`
     *    is the spelling a model actually emits.
     *
     * 2. THE DEPTH READING, restrictive actions only: a RELATIVE pattern also
     *    matches at any depth, so `Deny Read(.env)` covers
     *    `/home/u/proj/.env`. This one DOES map different paths together, so it
     *    is a widening, and the class doc-block's asymmetry decides where a
     *    widening may go — a union reading for `Deny`/`Ask`, never for `Allow`.
     *    Without the split, `Allow Read(.env)` would have granted `/etc/.env`.
     *    An ABSOLUTE pattern is excluded because the user anchored it
     *    themselves; reading `/etc/passwd` as "any `etc/passwd` at any depth"
     *    would be inventing an intent the leading `/` denies.
     *
     * 3. THE TOOL'S OWN READING, only when a root is supplied (audit F-J3).
     *    The tools resolve a relative path against `--root` and then open
     *    whatever the filesystem says it names
     *    ({@see \SugarCraft\Crush\Tools\PathJail::resolve()}), so steps 1-2
     *    alone judged a string the tool never opens: with
     *    `Deny Read(/proj/secret.txt)`, `secret.txt` and `./secret.txt` were
     *    ALLOWED (measured), and so was a symlink `notes -> secret.txt` under
     *    any path deny at all. The extra spellings are the call anchored at the
     *    root, and where it really lands on disk — {@see resolvedPathSpellings()},
     *    the same realpath-or-realpath(parent) move as
     *    {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook}.
     *
     * WHY THIS NOW TOUCHES THE FILESYSTEM, when this block used to promise it
     * never would. The promise rested on two worries — a decision that depends
     * on the process's cwd, and a gate racing the tool it gates — and neither
     * survives contact with the alternative. The root is passed in, not read
     * from `getcwd()` (a RELATIVE root is resolved the way the tool resolves
     * it, which is against the cwd — mirroring the tool is the point), and no
     * root means no resolution, so a root-less caller decides exactly as
     * before. The race is real but one-sided: it can only make the gate judge a
     * spelling the tool then does not open, and the tool's own jail resolves
     * again at use. Against that, a deny that a symlink defeats is not a rule
     * about the FILE — it is a rule about one of the file's names, and the
     * model picks the name.
     *
     * THE ASYMMETRY, applied to the new spellings:
     *
     * - `Deny` / `Ask` fire on the UNION — any lexical spelling, any resolved
     *   one, each with the depth reading. Over-blocking (a deny catching a
     *   symlink the user did not think of) is the safe direction.
     * - `Allow` fires on the INTERSECTION: a lexical spelling (the call's own,
     *   or the call anchored at the root, so `Allow Write(/proj/src/*)` covers
     *   `src/a.php`) must match AND a resolved spelling must match too. So a
     *   symlink cannot LAUNDER a grant — `src/link -> /etc/passwd` passes the
     *   lexical half of `Allow Write(/proj/src/*)` and fails the resolved half,
     *   and falls through to the mode. The cost, stated because it is real: a
     *   symlink inside an allowed tree whose target lies outside it is no
     *   longer granted by the rule, even when the user meant it to be; it gets
     *   the mode's answer (usually a prompt) instead. The resolved half also
     *   uses only the absolute real path, the real path re-spelled under the
     *   root as the caller wrote it, and the root-relative remainder — never
     *   the depth reading — so it narrows and never widens.
     */
    private function matchesPathSubject(string $subject, string $argumentPattern, ?string $projectRoot): bool
    {
        $pattern = self::normalisePath($argumentPattern);
        $lexical = self::normalisePath(self::collapseWhitespace($subject));

        if ($projectRoot === null) {
            return $this->matchesAnyPathSpelling($pattern, [$lexical]);
        }

        $lexicalSpellings = [$lexical];
        if (!str_starts_with($lexical, '/')) {
            $lexicalSpellings[] = self::normalisePath($projectRoot . '/' . $lexical);
        }
        $resolvedSpellings = self::resolvedPathSpellings($subject, $projectRoot);

        if ($this->action === PermissionAction::Allow) {
            return $this->matchesAnyPathSpelling($pattern, $lexicalSpellings)
                && $this->matchesAnyPathSpelling($pattern, $resolvedSpellings);
        }

        return $this->matchesAnyPathSpelling($pattern, [...$lexicalSpellings, ...$resolvedSpellings]);
    }

    /**
     * Does ANY spelling match — exactly, or (restrictive action, relative
     * pattern) at any depth? Step 2 of {@see matchesPathSubject()}, applied
     * per spelling.
     *
     * @param list<string> $spellings normalised path spellings
     */
    private function matchesAnyPathSpelling(string $pattern, array $spellings): bool
    {
        $depth = $this->action !== PermissionAction::Allow && !str_starts_with($pattern, '/');

        foreach (array_unique($spellings) as $spelling) {
            if (fnmatch($pattern, $spelling)) {
                return true;
            }
            if (!$depth) {
                continue;
            }
            foreach (self::trailingPathSuffixes($spelling) as $suffix) {
                if (fnmatch($pattern, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Where the TOOL will land for this subject, spelled every way a pattern
     * might name it: the absolute real path, and — when it lies inside the
     * root — the same path re-spelled under the root AS THE CALLER WROTE IT,
     * plus the root-relative remainder.
     *
     * The re-spelling is what keeps a symlinked root working: with `--root
     * /home/u/proj` where `proj -> /srv/proj`, the real path of `secret.txt` is
     * `/srv/proj/secret.txt`, while the user wrote their deny as
     * `Read(/home/u/proj/secret.txt)` — so the real path alone would miss it.
     *
     * Anchored at the REAL root exactly as {@see \SugarCraft\Crush\Tools\PathJail::resolve()}
     * anchors it. Returns nothing for a value no filesystem call can take (a
     * NUL byte), which leaves a deny with its lexical spellings and an `Allow`
     * with no resolved half — no grant, the fail-closed direction.
     *
     * @return list<string>
     */
    private static function resolvedPathSpellings(string $subject, string $projectRoot): array
    {
        if (str_contains($subject, "\0") || str_contains($projectRoot, "\0")) {
            return [];
        }

        $rootAbsolute = str_starts_with($projectRoot, '/')
            ? $projectRoot
            : (getcwd() ?: '') . '/' . $projectRoot;
        $rootReal = self::resolveOnDisk($rootAbsolute);

        $resolved = self::resolveOnDisk(
            str_starts_with($subject, '/') ? $subject : $rootReal . '/' . $subject,
        );
        $spellings = [$resolved];

        $prefix = rtrim($rootReal, '/') . '/';
        if ($resolved === $rootReal || str_starts_with($resolved, $prefix)) {
            $relative = $resolved === $rootReal ? '' : substr($resolved, strlen($prefix));
            $spellings[] = self::normalisePath($projectRoot . '/' . $relative);
            $spellings[] = $relative;
        }

        return $spellings;
    }

    /**
     * The canonical absolute path an ABSOLUTE path names, whether or not the
     * leaf (or several trailing segments) exists yet.
     *
     * `realpath()` when it answers; otherwise the nearest existing ancestor is
     * canonicalised and the missing remainder re-attached lexically — the walk
     * {@see \SugarCraft\Crush\Tools\PathJail::resolveForCreate()} does for a
     * `Write` into a directory that does not exist yet. A DANGLING symlink on
     * the way is followed to its target (a `Write` through it creates the
     * target, so the target is what the write is about), bounded by the
     * kernel's own `MAXSYMLINKS` so a link loop terminates.
     */
    private static function resolveOnDisk(string $absolute, int $hops = 0): string
    {
        $real = realpath($absolute);
        if ($real !== false) {
            return $real;
        }

        $tail = [];
        $probe = $absolute;
        while (true) {
            if ($hops < self::MAX_SYMLINK_HOPS && is_link($probe)) {
                $target = readlink($probe);
                if ($target !== false) {
                    $base = str_starts_with($target, '/') ? $target : dirname($probe) . '/' . $target;

                    return self::resolveOnDisk(
                        $tail === [] ? $base : $base . '/' . implode('/', array_reverse($tail)),
                        $hops + 1,
                    );
                }
            }

            $parent = dirname($probe);
            if ($parent === $probe) {
                return self::normalisePath($probe . '/' . implode('/', array_reverse($tail)));
            }

            $tail[] = basename($probe);
            $probe = $parent;

            $real = realpath($probe);
            if ($real !== false) {
                // The remainder may still hold `..`; normalising it lexically is
                // sound here because it is applied on top of a REAL path, which
                // has no symlink left for a `..` to climb out of.
                return self::normalisePath($real . '/' . implode('/', array_reverse($tail)));
            }
        }
    }

    /**
     * Resolve `.`, `..`, `//` and a leading `./` LEXICALLY, preserving whether
     * the path was absolute.
     *
     * Applied to the PATTERN as well as to the subject, which is the only way
     * the two can be compared at all: normalising one side would make
     * `Read(./.env)` unable to match its own normalised subject. Glob
     * metacharacters survive because they never appear as a whole segment that
     * this function removes — `secrets/*` normalises to `secrets/*`.
     *
     * A leading `..` on a relative path is KEPT (`../x` cannot be resolved
     * without a cwd, and this function has none), and a `..` that would climb
     * above `/` on an absolute path is dropped, since there is nothing above it.
     */
    private static function normalisePath(string $value): string
    {
        $absolute = str_starts_with($value, '/');
        $out = [];

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);

                    continue;
                }

                if ($absolute) {
                    continue;
                }

                $out[] = '..';

                continue;
            }

            $out[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $out);
    }

    /**
     * Every SEGMENT-ALIGNED trailing suffix of a normalised path, shortest
     * spelling last, excluding the whole path (the caller already tried that).
     *
     * Segment-aligned rather than substring: `/x/notes.env` must not be read as
     * containing `.env`, or `Deny Read(.env)` would over-block every file whose
     * name merely ends that way. That is the difference between "the same file
     * spelled differently" and "a different file".
     *
     * @return list<string>
     */
    private static function trailingPathSuffixes(string $path): array
    {
        $segments = explode('/', ltrim($path, '/'));
        $suffixes = [];

        for ($i = 1, $count = count($segments); $i < $count; ++$i) {
            $suffixes[] = implode('/', array_slice($segments, $i));
        }

        return $suffixes;
    }

    private static function collapseWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
