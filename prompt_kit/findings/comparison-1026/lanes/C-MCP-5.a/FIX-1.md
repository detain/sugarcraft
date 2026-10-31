# C-MCP-5.a — round-1 fixer report (verdict-first)

VERDICT: NO FIX LANDED — both brief-carried findings are PHANTOMS vs disk.
No commit made; lane tip unchanged at 1c8166eab (base 288b81f11 + 2 picks
be7c88bd0 code+test, 1c8166eab HOOKS.md), tree clean. Reasons + proofs below.

## 0. Authoritative findings file is missing
- Brief cites REVIEW-1.md (9711 B, lead-stat-verified) at
  prompt_kit/findings/comparison-1026/lanes/C-MCP-5.a/REVIEW-1.md.
- Disk: that dir contains ONLY the builder's untracked REPORT.md (6696 B,
  mtime 23:18). No REVIEW-1.md anywhere: lanes/ has tracked REVIEW-1.md for
  sibling lanes (C-MCP-6.a, C-MCP-3.b) but NO C-MCP-5.a lane dir in the lane
  worktree; `git log --all -- '*C-MCP-5.a*'` is empty in both worktrees — a
  review file for this lane was never committed on any ref. /tmp/opencode has
  no MCP-5 review md after 21:00. (Gotcha recorded: `-path "*C-MCP-5*"`
  matches the whole worktree because its dirname contains the step id.)
- If a real r1 review exists elsewhere, re-issue it; this fixer found nothing
  to act on and per the FIRST-ACTION rule surfaced instead of guessing.

## 1. MAJOR claim — "read-only guarantee unenforced at list time": NOT ON DISK
Claimed shape: `ToolCatalog::build($this->config, 'auto', $writer)` applies the
generic enabled filter so a config-enabled Bash lands in tools/list.
- That call form has ZERO grep hits repo-wide (src+tests). Real signature:
  src/Tools/Catalog/ToolCatalog.php:200 `public static function
  build(ToolBuildContext $context): array` — no config argument;
  ToolBuildContext is final readonly with no config field.
- The serve path (src/MCP/Serve/McpServeRoster.php, 120 L) IS the prescribed
  fix, already shipped at be7c88bd0: public const NAMES = ['Read','Glob',
  'Grep','Ls','RepoMap']; it builds the catalog (:98) then name-PICKS only
  NAMES, throwing \RuntimeException when a required name is absent or when
  ToolCatalog::permissionOf($name) !== Read. File docblock: "@throws
  ... Fail-loud by design". Narrowed-at-construction + fail-loud-on-missing/
  non-Read = exactly what the finding asks to add.
- Consequence: a config-enabled Bash is STRUCTURALLY impossible in
  tools/list — the name-pick discards everything unlisted before list AND
  before call. tools/call of "Bash" returns isError "unknown tool" (host
  resolves only $this->tools[$name]); there is no name-resolved route to a
  mutating tool, so the "CRITICAL if reachable" condition is not reachable.
