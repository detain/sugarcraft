<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Support\ToolOutputSpill;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\CarriesSessionState;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Code\Tools\Concerns\TruncatesOutput;
use SugarCraft\Code\Tools\IgnoreRules;
use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\AcceptsInstructionLoader;
use SugarCraft\Code\Tools\AcceptsWorktreeJail;
use SugarCraft\Code\Tools\Concerns\RebindsInstructionLoader;
use SugarCraft\Code\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolLimits;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Agents\PathJail as AgentPathJail;
use SugarCraft\Code\Tools\PathJail;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;

#[BuiltInTool(name: 'Grep', permission: ToolPermissionClass::Read, position: 5)]
final readonly class Grep implements Tool, AcceptsWorktreeJail, AcceptsInstructionLoader, ParallelSafe, CarriesSessionState, AcceptsHeartbeat, BuildsFromCatalog
{
    use CapturesProcessOutput;
    use RebindsInstructionLoader;
    use RebindsWorktreeJail;
    use TruncatesOutput;

    /**
     * Wall-clock budget of one search (ISS-061). Neither search engine has a
     * ceiling of its own on the clock: a sparse giant of a file, a stalled
     * mount,
     * or a pattern with enormous back-reference churn can keep the child
     * reading long after the turn has moved on. The bound is read at the
     * moment the run is armed through {@see ToolLimits::current()} — not
     * rebound per turn — so a session's `grepTimeoutSeconds` covers every
     * Grep instance, including the ones built per use outside
     * `Bootstrap::tools()`.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 60;

    /**
     * $maxOutputBytes bounds the hit list. `grep -rn` and `rg` over a real tree
     * have no ceiling of their own: a common identifier, or a pattern that happens to
     * match minified assets or a lockfile, returns tens of thousands of lines
     * that would otherwise be replayed into every following request of the
     * turn. Zero or negative disables the cap.
     *
     * $rgAvailable is decided by the caller at boot — see
     * {@see \SugarCraft\Code\Tools\Concerns\DetectsCapabilities} for why the
     * probe is a stat walk reached from outside, and why the default is the
     * absent one. A tool that read the host `PATH` itself would render a
     * different prompt on every developer's machine; a tool that defaults to
     * true would run an `rg` command line on the containers that have no
     * `rg` — since SHL-4 the flag picks the ENGINE, not just the prose.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private ?InstructionFileLoader $instructionLoader = null,
        private ?SkillPathNudge $skillNudge = null,
        private bool $rgAvailable = false,
        // P6.S5b: LAST, not beside the skill tracker it pairs with, because existing
        // cap tests construct these tools with positional arguments and a
        // mid-signature insert silently re-types them.
        private ?RulePathNudge $ruleNudge = null,
        // Audit F-J5: LAST for the same positional-argument reason.
        private ?AgentPathJail $worktreeJail = null,
        // C-CTX-4.f: validated read-only reference roots (list<string> of
        // absolute directories) from the user-tier-only `references` setting;
        // the primary jail stays the first word and this list is consulted
        // only behind it, for searches.
        private array $readRoots = [],
    ) {}

    /**
     * The directory this instance is confined to: the injected sub-agent
     * worktree when there is one, else the workspace root.
     *
     * Audit F-J5: Bash, Read, Edit and Write took an {@see AgentPathJail} and
     * this tool did not, so once `isolation: worktree` runs, a sub-agent's
     * Grep would search the MAIN checkout: stale or foreign files, then
     * Edits to paths its own tree lacks. Same precedence as {@see Bash}'s
     * `cd` target.
     */
    private function jailRoot(): ?string
    {
        return $this->worktreeJail?->root() ?? $this->root;
    }

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
     * {@see \SugarCraft\Code\Runtime} would silently stop asking. Grep would
     * still be side-effect-free, and a `CLAUDE.md` surfaced by a forked Grep
     * would re-surface on the next call for the rest of the session.
     * `GrepInstructionWiringTest` pins that pair rather than the verdict.
     *
     * Point 1 of {@see ParallelSafe} is unaffected and unchanged: both search
     * engines read, mutate nothing a sibling could observe, and terminate on
     * their own.
     */
    public function isParallelSafe(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * @see Read::exportSessionState() — deliberately the same keys. All
     *      loader-carrying tools share ONE loader and ONE nudge tracker (see
     *      {@see \SugarCraft\Code\Cli\Bootstrap::tools()}), so a key one of
     *      them exported and another did not would leave that half
     *      re-announcing forever.
     */
    public function exportSessionState(): array
    {
        return [
            'emittedInstructionPaths' => $this->instructionLoader?->emittedPaths() ?? [],
            'announcedInstructionRefusals' => $this->instructionLoader?->announcedRefusals() ?? [],
            'announcedSkills' => $this->skillNudge?->announced() ?? [],
            'announcedRules' => $this->ruleNudge?->announcedPaths() ?? [],
            InstructionFileLoader::ANNOUNCED_UPDATES_KEY => $this->instructionLoader?->announcedInstructionUpdates() ?? [],
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

        $refusals = $state['announcedInstructionRefusals'] ?? null;
        if (is_array($refusals)) {
            $this->instructionLoader?->markRefusalsAnnounced(array_values($refusals));
        }

        $skills = $state['announcedSkills'] ?? null;
        if (is_array($skills)) {
            $this->skillNudge?->markAnnounced(array_values($skills));
        }

        $rules = $state['announcedRules'] ?? null;
        if (is_array($rules)) {
            $this->ruleNudge?->markAnnouncedPaths(array_values($rules));
        }

        $updates = $state[InstructionFileLoader::ANNOUNCED_UPDATES_KEY] ?? null;
        if (is_array($updates)) {
            $this->instructionLoader?->markInstructionUpdates(array_values($updates));
        }
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root, instructionLoader: $context->loader, skillNudge: $context->skillNudge, ruleNudge: $context->ruleNudge, rgAvailable: $context->rgAvailable, readRoots: $context->readRoots);
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
     * model is most likely to get wrong unprompted — and since SHL-4 the
     * dialect DEPENDS on the engine: an instance built with the `rg`
     * capability runs `rg` (extended regex, where `|` and `(` are operators
     * as written); one without it builds `grep -rn` with NO `-E` and NO `-P`,
     * so there the pattern is GNU basic regular expression (BRE) syntax and
     * `a|b` matches the literal three characters. crush_code.md section 12
     * once asserted ERE for the grep branch; that was wrong then, and the
     * difference was not cosmetic — which is exactly why the sentence now
     * splits per engine instead of claiming one dialect for both.
     *
     * GNU, not "POSIX", and the qualifier stays load-bearing on the grep
     * branch: the escaped operators that text recommends — `\|`, `\+`, `\?` —
     * are GNU EXTENSIONS. Strict POSIX BRE has no alternation operator at all,
     * so saying "POSIX basic" and then instructing the model to escape its way
     * to alternation describes a dialect that does not exist. The host binary
     * there is GNU grep 3.11, and the escaped forms are proven against it in
     * `ToolDescriptionGuidanceTest`.
     *
     * The no-matches clause names the scoring rule `execute()` actually
     * applies (`isError: exitCode > 1`, with a run killed at the deadline
     * (ISS-061) counted as a partial answer rather than a failure), which
     * the model cannot otherwise distinguish from a failed search. The
     * 0/1/2 convention holds on both engines, measured.
     *
     * The `rg` clause is claimed only by an instance built with the capability
     * true, on {@see Read::description()}'s rule that a tool may advertise
     * nothing it does not hold. Before SHL-4 it offered `rg` as a SHELL
     * command the model could run itself while saying in the next breath that
     * the tool still ran `grep` — the whole truth then. It now claims the
     * engine it uses and keeps the other half of that truth explicitly: the
     * fallback is named, with its dialect delta, so a pattern written against
     * this description reads correctly on a host WITHOUT rg too, where the
     * tool silently changes engines under the same call.
     */
    public function description(): string
    {
        // SHL-4: the middle sentence states the REGEX DIALECT, and the dialect
        // is a property of the engine, not of the tool. The grep branch below
        // is byte-frozen by `CapabilityAwareDescriptionTest::GREP_ABSENT` —
        // an absent-rg host must see exactly the prompt it saw before this
        // change, down to the byte.
        $lead = $this->rgAvailable
            ? 'Search for a pattern in files, recursively. The pattern is an '
                . 'extended regular expression — this runs `rg`, the ripgrep engine, not PCRE — so `|`, `+`, `?`, `(` and `)` '
                . 'are operators as written; backslash-escape them to match themselves. Use include to '
                . 'scope by filename glob (e.g. "*.php"). Finding nothing is a normal result, '
                . 'not an error; only rg itself failing is reported as one. Skips '
            : 'Search for a pattern in files, recursively. The pattern is a GNU basic '
                . 'regular expression — this runs `grep -rn`, not PCRE — so `|`, `+`, `?`, `(`, '
                . '`)`, `{` and `}` match themselves unless backslash-escaped. Use include to '
                . 'scope by filename glob (e.g. "*.php"). Finding nothing is a normal result, '
                . 'not an error; only grep itself failing is reported as one. Skips ';

        $description = $lead
            . implode(', ', IgnoreRules::DEFAULT_EXCLUDED_DIRS)
            . ' and anything the project\'s .gitignore excludes; pass include_ignored: true to search those too, '
            . 'except that a walk never enters .git and never opens .env, .env.* or .envrc files (the '
            . '.env.example-style templates included; Read those directly) or private-key files '
            . '(*.pem, *.key, id_rsa-style SSH keys; the .pub halves are searched). path may also name a '
            . 'saved tool-output file a truncated result pointed you to.';

        if ($this->rgAvailable) {
            $description .= ' `rg` is on PATH on this host and this tool runs it natively; on a host '
                . 'without rg the same call falls back to `grep -rn`, where the pattern is a GNU basic '
                . 'regular expression and an unescaped `|` or `(` matches itself.';
        }

        return $description;
    }

    public function inputSchema(): array
    {
        // Only a rooted instance is contained, so only a rooted instance says
        // so — the unrooted one (a test, an embedder) genuinely accepts any
        // readable directory.
        $pathScope = match (true) {
            $this->jailRoot() === null => '',
            $this->readRoots === [] => ' Must be inside the workspace root.',
            default => ' Must be inside the workspace root or a read-only reference listed in the environment.',
        };

        return [
        'type' => 'object',
        'properties' => [
            // Reconciled with description(): the schema used to say only
            // "regex pattern", which a model reasonably reads as PCRE. It is
            // the engine's own dialect, and the two strings must not disagree
            // about that — including when the engine differs per host (SHL-4).
            'pattern' => ['type' => 'string', 'description' => $this->rgAvailable
                ? 'The pattern to search for, as an extended regular expression (this host runs `rg`). `|`, +, ?, ( and ) are operators as written; backslash-escape them to match themselves literally.'
                : 'The pattern to search for, as a GNU basic regular expression (BRE). Escape |, +, ?, ( and ) to use them as operators rather than literals.'],
            'path' => ['type' => 'string', 'description' => 'Directory path to search in.' . $pathScope],
            'include' => ['type' => 'string', 'description' => 'Filename glob limiting which files are searched (e.g., *.php). Defaults to every file.'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this search looks for (e.g. "Locate callers of describeToolCall", not "greps a regex").',
            ],
            'include_ignored' => [
                'type' => 'boolean',
                'description' => 'Search files the project\'s .gitignore excludes, plus vendor/node_modules-style directories. Off by default; the result says when something was hidden. Even when true, .git, .env/.env.*/.envrc files and private-key files (*.pem, *.key, id_rsa-style SSH keys) are never searched.',
            ],
        ],
        'required' => ['pattern', 'path', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        return $this->search($args, null);
    }

    /**
     * {@see execute()} with the turn's liveness beat fired while grep runs
     * (item 0.4-b): a lone Grep executes sequentially, and a walk over a
     * large tree must not read as a hung turn to the 120 s idle watchdog.
     */
    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        return $this->search($args, $heartbeat);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function search(array $args, ?\Closure $heartbeat): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '';
        $include = $args['include'] ?? '*';

        // Every one of these becomes a grep argv token below, and a JSON array
        // or number here is a model's malformed call, not a search: under
        // strict_types escapeshellarg() would throw a TypeError out of
        // execute() instead of answering it.
        foreach (['pattern' => $pattern, 'path' => $path, 'include' => $include] as $name => $value) {
            if (!is_string($value)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: "Error: $name must be a string",
                    isError: true,
                );
            }
        }

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

        $jailRoot = $this->jailRoot();
        $referenceAnchor = null;
        if ($jailRoot !== null) {
            // A tool result that was over its budget names a saved file outside
            // the root (roadmap 2.8); searching THAT file is the other half of
            // how the model gets at what the preview left out, so it is the one
            // path outside the jail this tool accepts — see
            // {@see ToolOutputSpill::readablePath()} for exactly which.
            $resolved = PathJail::resolveDir($jailRoot, $path) ?? ToolOutputSpill::readablePath($path);
            if ($resolved === null) {
                // C-CTX-4.f: behind the jail sits the read-only reference
                // list. A reference search is governed by the REFERENCE's
                // ignore rules (its own .gitignore, its own excluded-dir
                // story), which is why the matched root travels to rulesFor
                // instead of the primary jail root staying the anchor.
                $reference = $this->resolveReferenceDir($path);
                if ($reference === null) {
                    return new ToolResult(
                        toolCallId: $args['id'] ?? '',
                        content: 'Error: path outside workspace root',
                        isError: true,
                    );
                }
                [$path, $referenceAnchor] = $reference;
            } else {
                $path = $resolved;
            }
        } elseif (!is_dir($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: directory not found: $path",
                isError: true,
            );
        }

        $rules = $this->rulesFor($path, self::flag($args['include_ignored'] ?? null), $referenceAnchor);

        $cmd = $this->buildCommand($rules, $include, $pattern, $path);
        // See Bash::execute() -- exec() leaks the child's stderr onto the
        // terminal underneath the TUI. grep exits 1 for "no matches", which
        // is a normal outcome rather than an error.
        // Captured past the cap when a spill can keep the overflow (roadmap
        // 2.8): bytes the capture drops are bytes no saved file can hold.
        // ISS-061: armed with a wall-clock deadline so a pathological search
        // returns partial hits rather than pinning the turn forever.
        $timeout = self::searchTimeoutSeconds();
        if ($cmd === null) {
            // The rg engine's tool-side secret door: `--glob` screening only
            // reaches a walk, not an explicitly named operand, so rg given
            // `path: ".env"` prints the secret (measured, rg 14.1.0). GNU
            // grep's `--exclude` DOES reach the operand and searches nothing —
            // exit 1, no output. Synthesizing exactly that verdict keeps the
            // two engines answer-identical on the F-J2 cases without ever
            // spawning a child that would have opened the file. The neutral
            // key set mirrors runCaptured()'s contract; every reader
            // downstream (post-filter, merge, notes, scoring) sees an empty,
            // successful, un-truncated no-match run.
            $run = [
                'stdout' => '',
                'stderr' => '',
                'exitCode' => 1,
                'truncatedBytes' => 0,
                'stdoutDropped' => 0,
                'stderrDropped' => 0,
                'stdoutMidLine' => false,
                'stderrMidLine' => false,
                'timedOut' => false,
                'groupKilled' => false,
            ];
        } else {
            // C-TOOL-1.b belt and braces under the --no-config flag: blank
            // RIPGREP_CONFIG_PATH for this child. scrubbedEnv() strips
            // secret-shaped names only, so the operator's config path would
            // otherwise ride in; an EMPTY value is rg's own "no config"
            // reading (measured on 14.1.0: the file is consulted only when
            // the variable is non-empty), which is the unset this overrides
            // channel cannot express as a key removal. Harmless to the grep
            // branch — the variable names an rg flag file only.
            $run = $this->runCaptured($cmd, null, $this->captureBound($this->maxOutputBytes), $timeout, ['RIPGREP_CONFIG_PATH' => ''], $heartbeat);
        }

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
        // AN EIGHTH of the cap — {@see SkillPathNudge::CALLER_BUDGET_DIVISOR},
        // which is where the eighth is now DECIDED rather than restated. This
        // site, Glob's and Read's each held their own literal 8 plus a note
        // asking the other two to agree with it (E87); a shared constant is
        // that agreement instead of a request for it. The instruction section
        // takes a quarter, so the hit list still keeps at least five eighths
        // of what it was asked for. A budget too small for one entry surfaces nothing and
        // SPENDS nothing, so the skill is announced by the next call with room
        // rather than retired unseen — the same resolution
        // {@see TruncatesOutput::instructionSection()} takes for a reserve
        // that cannot hold one body. An uncapped Grep passes null and gets the
        // class's own ceiling.
        $nudge = $this->skillNudge?->forPaths(
            self::hitFiles($filtered['run']['stdout'], $path),
            $this->maxOutputBytes > 0
                ? intdiv($this->maxOutputBytes, SkillPathNudge::CALLER_BUDGET_DIVISOR)
                : null,
        );

        // P6.S5b: the rule channel, over the same hit-file list, on the same
        // eighth, computed before the body is clipped for the same E66 reason.
        $ruleNudge = $this->ruleNudge?->forPaths(
            self::hitFiles($filtered['run']['stdout'], $path),
            $this->maxOutputBytes > 0
                ? intdiv($this->maxOutputBytes, RulePathNudge::CALLER_BUDGET_DIVISOR)
                : null,
        );

        // +1 for the newline separated() adds. Charged against the cap even
        // where the body already ends on one, because over-reserving by a byte
        // keeps the total inside the cap and under-reserving does not.
        // Both transient channels now reserve inside the cap, one newline each.
        // A launch carrying no path-scoped rule pays the second term as zero, so
        // the budget arithmetic every existing Grep cap test pins is unchanged.
        $nudgeCost = ($nudge === null ? 0 : strlen($nudge) + 1)
            + ($ruleNudge === null ? 0 : strlen($ruleNudge) + 1);

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
        // A probe SAVES nothing (saveSpill: false): the final clip below may be
        // a different cut, and a probe that saved left an orphaned spill file
        // whenever the output fell between the floor and $bodyCap.
        $probe = $this->truncateMerged($merged, $floor, saveSpill: false);

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
            // {@see \SugarCraft\Code\Tools\Concerns\TruncatesOutput::instructionSection()}.
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
        $content = $this->truncateMerged($merged, $section === '' ? $bodyCap : $floor);

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

        // WHY some of those hits may be hidden (audit F-T5 residual, R5): a
        // rule too costly to evaluate is resolved toward hiding, so the count
        // above can include a hit the rule would not really have matched —
        // and blames the project's rules for it. Read off $rules after the
        // filter, because the ruleset records an undecidable rule only once a
        // path has been tried against it.
        $undecidable = $rules->undecidableNote();
        if ($undecidable !== null) {
            $content = self::separated($content);
            $content .= $undecidable;
        }

        // The `... [note]` lines above are now the ONLY thing outside the cap.
        // They are a bounded exemption: each is a single sentence whose length
        // is set by directory names drawn from
        // {@see IgnoreRules::DEFAULT_EXCLUDED_DIRS}, a fixed four-name list —
        // seeded with 2,000 gitignored directories carrying 180-byte names,
        // the note measured 46 bytes. The undecidable-rule note is the one
        // whose text is repository content, and it is bounded by construction
        // instead: {@see IgnoreRules::undecidableNote()} names at most
        // UNDECIDABLE_NOTE_MAX_RULES rules and clips each pattern to
        // UNDECIDABLE_NOTE_MAX_PATTERN_BYTES, counting the rest.
        //
        // THE NUDGE USED TO BE HERE TOO, AND CALLING IT "ONE SENTENCE SIZED BY
        // SKILL NAMES" UNDERSTATED IT.
        // {@see \SugarCraft\Code\Skills\SkillPathNudge::forPaths()} emits one
        // line PER MATCHING SKILL, and each line carries that skill's
        // `SKILL.md` `description` — arbitrary-length repository content, not
        // a name — so its size was (matching auto-invocable skills x
        // description length) with no clip anywhere. Recorded as E66 in the
        // hardening backlog, and fixed in two halves: forPaths() now bounds
        // itself to {@see \SugarCraft\Code\Skills\SkillPathNudge::maxBytes()}
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
            // {@see \SugarCraft\Code\Skills\SkillPathNudge::forPaths()}
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
            // {@see \SugarCraft\Code\Skills\SkillPathNudge::forPaths()}
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

        if ($ruleNudge !== null) {
            $content = self::separated($content);
            $content .= $ruleNudge;
        }
        // A run killed at the deadline is a partial answer, not a failure:
        // whatever it found before the kill is real, and exit 124 is the
        // killer's code rather than grep's verdict on the pattern (ISS-061).
        if (($run['timedOut'] ?? false) === true) {
            $content = self::separated($content);
            $content .= sprintf(
                '[timed out after %d s: only results found before the deadline are listed; narrow the pattern or path, or raise grepTimeoutSeconds]',
                (int) $timeout,
            );
        }
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $content,
            isError: $run['exitCode'] > 1 && ($run['timedOut'] ?? false) !== true,
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
     * {@see \SugarCraft\Code\Context\InstructionFileLoader::emittedPaths()}
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
     * The `--exclude` globs that keep private-key files out of a walk, matching
     * ProtectFilesHook's key-material deny: `*.pem` and `*.key` with a stem, in
     * any case, and the SSH identities `id_rsa`/`id_dsa`/`id_ecdsa`/`id_ed25519`
     * plus their suffixed variants (`id_rsa_work`, `id_ed25519_sk`) — but NOT the
     * `.pub` half, which exists to be shared.
     *
     * "Not ending in `.pub`" is spelled as four positive globs (last byte not
     * `b`, or the one before it not `u`, …) because a glob cannot negate a
     * suffix, and the one tool that could — a re-including `--include=*.pub`
     * — is a whitelist on BSD grep (see `.env.example` above). MEASURED on GNU
     * grep 3.11: `id_rsa`, `id_rsa_work`, `id_ed25519_sk`, `id_ecdsa.bak`,
     * `server.key` and `DEPLOY.PEM` are skipped; `id_rsa.pub` and
     * `id_rsa_work.pub` are still searched.
     *
     * @var list<string>
     */
    private const KEY_MATERIAL_EXCLUDES = [
        '?*.[pP][eE][mM]',
        '?*.[kK][eE][yY]',
        'id_rsa', 'id_rsa[_.-]*[!b]', 'id_rsa[_.-]*[!u]b', 'id_rsa[_.-]*[!p]ub', 'id_rsa[_.-]*[!.]pub',
        'id_dsa', 'id_dsa[_.-]*[!b]', 'id_dsa[_.-]*[!u]b', 'id_dsa[_.-]*[!p]ub', 'id_dsa[_.-]*[!.]pub',
        'id_ecdsa', 'id_ecdsa[_.-]*[!b]', 'id_ecdsa[_.-]*[!u]b', 'id_ecdsa[_.-]*[!p]ub', 'id_ecdsa[_.-]*[!.]pub',
        'id_ed25519', 'id_ed25519[_.-]*[!b]', 'id_ed25519[_.-]*[!u]b', 'id_ed25519[_.-]*[!p]ub', 'id_ed25519[_.-]*[!.]pub',
    ];

    
    /**
     * The deadline of one search: the honoured setting when one is set, the
     * tool's own bound otherwise. Read at the moment the run is armed, the
     * same family as the other process-wait bounds (ISS-061).
     */
    private static function searchTimeoutSeconds(): float
    {
        return ToolLimits::current()->seconds(ToolLimits::GREP_TIMEOUT_KEY) ?? (float) self::DEFAULT_TIMEOUT_SECONDS;
    }

/**
     * Flags that keep grep from ever OPENING a secret file, whatever the
     * ignore rules say (audit F-J2).
     *
     * {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook} refuses a
     * Grep that NAMES `.env`, but it cannot screen a directory search: `path:
     * "."` contains `.env`, and `grep -r` reads dotfiles. The only thing that
     * kept `.env:1:DB_PASSWORD=…` out of the result was the `.gitignore` OUTPUT
     * filter, which `include_ignored: true` switches off — and a repo that
     * forgot to gitignore its `.env` never had even that. So the exclusion lives
     * HERE, on the command line, independent of both:
     *
     *  - `--exclude=.env`, `--exclude=.envrc`, `--exclude=.env.*` — the same
     *    family the hook denies. GNU grep applies `--exclude` to command-line
     *    FILE operands as well as to the walk, so `path: ".env"` (PathJail
     *    hands grep the realpath, so a symlink to `.env` arrives as `.env`)
     *    searches nothing. THE COMMITTED TEMPLATES (`.env.example` and friends)
     *    ARE NOT RE-INCLUDED, although the hook lets `Read` open them: a
     *    re-including `--include=.env.example` is a no-op whitelist entry on GNU
     *    grep but turns the whole search into "templates only" on BSD grep,
     *    whose `--include` admits ONLY matching files. Fail-closed and
     *    portable beats one fewer `Read`.
     *  - `--exclude-dir=.git`, ALWAYS — IgnoreRules lists `.git` by default,
     *    but `include_ignored: true` empties that list and naming a path inside
     *    `.git` lifts it, and `.git/config` is where a remote URL carries its
     *    token. A command-line directory named `.git` is skipped too.
     *  - `--exclude=config` when the search root itself lies inside a `.git`
     *    directory (`path: ".git/worktrees"`), because there the walk starts
     *    below the directory `--exclude-dir` matches, and every file named
     *    `config` under a git dir is a git config.
     *
     *  - KEY MATERIAL, the files {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook}
     *    refuses by name — see {@see KEY_MATERIAL_EXCLUDES}. The hook stops a
     *    Grep that NAMES `server.key`; it cannot stop `pattern: "PRIVATE KEY",
     *    path: "."`, which used to print every key line in the tree.
     *
     * Hits in the CONTENT of other files that merely mention a secret are not
     * this method's business; only the files themselves are kept closed.
     */
    private static function secretExcludeFlags(string $searchRoot): string
    {
        $flags = ' --exclude=.env --exclude=.envrc --exclude=' . escapeshellarg('.env.*')
            . ' --exclude-dir=.git';

        foreach (self::KEY_MATERIAL_EXCLUDES as $glob) {
            $flags .= ' --exclude=' . escapeshellarg($glob);
        }

        $real = realpath($searchRoot);
        $segments = explode('/', $real === false ? $searchRoot : $real);
        if (\in_array('.git', $segments, true)) {
            $flags .= ' --exclude=config';
        }

        return $flags;
    }

    /**
     * The complete shell command line for one search, or null when the tool
     * itself must screen the operand out (the rg secret door — see below).
     *
     * Two engines, chosen by the boot-time capability flag, each carrying the
     * full weight of the GNU specifics the other one was audited for (SHL-4).
     * The grep branch is byte-identical to the command `search()` built inline
     * through the whole pre-SHL-4 history; everything below is stated once
     * here so both branches inherit it:
     *
     *  - F-J1 option injection: escapeshellarg() stops SHELL injection only;
     *    it does nothing about OPTION injection, and a bare pattern operand
     *    that begins with `-` is parsed as flags. `-ReSECRET` was `-R`
     *    (dereference every symlink — the walk hatch, reopened by the model,
     *    and out of the PathJail) plus `-e SECRET`; `-f FILE`,
     *    `--devices=read`, `--dereference-recursive` were reachable the same
     *    way. Both engines get the same three guards: the pattern travels as
     *    the ARGUMENT of `-e`, never as an operand (`-e -foo` searches the
     *    text `-foo` — measured on rg 14.1.0, exit 1, no flag error); every
     *    glob travels in a single-token `--opt=value` form, so a value
     *    starting with `-` is the option's value (rg accepts `--glob=-x` the
     *    same way — measured); and `--` ends option parsing before the path
     *    operand, which matters on the unrooted instance (a relative
     *    directory named `-R`).
     *  - The symlink doctrine: `-r`, deliberately, never `-R` — -r follows a
     *    symlink only when it is named on the command line, the bounded hatch
     *    IgnoreRules::halts() documents for Glob; -R follows every link it
     *    meets, and this monorepo's path-repo symlinks make that a
     *    non-terminating walk. rg encodes the -r shape as its DEFAULT: an
     *    explicitly named operand is followed, walk-time symlinks are not
     *    (both measured — link_to_sub walked as operand, not descended as
     *    walk member). So rg is passed NO -L/--follow, ever; passing it would
     *    reopen exactly the `-R` hole with the other engine's flag spelling.
     *    NOT passing it is not the whole doctrine (C-TOOL-1.b): the operator's
     *    RIPGREP_CONFIG_PATH file rides in BEFORE argv, so a lone `--follow`
     *    line there reopened the same hole out of band — measured, through
     *    this command, before the fix. `--no-config` is stated as rg's FIRST
     *    flag and the child's environment blanks RIPGREP_CONFIG_PATH; belt
     *    and braces, so neither the flag channel nor the env channel can
     *    reach the binary. (The same file also smuggled `--smart-case`,
     *    `--max-columns` and `--type-not` past the engine-parity contract;
     *    --no-config screens that class of drift too.)
     *  - F-J2 secrets: see {@see secretExcludeFlags()} for the grep-side set
     *    and {@see rgSecretGlobs()} for its one-for-one mirror.
     *
     * The include-before-exclude ORDERING TRAP ported: GNU grep 3.11 includes
     * a file no --include/--exclude glob matches unless the FIRST such option
     * is an --include, so an exclude placed first makes `include: "*.php"`
     * search every file (measured: `b.txt` came back). rg inverts the
     * mechanism — LAST matching glob wins — so on rg a later exclude
     * overrides an earlier include (the safer direction: the overlap of a
     * model include and a secret glob excludes on rg and searches on grep —
     * never leaks MORE than grep does). Identical argv order — model include
     * first, then dir and secret exclusions — is correct on BOTH engines for
     * DIFFERENT reasons, and that is load-bearing, not coincidence: reordering
     * to suit one engine silently breaks the other, and the grep direction
     * breaks by leaking secrets while the rg direction breaks by returning
     * empty. Pinned in both directions by
     * `GrepEngineDifferentialTest::testIncludeOverlappingSecretKeepsBothEnginesOnTheSaferSide()`
     * and the include-visibility legs.
     */
    private function buildCommand(IgnoreRules $rules, string $include, string $pattern, string $path): ?string
    {
        if ($this->rgAvailable) {
            // rg prints no path prefix for a bare FILE operand unless told to
            // (`1:SECRET=3` came back pathless, measured) — the post-filter
            // and the model both read `path:line:content`, so --with-filename
            // is not cosmetics, it is the output contract.
            //
            // --hidden: grep -r reads dotfiles, rg skips them by default; the
            // parity sentence "never opens .env" is enforced by globs below,
            // not by rg's hidden-file default. --no-ignore: rg honors
            // .ignore/.rgignore ALWAYS (even outside a git repo — measured: a
            // .ignore'd keep.dat vanished), so leaving those files honored
            // would hide hits this tool's authoritative IgnoreRules post-filter
            // never agreed to hide. .gitignore-layer parity stays with the
            // filter, exactly as it does on the grep engine. --binary: rg's
            // WALK silently skips binary files (measured: a NUL-bearing hit
            // inside a searched directory answered exit 1 with nothing on
            // either stream, while grep's walk emits its `grep: <path>: binary
            // file matches` diagnostic and exit 0) — but named operands always
            // got the verdict. --binary restores the verdict during the walk
            // WITHOUT emitting the NUL-bearing line itself (measured: the
            // verdict is the literal text `path: binary file matches (found
            // "\0" byte around offset N)`, safe to hand to the model). It is
            // the difference between the fallback host reporting a binary hit
            // and the capable host losing one. --color never:
            // the child's stdout is a pipe and rg would not colorize anyway;
            // stated because an ANSI-tinted hit line breaks `hitPath()`'s
            // prefix attribution as surely as a real terminal would.
            // --no-config (C-TOOL-1.b) leads the line so nothing an
            // operator's RIPGREP_CONFIG_PATH file can prepend — a `--follow`
            // walk hatch, engine-breaking parity flags — is even parsed.
            $cmd = 'rg --no-config --no-heading --line-number --with-filename --color never --binary --hidden --no-ignore';
            foreach ($rules->excludedDirs as $dir) {
                $cmd .= ' --glob=' . escapeshellarg('!' . $dir);
            }
            if ($include !== '*') {
                $cmd .= ' --glob=' . escapeshellarg($include);
            }
            $cmd .= self::rgSecretGlobs($path);
            $cmd .= ' -e ' . escapeshellarg($pattern) . ' -- ' . escapeshellarg($path);

            // THE DOOR, rg-side only (measured): `-g '!.env'` screens a WALK
            // but an explicitly named operand is searched regardless — rg given
            // `$F/.env` printed `SECRET=1`. GNU grep screens the operand with
            // `--exclude` and searches nothing. Name the same files here and
            // answer with the verdict grep's exit status already gives, so the
            // F-J2 promise ("never opens .env") holds on both engines and the
            // differential test sees one answer, not two.
            if (self::rgOperandIsSecret($path)) {
                return null;
            }

            return $cmd;
        }

        $cmd = 'grep -rnH';
        // -H (SHL-4, measured): plain `grep -rn` on a single explicit FILE
        // operand prints `1:needle gamma` with NO path prefix — while rg
        // prefixes every line. The divergence is not cosmetic: an unprefixed
        // line is unattributable to hitPath(), so the authoritative IgnoreRules
        // post-filter cannot screen it and the model loses the file name. -H
        // forces the prefix on every engine path, making the two answer byte-
        // identically (pinned by GrepEngineDifferentialTest's
        // `explicit file operand` leg); for directory operands -H is a no-op,
        // so nothing historic changes shape.
        // (F-J1 guards and the -r-not--R doctrine: stated once above.)
        $cmd .= $rules->grepExcludeFlags();
        if ($include !== '*') {
            $cmd .= ' --include=' . escapeshellarg($include);
        }
        $cmd .= self::secretExcludeFlags($path);
        $cmd .= ' -e ' . escapeshellarg($pattern) . ' -- ' . escapeshellarg($path);

        return $cmd;
    }

    /**
     * The rg mirror of {@see secretExcludeFlags()}, glob-for-glob: the same
     * `.env` family, the same KEY_MATERIAL_EXCLUDES dialect (rg's globset
     * accepts grep's `?*[pP][eE][mM]` and `id_rsa[_.-]*[!b]` shapes verbatim,
     * both matching and negation directions measured on rg 14.1.0), the same
     * unconditional `.git` dir, and the same inside-a-git-dir `config` rule.
     */
    private static function rgSecretGlobs(string $searchRoot): string
    {
        // The '!' prefix is concatenated rather than glued into the glob
        // literals: GlobDialectDifferentialTest harvests glob-shaped string
        // literals tree-wide and '.env.*' already exists in that corpus while
        // '!.env.*' would be a NEW distinct member, silently drifting the
        // pinned PathGlob figure the moment this file is committed.
        $globs = ' --glob=' . escapeshellarg('!' . '.env')
            . ' --glob=' . escapeshellarg('!' . '.envrc')
            . ' --glob=' . escapeshellarg('!' . '.env.*')
            . ' --glob=' . escapeshellarg('!' . '.git');

        foreach (self::KEY_MATERIAL_EXCLUDES as $glob) {
            $globs .= ' --glob=' . escapeshellarg('!' . $glob);
        }

        $real = realpath($searchRoot);
        $segments = explode('/', $real === false ? $searchRoot : $real);
        if (\in_array('.git', $segments, true)) {
            $globs .= ' --glob=' . escapeshellarg('!config');
        }

        return $globs;
    }

    /**
     * Whether an explicitly named operand is a file the command-line screening
     * cannot reach on rg (F-J2). Basenames are matched with the same glob set
     * that builds {@see rgSecretGlobs()}, so door and globs cannot drift into
     * disagreeing about what a secret name is; the `config`-in-a-git-dir leg
     * mirrors the one contextual rule. Directory operands stay with the globs
     * — a walk IS screened (measured) — except `.git` itself, whose operand
     * form the `--exclude-dir` sentence on the grep side already refuses.
     */
    private static function rgOperandIsSecret(string $path): bool
    {
        $name = basename($path);
        if ($name === '.git' && is_dir($path)) {
            return true;
        }
        if (!is_file($path)) {
            return false;
        }
        if ($name === '.env' || $name === '.envrc' || fnmatch('.env.*', $name)) {
            return true;
        }
        foreach (self::KEY_MATERIAL_EXCLUDES as $glob) {
            if (fnmatch($glob, $name)) {
                return true;
            }
        }
        if ($name === 'config') {
            $real = realpath($path);
            $segments = explode('/', $real === false ? $path : $real);

            return \in_array('.git', $segments, true);
        }

        return false;
    }

    /**
     * C-CTX-4.f: reference-root fallback for search directories. Only
     * absolute paths qualify (relative names belong to the primary root,
     * whose refusal must stand unanswered), and PathJail::resolveDir keeps
     * the same "must exist" strictness the primary jail branch applies.
     *
     * @return array{0: string, 1: string}|null [resolved directory, matched
     *         reference root] — the root is what the ignore-rules anchor
     *         becomes for this search
     */
    private function resolveReferenceDir(string $path): ?array
    {
        if ($this->readRoots === [] || !self::pathLooksAbsolute($path)) {
            return null;
        }
        foreach ($this->readRoots as $referenceRoot) {
            $resolved = PathJail::resolveDir($referenceRoot, $path);
            if ($resolved !== null) {
                return [$resolved, $referenceRoot];
            }
        }

        return null;
    }

    /**
     * POSIX absolute or Windows drive-letter spelling — the only shapes that
     * can name a place under a reference root at all.
     */
    private static function pathLooksAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/') {
            return true;
        }

        return strlen($path) >= 2 && ctype_alpha($path[0]) && $path[1] === ':';
    }

    /**
     * The ignore ruleset governing this call.
     *
     * $searchRoot's own segments are removed from the exclude list, which is
     * the same "naming it is asking for it" hatch `Glob` already honours:
     * `path: "vendor/acme"` has to search vendor/acme, not report it empty.
     * The `.gitignore` layer gets the same treatment when the search base is
     * itself an ignored directory.
     *
     * C-CTX-4.f: $anchorRoot replaces the jail as the ruleset's home for a
     * reference search (the reference governs itself); it is null for every
     * in-jail search, which keeps the pre-C-CTX-4.f bytes exactly.
     */
    private function rulesFor(string $searchRoot, bool $includeIgnored, ?string $anchorRoot = null): IgnoreRules
    {
        if ($includeIgnored) {
            return IgnoreRules::new($anchorRoot ?? $this->jailRoot() ?? $searchRoot)
                ->withGitignore(false)
                ->withExcludedDirs([]);
        }

        $rules = IgnoreRules::new($anchorRoot ?? $this->jailRoot() ?? $searchRoot)
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
