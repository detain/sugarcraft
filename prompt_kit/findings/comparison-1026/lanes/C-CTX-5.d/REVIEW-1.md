VERDICT: FINDINGS — 1 MAJOR / 0 CRITICAL / 2 MINOR / 2 NIT. Fix-before-weld required for MAJOR-1.

# REVIEW-1 — C-CTX-5.d (prompt-cache keep-warm)

- Lane: branch `cc/C-CTX-5.d`, worktree `/home/sites/cc-cmp-C-CTX-5.d`, EXACTLY 1 commit `eb5108c9c` atop `b503bb054` (13 files, +1176/−59). Both SHAs cat-file OK; base is a master ancestor.
- Master moved during review (f11a0b019 → 21641a43f: C-TUI-3.d weld + DUR rows; 2d44af1b5 StderrEmitterCensus cure). merge-tree drift is the weld's gate.

## Design duties (quoted verbatim from design-prv-ctx.md `### C-CTX-5.d`)
> "Before the TTL lapses on an idle session that the cacheTtl fold did not take, a Context\KeepWarmPolicy decides to send a `max_tokens: 1` request over the frozen prefix, on the session engine, at most N times. It never sends while a turn is in flight or once the cap is reached."
> "CacheHealthWatch records warm hits, and the ledger bills them like any call. E646: no total timeout."
> "It uses the same TUI tick and served alarm as C-CTX-5.c."

## Findings

### MAJOR-1 — TUI keep-warm pings fire PAST the spend cap while the lane's own docs promise it binds
`candy-code/src/Chat/Handlers/HandlesRunControl.php:419` (handleCacheTtlIdleCompactTick) order is: silence list → `shouldAutoCompactAtCacheTtl` → `if ($this->spendCapReached()) { return $this->idleHeuristicFold(...); }`. `idleHeuristicFold` (`src/Chat.php:15470`) returns `[$this, null]` when `$tier['compactionNotice'] === null` — cap-reached + below heuristic tier therefore reads as "fold declined" to the lane's rewritten arm, falling through to `handleKeepWarmTick()`. `KeepWarmPolicy::shouldPing` takes **no spend-cap input**, so a capped, idle, cacheTtl-mode TUI session buys billed `accountUsage(..., 'warm')` pings up to N per stretch, every stretch. The SERVED path is cap-gated upstream (`onIdleAutoCompaction` returns on `ledger()->capReached(...)` before foldDue computes) — only the TUI path leaks. The lane's shipped docs assert otherwise:
> `candy-code/lang/en.php:3883`: "These are billed calls to a session nobody is watching; the spend cap still binds."
> `candy-code/docs/CONTEXT.md` (~:134): "the spend cap binds and the count lands in /usage under warm"

Design risk paragraph ("warm pings cost money while nobody is watching") makes the money guard load-bearing. **Exact fix:** in `handleKeepWarmTick()`, before the policy call, add the same gate the fold handler itself uses — `if ($this->spendCapReached()) { return [$this, null]; }` — plus a discriminating pin (thread a `bool $spendCapReached` into `shouldPing` as one more table leg with a flipped test row, or a handler-level test). Docs then become true; served path already correct.

### MINOR-1 (step-local) — No direct production pin for the TUI handler→ping leg
KeepWarmTest pins the policy table and the served path end-to-end (real host, billed usage), but never drives `handleKeepWarmTick`/`IdleCompactTickMsg` itself; the fold→keep-warm fallthrough is proven only by reasoning + the fold handler being untouched. MAJOR-1 is exactly the defect this leg would have caught. The MAJOR-1 pin requirement covers it; implement together.

### MINOR-2 (out-of-scope, standing roster) — Foreign ReadPathCensus red
`ReadPathCensusTest::testTheCensusIsDerivedFromSrcAndFullyClassified` fails on current master (21641a43f) WITHOUT lane files, diff identical in lane tree: missing rows `Server/ServerConfig.php|file_get_contents => 2` and `Session/Import/RemoteSessionPush.php|file_get_contents => 1`. Not the lane's (lane adds zero disk reads). Weld/fixer must NOT count it against the lane; belongs to the standing-roster closeout.

### NIT-1 — Warm registry statics never GC'd
`CacheHealthWatch::$warm` per-session entries live for process lifetime (5 small fields each). Bounded in practice by session count; cross-session leakage itself is pinned (CacheHealthWatchTest `testOnlyBilledLandingsAreWarmHits`, "one session does not spend another's count"). Acceptable as-is.

