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

## 5. THE recurring defect — seventeen rounds running

**A number or a claim must never travel without its domain.** A count, width, limit,
or behavioural claim that is true of one thing, written next to a different thing.
It has appeared in *every single round*, including inside the work of the agent
fixing the previous round's instance of it, and in the supervisor's own notes.

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
  ("Would require mocking built-in functions"). Leave it.
- `SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves` is a
  **pre-existing timing flake**. Don't skip it, don't weaken its assertion, don't
  report it as a finding.
- `src/Support/ToolIpcFiles.php:79` `private const STAT_REGULAR_FILE = 0o100000;` is
  an **octal file mode** — a false positive for any `100000` grep-and-replace.
- `Chat::shouldPromptIdleCompaction()` **deliberately** duplicates `Runtime`'s
  version ("where Runtime instance is not directly available"). Don't collapse it by
  making `Chat` reach for a `Runtime` it deliberately does not hold.

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
`grep tokensUsed src/Backend/EngineBackend.php` is empty.

## 10. Current state and the queue

**Current state: see the "Execution status" block at the top of `crush_code.md`** for
what is complete, and §11 below for what is next. Verify the suite yourself before
believing any number written anywhere.

## 11. QUEUE — in order

- ~~**B1** Phase 5 items 4,5 — provider `contextWindow()` wiring + live 85%/95%
  compaction tiers.~~ **DONE `08cc1b6a`** (6931/71073/1, exit 0).
- **B2** Phase 5 items 6,7 — model-driven `generateExchangeSummary()`; instantiate
  `TokenTracker`, cost readout in the status bar, spend cap (`SUGARCRUSH_MAX_COST` or
  `/budget $N`). Adds a slash command, so it must satisfy Phase 4's arm→registry
  inventory.
- **B3** Phase 5 items 8,9,10a — provider retry with backoff in
  `EngineBackend::runCompleteInChild()`; `MemoryStore` folded into
  `buildSystemPrompt()`; `EnvironmentBlock` OS-version + additional-dirs lines.
- **C1** Phase 2 items 1,8 — rename the duplicate `src/McpClient.php`
  (→ `ClaudeCodeMcpClient`); swap `CommandBackend`→`StreamingCommandBackend` when
  `$SUGARCRUSH_BACKEND_CMD` is set.
- **C2** Phase 2 item 3 — construct `WorkflowEngine`/`WorkflowRegistry` in
  `Bootstrap::chat()`, thread `workflowEngine:` into `Chat`.
- **C3** Phase 2 item 2 — `Bootstrap::mcpClient($root)` reading `.mcp.json`, MCP tools
  wrapped as `Tools\Tool` adapters into `Bootstrap::tools()`.
- **C4** Phase 2 item 4 — **the biggest remaining item.** Wire `CommandLoader::loadAll()`
  AND build the missing template-substitution engine (`$ARGUMENTS`, `$1`, backtick-cmd,
  `@file` — none exist). Shell-out must use ReactPHP `Process`, never blocking
  `shell_exec`. This is what makes the README's "loadable, not loaded" note obsolete.
- **C5** Phase 2 item 5 — `HookManager::loadFromFile()` in `Bootstrap::hooks()` after
  `registerBuiltIns()`. **VERIFY the prerequisite first:** the plan sequences this
  after Phase 1 item 2's `ask`/`modify` exit-code extension; item 2 is marked done at
  `df0a563b` ("permission-system consolidation"), which is not obviously the same
  thing. Probe before briefing.
- **C6** Phase 2 item 7 — `LspTool implements Tool` over the existing `LspClient`.
- **D** Phase 3 items 2-5 — `candy-focus\FocusRing` in `Tui\Pane`; `sugar-veil`
  `withClickOutsideDismiss()`; `candy-sprinkles\Table`; `strlen()` padding fixes.
- **E** Phase 6 items 1-6 — `WorktreeConfig`'s `__DIR__`-relative bug (cheap, do
  first); layered settings files; `tools.allow`/`deny`; permission block;
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
