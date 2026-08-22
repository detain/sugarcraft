<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\IgnoreRules;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Grep implements Tool, ParallelSafe, CarriesSessionState
{
    use CapturesProcessOutput;
    use TruncatesOutput;

    /**
     * $maxOutputBytes bounds the hit list. `grep -rn` over a real tree has no
     * ceiling of its own: a common identifier, or a pattern that happens to
     * match minified assets or a lockfile, returns tens of thousands of lines
     * that would otherwise be replayed into every following request of the
     * turn. Zero or negative disables the cap.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private ?InstructionFileLoader $instructionLoader = null,
        private ?SkillPathNudge $skillNudge = null,
    ) {}

    /**
     * Still concurrency-safe, but NOT for the reason this docblock used to
     * give. It said:
     *
     *   "Unconditionally concurrency-safe: `grep -rn` reads, and this tool
     *    holds no session-scoped state for a fork to strand (contrast
     *    `Read`/`Glob`, which carry the announce-once collaborators)."
     *
     * BOTH halves of that were wrong once the collaborators arrived, and the
     * second half was ALREADY wrong before they did. `Read` and `Glob` carry
     * the announce-once collaborators AND both return true here — so carrying
     * them was never the thing that would have cost a tool its verdict, and
     * "contrast Read/Glob" pointed at two tools that do not in fact contrast.
     *
     * {@see ParallelSafe} states the real rule in its point 2: session-scoped
     * state is allowed in a group PROVIDED it survives the fork. There are two
     * ways to satisfy that — hold none, or implement
     * {@see CarriesSessionState}. This tool used to satisfy it the first way
     * and now satisfies it the second, which is why the verdict is unchanged
     * while its justification is not.
     *
     * So the promise is CONDITIONAL on the export/merge pair below, and that
     * is the load-bearing sentence: delete {@see exportSessionState()} and
     * this `true` becomes a lie that nothing in the type system catches — the
     * `CarriesSessionState` implements-clause would go with it and
     * {@see \SugarCraft\Crush\Runtime} would silently stop asking. Grep would
     * still be side-effect-free, and a `CLAUDE.md` surfaced by a forked Grep
     * would re-surface on the next call for the rest of the session.
     * `GrepInstructionWiringTest` pins that pair rather than the verdict.
     *
     * Point 1 of {@see ParallelSafe} is unaffected and unchanged: `grep -rn`
     * reads, mutates nothing a sibling could observe, and terminates on its
     * own.
     */
    public function isParallelSafe(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * @see Read::exportSessionState() — deliberately the same two keys. All
     *      loader-carrying tools share ONE loader and ONE nudge tracker (see
     *      {@see \SugarCraft\Crush\Cli\Bootstrap::tools()}), so a key one of
     *      them exported and another did not would leave that half
     *      re-announcing forever.
     */
    public function exportSessionState(): array
    {
        return [
            'emittedInstructionPaths' => $this->instructionLoader?->emittedPaths() ?? [],
            'announcedSkills' => $this->skillNudge?->announced() ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mergeSessionState(array $state): void
    {
        $paths = $state['emittedInstructionPaths'] ?? null;
        if (is_array($paths)) {
            $this->instructionLoader?->markEmitted(array_values($paths));
        }

        $skills = $state['announcedSkills'] ?? null;
        if (is_array($skills)) {
            $this->skillNudge?->markAnnounced(array_values($skills));
        }
    }

    public function name(): string
    {
        return 'Grep';
    }

    /**
     * The ignore behaviour is stated up front for the same reason `Glob`'s
     * prune list is: a default the model only learns about AFTER it has
     * already asked the wrong question costs a turn, and "no hits" in a tree
     * that was never searched is indistinguishable from "no hits".
     *
     * The regex dialect is stated for the same reason and is the one clause a
     * model is most likely to get wrong unprompted: {@see execute()} builds
     * `grep -rn` with NO `-E` and NO `-P`, so the pattern is GNU basic
     * regular expression (BRE) syntax. crush_code.md section 12 asserted ERE
     * here; that is wrong, and the difference is not cosmetic — in BRE `a|b`
     * matches the literal three characters `a|b`, and `(a)` and `a+` match
     * their own punctuation rather than grouping or repeating.
     *
     * GNU, not "POSIX", and the qualifier is load-bearing rather than pedantic:
     * the escaped operators the text goes on to recommend — `\|`, `\+`, `\?` —
     * are GNU EXTENSIONS. Strict POSIX BRE has no alternation operator at all,
     * so saying "POSIX basic" and then instructing the model to escape its way
     * to alternation describes a dialect that does not exist. The host binary
     * here is GNU grep 3.11, and the escaped forms are proven against it in
     * `ToolDescriptionGuidanceTest`.
     *
     * The no-matches clause names the scoring rule `execute()` actually
     * applies (`isError: exitCode > 1`), which the model cannot otherwise
     * distinguish from a failed search.
     */
    public function description(): string
    {
        return 'Search for a pattern in files, recursively. The pattern is a GNU basic '
            . 'regular expression — this runs `grep -rn`, not PCRE — so `|`, `+`, `?`, `(`, '
            . '`)`, `{` and `}` match themselves unless backslash-escaped. Use include to '
            . 'scope by filename glob (e.g. "*.php"). Finding nothing is a normal result, '
            . 'not an error; only grep itself failing is reported as one. Skips '
            . implode(', ', IgnoreRules::DEFAULT_EXCLUDED_DIRS)
            . ' and anything the project\'s .gitignore excludes; pass include_ignored: true to search those too.';
    }

    public function inputSchema(): array
    {
        // Only a rooted instance is contained, so only a rooted instance says
        // so — the unrooted one (a test, an embedder) genuinely accepts any
        // readable directory.
        $pathScope = $this->root !== null ? ' Must be inside the workspace root.' : '';

        return [
        'type' => 'object',
        'properties' => [
            // Reconciled with description(): the schema used to say only
            // "regex pattern", which a model reasonably reads as PCRE. It is
            // GNU BRE, and the two strings must not disagree about that.
            'pattern' => ['type' => 'string', 'description' => 'The pattern to search for, as a GNU basic regular expression (BRE). Escape |, +, ?, ( and ) to use them as operators rather than literals.'],
            'path' => ['type' => 'string', 'description' => 'Directory path to search in.' . $pathScope],
            'include' => ['type' => 'string', 'description' => 'Filename glob limiting which files are searched (e.g., *.php). Defaults to every file.'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this search looks for (e.g. "Locate callers of describeToolCall", not "greps a regex").',
            ],
            'include_ignored' => [
                'type' => 'boolean',
                'description' => 'Search files the project\'s .gitignore excludes, plus vendor/node_modules-style directories. Off by default; the result says when something was hidden.',
            ],
        ],
        'required' => ['pattern', 'path', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '';
        $include = $args['include'] ?? '*';

        if ($pattern === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: pattern cannot be empty',
                isError: true,
            );
        }

        // realpath() THROWS a ValueError on a NUL byte rather than failing, so
        // an unguarded NUL left execute() as an uncaught crash. PathJail also
        // rejects it now, but say so in this tool's own vocabulary instead of
        // reporting a malformed argument as a containment verdict.
        if (str_contains($path, "\0")) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: path contains a NUL byte',
                isError: true,
            );
        }

        if ($this->root !== null) {
            $resolved = PathJail::resolveDir($this->root, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        } elseif (!is_dir($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: directory not found: $path",
                isError: true,
            );
        }

        $rules = $this->rulesFor($path, self::flag($args['include_ignored'] ?? null));

        $cmd = 'grep -rn';
        // `-r`, deliberately, never `-R`: -r follows a symlink only when it is
        // named on the command line, which is exactly the bounded hatch
        // IgnoreRules::halts() documents for Glob. -R follows every link it
        // meets, and this monorepo's path-repo symlinks make that a
        // non-terminating walk rather than a slow one.
        $cmd .= $rules->grepExcludeFlags();
        if ($include !== '*') {
            $cmd .= ' --include=' . escapeshellarg($include);
        }
        $cmd .= ' ' . escapeshellarg($pattern) . ' ' . escapeshellarg($path);
        // See Bash::execute() -- exec() leaks the child's stderr onto the
        // terminal underneath the TUI. grep exits 1 for "no matches", which
        // is a normal outcome rather than an error.
        $run = $this->runCaptured($cmd, null, $this->maxOutputBytes > 0 ? $this->maxOutputBytes : null);

        $filtered = self::withoutIgnoredHits($run, $rules, $path);

        $merged = $this->mergeCapturedOutput($filtered['run']);

        // The instruction section is budgeted FIRST, exactly as
        // {@see TruncatesOutput::truncateMerged()} budgets a failing command's
        // stderr first, and for the mirror-image reason: there the risk is that
        // the noise pushes the ANSWER off the end, here it is that the rules
        // push the answer off the end. Reserving a quarter up front means the
        // hit list keeps three quarters of the cap no matter how large the
        // governing CLAUDE.md is.
        //
        // MEASURED at 4a4ecb98 against the fixture in
        // `ToolOutputBudgetTest`: a 9,611-byte `sub/CLAUDE.md` over five
        // matched files returned 10,096 bytes against a 400-byte cap (25.2x)
        // before this reservation existed, because the bodies were appended
        // AFTER the clip and so were never inside the budget at all.
        $ceiling = $this->instructionSectionCeiling($this->maxOutputBytes);

        // COMPUTED BEFORE THE BODY IS CLIPPED, and that ordering is the whole
        // of the second half of E66. The nudge used to be built after the clip
        // and appended beside it, so the cap bounded the hit list and not the
        // result: MEASURED at 8add627b over a 30-file fixture, cap 1,000 with
        // one path-scoped skill carrying a 200-byte description returned 1,334
        // bytes (1.3x), five skills x 5,000 returned 26,182 (26.2x) and twenty
        // x 20,000 returned 401,372 (401.4x). Building it first turns its
        // length into a reservation the hit list pays for, so the total lands
        // inside $maxOutputBytes.
        //
        // Its INPUT is unchanged — every path with a hit in the full stdout,
        // not the paths that survived the clip — for the reason argued where
        // it is appended below.
        //
        // Nothing is lost by building it early: forPaths() returns null unless
        // it has something new to say, and a null costs nothing.
        //
        // AN EIGHTH of the cap, where the instruction section takes a quarter,
        // so the hit list still keeps at least five eighths of what it was
        // asked for. A budget too small for one entry surfaces nothing and
        // SPENDS nothing, so the skill is announced by the next call with room
        // rather than retired unseen — the same resolution
        // {@see TruncatesOutput::instructionSection()} takes for a reserve
        // that cannot hold one body. An uncapped Grep passes null and gets the
        // class's own ceiling.
        $nudge = $this->skillNudge?->forPaths(
            self::hitFiles($filtered['run']['stdout'], $path),
            $this->maxOutputBytes > 0 ? intdiv($this->maxOutputBytes, 8) : null,
        );

        // +1 for the newline separated() adds. Charged against the cap even
        // where the body already ends on one, because over-reserving by a byte
        // keeps the total inside the cap and under-reserving does not.
        $nudgeCost = $nudge === null ? 0 : strlen($nudge) + 1;

        // The cap the BODY may spend, which is the whole cap on every call
        // that produces no nudge — announce-once means that is every call
        // after the first touch of a scoped path, and every call of a Grep
        // built with no nudge tracker at all.
        //
        // max(1, ...) and not the raw subtraction: 0 is truncateMerged()'s
        // "no cap" sentinel, so a cap the nudge alone exceeds would hand the
        // body an UNBOUNDED budget — the same knife-edge instructionSection()
        // guards, one reservation over.
        $bodyCap = $this->maxOutputBytes > 0
            ? max(1, $this->maxOutputBytes - $nudgeCost)
            : 0;

        // The probe exists to answer ONE question — which hits will still be
        // in the result once the instruction section has taken its share — and
        // it is deliberately clipped to the SMALLEST budget the final content
        // can be given. The final clip is therefore never tighter than this
        // one, so every path read off the probe is guaranteed to be in what
        // the model receives. That is what keeps the announce-once mark honest
        // now that the clip depends on the instructions and the instructions
        // depend on the clip: the cycle is broken by probing at the floor
        // rather than by clipping twice and hoping the second cut agrees with
        // the first.
        //
        // See Bash::execute() for why the merge's account is what gets clipped
        // rather than the capture's raw byte totals.
        // max(1, ...) and not max(0, ...): 0 is truncateMerged()'s "no cap"
        // sentinel, so a cap small enough that a quarter rounds the floor to
        // zero would hand the probe an UNBOUNDED budget. Grep is spared the
        // consequence Glob measured only because its capture is pre-bounded by
        // runCaptured(); the guard belongs here anyway, since a guarantee that
        // holds by accident in one tool is not a guarantee.
        // The floor is computed from instructionSectionCeiling() and NOT from
        // the reserve, and the difference only shows at small caps: where a
        // quarter cannot hold one body floor the section is allowed to spend
        // that floor instead, because a rule governing a path the model was
        // shown has to be surfaced somewhere. Sizing the floor with the same
        // ceiling is what keeps the total inside the cap anyway — the result
        // simply gives up the difference — and what keeps this probe no
        // tighter than the final clip below, which is the property the
        // announce-once mark depends on.
        $floor = $this->maxOutputBytes > 0
            ? max(1, $bodyCap - $ceiling - 1)
            : 0;
        $probe = $this->truncateMerged($merged, $floor);

        $section = '';
        if ($this->instructionLoader !== null) {
            $hitFiles = self::hitFiles($probe, $path);

            // LABELLED, where Read emits the body raw. Read puts it where
            // position alone explains it — at the top of the one file being
            // read. Here it lands after a run of `... [note]` lines at the end
            // of a record stream, and an unlabelled markdown blob in that
            // position is indistinguishable from tool output that failed to
            // parse. Glob composes the identical section from the identical
            // helper, for the same reason and in the same place: the two tools
            // answer the same shape of question and used to disagree about
            // where the rules go.
            //
            // Bounded in COUNT as well as in bytes, and the count is decided
            // BEFORE any body is loaded, so a path whose rules do not fit is
            // not retired for the session — see
            // {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput::instructionSection()}.
            $section = $this->instructionSection($hitFiles, $this->instructionLoader, $this->maxOutputBytes);
        }

        // Clipped at the FLOOR whenever a rule was surfaced, and NOT at what
        // the section happened to leave over. Spending the leftover would show
        // more paths than the probe examined, and the rules of those extra
        // paths are then neither announced nor spent — harmless, but it makes
        // "the announce-once mark is spent on exactly what the model receives"
        // an accident of how big the section came out rather than a law.
        // MEASURED with the leftover spent, over
        // `GrepInstructionWiringTest`'s six-directory fixture at cap 1024: the
        // final clip showed aaa, bbb and fff where the probe had examined only
        // bbb. Pinning the result at the floor makes probe and final clip the
        // same cut, so what is ANNOUNCED is drawn from exactly the set that is
        // VISIBLE. It is a containment and not an equality — the count bound
        // can still withhold a path the probe examined, and says so in the
        // result — but the direction that matters is closed: nothing is ever
        // announced for a path the model cannot see.
        //
        // It costs only the calls that actually surface a rule, and only the
        // difference between the cap and the three-quarter floor the split
        // promises. Under announce-once that is the first touch of a
        // directory; every call after it has no section and takes the whole
        // cap, byte-identical to the same tool built with no loader at all.
        $content = $section === ''
            ? $this->truncateMerged($merged, $bodyCap)
            : $probe;

        $skipped = self::presentExcludedDirs($path, $rules);
        if ($skipped !== []) {
            $content = self::separated($content);
            $content .= sprintf(
                "... [skipped: %s were not searched. Point path inside one to search it.]",
                implode(', ', $skipped),
            );
        }

        if ($filtered['hidden'] > 0) {
            $content = self::separated($content);
            // Announced for the same reason truncation is: a quietly shortened
            // hit list reads as a complete one, and "that string appears
            // nowhere" is a confident wrong answer when the file holding it was
            // simply never shown.
            $content .= sprintf(
                "... [gitignored: hid %d hit(s) in files the project's .gitignore excludes. "
                . "Pass include_ignored: true to see them.]",
                $filtered['hidden'],
            );
        }

        // The `... [note]` lines above are now the ONLY thing outside the cap.
        // They are a bounded exemption: each is a single sentence whose length
        // is set by directory names drawn from
        // {@see IgnoreRules::DEFAULT_EXCLUDED_DIRS}, a fixed four-name list —
        // seeded with 2,000 gitignored directories carrying 180-byte names,
        // the note measured 46 bytes.
        //
        // THE NUDGE USED TO BE HERE TOO, AND CALLING IT "ONE SENTENCE SIZED BY
        // SKILL NAMES" UNDERSTATED IT.
        // {@see \SugarCraft\Crush\Skills\SkillPathNudge::forPaths()} emits one
        // line PER MATCHING SKILL, and each line carries that skill's
        // `SKILL.md` `description` — arbitrary-length repository content, not
        // a name — so its size was (matching auto-invocable skills x
        // description length) with no clip anywhere. Recorded as E66 in the
        // hardening backlog, and fixed in two halves: forPaths() now bounds
        // itself to {@see \SugarCraft\Crush\Skills\SkillPathNudge::maxBytes()}
        // in COUNT as well as in bytes, and $bodyCap above subtracts what it
        // actually returned from the hit list's budget, so it is spent INSIDE
        // the cap rather than beside it.
        //
        // What the reservation replaced was a different order of magnitude
        // again: a whole markdown file, and as many of them as there are
        // matched directories.
        if ($section !== '') {
            $content = self::separated($content);
            $content .= $section;
        }

        if ($nudge !== null) {
            // Scoped to every path with a HIT, not to the paths that survived
            // the clip, and that is the SAME rule {@see Glob::execute()}
            // follows two files over — where it is scoped to every matched
            // path rather than to $shown. The two tools answer the same shape
            // of question and must not disagree about this one:
            // {@see \SugarCraft\Crush\Skills\SkillPathNudge::forPaths()}
            // answers "does a skill claim this area of the tree", which the
            // clip does not change the truth of, and the nudge names the SKILL
            // rather than the path — so a nudge earned by a path the cap
            // dropped is still a true and actionable statement, where an
            // instruction body shown for an unseen path is neither.
            //
            // Reading it off $probe instead was a REGRESSION this change's own
            // parent introduced, and it broke the containment in the direction
            // that actually costs the model something. $probe is the
            // three-quarter floor; $content is the FULL cap whenever no rule
            // was surfaced, which under announce-once is almost every call. So
            // a hit between the two cuts was VISIBLE in the result while its
            // skill went unannounced.
            //
            // WHAT THIS SENTENCE USED TO END WITH: "— and announce-once means
            // unannounced here is unannounced for the session."
            // WHAT IS TRUE NOW: that is FALSE, and E70 caught it.
            // {@see \SugarCraft\Crush\Skills\SkillPathNudge::forPaths()}
            // marks only the entries it actually EMITS, so a nudge that was
            // never built spends no mark and the skill is announced by the
            // next call with room. DRIVEN at ae30fee5 over the one-hit
            // fixture: two Grep calls at cap 1,000 both came back silent with
            // `announced() === []`, and a third at cap 4,000 announced.
            // Unannounced is DEFERRED, not retired — pinned by
            // `GrepInstructionWiringTest::
            // testACapTooTightForTheNudgeDefersTheSkillRatherThanRetiringIt()`.
            // WHY THIS STILL EARNS ITS PLACE: deferral is cheaper than
            // retirement but it is not free. The model is looking at a hit
            // whose skill it has not been told about, and whether it is ever
            // told depends on a later call happening to arrive with more room.
            // Building the nudge off the full stdout rather than off $probe is
            // what removes that dependence, so the argument for the input this
            // line reads survives its overstated consequence intact.
            //
            // MEASURED over `GrepInstructionWiringTest`'s fixture as it stood
            // at 6569891f — a `*.zzz.php`-scoped skill, 201 hits, a 35-byte
            // flat root; the fixture now nests the hits one directory deeper,
            // which moves the band without changing its shape — sweeping caps
            // 200 to 12,000 one at a time and counting the caps where the hit
            // is visible and the skill is silent: 0 at d7919902, 1,745 at
            // 6569891f (caps 5,233 to 6,977), 0 here. The band moves with the
            // length of the root, since that prefix is repeated on every hit
            // line.
            //
            // BUILT ABOVE, not here, and that is the only difference: its
            // length is subtracted from $bodyCap before the hit list is
            // clipped, so this append cannot push the result past the cap.
            $content = self::separated($content);
            $content .= $nudge;
        }
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $content,
            isError: $run['exitCode'] > 1,
        );
    }

    /**
     * The distinct files the hit lines in $content refer to, in first-hit
     * order.
     *
     * TWO CALLERS PASS DIFFERENT $content ON PURPOSE, and the difference is
     * the whole announce-once doctrine of this tool.
     *
     * The INSTRUCTION path passes the probe, i.e. text that has already been
     * clipped, and it must: a hit truncation dropped is a path the model
     * cannot see, and announcing its `CLAUDE.md` against it would spend the
     * once-per-session mark on a file the model was never shown.
     *
     * The SKILL NUDGE passes the unclipped capture, and it must: the nudge
     * names the SKILL and not the path, so it stays true of a path the cap
     * dropped, and scoping it to the clip loses it for the whole session for a
     * hit the model can in fact see. {@see Glob::execute()} draws the same
     * line between $shown and $files.
     *
     * NEITHER caller's $content carries the `... [skipped: ...]` or
     * `... [gitignored: ...]` notes any more — the probe is cut before they
     * are appended, and the capture never had them — but the parse is immune
     * to them either way, which is what lets the two call sites move without
     * being re-audited: {@see hitPath()} recognises a hit only by the
     * search-root prefix (or by grep's exact `Binary file X matches`
     * wording), and every note opens with `... [`.
     *
     * `strval` over the keys for the reason
     * {@see \SugarCraft\Crush\Context\InstructionFileLoader::emittedPaths()}
     * documents: PHP coerces a decimal-integer string key to `int`.
     * Unreachable here (these are rooted paths), mirrored so the idiom does
     * not drift.
     *
     * @return list<string>
     */
    private static function hitFiles(string $content, string $searchRoot): array
    {
        if ($content === '') {
            return [];
        }

        $prefix = rtrim($searchRoot, '/') . '/';
        $files = [];

        foreach (explode("\n", $content) as $line) {
            $file = self::hitPath($line, $prefix);
            if ($file !== null) {
                $files[$file] = true;
            }
        }

        return array_map(strval(...), array_keys($files));
    }

    /**
     * $content with a trailing newline, so an appended block starts on a line
     * of its own. The two notes above open with the same two lines inline;
     * this is that idiom named once.
     */
    private static function separated(string $content): string
    {
        if ($content !== '' && !str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        return $content;
    }

    /**
     * The ignore ruleset governing this call.
     *
     * $searchRoot's own segments are removed from the exclude list, which is
     * the same "naming it is asking for it" hatch `Glob` already honours:
     * `path: "vendor/acme"` has to search vendor/acme, not report it empty.
     * The `.gitignore` layer gets the same treatment when the search base is
     * itself an ignored directory.
     */
    private function rulesFor(string $searchRoot, bool $includeIgnored): IgnoreRules
    {
        if ($includeIgnored) {
            return IgnoreRules::new($this->root ?? $searchRoot)
                ->withGitignore(false)
                ->withExcludedDirs([]);
        }

        $rules = IgnoreRules::new($this->root ?? $searchRoot)
            ->withoutExcludedDirs(explode('/', $searchRoot));

        return $rules->ignores($searchRoot, true) ? $rules->withGitignore(false) : $rules;
    }

    /**
     * Which default-excluded directories are actually present under
     * $searchRoot, so the result can name what it skipped.
     *
     * `--exclude-dir` spares the traversal but tells us nothing about what it
     * spared, and a hit list silently missing `vendor/` reads as a complete
     * one — the same failure `Glob`'s prune note exists to prevent. Its walk
     * records real skips; grep's cannot, so this probes instead.
     *
     * Bounded to three levels ON PURPOSE. An unbounded search for the
     * directories would cost the whole traversal the exclusion just avoided,
     * and every realistic layout puts `vendor/`/`node_modules/` at the search
     * root or a level or two below it. A skip nested deeper than that goes
     * unannounced; description() still names the exclusions up front, which is
     * the case this note is a backstop for.
     *
     * @return list<string>
     */
    private static function presentExcludedDirs(string $searchRoot, IgnoreRules $rules): array
    {
        $base = rtrim($searchRoot, '/');
        $found = [];

        foreach ($rules->excludedDirs as $dir) {
            foreach (['/', '/*/', '/*/*/'] as $depth) {
                if (glob($base . $depth . $dir, GLOB_ONLYDIR)) {
                    $found[] = $dir;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Drop hits in files the ruleset excludes.
     *
     * `.gitignore` is enforced HERE rather than on grep's command line because
     * ignore rules can be negated and grep's `--exclude` cannot — see
     * {@see IgnoreRules::grepExcludeFlags()}. Filtering output keeps negation
     * working; the exclude flags still spare the walk the trees that dominate
     * it.
     *
     * The capture's discard counters are deliberately left ALONE. A line this
     * method removes was never going to be shown at any cap, and folding it
     * into `stdoutDropped` would label a complete answer partial — the same
     * mistake {@see CapturesProcessOutput::mergeCapturedOutput()} avoids when
     * it throws a whole stream away.
     *
     * @param array{stdout: string, stderr: string, exitCode: int, truncatedBytes?: int, stdoutDropped?: int, stderrDropped?: int, stdoutMidLine?: bool, stderrMidLine?: bool} $run
     *
     * @return array{run: array{stdout: string, stderr: string, exitCode: int, truncatedBytes?: int, stdoutDropped?: int, stderrDropped?: int, stdoutMidLine?: bool, stderrMidLine?: bool}, hidden: int}
     */
    private static function withoutIgnoredHits(array $run, IgnoreRules $rules, string $searchRoot): array
    {
        if ($run['stdout'] === '') {
            return ['run' => $run, 'hidden' => 0];
        }

        $prefix = rtrim($searchRoot, '/') . '/';
        $kept = [];
        $hidden = 0;

        foreach (explode("\n", $run['stdout']) as $line) {
            $file = self::hitPath($line, $prefix);
            // A line whose path cannot be recovered is kept: grep's own
            // diagnostics look like this, and dropping text nobody can
            // attribute is how a result loses the sentence explaining itself.
            if ($file !== null && ($rules->ignores($file, false) || $rules->excludedDirectoryIn($file) !== null)) {
                $hidden++;
                continue;
            }
            $kept[] = $line;
        }

        if ($hidden === 0) {
            return ['run' => $run, 'hidden' => 0];
        }

        $run['stdout'] = implode("\n", $kept);

        return ['run' => $run, 'hidden' => $hidden];
    }

    /**
     * The file a grep hit refers to, or null when the line is not a hit.
     *
     * Splitting on the FIRST `:` is wrong — a path may contain one — so the
     * search starts past the search root, which every `-r` hit is prefixed
     * with. `Binary file X matches` is recognised separately because grep
     * writes it instead of a hit for a matching binary, and an ignored blob is
     * exactly the sort of file that produces it.
     */
    private static function hitPath(string $line, string $prefix): ?string
    {
        if (preg_match('/^Binary file (.*) matches$/', $line, $m) === 1) {
            return $m[1];
        }

        if (!str_starts_with($line, $prefix)) {
            return null;
        }

        $colon = strpos($line, ':', strlen($prefix));

        return $colon === false ? null : substr($line, 0, $colon);
    }

    /**
     * Read a boolean tool argument.
     *
     * Models routinely send `"true"` and `1` for a boolean, and a bare cast
     * turns the string `"false"` into true — which would silently disable the
     * very filter this flag exists to control.
     */
    private static function flag(mixed $value): bool
    {
        return filter_var($value ?? false, FILTER_VALIDATE_BOOL);
    }
}
