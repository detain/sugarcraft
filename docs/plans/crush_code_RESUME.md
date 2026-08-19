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

## 5. THE recurring defect — nineteen rounds running

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

**As of 2026-08-19 Bundle B3 is COMMITTED as `a72c5b0a`** (Phase 5 items 8, 9, 10a), with
its review and fix rounds done. Supervisor-verified on a clean tree: **7204 / 75944 / 1,
exit 0**. Worklog section "Bundle B3 — review + fix rounds" carries the nine mutation
survivors, the one real code bug (`MemoryBlock::MAX_BYTES` was not a ceiling — 11,119 bytes
measured against a 4,096 budget, with the false promise in the model-facing header), the
five corrections the fix agent made to the supervisor's brief, and the two review findings
that were against the supervisor's own backlog rather than the code.

**In flight: E21** — the implementation round of "wire the automatic 85% compaction tier to
the model", brief at `/tmp/…/scratchpad/e21-brief.md` (330 lines, measured at `a72c5b0a`,
baseline 7204/75944/1). Recovery if that round's result was lost: the brief is
self-contained, so re-spawn against it. **Then review → fix → verify → commit as usual —
E21 has NOT been reviewed yet.** If the tree is clean and `git log` shows an E21/Phase 5
item 6 commit, that round finished; move to C1 in §11.

**`crush_code.md`'s status block still needs Phase 2 items 3 and 5 marked complete** (see
§9 — measured already-done, no code needed) and item 6 promoted from 🟡 to ✅ once E21
lands. Held back deliberately while E21's agent is running, to avoid two writers on that
file.

## 11. QUEUE — in order

- ~~**B1** Phase 5 items 4,5 — provider `contextWindow()` wiring + live 85%/95%
  compaction tiers.~~ **DONE `08cc1b6a`** (6931/71073/1, exit 0).
- ~~**B2** Phase 5 items 6,7~~ **DONE `738c586c`** (7089/75695/1, exit 0). Item 7
  complete; **item 6 is 🟡 partial** — `/compact` asks the model, the automatic 85% tier
  still uses the heuristic (backlog E21), which is the lossier path and where most real
  compactions happen. Pick E21 up before calling Phase 5 finished.
- ~~**B3** Phase 5 items 8,9,10a.~~ **DONE `a72c5b0a`** (7204/75944/1, exit 0).
- **E21 — finish Phase 5.** Brief written and ready: `/tmp/…/scratchpad/e21-brief.md`
  (327 lines, measured against the tree; includes the four traps and the recommended
  park-the-submission design). The automatic 85% compaction tier still uses the heuristic
  and never the model, so Phase 5 item 6 is 🟡 not ✅. Wiring it means parking a
  submitted draft behind a compaction round-trip and re-siting the 95% blocking check
  into that continuation. The seam is already built and tested. Do this before calling
  Phase 5 done.
- **C1** Phase 2 items 1,8 — rename `src/McpClient.php` → `ClaudeCodeMcpClient` (a
  BASENAME collision, not a PSR-4 one; no production call sites) and wire the dormant
  `StreamingCommandBackend`. **Item 8 is harmful as written** — swapping it onto
  `$SUGARCRUSH_BACKEND_CMD` deletes every newline and blank line from the reply and adds a
  blanket 120s cap. Ground truth measured and written up:
  `/tmp/…/scratchpad/c1-measured.md`, summarised in §9. Wire it behind its own opt-in and
  fix the timeout + its wrong-unit message either way. **BRIEF IS WRITTEN AND READY:**
  `/tmp/…/scratchpad/c1-brief.md` (195 lines).
- ~~**C2** Phase 2 item 3 — `WorkflowEngine`/`WorkflowRegistry` in `Bootstrap::chat()`.~~
  **ALREADY DONE** — `Bootstrap.php:374` passes it. Measured 2026-08-19, see §9. No work.
- **C3** Phase 2 item 2 — `Bootstrap::mcpClient($root)` reading `.mcp.json`, MCP tools
  wrapped as `Tools\Tool` adapters into `Bootstrap::tools()`.
- **C4** Phase 2 item 4 — **the biggest remaining item.** Wire `CommandLoader::loadAll()`
  AND build the missing template-substitution engine (`$ARGUMENTS`, `$1`, backtick-cmd,
  `@file` — none exist). Shell-out must use ReactPHP `Process`, never blocking
  `shell_exec`. This is what makes the README's "loadable, not loaded" note obsolete.
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
