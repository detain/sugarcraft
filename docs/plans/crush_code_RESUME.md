# crush_code.md — RESUME HERE

**Single entry point for continuing the `sugar-crush` audit plan.** Read this file
first, then `docs/plans/crush_code_worklog.md` for the round-by-round record.
Nothing here depends on a prior conversation's context.

---

## 1. The standing directive

**Run the plan to 100% without pausing.** Do not stop at phase boundaries to report
and wait. After committing a bundle, immediately brief and spawn the next one in the
same turn. Reporting progress is fine; *ending the turn to await approval* is not.

Stop only for (a) a decision genuinely the user's that cannot be resolved from the
request, the code, or a sensible default, or (b) an explicit instruction to pause.

Stated 2026-08-18: *"do not stop anymore keep going until the plan is 100% completed
unless you cannot proceed further without a decision from me or i told you to pause"*.

## 2. The loop — every bundle, no exceptions

1. **Implement** — spawn an agent with a brief carrying the ground truth you measured
   yourself (never the plan's line numbers; see §5).
2. **Review** — spawn a **separate** adversarial agent on the diff. Never the same
   agent, never skip this.
3. **Fix** — spawn a fix agent with the findings.
4. **Verify** — the *supervisor* runs the full suite personally. Do not trust an
   agent's reported totals.
5. **Commit** direct to `master`. No branches, no PRs.

"Don't pause" means don't stop *between* rounds, not skip rounds.

## 3. Sequencing rules

- **Functionality first.** Security/hardening and audit-instrument correctness are
  deferred to the end. **Defer the FIX, never the FINDING** — record every deferred
  item in `docs/plans/crush_code_hardening_backlog.md` with its probe, in the
  What/Where/Severity/Evidence/Step/Blocked-on format. The user's goal is to
  daily-drive sugar-crush while the security pass is still being worked.
- **Counts as functionality, fix it now:** frame-corruption bugs (over-wide rows —
  the diff renderer paints one line per row), automatic data loss, a confirmed RCE
  path.
- **Counts as deferrable:** path-containment gates, permission-surface tightening,
  tool-capability filtering, mutation registers / censuses / inventories.
- **Never remove dormant code — wire it or document it as an intentional seam.** The
  audit's own research agents made several "delete this" recommendations that were
  explicitly overridden. Honor that override.
- **Serialise anything touching the same file.** A *suite run* that loads a file
  another lane is editing shifts `file(__FILE__)` ranges against already-loaded
  reflection and produces phantom failures. Serialise the runs, not just the writes.

## 4. Environment facts

- Commit with a **plain `git commit`**. Never `-c core.hooksPath=/dev/null`, never
  `--no-verify` — the user wants a hook they add later to actually fire. There is no
  active git hook here (`.git/hooks/` is samples only, `core.hooksPath` unset).
- **Never run `caliber` anything.** The Caliber hooks were removed from
  `~/.claude/settings.json`; backup at `~/.claude/settings.json.bak-precaliber-removal`.
  The tracked `<!-- caliber:managed -->` blocks in CLAUDE.md/AGENTS.md are correct for
  machines that *have* Caliber and were deliberately left in place.
- **Never run a global `pkill`** — the `[p]hpunit` bracket trick still kills sibling
  agents' test runs. Kill only PIDs you started.
- Full suite: `vendor/bin/phpunit`, ~2m20s.
  **Allow a 600000ms Bash timeout** or a 2-minute default kills it with exit 143 and
  you will misread that as a failure.
- `failOnRisky`/`failOnWarning` are load-bearing: a warning-only kill is red *purely*
  via exit code while the banner still prints "OK, but there were issues!". Check `$?`,
  never the banner.
- `php-cs-fixer` is NOT installed here, and this lib is not cs-fixer-clean repo-wide.
  Don't report its absence; don't normalise unrelated files.
- Never commit a per-lib `composer.lock`; no `repositories[]` in a lib manifest.
  Verify with `php tools/check-path-repos.php --no-lib-path-repos` (must exit 0).

## 5. THE recurring defect — twenty-six rounds running

**A number or a claim must never travel without its domain.** A count, width, limit,
or behavioural claim that is true of one thing, written next to a different thing.
It has appeared in *every single round*, including inside the work of the agent
fixing the previous round's instance of it, and in the supervisor's own notes.

**Round 18 looked like progress and round 19 priced it.** B3's implementer self-caught
three instances, including a test whose NAME asserted a completeness its body did not
have — real progress. It then reported "28 mutations, 28 killed, 0 survivors". The
independent reviewer ran **55 mutations and found 9 survivors** plus 17 confirmed
findings. So self-catching does not substitute for the review round; it just moves where
the round's findings come from. **Never accept an agent's own mutation score as
coverage** — the number that matters is what a reviewer who did not write the code can
still break.

The single sharpest recurrence: `testAConnectExceptionIsTransient` passes through the
`TransferException` fallback, so replacing its named clause
(`$link instanceof NetworkExceptionInterface`) with `if (false)` survived 2863 tests. The
round before had the identical shape. **A test named after a clause is not a test of that
clause.**

Its companion, found repeatedly since: **tests pin the PRESENCE of a clause and not
its TRUTH.** One review ran 18 mutations — 13 died, and **all 5 survivors made a
clause false while keeping its keywords intact.** So:

- Measure before writing. Name the domain in the same sentence. Say the **unit**
  (this codebase has *estimated* chars/4 tokens vs *provider-counted* tokens, and
  they get confused constantly).
- Prefer deriving a value at runtime over writing a literal.
- A docblock clause nothing asserts is this defect in prose form. Either pin it
  behaviourally or name it as an honest gap — never add a presence check that looks
  like coverage.
- **Changing a fact falsifies every place that described the old one.** Sweep `src/`,
  `tests/`, `README.md` and `sugar-crush/docs/` — a past round's sweep missed `tests/`
  and shipped a stale claim.

## 6. Files that must not be touched by agents

`sugar-crush/phpunit.xml` (the supervisor's) · `/home/sites/sugarcraft/.sugar-crush/config.json`
(git-tracked; md5 must stay `05480c743aff302fd6c06c5a4a4c2210`) ·
`docs/plans/plans_cleaning.md` and `sugar-crush/python_port/` (the user's own
untracked work) · `docs/plans/crush_code_worklog.md` and this file (supervisor-owned).

`crush_code.md` is the plan — edit its status block inline as items land; it IS
tracked, contrary to an earlier belief.

## 7. Sandbox recipe for mutation testing (hand this to every agent)

`cp -a` — **never `cp -al`**, which preserves relative `vendor/sugarcraft/*` symlinks
that then dangle into a phantom `Interface "SugarCraft\Core\Model" not found`.
Re-point each relative symlink **explicitly** at `/home/sites/sugarcraft/<lib>`;
naive "absolutising" produced self-referential links for three separate agents. Copy
`.sugar-crush/`, `.vhs/`, `examples/`, `bin/`, `workflows/` too or ~10 fixture tests
fail. Assert `ReflectionClass::getFileName()` is inside the sandbox before believing
a run. Judge a mutation by whether the **targeted test file** flips green→red via
`$?` — never by suite totals.

## 8. Known-stable test facts

- The **1 legitimate skip** is `McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails`
  ("Would require mocking built-in functions"), in **`tests/MCP/McpClientTest.php`** —
  two files share that class basename, so cite the path, not the class. Leave it.
- `SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves` is a
  **pre-existing timing flake**. Don't skip it, don't weaken its assertion, don't
  report it as a finding.
- `src/Support/ToolIpcFiles.php:79` `private const STAT_REGULAR_FILE = 0o100000;` is
  an **octal file mode** — a false positive for any `100000` grep-and-replace.
- **The `tests/Cli` hang is DIRECTORY-scoped only, and this matters a lot.**
  `vendor/bin/phpunit tests/Cli` hangs (>4min, backlog E29) — but a single FILE inside it
  runs in **0.054s at rc 0**, and `--filter` against a single file is ~0.02s. Corrected
  2026-08-19: the old blanket "never judge green from a directory-scoped run" was
  discouraging the only affordable mutation harness in this suite. Use single-file runs
  freely for mutation loops; only the FINAL green/red judgement needs the full configured run.
- `tests/Cli/BootstrapSkillSkipsTest.php` run **alone** is rc=1 (`OK, but there were issues!
  Risky: 2`) on a clean tree and contributes 0 risky in the full suite — order-dependent,
  pre-existing, backlog **E36**. Do not chase it as a regression.
- **Six test files did not clear the backend-selection env chain** until `6bc5218b`; with
  either shell-out variable ambient the suite showed 1 error + 10 failures. Now handled by
  `tests/Support/BackendSelectionEnvSandboxTrait.php`, which holds the chain ONCE. If you add
  a `SUGARCRUSH_*` variable to backend selection, add it to that trait's `CHAIN`, not to a
  tenth hand-written list.
- `Chat::shouldPromptIdleCompaction()` **deliberately** duplicates `Runtime`'s
  version ("where Runtime instance is not directly available"). Don't collapse it by
  making `Chat` reach for a `Runtime` it deliberately does not hold.
- **The suite baseline moves every round, including between a bundle's implement and
  fix rounds. MEASURE `HEAD`'s total before briefing; never quote a remembered
  figure.** Bundle B2's brief quoted 6918/70996 when B1's fix round had already moved
  it to 6931/71073, and the implementing agent had to stash, run, and pop to find out.
  That is the supervisor committing the same defect §5 describes — a number written
  next to the wrong domain, here "the baseline" meaning two different commits.
- Two numbers in `tests/Tools/BuiltInToolCorpusTest.php` are censuses over `src/`
  (file count and declaration count) and `BinSugarcrushWiringTest::crushSourceFiles`
  is a data provider over every `src/*.php` file — so **adding a source file changes
  the suite total by more than the tests you wrote**, and both censuses plus their
  prose copies need updating in the same diff.
- **`vendor/bin/phpunit tests/Cli` HANGS at baseline** — over 4 minutes, killed at 250s —
  while the full configured run passes in ~2m26s and every `tests/Cli/*.php` file passes
  individually in under a second. A cross-test leak that `defaultTimeLimit=60` does not
  abort. Measured 2026-08-19 by B3's reviewer, pre-existing and not from that bundle. The
  consequence for every future round: **do not judge green from a directory-scoped run.**
  Judge from the full configured run, or from a single targeted FILE. Mutation work in
  particular has to use file-scoped or curated multi-directory sets.
- **`BASE_BACKOFF_MICROSECONDS = 500_000` → `1` survives 3188 tests**, because every backoff
  assertion is relational rather than literal. That is the "derive, don't hardcode" rule
  working as intended — but it means the prose figures ("500ms doubling, ~1.5s total") have
  no reader and will rot silently if the constant moves.

## 9. The plan lies about its own state — verify, don't trust

Corrected so far (all measured): every §12 line number is stale · §12's drafted text
for `Grep`/`Glob` would have *regressed* them by deleting guidance Phase 8 item 7
added · §12's `dispatchSkill()` fix does not compile (`App` has no
`environmentBlock`) · §12 asserts Grep is POSIX ERE — **it is GNU BRE** · lane D
F3–F7 already landed in `dad90b18` · the `Write` tool and `TerminalBackground::observe()`
are already wired · `StallDetector`'s call-site half is done and it is **not** blocked
on Phase 1 · `KEY_HELP_COLS` is 64, not the 58 the backlog claimed · tracker numbers
#83 and #85 each denote two different findings · #88's figure has eight successive
measurements, so re-measure it *after* a round lands, never before. · Phase 5 item 7's
"feed it from `AssistantMsg` usage data already flowing through `EngineBackend`/`Runtime`"
is false — usage dies at two seams: `Runtime::runBatch()` yields
`new AssistantMessage($content, $toolCalls, $reasoning)` and `Backend::complete()`
returns a `Message`, neither of which has any usage field, and
`grep tokensUsed src/Backend/EngineBackend.php` is empty. There are **three** seams,
not two — `completeAsync()`'s fork unserializes with `allowed_classes => false`. ·
Three providers compute an input/output split, not one: Bedrock, Vertex, **and**
`OpenAIProvider::calculateCost()`, which prices both halves and then reports only the
total. · `VertexProvider`'s *stream* emits the two halves as separate responses, so
streamed usage must be **summed**, not read off the last chunk — and that file's own
`completeStream()` docblock said the opposite. · **Phase 5 item 8 names a harmful
location**: `EngineBackend::runCompleteInChild()` wraps the whole agentic loop, so a
retry there replays every tool call the failed attempt already executed. The seam is
the four single-provider call sites (`Runtime::runBatch`/`runStreaming`,
`AgentManager::executeSubAgent`'s two branches). §10 recommendations 5 and 8 carry the
same instruction and are now marked ⚠ SUPERSEDED. · **Phase 5 item 9's
`MemoryStore::search()` route does not work**: `search()` is a case-insensitive
SUBSTRING match over content/type/tags across every scope, so a whole turn as the query
matches essentially nothing — recall built that way is permanently empty while looking
wired. · **Phase 5 item 10a's "additional working directories" line has no data
source** — zero hits for any multi-root concept in `src/`; the prerequisite is a
settings key plus a multi-root `PathJail` (backlog E26). · `MemoryScope::Local`
normalises to the on-disk scope **`agent`**, so the enum values are not the directory
names.

**Phase 2 measured 2026-08-19 (supervisor, read-only probes) — two queue items were
already done and one names a class that does not exist:**

- **Item 3 (`WorkflowEngine`/`WorkflowRegistry` in `Bootstrap::chat()`) is DONE.**
  `src/Cli/Bootstrap.php:374` passes `workflowEngine: self::workflowEngine($root, $permissionGate)`,
  and that factory (~390) deliberately uses `trustedConfigDirPath()` rather than
  `configDirPath()` because `WorkflowRegistry::load()` reaches a `.php` workflow through
  `require` — a directory whose contents get EXECUTED. Nothing to do.
- **Item 5 (`HookManager::loadFromFile()` in `Bootstrap::hooks()`) is DONE, and better
  than the plan's instruction.** `Bootstrap::hooks()` (1569-1599) calls
  `registerBuiltIns()` and then `loadEntries(self::hookFileEntries($path), $path)` per
  candidate file, fail-closed into `PermissionConfigException`, deduplicated by realpath,
  with an unreachable-ancestor refusal and a per-project trust opt-in. It does NOT call
  `HookManager::loadFromFile()` directly, on purpose: entries are read ONCE PER PROCESS
  so a session cannot install hooks into itself mid-session (a `>> ~/.sugar-crush/hooks.yaml`
  plus a Ctrl+P provider switch used to do exactly that). The plan's literal instruction
  would re-read the file on every hook-manager build and reopen that hole.
  Its stated prerequisite is also genuinely satisfied: `df0a563b` really is Phase 1
  item 2, and `ScriptHook::EXIT_ASK = 3` / `EXIT_MODIFY = 4` exist.
- **Item 7 names `LspTool`, which does not exist.** There is no `src/Tools/LspTool.php`.
  What exists is `src/LSP/` — `LspClient`, `LspConnection(Interface)`, `LspCache(Interface)`,
  `LspResponse`, and two exception types. So item 7 is "write the tool", not "add
  `implements Tool`" to something.
- **Item 4's own source confirms it is unwired**, so the queue entry is right for once:
  `src/Commands/CommandLoader.php`'s class docblock says "NOT YET REACHABLE FROM
  bin/sugarcrush: nothing constructs a CommandLoader in production yet" and defers the
  `$ARGUMENTS`/`$1`/`` !`cmd` ``/`@file` substitution. That docblock also claims
  `src/Chat.php` "is owned by a concurrent track" — stale prose to fix when item 4 lands.
- **Item 8 (`CommandBackend` → `StreamingCommandBackend`) is HARMFUL AS WRITTEN.** Full
  measurement in `/tmp/…/scratchpad/c1-measured.md`; the short form: both classes take
  `string|array` and receive the identical stdin payload, but `StreamingCommandBackend::complete()`
  does `rtrim($line, "\r\n")`, drops empty lines, and `implode('', $tokens)` — so the
  wrapper `CommandBackend`'s own docblock recommends (`curl … | jq -r '.content[0].text'`)
  comes back as one run-on line with every newline and blank line deleted. It also carries
  a blanket `$timeout = 120` total-request cap (against the standing directive) whose
  expiry message reports ITERATIONS where the user configured SECONDS, and a no-op
  ternary `is_array($this->command) ? $this->command : $this->command`. The two output
  protocols are mutually exclusive; wire the dormant seam behind its own opt-in instead of
  swapping the existing one.
- **Item 1's "duplicate `McpClient`" is a BASENAME collision, not a PSR-4 one.**
  `SugarCraft\Crush\McpClient` (stdio/JSON-RPC to Claude Code) and
  `SugarCraft\Crush\MCP\McpClient` (Guzzle HTTP) coexist legally. The rename is still
  worth doing — it is what disambiguates `tests/McpClientTest.php` from
  `tests/MCP/McpClientTest.php`, which has already caused one mis-citation of the single
  legitimate skip. The root class has **no `src/`, `bin/` or `examples/` call sites**; it is
  a dormant seam reached only from its own test.

## 10. Current state and the queue

**Current state: see the "Execution status" block at the top of `crush_code.md`** for
what is complete, and §11 below for what is next. Verify the suite yourself before
believing any number written anywhere.

**CURRENT STATE, 2026-08-19.** Last CODE commit is **`7ed551b6`** (bundle E33, the reminder
pile-up), supervisor-verified at 7285 / 76294 / 1, exit 0. Bundle C1 is `6bc5218b`
(7276/76239/1). **Phase 5 is complete. Phase 2 items 1, 3, 5, 6 and 8 are complete** — 3 and 5
needed no code and the plan's premise about both was measured false (see §9).

## BUNDLE C3 IS COMMITTED — `3b0ba8fe`. Phase 2 item 2 done.

Supervisor-verified TWICE, and the second run is the one that counts: **7387 / 76813 / 1, exit 0
against LOCAL sibling symlinks** (and 7387 / 76811 / 2 against Packagist copies — see the vendor
note below for why the two differ). Implementation + adversarial review (17 findings, 5 surviving
mutations) + two fix rounds. `src/` is **276** files.

**The security defect is closed, and I verified it myself rather than taking the agent's word.**
`.mcp.json` now requires the root to be listed under `trustedProjectMcp` in the user's own
`~/.sugar-crush/config.json`. Measured personally, three ways: untrusted root in `plan` → payload
never runs; untrusted in `default` → never runs; **grant written → runs** (the positive control
matters — a gate that simply broke MCP would also show "no payload" and would have looked like a
pass). The refusal is visible through the real `chat()` path, naming the root and the key to add.

Findings 6, 7, 10-14 landed in fix round B, plus two hand-offs: the `error_log()` diagnostic is now
asserted rather than silenced, and the `projectTierRefusals()` count got its missing domain — "TEN"
is true of dot-DIRECTORY paths, and `.mcp.json` is a bare dot-file the derivation cannot see, so
the figure a reader wants is **EIGHT** paths feeding that map.

**Two things fix round B reported and deliberately did not change — both still open, my call:**

1. `mcpClient()`'s untrusted branch is guarded `$canonicalRoot === false || !projectMcpIsTrusted(…)`,
   and the `false` arm is **unreachable** there (`is_file()` already succeeded on a path composed
   from `$canonicalRoot`). Same shape as the dead `stdClass` clause round A deleted — but here the
   dead arm is the fail-CLOSED direction on a security gate. **Decision: keep it, document it as
   deliberate belt-and-braces.** A later reader deleting it "because it is unreachable" is exactly
   how a gate acquires a hole, and the cost of keeping it is one branch.
2. Neither refusal branch writes `$mcpClients`, so an untrusted root re-stats and re-checks trust on
   every `tools()` call. Harmless and idempotent; the memo docblock reads as if every outcome is
   cached, which is the claim to correct, not the behaviour.

## ⚠️ VENDOR STATE IS NOW A THING TO CHECK, and it silently changes what "green" means

Mid-round, something ran `composer update` and replaced `sugar-crush/vendor/sugarcraft/*`'s
symlinks with real Packagist directories — so the suite stopped testing the monorepo's own
`candy-*` and started testing published copies, with no signal except a skip count moving 1 → 2
(`GitignoreAwarenessTest::testTheMonorepoPathRepoSymlinksAreNotFollowed` self-skips when there are
no symlinks). **A 2-skip run means you are not testing the monorepo.** It also left an unrelated
third-party bump in the tracked root `composer.lock` (aws-sdk 3.390.4 → 3.393.1 and others), which
I reverted rather than letting it ride along inside a feature commit.

Restore local wiring with the documented loop, and note it is `sugarcraft/*` scoped so third-party
versions do not move:

    php tools/check-path-repos.php --fix --strict-closure
    cd sugar-crush && composer update 'sugarcraft/*' --quiet
    cd .. && git checkout -- '*/composer.json'      # NEVER commit these
    php tools/check-path-repos.php --no-lib-path-repos   # must exit 0

`vendor/` is gitignored, so reverting the manifests keeps the symlinks AND a clean tree. **Tell
every agent not to run `composer install`/`update`** — it silently undoes this.

## THE COUNT, and two items that had fallen out of the queue

**47 of 75 plan items complete (63%), 28 left.** Counted by item from `crush_code.md`'s phase
sections: Phase 0=14, 1=3, 2=9, 3=5, 4=7, 5=10, 6=6, 7=6, 8=15.

Two corrections the arithmetic forced, both of them errors in THIS file:

- **"PHASE 5 IS COMPLETE" was wrong.** Item **10b** — differentiate the five hardcoded
  `AgentDefinition` preset prompts, currently generic one-liners that do not mention the skills
  they grant — is untouched. B3 shipped 10a (the `EnvironmentBlock` OS-version line) only.
  Phase 5 is items 1-9 + 10a.
- **Phase 4 item 6 was missing from the queue entirely.** Real subcommands (`mcp list`,
  `session list`/`delete`, `models`, `doctor`, `completion bash|zsh|fish`), `--config <path>`, a
  0/1/2 exit-code convention, and warn-not-silently-drop on an unrecognised `--output-format`.
  Never done, just absent from §11.

The item count is not effort. Phase 2 item 4 alone is bigger than all of Phase 7; Phase 8's nine
remaining items are mostly small. And the hardening backlog (E1-E42, and growing as rounds land) is
a SECOND queue, deliberately held to the end.

**The two lessons this session added, both from C1:**

0. **A carefully verified argument can answer the wrong question.** For C3 I reasoned that
   `unrestricted: true` was safe because every main-agent tool call rides the PreToolUse chain
   exactly as `Bash` does, wrote the reasoning down, and asked the implementer to verify it end to
   end. It verified. It was also irrelevant: that gate sees tool CALLS and never sees
   `proc_open()`. Before trusting a safety argument, ask what it does NOT cover — and check
   whether the project already has a boundary for this threat class rather than reasoning a new
   one from scratch.
0b. **Do not edit `docs/plans/*` while a round is live.** Two agents in a row have reported
   `git status` moving under them because I committed backlog edits mid-round. Either hold docs
   edits until the round returns, or tell the agent up front that `docs/plans/*` will move and
   is not part of its bundle.
1. **A reproduction fixture can fail to reproduce, and then the test passes on the broken
   code.** My SIGTERM fixture (`trap '' TERM; sleep 8`) put the trap in a script file, so
   `proc_open`'s direct child was the `sh -c <script>` wrapper — which does NOT ignore SIGTERM.
   It died in ~50ms, orphaned the trapping shell, and the bug became invisible. Always confirm
   the fixture reproduces the defect BEFORE writing the assertion against it.
2. **An overstatement passed along is indistinguishable from one invented.** I forwarded a
   reviewer's "no newline AND no carriage return, for any command whatsoever" without checking
   the CR half, which was false and which the reviewer's own next finding contradicted. Reading
   a finding is not verifying it.

Older but still live: **"survives the full suite" is not "is correct" — it is only "nothing
measures this"**, and **a fix lifted from a reviewer's mutation is still a mutation**, chosen
to probe coverage rather than to be right.

**Historical: Bundle B3 was COMMITTED as `a72c5b0a`** (Phase 5 items 8, 9, 10a), with
its review and fix rounds done. Supervisor-verified on a clean tree: **7204 / 75944 / 1,
exit 0**. Worklog section "Bundle B3 — review + fix rounds" carries the nine mutation
survivors, the one real code bug (`MemoryBlock::MAX_BYTES` was not a ceiling — 11,119 bytes
measured against a 4,096 budget, with the false promise in the model-facing header), the
five corrections the fix agent made to the supervisor's brief, and the two review findings
that were against the supervisor's own backlog rather than the code.

**PHASE 5 IS COMPLETE as of 2026-08-19.** E21 committed as `261ac59d`, supervisor-verified at
**7237 / 76136 / 1, exit 0**. **In flight: C1** (Phase 2 items 1 and 8), implementation round,
brief at `/tmp/…/scratchpad/c1-brief.md` — self-contained, so re-spawn against it if that round
was lost. Then review → fix → verify → commit as usual.

**Two lessons from E21 that apply to every round from here:**

1. **"Survives the full suite" is not "is correct" — it is only "nothing measures this".** The
   `groupIntoPairs()` fix the supervisor prescribed came from a reviewer's mutation that
   survived all 7221 tests. Measured, it took `exchangesToSummarize()` from 10 exchanges to 0
   on any history with a reminder after each prompt — i.e. **every session that reaches 85%,
   since 70% fires first** — which would have made E21 fall back to the heuristic forever,
   silently, while looking wired. A fix prescribed from a mutation is still a mutation: chosen
   to probe coverage, not to be right.
2. **`ContextCompactor` had four victims, not the one the implementation round reported.** The
   unreported one: two consecutive assistant turns, where the second **overwrote** the first —
   and `/compact`'s landing report, the spend-cap refusal and the 95% refusal all append an
   assistant message onto a history already ending in one. All four fixed in `261ac59d`.

**PENDING EDIT TO `crush_code.md`, deliberately not yet made.** Its status block's
`**Complete:**` line reads "Phase 2 item 6" and must also list **Phase 2 items 3 and 5**,
which §9 records as measured already-done with no code required. E21's agent already updated
the Phase 5 half of that block, so item 6 and "Phase 5 items 1-7" are correct as they stand.
The edit is held back only because **C1's agent is live in `crush_code.md`** (it will mark
items 1 and 8) and a second writer there loses one of the two edits. Make it once C1 commits.

**C1's in-flight state, for recovery.** As of this note the working tree carries two
completed renames and nothing else: `sugar-crush/src/McpClient.php` →
`src/ClaudeCodeMcpClient.php` and `tests/McpClientTest.php` → `tests/ClaudeCodeMcpClientTest.php`
(both showing as `RM` in `git status`). If that round was lost mid-flight, the brief at
`/tmp/…/scratchpad/c1-brief.md` is self-contained — re-spawn against it; it is safe to re-run
over a tree where the renames already happened, but check whether the class and namespace
inside those two files were renamed too before assuming the item is half-done.


## 11. QUEUE — in order

- ~~**B1** Phase 5 items 4,5 — provider `contextWindow()` wiring + live 85%/95%
  compaction tiers.~~ **DONE `08cc1b6a`** (6931/71073/1, exit 0).
- ~~**B2** Phase 5 items 6,7~~ **DONE `738c586c`** (7089/75695/1, exit 0). Item 7
  complete; **item 6 is 🟡 partial** — `/compact` asks the model, the automatic 85% tier
  still uses the heuristic (backlog E21), which is the lossier path and where most real
  compactions happen. Pick E21 up before calling Phase 5 finished.
- ~~**B3** Phase 5 items 8,9,10a.~~ **DONE `a72c5b0a`** (7204/75944/1, exit 0).
- ~~**E21** — finish Phase 5 (wire the automatic 85% tier to the model).~~ **DONE `261ac59d`**
  (7237/76136/1, exit 0). It also fixed four silent-loss bugs in
  `ContextCompactor::groupIntoPairs()` and one spend-cap bypass it had itself introduced.
  **This row used to claim "PHASE 5 IS COMPLETE". That was wrong** — item **10b** (the five
  preset prompts) is untouched, so Phase 5 is items 1-9 + 10a. See §10's count.
- ~~**E33** — the 70% reminder piling up in permanent history.~~ **DONE `7ed551b6`**
  (7285/76294/1, exit 0). Deduplicated: strip unconditionally, append only when the tier fires.
  Also fixed a bug the review found and I had not thought to look for — **`/rewind` was
  reconstructing every non-`assistant` checkpoint row as a USER message**, so a rewound reminder
  came back as the user's own words on the provider wire, and the dedup's own role guard made it
  permanent. Same coercion mis-roled `_Request cancelled._`, the tier report and
  `_Permission denied_`. Fixed with a `'system'` arm, zero fixture churn. The `tool` case stays
  coerced **by necessity** — `Role` has three cases and no `tool`, and nothing serialises one.
  Residual: **E38** (a compaction folds the reminder's full text into a `[summary] ` line the
  dedup cannot match, so the pile-up changes shape rather than ending).
- ~~**C1** Phase 2 items 1,8 — the `ClaudeCodeMcpClient` rename and the streaming tier.~~
  **DONE `6bc5218b`** (7276/76239/1, exit 0). Item 8 carried far more than the plan said: the
  dormant class **could not return a newline from any command whatsoever**, so five doc sites
  were recommending a wrapper that cannot exist. Resolved by making a terminated blank line
  mean a literal newline. Also fixed an unbounded 100%-CPU spin, an escape hatch a
  `trap '' TERM` child held for 8s against a 1s deadline, and `CommandBackend` returning an
  EMPTY answer whenever the whole reply was `0` (`?: ''`). **Two claims withdrawn, not
  delivered:** `$onToken` fires but the blocked loop means nothing paints it — measured six
  callbacks, ZERO render ticks. Backlog **E34** (non-blocking rewrite) and **E35**
  (cancellation) carry the remainder. That is the bundle's one FUNCTIONAL deferral and it is
  deliberate.
- ~~**C2** Phase 2 item 3 — `WorkflowEngine`/`WorkflowRegistry` in `Bootstrap::chat()`.~~
  **ALREADY DONE** — `Bootstrap.php:374` passes it. Measured 2026-08-19, see §9. No work.
- ~~**C3** Phase 2 item 2 — MCP tools reachable.~~ **DONE `3b0ba8fe`** (7387/76813/1, exit 0
  against local siblings). Three rounds: implement, adversarial review, two fix rounds. The
  headline is not the wiring but the gate — see §10. `trustedProjectMcp` is a NEW key, verified by
  me in all three directions including the positive control. E40/E41/E42 carry the deferred
  remainder.
- **W1 — IN FLIGHT: the user's live render bug, and it jumps the queue.** Reported while
  daily-driving: long assistant lines "not wrapped but cut off", then a blank line, then unrelated
  content. Measured root cause: the renderer emits a row **204 columns wide in a 100-column
  terminal**, the terminal soft-wraps it, and candy-core's absolute `cursorTo()` then paints later
  rows at stale coordinates. Nothing was cut — the tail is in the frame bytes. One unpassed
  argument: `renderView()` computes `cols - SHELL_CHROME_COLS` correctly and `renderHistory()`
  forwards it to tool results and diffs, but builds the Markdown renderer as
  `new Markdown($theme->markdown)` — and candy-shine's word wrap is **opt-in, default OFF**.
  `renderStreamingTurn()` has the same defect and receives no width at all.
  **Half 1 (pass the width) is not the whole fix**: candy-shine deliberately never wraps code
  blocks or tables, so a fenced block with a long line keeps the bug fully reproducible, and those
  are constant in a coding agent's replies. Half 2 is a frame-level width invariant, preferring
  `Width::wrapAnsi()` (content-preserving) over `Width::truncate` (which deletes) for reply BODY
  text. Four hazards named in the brief: zone sentinels come in pairs and an unmatched open marker
  makes `Scan::parse()` throw and costs the whole frame its click zones; image markers share
  U+E000 with those sentinels; `mb_substr()` cannot see an SGR escape; and wrapping makes the
  transcript taller, which interacts with the existing height clip and the scroll arithmetic.
  Brief at `/tmp/…/scratchpad/w1-brief.md`, self-contained.
  **No test in 7,387 measured row width against the terminal — that absence is why this shipped.**
- **Phase 5 item 10b** — differentiate the five hardcoded `AgentDefinition` preset prompts. Small,
  and it is what stops Phase 5 being finished.
- **Phase 4 item 6** — real subcommands (`mcp list`, `session list`/`delete`, `models`, `doctor`
  health-check distinct from the model-invoked tool, `completion bash|zsh|fish`), `--config <path>`,
  a 0/1/2 exit-code convention, warn-not-silently-drop on an unrecognised `--output-format`. **This
  row was missing from the queue entirely** until the item arithmetic caught it.
- **C4** Phase 2 item 4 — **the biggest remaining item.** Wire `CommandLoader::loadAll()`
  AND build the missing template-substitution engine (`$ARGUMENTS`, `$1`, backtick-cmd,
  `@file` — none exist; a `grep -rn ARGUMENTS src/` returns three hits, all unrelated prose).
  Shell-out must use ReactPHP `Process`, never blocking `shell_exec`. This is what makes the
  README's "loadable, not loaded" note obsolete.
  **THE STRUCTURAL BLOCKER THE PLAN DOES NOT MENTION**, measured 2026-08-19, full write-up at
  `/tmp/…/scratchpad/c4-measured.md`: `CommandRegistry` is **entirely static** — no
  constructor, no instance state, `all()` returns a hardcoded literal, and
  `grep -rn 'new CommandRegistry'` returns ZERO. There is no instance for loaded commands to be
  injected into, so the bundle's FIRST decision is static-merge-point vs instance registry (the
  latter touches `Renderer::renderSlashMenu()`, the palette, and the two tests below), not
  template syntax.
  **AND TWO INVENTORY TESTS WILL RED IN A MISLEADING WAY**, both in
  `tests/Commands/SlashDispatchTest.php`. `testEverySlashVisibleRegistryRowHasALiveDispatchHandler()`
  (:98) asserts **`$next->inFlight === false`** for every slash-visible row — but a file-based
  command MUST set `inFlight = true`, because sending its template to the model IS its
  behaviour. Its failure message will tell the implementer to add a dispatch arm, which is the
  wrong remedy. `testEveryDispatchArmIsAdvertisedOrDeliberatelyUnadvertised()` (:158) derives
  arm names from `dispatchCommand()`'s own SOURCE and needs the same third case. **Do not let
  either be relaxed** — both are load-bearing completeness inventories; teach them that a
  file-based row dispatches to the model by design and assert that positively.
  `CommandSpec::isFileBased()` already exists to express it. Also: `CommandLoader`'s class
  docblock defers this work because "`src/Chat.php` is owned by a concurrent track" — **that is
  now STALE** and should be corrected as part of the bundle.
