# C-TOOL-6.h — REVIEW-2 (closure)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (record-only: the single new markTestSkipped in the range is the fork test's ext-pcntl setUp guard — precedent-verbatim, never fires here; no action). WELD-ELIGIBLE: YES.

STEP=C-TOOL-6.h ROLE=r2-closure
Identity: lane /home/sites/cc-cmp-C-TOOL-6.h, branch cc/C-TOOL-6.h, base b45117223 (re-derived from disk via rev-parse, not memory; merge-base master...HEAD == b45117223 confirmed at my close), nine picks 3086cda45 · eaa3dbd04 · a8cf68313 · a49711e18 · 9e1c141bc · f69538669 · 2f4373c8c · 5fae61054 · 12aa62289 at tip 12aa62289140c0af79acdf067c1e941075a556ca. Master MOVED to 13f9a1829 mid-review (C-TUI-6.a weld landed per WARN) — all analysis below pinned to LANE blobs only. Porcelain clean (only the untracked lanes/ working-dirt dir, the fixer's recorded shape). Authors: %ae detain 9/9, %ce detain 9/9 (separate count greps), Co-Authored-By 0/9, commit count 9.

## Closed-loop duties

**1. MINOR cure (r1: PendingClientCall docblock claimed the parent recomputes the callId) — CLOSED.**
Fix blob `git show 12aa62289:candy-code/src/Backend/PendingClientCall.php` — class docblock :27-32 reads (verbatim):

```
 * The `callId` is a hash of the identity triple (call id, proxy name,
 * arguments), the same construction {@see PendingAsk::askId()} uses for asks:
 * the child computes it before writing, the parent format-validates it
 * (the `/[0-9a-f]{16}/` shape and the deadline bounds — nothing is
 * recomputed), and a client can only answer a call whose exact arguments
 * it was shown.
```

The false recompute claim is GONE. Only "recomput"-shaped occurrence in the whole fix blob is line 31's "recomputed)" — the tail of the truthful "nothing is recomputed" clause, matching r1's exact prescription ("drop or reword to format-validates"). The new wording is honest on both halves: `PendingClientCall::callId()` is derived from the identity triple via sha256-prefix and has exactly ONE external call site — `ChildChannel.php:356`, inside `callClient()` child-side before writing (HistorySanitizer's `self::callId` hits are an unrelated same-named private method) — the parent never calls it; `fromFrame` validates only: `preg_match('/\A[0-9a-f]{16}\z/'` + type/bounds on `deadlineSeconds` — no hash comparison anywhere in the file.
**PendingAsk twin consistency**: `grep -i recomput` over `candy-code/src/Backend/PendingAsk.php` = 0 hits; the twins' id docblocks now agree (twin never claimed recompute — r1's MINOR premise).
**Tree-wide residual grep** for any other callId-recompute claim in candy-code src/tests/docs: 6 hits in 4 files, ALL unrelated prose (PaletteSkill.php:104, WebhookSigner.php:141/:160 "never recomputed", InstructionFileLoader.php:326, DocFigureProseDriftTest.php:1732/:2812). ZERO anywhere else claiming client__ callId recomputation — grep done, no offender to name.

**2. Zero-collateral — PROVEN.**
- `git show --name-only 12aa62289` == EXACTLY one file (candy-code/src/Backend/PendingClientCall.php); stat +4/−3, all lines inside the class docblock (diff inspected hunk-by-hunk: −3/+4 comment lines only, no code token touched). Pure docblock — nothing behavior-wise to discriminate, so no mutation re-EXEC (nothing to fake; per §7 no fabricated claims, this is stated as not-applicable, not as run).
- Eight builder picks byte-intact: `git diff 5fae61054 12aa62289 --name-only` = 1 file, and the fix blob's parent tree equals the reviewed era — fix rides additive as ninth onto the r1-reviewed chain. Per-pick filesets re-walked across b45117223..12aa62289: 22 files total, matching r1 Q5's inventory + 4 disclosed consequences; the eight picks' filesets match the r1 rows (the 22 = design files.create/modify + SessionHostTest/TurnPathContractTest/TurnRunnerTest/carry edits).
- Locked-file scan across the FULL range b45117223..12aa62289 (name-only + numstat): en.php ZERO lines; protocol trio (docs/protocol/candy-code.v1.schema.json, docs/SERVER.md, candy-code-web/src-web/protocol/generated.ts) ZERO; web mirrors web-strings.en.json / strings.en.generated.ts ZERO; candy-code-web/dist ZERO; suite-figure.json ZERO; scripts/parallel-tests-durations.tsv ZERO; plan_comparison.md absent from branch entirely.
- markTestSkipped on added test lines: ONE — ForkChannelClientCallRoundTripTest.php:46-49 setUp ext-pcntl environment guard, message verbatim-identical to the precedent ForkChannelAskRoundTripTest the design names; my fork gate ran 7T with zero skips (guard never fires here). Not a vacuity dodge.
- Residue greps on the fix blob (`if (false`, `&& false`, `|| true`): 0.
- Quarantined lineage files under lanes/C-TOOL-6.h/ BYTE-STABLE at r1-era sizes: REVIEW-1.md 7,167 · FIX-1.md 6,625 · REPORT.md 17,365 · NOTES.md 10,615 (read for reference only; zero authority exercised — my findings derive from files/disk).

## Gates re-run (my live §7 figures — docblock-only fix, all UNCHANGED vs r1)
All runs: cwd=candy-code (lane worktree), `-c phpunit.xml`, env -u LINES -u COLUMNS, plain pipe, anchored exact-class FULL Test-suffixed stems (no stem-only alternations), NON-ZERO sanity on every line.
- `(ClientToolRegistryTest|ClientToolProxyTest|ClientToolPermissionTest)::` → **OK, Tests: 53, Assertions: 136, PHPUnit Deprecations: 2** (my run; fixer + r1 claim 39/91 + 8/23 + 6/22 = 53/136 — sum reproduced EXACTLY).
- `ForkChannelClientCallRoundTripTest::` → **OK — Tests: 7, Assertions: 40, PHPUnit Deprecations: 2** (real-fork legs all EXEC green; zero skips — the ext-pcntl setUp guard never fires; Deprecations 2 = the listed accepted-foreign AccessorDeprecationTest:168/:202 ambient pair, present identically on every unrelated filter run).
- BARE `TreeWideGuardRosterTest` → **OK, Tests: 17, Assertions: 3947, Deprecations: 2** (unchanged).
- BARE `DuplicatedTestHelperDriftTest` → **OK, Tests: 14, Assertions: 204, Deprecations: 2** (unchanged).
- `SessionHostTest::` → **18T/83A 0F**; `TurnPathContractTest::` → **9T/68A 0F** (my live re-runs, green).
- **Correction to the lane's own ledger:** there is NO `TurnRunnerTest` class in this tree — a `TurnRunnerTest::` filter returns "No tests executed" (the zero-match stem trap; TurnRunner's roster-append path is exercised via the SessionHost/TurnPath filters above and the Chat/Server suites, all unmodified here). FIX-1.md §4 does not claim this filter, but any later ledger citing "TurnRunnerTest" figures is stale.
- Pairing `BashSandboxTest|BubblewrapHardeningTest::` → **OK, Tests: 39, Assertions: 192, Deprecations: 2** (db1441a61 is an ancestor of base b45117223 — the only merge-base fact I need; master itself moved to 13f9a1829 mid-review as the C-TUI-6.a weld landed, expected per the welder WARN: expected GREEN, confirmed, not cured).
- Spot anchors only for the Channel window — NEVER a full suite; no Channel 157T re-pull.
Docblock-only change ⇒ zero behavior delta anywhere: every figure identical to r1's live counts.