### NIT-2 — Lane-tree StderrEmitterCensus reds are base-era artifacts
3 StderrEmitterCensus failures in the lane tree name `src/Cli/Subcommands.php` + BinCandyCodeAutoloadGuardTest 'thirty-six' — files byte-identical base↔master↔tip, lane adds zero emitter lines; cured on master by `2d44af1b5` AFTER the lane's base. Will be green when the pick rides current master; no action.

## Builder deviation adjudications (design wins)
- (a) literal `max_tokens:1` unattainable in-scope — ACCEPTED. EngineBackend has no per-call output ceiling and is outside the step file set; ping rides the frozen-prefix `summariseAsync` seam, billed whatever usage returns. No shipped doc claims the literal cap; KeepWarmPolicy docblock is truth-first. Doc-truth check PASSES.
- (b) static session-keyed warm registry — ACCEPTED. Engine watch instance unreachable from allowed files; sessionKey partitioning exists AND is pinned against cross-session bleed.
- (c) new `src/App/KeepWarmPingedMsg.php` instead of touching Chat.php — ACCEPTED, reachability traced: `Chat.php:2630` `?? $this->handleRunControlMessages($msg)` lands the msg; handler arm lives in the same trait as IdleCompactTickMsg; promise→Msg wiring is the fold's own shape.
- (d) README touched — ACCEPTED. Both hunks are guard-required only: settings:layered GEN region (141→142 keys) + TrustKey never-project enumeration (83→84). SettingsSchemaDocDrift/TrustKey/ReadmeRoster guards green.
- (e) reject→unbilled settle instead of ExceptionMsg — ACCEPTED. Unsolicited background call; latch always released (both settle legs), unbilled landings pinned not to count.
- (f) served re-arm only when a ping sent — ACCEPTED. Preserves 5.c status quo byte-for-byte when off; both polarities pinned (assertNull alarm when cap 0).
- (g) keep-warm requires idleAuto-cacheTtl ON — ACCEPTED, documented in en.php help, CONTEXT.md, and the setting definition comments.

## Checks ledger
1. Reachability: setting>0 + idle + frozen-prefix → ping sent, billed (served e2e pin spendUsd>0, warmPings 1) ✓ — but see MAJOR-1 for the past-cap TUI leak. Mid-turn never ✓ (`turnInFlight` gate + `warmPingInFlight` one-at-a-time latch, both mutation-discriminating). Past-count never ✓ (cap leg mutation reddens `testTheCapCountsAgainstTheIdleStretchAndAReturnRollsIt`).
2. E646: zero new wall-clock timers in diff; rides 5.c tick/alarm ✓.
3. Hot-file add-only: SessionHost = one insertion at the `!$foldDue` branch + appended method; HandlesRunControl = imports + fold-handler UNTOUCHED + new methods at trait tail; diff-vs-base-blob confirms no reorder ✓.
4. Byte-stability: `PING_INSTRUCTION` is a const; ping rides the same frozen-prefix summariseAsync seam as the fold — no cache invalidation ✓.
5. Guards: SettingsSchemaDocDrift/TrustKey/ReadmeRoster/EnvRoster 78T/41421A green; DocFigure 92T/4177A green; DTHD+GlobDialect green; no getenv added (grep of +lines exit 1); no new emitters, no new disk reads; ReadPathCensus/StderrEmitter reds attributed above (foreign/base-era).
6. Non-vacuity: mutation-reasoned cap leg, inFlight leg, turnInFlight leg, latch leg — each reddens exactly its pin ✓.
7. suite-figure.json / durations.tsv / dist: zero touches ✓ (DUR owed at weld).
8. Hygiene: `%ae` and `%ce` both detain@interserver.net on separate greps; author "Joe Huss"; empty trailer list ✓.
9. Tests measured: KeepWarmTest 14T/45A EXACT; CacheHealthWatchTest 20T/87A (16→20, +4 warm incl cross-session pin) ✓.

## Weld-carry notes (after MAJOR-1 cure)
Pick `b503bb054..eb5108c9c` EXCL-start = exactly eb5108c9c onto current master; DUR MINT KeepWarmTest (14 cases, junit-testcase-SUM median-of-3) + RE-TIME CacheHealthWatchTest row (16→20 cases); §7 +14T/+4T; collect REPORT.md (7071B — exists in lane WORKTREE only, untracked, NOT on the branch: `git show --name-only eb5108c9c` has no prompt_kit path) + this REVIEW-1.md pre-prune; deviation ledger notes ride §4 mark text. Foreign ReadPathCensus rows stay with standing-roster closeout.
