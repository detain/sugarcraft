<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\ToolCall;

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
 * PATH subjects (`Read`, `Edit`, `Write`, `Glob`, `Grep`, `Lsp`). Closed by
 * {@see matchesPathSubject()}: the `./` prefix, `//` runs, and `.`/`..`
 * segments, all normalised away on BOTH sides — so `Deny Read(./.env)` now also
 * covers `.env`, `.//.env` and `./foo/../.env`, which it did not before and
 * which are the spellings a model is likelier to emit than the documented one.
 * Additionally, for a RESTRICTIVE rule only, a relative pattern reads as "at
 * any depth", so that same deny covers `/home/u/proj/.env`. NOT closed: a
 * symlinked or hard-linked spelling of the same file, a bind mount, and — the
 * mirror image of the depth reading — an ABSOLUTE pattern (`Deny Read(/etc/*)`)
 * against a relative call spelling that resolves there. Nothing here touches
 * the filesystem, deliberately: a matcher that stat'ed every candidate would
 * make the decision depend on the process's cwd and on races with the tool it
 * is gating.
 *
 * So treat any `Tool(...)` deny as a guard rail against the model doing
 * something by ACCIDENT, not as a containment boundary against something trying
 * to get past it. The boundaries that do not depend on a spelling are
 * {@see PermissionMode::Plan}, which refuses whole tool KINDS, and the path
 * jails, which resolve. Be precise about one that reads like a third and is
 * not: the unconditional `rm -rf /` breaker in {@see PermissionGate} is
 * mode-independent, but it reads `arguments['command']` and tokenises it, so it
 * is shell-text matching too and `/bin/rm -rf /` is past it. (Its own
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
     * need this class to make.
     *
     * @var list<string>
     */
    private const PATH_SUBJECT_TOOLS = ['Read', 'Edit', 'Write', 'Glob', 'Grep', 'Lsp'];

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
     */
    public function matches(ToolCall $call, bool $argumentsKnown = true): bool
    {
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

        return $this->matchesSubject($subject, $argumentPattern, $call->name);
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
    private function matchesSubject(string $subject, string $argumentPattern, string $toolName): bool
    {
        if (in_array($toolName, self::SHELL_SUBJECT_TOOLS, true)) {
            return $this->matchesShellSubject($subject, $argumentPattern);
        }

        if (in_array($toolName, self::PATH_SUBJECT_TOOLS, true)) {
            return $this->matchesPathSubject($subject, $argumentPattern);
        }

        return fnmatch($argumentPattern, self::collapseWhitespace($subject));
    }

    /**
     * Match a SHELL subject: the whole command, and each command in a chain.
     *
     * SPLIT FIRST, COLLAPSE PER SEGMENT — and that ORDER is the fix rather than
     * an implementation detail. The first cut collapsed whitespace over the
     * whole subject and then split on `/[;&|]+/`, which meant
     * `preg_replace('/\s+/', ' ')` had already turned every newline into a
     * space before the splitter could see it. A newline separates two commands
     * exactly as `;` does, so `echo hi\nrm -rf x` reached the matcher as one
     * command beginning `echo` and walked past `Deny Bash(rm -rf *)` — measured
     * as `Ask` where the `&&` spelling of the same thing was `Deny`. The
     * separator class therefore includes `\r` and `\n`, and the raw subject is
     * what gets split.
     *
     * Then the class doc-block's asymmetry applies — union for `Deny`/`Ask`,
     * intersection for `Allow`. The same `preg_split()` class is used by
     * {@see PermissionGate::isRmRfRootOrHome()}, for the same reason.
     */
    private function matchesShellSubject(string $subject, string $argumentPattern): bool
    {
        $segments = [];
        foreach (preg_split('/[;&|\r\n]+/', $subject) ?: [] as $segment) {
            $segment = self::collapseWhitespace($segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        if ($this->action === PermissionAction::Allow) {
            // INTERSECTION, and deliberately WITHOUT an "or the whole command
            // matched" escape hatch: `fnmatch('git *', 'git log && rm -rf /')`
            // is true, so a whole-command match is exactly the evidence that
            // must not be enough to grant. An empty segment list (a command of
            // nothing but separators) grants nothing.
            if ($segments === []) {
                return false;
            }

            foreach ($segments as $segment) {
                if (!fnmatch($argumentPattern, $segment)) {
                    return false;
                }
            }

            return true;
        }

        // UNION for the restrictive actions: the whole command, or any one
        // segment of it. The whole-command reading is kept here (unlike the
        // Allow arm) because for a deny a greedy `*` erring towards MORE
        // matches is the safe direction, and it is what makes a pattern that
        // spans a separator (`Deny Bash(* && rm *)`) work at all — a pattern no
        // single segment can ever match, since the split removed the `&&` the
        // pattern is written around.
        if (fnmatch($argumentPattern, self::collapseWhitespace($subject))) {
            return true;
        }

        foreach ($segments as $segment) {
            if (fnmatch($argumentPattern, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a PATH subject.
     *
     * TWO STEPS THAT ARE NOT THE SAME KIND OF THING, and keeping them apart is
     * what lets one apply to every action and the other only to the restrictive
     * ones:
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
     * NOTHING HERE TOUCHES THE FILESYSTEM. No `realpath()`, no `getcwd()`: a
     * gate whose decision depended on the process's working directory would
     * decide differently for the same call in two sessions, and one that
     * resolved a symlink would be racing the tool it is gating. The cost is the
     * limit named in the class doc-block — a symlinked spelling is not caught —
     * and it is the correct thing to lose here rather than in the path jails,
     * which resolve because containment is their entire job.
     */
    private function matchesPathSubject(string $subject, string $argumentPattern): bool
    {
        $pattern = self::normalisePath($argumentPattern);
        $path = self::normalisePath(self::collapseWhitespace($subject));

        if (fnmatch($pattern, $path)) {
            return true;
        }

        if ($this->action === PermissionAction::Allow || str_starts_with($pattern, '/')) {
            return false;
        }

        foreach (self::trailingPathSuffixes($path) as $suffix) {
            if (fnmatch($pattern, $suffix)) {
                return true;
            }
        }

        return false;
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