## Foreign reds (adjudicate-at-weld ONLY, never cure here)
- StderrEmitterCensusTest 2F — PtyRelay 6.j-debt shapes (bare emitter at 75174ea40:2155 + roster move 6fd8b29d0 "nine"/"thirty-eight"-vs-47 at :2512). Lane touches neither PtyRelay nor the census file.
- ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode 1F — offenders Config/StatusLineSession.php:81 + Host/Commands/SchedulesCommand.php:583; re-verified cheaply: NEITHER file is in the lane's 22-file set, and `git log b45117223..HEAD -- <both>` = EMPTY ⇒ base-side-shaped exactly as r1 proved.
- GlobDialect 1F base-carried corpus figure (docblock 311,360 vs derived 310,245) — assert NEITHER number.
- ReadmeSuiteFigureDrift staleness pair: disclose, NEVER edit (figure/durations are welder-minted at battery).
- **Census corrections (report-only):** `SeamCallSiteCensusTest` does not exist anywhere on disk (find over tests = 0 hits; the §4/lead reference to "SeamCallSiteCensus 2F" cannot fire as named; its real subject — ChildChannel's deadline newTimer consumption — is E646-clean per design's own note). `LaunchContextDelegatesTest` DOES exist (`tests/Cli/LaunchContextDelegatesTest.php:34`) — I did NOT run it (out of a docblock-closure round's gate scope; the lane's 22-file set touches none of its read-census subjects: `git log b45117223..HEAD` names neither of its likely offenders), so I make no green/red claim about it; welder adjudicates the df106a84c 4.g-debt row from its own battery.