- The requested pin ("enabled Bash ABSENT from tools/list, RED under current
  code") cannot be written honestly: current code is already GREEN for that
  property (tests/MCP/Serve/McpServeHostTest.php::
  testToolsListHoldsExactlyTheFiveReadOnlyNames asserts the list === NAMES;
  testBashIsUnknownOnTheServeSurface pins the call arm). Only a vacuous
  belt-and-braces pin would "pass red" by first mutating the roster — which
  would misreport discrimination. The allowedTools/disabledTools machinery
  does exist for the model-facing launch path (ToolCatalog.php:35/:76
  docblocks; LaunchContext :1596/:1666-1676/:1820) but never feeds the serve
  roster's context, and even if it did, the name-pick keeps Bash out.

## 2. MINOR claim — "McpServeHost::statusLine duplicates STATUS/STATUS_ICONS": NOT ON DISK
- src/MCP/Serve/McpServeHost.php (320 L, read in full) has NO statusLine
  method, NO STATUS/STATUS_ICONS constants; grep for statusLine|STATUS over
  both src/MCP/Serve/*.php files returns 0 hits.
- src/App/Commands.php and src/Host/SessionMethods.php DO NOT EXIST (ls: No
  such file). The real statusLine homes in this tree are unrelated domains:
  src/Host/SpendLedger.php:221 (spend-cap line) and
  src/Chat/Handlers/HandlesRunControl.php:305-393 (the configured statusLine
  COMMAND feature). Nothing on this lane duplicates them.
- Acting on this finding would mean editing a method that does not exist;
  one-implementation law is satisfied trivially (zero copies here).

## 3. Gate + bookkeeping
- Targeted gate run once (cwd=candy-code, -c phpunit.xml, env -u LINES -u
  COLUMNS, plain pipe, anchored filter '|McpServeHostTest::|'):
  Tests: 14, Assertions: 77 — OK (2 pre-existing PHPUnit deprecations,
  unrelated; PtyRunToolTest exclusion N/A to this filter).
- No src/test/doc edits in the lane => in-step guard law has nothing to move
  (HOOKS.md prose from 1c8166eab stays valid: roster semantics unchanged).
- Forbidden set untouched: no suite-figure.json, durations.tsv, dist,
  plan_comparison.md, protocol trio/SERVER.md/generated.ts, run-state,
  escalated, AGENT_LAW_SNIPPET.md, LEAD_LAWS.md, .opencode/*. No composer /
  refresh-deps / npm run. No new child spawns, no new files needing census
  rows. New *Test.php for section 7: NONE.
- This report file is evidence-only, main-tree findings dir (same convention
  as sibling lanes' REPORT.md; prompt_kit is never staged by lanes).

## 4. Recommendation to lead
Re-derive the C-MCP-5.a round-1 review from fresh disk (or void the REVIEW-1
summary that produced these two findings). The builder's lineage (be7c88bd0 +
1c8166eab) already implements the design block (design-mcp.md ~line 338:
"exec-class and write-class tools are never listed or callable") exactly;
both claimed defects describe code shapes absent from the committed tree —
consistent with the run's known phantom/LEAD-CONFAB hazard class.

---

# FIX-1 — ROUND 1 REAL (fixer, disk-derived, 2026-10-31)

VERDICT: MAJOR-1 FIXED + NIT-1 BOTH PINS ADDED. NOT TOUCHED: roster (confirmed enforced), dormancy (5.b owns wiring), MINOR-2 (pick granularity — lead's weld-disclosure item), both existing picks (no amend/rebase).

- Lane start (verified first): tip `1c8166eab`, rev-list 288b81f11..HEAD == 2, porcelain 0, branch `cc/C-MCP-5.a`. Matches REVIEW-1.md exactly.
- New commit: `8aeae6900` ("C-MCP-5.a: review fixes (round 1)"), ONE file: `candy-code/tests/MCP/Serve/McpServeHostTest.php` +136/−1. `%ae`=detain grep 1, `%ce`=detain grep 1, Co-Authored trailers 0. src/ byte-untouched (post-mutation md5 2177d5c8e5b2f538af1e0d0a3522d660 == pre-edit).
- MAJOR-1 pin `testAnUnansweredAskRefusesAndNeverExecutesTheTool`: registers an inline anonymous PreToolUse hook (matcher `^Read$`, name serve-pin-asks-everything) returning `HookResult::ask(...)`; drives one tools/call; asserts isError:true + text startsWith `Permission required: ` (the Unanswered render) + content-absence of `hello serve` as the non-execution witness.
- MUTATION PROOF (the mandated one, cp+md5 restore, never git-checkout): baseline 14T/77A green BEFORE → sed :266 `!$verdict->permitsExecution()` → `$verdict->isDenied()` → **1F exactly**: testAnUnansweredAskRefusesAndNeverExecutesTheTool (ASK now executes) — restore md5-verified → 17T/96A green. The `.env` DENY pin stays green under the mutation (correct discrimination: each pin owns its arm).
- NIT-1 pins, both added (cheap, same file): `testAModifiedVerdictRunsTheRewrittenArgumentsNotTheOriginals` (settled MODIFY → rewritten args reach execute; extra mutation `:280` → `if (false)` reddens exactly 1F = this pin, restored md5-clean) and `testAThrowingHookAnswersInternalErrorAndTheStreamKeepsServing` (hook throw propagates preToolUse [failClosedOnThrow=false for this chain] → -32603 once + next ping answered; no src mutation needed — the code path is already the host's catch at :189-194).
- New test FILES: NONE (existing file extended). Existing-file figure moves 14T/77A → 17T/96A (weld/§7 note for the durations row owner).
- Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe): McpServeHostTest 17T/96A OK; reviewer guard family minus Serve class 236T/28,336A OK (sums == REVIEW-1's 250T/28,413A minus the 14T/77A class); DuplicatedTestHelperDriftTest 14T/202A 2F == the accepted foreign pair, unchanged by my file (no third red). No full suite; PtyRunToolTest never entered any filter.
- GlobDialect: matcher literals use `^Read$` (contains `$` → corpus-exempt); no `.*` matcher, no `**Refused:**` literal. One-type-per-file: no new top-level type (anonymous classes inline). E646: zero timers. No spawns.
- History preserved: the withdrawn-phantom section above stays untouched.

---

# FIX-1 — ROUND 2 (r2 fixer, disk-verified, 2026-10-31)

VERDICT: NO NEW COMMIT — assignment already satisfied by `8aeae6900`; all claims independently re-verified from disk. The r2 brief was stale (stated tip `1c8166eab`/2 picks; disk shows 3 picks), so per FIRST-ACTION law I surfaced instead of duplicating work.

- First action (contradiction surfaced): `rev-parse --short HEAD` = `8aeae6900`, `rev-list --count 288b81f11..HEAD` = 3, porcelain 0. Parent of 8aeae6900 = `1c8166eabbd0564...` (cat-file'd `commit`); both protected picks unamended and in lineage.
- Provenance of 8aeae6900: author `%ae` grep -c detain = 1, `%ce` grep -c detain = 1, Co-Authored = 0; one file `candy-code/tests/MCP/Serve/McpServeHostTest.php` +136/−1; tests-only (src untouched by the pick).
- Content vs REVIEW-1 MAJOR-1 prescription: EXACT match — anonymous PreToolUse `HookResult::ask()` hook, assert `Permission required: ` prefix + `isError:true` + non-execution witness (`hello serve` absent). NIT-1 both arms pinned (MODIFY rewrite; throwing hook → -32603 + stream keeps serving).
- MUTATION PROOF re-run independently (mandated discipline, cp+md5 restore, never git checkout): baseline green 17T → sed :266 `!$verdict->permitsExecution()` → `$verdict->isDenied()` → **1F exactly** = `testAnUnansweredAskRefusesAndNeverExecutesTheTool` (ASK would execute; §6 forbidden widening caught) → cp restore, md5 `2177d5c8e5b2f538af1e0d0a3522d660` OK, porcelain 0 → green again (17T/96A, 2 pre-existing deprecations).
- MINOR-2: NOT re-cut (history law). Tip-green CONFIRMED: r1's six-class anchored filter at tip = 253T/28,432A OK (r1's 250T/28,413A + the 3 new pins; DocFigureProseDriftTest green). Intermediate be7c88bd0 DocFigure-red stands as lead's §4 weld-disclosure item.
- r1's NIT-2 (run() accepts any list<Tool>) is a C-MCP-5.b wiring obligation — untouched here, correct (5.a stays dormant-by-design, design-mcp.md :349/:368/:378).
- New `*Test.php` for §7: NONE (existing file extended; class figure 14T/77A → 17T/96A — durations row for the file itself was already owed from the builder pick, unchanged by this).
- Forbidden set: zero edits to suite-figure.json, durations.tsv, dist, protocol trio/SERVER.md/generated.ts, plan_comparison.md, run-state/escalated/junk/AGENT_LAW_SNIPPET/LEAD_LAWS/.opencode; no composer/refresh-deps/npm/caliber; no full suite; PtyRunToolTest never entered a filter; the master-side roster rows cured at e78d5dc14 not touched. Lane tree left clean.
- This section is evidence-only; no git commands were run against /home/sites/sugarcraft.

---

# FIX-1 — ROUND 2-B (handler-leak brief fixer, disk-derived, 2026-10-31)

VERDICT: NO FIX LANDED — the briefed finding is a PHANTOM vs disk. No commit made; lane tip unchanged at `8aeae6900` (3 picks, porcelain 0, first-action rev-parse/rev-list/status all MATCHED the brief). Per the brief's own FIRST-ACTION and honesty clauses, refusing to fix code that does not exist.

## 1. The claimed call site does not exist
- `McpServeHost.php` (320 L, read in FULL): zero `PackedAtoi`, zero `diagnose`, zero `set_error_handler`/`restore_error_handler`, zero "Bench" refs. Real `:177-195` is the match dispatch (`initialize`/`ping`/`tools/list`/`tools/call`/MethodNotFound) over a catch — `:181` is `'tools/list' => $this->toolsList(),`. Imports (file :7-15) name no benchmark class.
- The whole step file-set (`git diff --name-only 288b81f11..HEAD` = HOOKS.md, McpServeHost.php, McpServeRoster.php, McpServeHostTest.php): grep for `PackedAtoi|diagnose|_error_handler` → ZERO hits in any of the four.
- `PackedAtoiBench` does not exist anywhere: `find` over BOTH /home/sites/cc-cmp-C-MCP-5.a and /home/sites/sugarcraft → no file; repo-wide content grep (both trees, *.php) → zero; `git log --all -S "PackedAtoiBench"` on the lane → EMPTY (the string has never existed in any reachable commit). `sugar-bits/src/Benchmarks/` does not exist.

## 2. The claimed foreign test does not exist
- `/home/sites/sugarcraft/sugar-async/tests/AsyncProbeTest.php` → No such file. `sugar-async/tests/` contains no AsyncProbe file at all. The repo's ONLY `AsyncProbeTest.php` is `sugar-colorprofile/tests/AsyncProbeTest.php`, which does NOT contain the named method `testEveryProbePathSurvivesEverySynchronousDispatchShape` (15 test methods enumerated; closest names are testNoColorResolvesSynchronouslyWithoutSpawning / testEverySpawnPathBooksABoundedDeadlineSlot), has zero refs to `McpServe`, `candy-code`, `PackedAtoi`, or `set_error_handler`, and sugar-colorprofile's composer.json declares NO candy-code/sugar-bits dependency. The "my new file on the load path reddens sugar-async" mechanism is structurally impossible: autoloaders never execute method bodies, and no edge exists from either lib to candy-code.
- I did NOT perform any AsyncProbeTest run (the briefed file is absent; colorprofile's vendor is not seeded in my lane and I do not run tests in the main tree). Disclosure per brief: the reproduction claimed by r2 cannot be cited because REVIEW-2.md does not contain it.

## 3. The cited evidence does not contain the claimed finding
- REVIEW-2.md on disk: complete at 20 lines, VERDICT CLEAN **0C/0M/0MINOR/0NIT** — no NIT, no "NEW real finding", zero mentions of any handler leak. The brief's "1NIT plus NEW finding below" misdescribes the file.

## 4. Gates at tip — the "single foreign red" does not exist
- Six-class anchored filter (`McpServeHostTest|DocFigureProseDriftTest|ReadPathCensusTest|StderrEmitterCensusTest|DenialPrefixRosterTest|GlobDialectDifferentialTest`, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe): **253T/28,432A OK, 0F** — byte-equal to REVIEW-2's own recorded figure. `McpServeHostTest`: **17T/96A OK** (2 pre-existing PHPUnit deprecations). The brief's "256T/28536A with that single foreign red as the only failure" matches NO recorded run (r1 250/28,413; r1-fix minus-class 236/28,336; r2 253/28,432; me 253/28,432).

## 5. Live no-leak harness (affirmative proof)
- `/tmp/opencode/cc-mcp5a-handler/harness.php` (fresh PHP 8.3 process): reflectively loads McpServeHost, builds the real roster + built-ins + PermissionGateHook chain, drives 6 frames (initialize, tools/list, real Read execute path, unknown-Bash call, unknown verb, non-JSON), then a THROWING PreToolUse hook through the exact catch-Throwable/-32603 arm (:189-194) — verified answered (`error.code === -32603`) — sampling the userland error-handler at every step. Result: `NULL (no userland handler installed)` at baseline, after each of the 7 receives, and at exit; `trigger_error(E_USER_WARNING)` stays a warning (not escalated). The file installs no handler on any path, so none can leak.

## 6. Disposition + law confirmations
- NOTHING committed (a fix would be a no-op edit to imaginary code). Both picks + `8aeae6900` unamended and intact; tree porcelain 0 after all runs. Foreign-file rule honored: did not touch sugar-async/sugar-bits (both cited shapes absent anyway); no rosters edited — no red existed to hide.
- E646: no timers added (no code added). Forbidden set untouched: suite-figure.json, durations.tsv, dist, protocol trio/SERVER.md/generated.ts, plan_comparison.md, run-state/escalated/AGENT_LAW_SNIPPET/LEAD_LAWS/.opencode — zero edits; no composer/refresh-deps/npm/caliber; PtyRunToolTest never entered a filter; no full suite.
- Recommendation to lead: re-derive this brief's finding source — it cites nonexistent files (`PackedAtoiBench.php`, `sugar-async/tests/AsyncProbeTest.php`), a nonexistent method, gate figures matching no reviewer run, and attributes content to REVIEW-2.md that the file does not contain. This is the known phantom/LEAD-CONFAB class (see §4 of the round-1 section above). Step C-MCP-5.a remains CLEAN per REVIEW-2 and this re-verification; weld can proceed.