- ~~**C5** Phase 2 item 5 — `HookManager::loadFromFile()` in `Bootstrap::hooks()`.~~
  **ALREADY DONE, and deliberately not by the route the plan names** — `Bootstrap::hooks()`
  loads entries once per process so a session cannot install hooks into itself mid-session.
  The prerequisite checks out too (`df0a563b` is Phase 1 item 2; `ScriptHook::EXIT_ASK = 3`).
  Measured 2026-08-19, see §9. No work.
- **C6** Phase 2 item 7 — **write** `LspTool implements Tool` over `src/LSP/LspClient.php`.
  The plan says "add `implements Tool`"; there is no `src/Tools/LspTool.php` at all, and
  measured 2026-08-19 the **whole `src/LSP/` subsystem has zero production users** — the grep
  for `LspConnection`/`new LspClient` outside `src/LSP/` is empty. So the item is four pieces
  of work, not one: write the tool; choose its surface over `LspClient`'s
  definitions/references/hover/symbols/codeActions/diagnostics API; construct a connection
  (`LspConnection::connect()` **spawns the server with `proc_open`**, so this needs server
  discovery/config); and degrade when no language server is installed, which is the common
  case and the real design work. **Its own bundle — do not fold it into C1.** Note
  `connect()`'s `float $timeout = 30.0` is a language-server request timeout, NOT an LLM
  completion timeout, so the no-blanket-timeout directive does not apply to it — say that in
  the brief so nobody "fixes" it. Full measurement in `/tmp/…/scratchpad/c1-measured.md`.