## Design conformance (§1.4)
Names/methods as the block writes them (spot-checked BY GREP at my close): `RelaysClientToolCalls` + `withClientCaller(\Closure): Tool`; `ClientToolSpec::NAME_PATTERN` `/^[a-z][a-z0-9_]{0,47}$/` (:49), MAX_TIMEOUT_SECONDS 600 (:59) with the 1..600 bound check (:90), `ClientToolRegistry::MAX_TOOLS_PER_CLIENT` 16 (:44, enforced :73); proxy `NAME_PREFIX = 'client__'`, not ParallelSafe (barrier-only), DelegatesToEngine per design's Task-subagent-withholding rationale; ChildChannel client_call/client_result kinds additive beside the 1.C ask channel, steer/cancel buffered, EOF→error never success; SessionHost registerClientTools/forgetClientTools/resolveClientCall/onClientCall present with hands+next-turn roster via TurnRunner::backendForTurn→Bootstrap::filterToolSet(withTools) so `disabledTools: ["client__*"]` drops proxies; Runtime::CLIENT_TOOL_PREFIX (:873, derived from the proxy const) read by isWriteCapableTool (:1461); PermissionGate::isWriteTool client__ `return true` arm (:1832-1834) BEFORE the verbatim final `return str_starts_with($call->name, 'mcp__');` (:1836) — the in-code comment itself states the ordering law; evaluateAuto routes client__ Ask (:1095) before stepTexts (:1113)/classify (:1117); ToolDocGenerator client__ tail. Note (informational, matches design): `timeoutSeconds` is the registration/WIRE key; the frame key is `deadlineSeconds` — exactly per the design's own field lists, no drift. Conventions: new src files declare(strict_types=1) + final classes + one type per file (PendingClientCall verified directly: `final class` at fix-blob :34); no Lang::t in new src (model-facing refusals, per docs/guards note); no proc_open in added src lines (frames ride the existing turn socket).
**E646 (§1.5):** the client deadline rides the parent loop's timer bookkeeping — `$clientDeadlineTimers` TimerInterface map at EngineBackend.php:4394-4397 with the settle-error 'the client did not answer within N s' at :4693, and the deadline leg EXEC-proven green in my fork-gate re-run (7T) — a TOOL-answer deadline on a loop timer, not a blanket wall-clock cap on any LLM request; set_time_limit/CURLOPT_TIMEOUT absent from ChildChannel (grep 0) and from every added line in the range.
**§6 rulings:** none of the ten rows touches 6.h internals; the t1009 "W38+ waves may run builders" ruling authorizes this lane's existence. Plan §4:4176 keeps 6.h's row as not-yet-landed (our weld has NOT happened; §3 W38 order = C-TOOL-6.g/6.h — 6.g third-cut review in flight, sibling untouched).

## Welder guidance (§7)
- NINE explicit picks by SHA read-from-lane: 3086cda45 eaa3dbd04 a8cf68313 a49711e18 9e1c141bc f69538669 2f4373c8c 5fae61054 12aa62289 — NEVER a b45117223..tip range onto moved master, no -x.
- C-TUI-6.a WELDER is sole master writer RIGHT NOW; its Windows/Bash :535 edits may move shared regions (BackgroundShellRegistry/Bash/ProcessContainment) — FIRST-landed welder owns the union, re-derive hunks BY TEXT, conflict = STOP. 6.h's file set is disjoint by construction from 6.a's (r1 Q5; Registry.php absent entirely).
- §7 LIST never fix — durations rows at MY live counts (identical to r1's, fix moved nothing): tests/Host/ClientTools/ClientToolRegistryTest.php 39T/91A · tests/Host/ClientTools/ClientToolProxyTest.php 8T/23A · tests/Permissions/ClientToolPermissionTest.php 6T/22A · tests/Backend/ForkChannelClientCallRoundTripTest.php 7T/40A (60T net-new across 4 new files) + carry on RuntimeTest/SessionHostTest/TurnPathContractTest/TurnRunnerTest/Wither/AskRoundTrip/BuiltInToolCorpus edits; suite-figure +~54–70T-class carry disclose at battery re-pin with fail-loud asserts of the OLD state.
- trio/dist/suite-figure/durations/plan UNOWED — per-pick absence proven above (full-range name-only scan); protocol regen chain N/A.
- §4 arc must honestly cite: confused-identity predecessor (quarantined files, zero authority), guard-cut delivered (builder arc), r1 MINOR-cure (this fix pick 12aa62289).
- Foreign roster above is adjudicate-only at weld; staleness pair NEVER-edit; i18n counter NOT raised from 14 (en.php zero-diff).
- Web side: zero files in range (name-diff proven) ⇒ vitest N/A.