- **D** Phase 3 items 2-5 — `candy-focus\FocusRing` in `Tui\Pane`; `sugar-veil`
  `withClickOutsideDismiss()`; `candy-sprinkles\Table`; `strlen()` padding fixes.
- **E** Phase 6 items 1-6 — **item 1's `__DIR__` bug is largely already fixed**, so do
  not brief it as the cheap opener. Measured 2026-08-19: `WorktreeConfig`'s old
  `__DIR__ . '/../../../.sugar-crush/config.json'` read is now the named seam
  `defaultConfigDir()` (`\dirname(__DIR__, 3)`) with `ContainedPath` gating in three
  places, and its docblock records the measured escape it closed (a `.worktreeinclude`
  line of `../secret/id_rsa` read AND wrote outside the checkout). What is genuinely left
  is the half that file explicitly defers: `dirname(__DIR__, 3)` is the directory
  CONTAINING the package, which under a composer install is `vendor/sugarcraft/` and not
  where anyone's config lives — point it at the project root / user config dir the way
  `Bootstrap` does. Also still true: nothing in `src/` constructs a `WorktreeManager`, so
  wiring it is part of the item ("DORMANT IS NOT UNGATED" is that file's own doctrine).
  Then: layered settings files; `tools.allow`/`deny`; permission block;
  keybindings + statusLine; `--model`/`--permission-mode` flags.
- **F** Phase 7 items 3-6 — the authoring/reference docs. Also fix README's stale
  built-in-hooks list (omits `BashEscapeDenyHook`).
- **G** Phase 8 items 3,4,6,8,9,10,11,13,15 — `StallDetector` render branch (only the
  paint is left); split-pane compositor fate; VHS demos; repo-map; `Grep`'s missing
  `InstructionFileLoader` wiring; proactive git diff in `EnvironmentBlock`;
  `loadRoot()` monorepo-parent awareness; `Task` tool (epic); file-watching (note only).
- **Phase 2 item 9** — unified `crush-plugin.json` + `PluginLoader`. Explicitly the
  deferred larger half; do after items 1-7.
- **LAST — the hardening pass:** `docs/plans/crush_code_hardening_backlog.md`, 50+
  items and growing as each round appends.
