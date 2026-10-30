VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR. Real 3-commit state b503bb054..35ba713a8 adjudicated on disk; weld-carries ready.

## Scope note (premise corrections, disk-verified)
- Spawn brief claimed r1 had 2 MAJORs (REVIEW-1.md 10737B). Disk truth: REVIEW-1.md = 8050B (main tree AND branch blob identical size), header "VERDICT: FINDINGS — 1 MAJOR / 0 CRITICAL / 2 MINOR / 2 NIT". Its MAJOR-2 (provider-key buildPromptCacheWireHistory vs fold-time wireHistory, testNoPingWhenServedPromptDiffersFromStoredKey vacuous) was CONFABULATED: both symbol names have ZERO hits in src/tests repo-wide (only 2 hits total: quoted inside FIX-1.md's own refutation), and zero occurrences in the lane diff → absent at base too. MAJOR-2 formally RETRACTED. The brief's "2M/3m" figures were ledger rot, not lane facts.
- r1's actual demands: MAJOR-1 (cap must bind the TUI tick; gate inside handleKeepWarmTick before the policy call + a discriminating real-handler-tick pin) + MINOR-1 (real-handler-tick pin — subsumed by the MAJOR-1 cure) + MINOR-2 (foreign ReadPathCensus standing roster — out of scope) + NIT-1 (warm statics never GC'd → cross-session bleed pin) + NIT-2 (base-era StderrEmitterCensus, cured on master 2d44af1b5).

## Git state (cat-file'd every SHA, same-shell)
- Branch cc/C-CTX-5.d, HEAD == 35ba713a8; b503bb054..HEAD = EXACTLY 3: eb5108c9c (builder, 13 files) → 7179a0f58 (fixer, EXACTLY 4 files: CacheHealthWatch.php, HandlesRunControl.php, SessionHost.php, KeepWarmTest.php — no scope creep beyond declared regions of the two hub files) → 35ba713a8 (evidence collect: prompt_kit FIX-1.md 4098B / REPORT.md 9491B with dated CORRECTION block at :41 / REVIEW-1.md 8050B / REVIEW-2.md 3366B). Parent chain exact; all four SHAs cat-file = commit; b503bb054 is ancestor.
- Author law, 3 commits: %ae grep -c detain = 3, %ce grep -c detain = 3 (separate greps), zero Co-Authored trailers.
- Zero-touch proven: cumulative 18-path `git diff b503bb054...HEAD --name-only` contains NO suite-figure.json, NO parallel-tests-durations.tsv, NO dist paths, no weld-only files.
- Tree clean except `?? prompt_kit/.../plan_comparison.md` (allowed untracked).

## MAJOR-1 cure — GATE IS IN THE LIVE TICK PATH (blob-proven)
git show 7179a0f58:...HandlesRunControl.php: handleKeepWarmTick starts :490; gate `if ($this->spendCapReached()) { return [$this, null]; }` at :501 — AFTER the watch door (:492), BEFORE the silence/inFlight gates, session-key door, anchor computation, shouldPing, sendKeepWarmPing and the served-alarm re-arm. This is the tick body App::update drives from IdleCompactTickMsg, NOT the :449 site (which is the pre-existing fold handler's own gate in handleCacheTtlIdleCompactTick :419 — the phantom-era mislocation is NOT repeated here). WHY-comment present. Cap-clear path unchanged. Satisfies exactly what r1's MAJOR-1 text demanded.

## Pins (KeepWarmTest.php at tip, read in full)
- :320 testTheSpendCapBindsTheTuiTickNotJustTheServedAlarm — REAL Chat via tuiChat():298 (below-floor history so the fold declines and the tick reaches keep-warm; capPings 2; TokenTracker 0.20 billed vs 0.10 cap): update(IdleCompactTickMsg) → same instance, NULL cmd, warmPings()===0 globally, warmPingInFlight('s')===false (gate sits BEFORE the latch — proves veto position), warmStretchPings('s',anchor)===0.
- :336 testATickUnderTheCapBuysThePingAndBillsItsLanding — identical fixture with fresh (unspent) tracker: cmd non-null, latch true, warmStretchPings('s',stamp)===1 (single-anchor structural leg), firing cmd() lands KeepWarmPingedMsg → warmPings('s')===1, latch down, spend billed.
- :365 testASpentSessionSpendsNoOtherSessionsBudget (NIT-1 bleed) — cap 1: A earns+lands (warmStretchPings('A',anchor)===1); parkLastPingAt(NOW−241,'A') removes the spacing excuse; B with the SAME anchor still asks TRUE → the budget is keyed per session (A's spend cannot block B); B's own pending latch blocks B; C asks TRUE. Session-keying genuine in blob: warmPings(string $sessionKey), warmStretchPings(string,int), warmPingInFlight(string), noteWarmPing(string,int); CacheHealthWatch.php:277 docblock truth-flipped to name the per-session partition law. Discriminates: key-agnostic counting turns :365's B/C legs red; tally-only keying (no budget keying) still reddens them — the spacing-park leg exists precisely to prove budget (not display) partition.

## MAJOR-2 twin (single anchor) — cured, sound, in-scope, honest
sendKeepWarmPing now takes int $anchorTs (:591, threaded from the single :499 computation); the dead `?? \time()` fallbacks are deleted (zero `?? \time` hits in both touched src files at tip); SessionHost.php:1097 hoists one $anchorTs feeding shouldPing(idleAnchorTs:) :1111 and noteWarmPing :1120; KeepWarmPolicy rejects a null anchor (shouldPing `|| $idleAnchorTs === null` in the :152 early-return guard, not merely defaulted away). Consequence: verdict and latch agree on bucket identity — the same anchor can never be booked twice under different buckets. This is a real twin-key/anchor-consistency defect class cured in the lane's own code; not scope creep (the files are the lane's declared hub regions).

## Mutation reasoning (read-mode disclosure)
LIVE mutation runs were IMPOSSIBLE for this reviewer: the session permission map rejected cp/mv write-backs (3 denials), so reds are REASONED, not executed — and the reasoning is decisive:
- Neuter :501 → :320 goes red (cmd non-null, latch set, warmPings()>0, stretch bucket booked → ALL its legs flip) while :336/:365 (below cap) stay green. Removing gate ⇒ exactly :320 red. ✔ brief's required mutation-reason.
- Invert :501 (return when NOT reached) → :320 red and :336 red. ✔
- Re-derive anchor at the latch (`\time()` instead of threaded $anchorTs, ~:610) → :336's stretch===1-at-stamp leg reads 0 → red. ✔
- Leak the budget across session keys (key-agnostic stretch read) → :365's B/C asks flip to false → red. ✔
FIX-1.md (both the 4098B branch blob and the fuller 6160B disk copy) reports these executed with exactly-one-red outcomes; my blob-level reasoning independently confirms each one-red shape. Zero non-vacuity concerns: :320/:336 differ ONLY in tracker spend (pair discrimination is structural), helpers parkLastPingAt/sendAndLand drive the real CacheHealthWatch statics with unique per-test session keys.

## Gates measured (cwd=candy-code -c phpunit.xml, plain pipe, env -u LINES -u COLUMNS)
- KeepWarmTest: **OK 17T/68A EXACT** (brief figure).
- KeepWarm|CacheHealthWatch combined: OK 40T/173A (class-anchored KeepWarmTest|CacheHealthWatchTest = 17+20=37; filter sweeps 3 idle-fold siblings whose names contain the words — 38T/161A is not exactly reproducible as a filter figure but every member test passes; CacheHealthWatchTest alone = 20T/87A green).
- Idle-cache family union (KeepWarm|CacheHealth|IdleCompact|IdleAuto|CacheTtl|IdleHeuristic|Breakpoint|Compaction…): OK 71T/289A EXACT (brief figure).
- DocFigure: OK 92T/4177A; DTHD+DocFigure union: OK 106T/4380A EXACT (brief figure).
- Drift-adjacent 77T/59568A: NOT re-run (permission-map + budget); r1 ran the adjacent guard family green and 7179a0f58 adds zero rosters/symbols beyond what r1 already censused (no new env keys, no new README entries in the fix commit — fixer's 4 files are 3 src + 1 test; SETTINGS/README untouched in 7179a0f58).
- php -l: all 6 key files clean (earlier session).
- gen-settings-doc --check: direct invocation permission-blocked; negative proof instead — fixer touched ZERO settings inputs (ContextSettings/LayeredSettings/SETTINGS.md all land in eb5108c9c alone; cumulative name-only confirms), and tip SETTINGS.md shows promptCache.keepWarm in the generator roster (rows :243/:1272/:1548/:1565/:1852) exactly as builder's in-step regeneration put it; r1 already rc0-checked the same region. No drift channel open.
- Foreign standing reds (green-equivalent, per brief): suite-figure/durations staleness pair + DTHD-era rosters = weld §7 duty; ReadPathCensus ServerConfig×2+RemoteSessionPush = C-TUI-5.c welder curing on master concurrently — attribution per master blob, never blocked on.
- 2 PHPUnit deprecations in every filtered run = tests/Config/AccessorDeprecationTest non-public providers — pre-existing at base, file absent from lane diff.

## E646 law
Diff-added lines scanned: every timeout/timer/alarm hit is docblock/doc prose or reflection reads of the EXISTING SessionHost idleAutoTimer. Zero new addTimer/set_time_limit/blanket total-request timeout anywhere in the 3 commits; ping budget is the N-cap count + policy date math; stalled ping holds its latch (anti-storm, no timer). Opt-in TTL semantics (keepWarm=0 default → zero dials) honored.

## Design-block conformance
KeepWarmPolicy.php + KeepWarmTest.php created; HandlesRunControl/SessionHost/CacheHealthWatch/ContextSettings/LayeredSettings(gen)/SETTINGS.md(gen)/README(gen)/CONTEXT.md modified — every named file present, nothing unnamed touched. Deviations (a)-(g) from r1 stand as ACCEPTED design-wins (not relitigated): max_tokens:1 rides the frozen-prefix summariseAsync seam (EngineBackend scope design-forbidden), static session-keyed registry, KeepWarmPingedMsg in src/App, README guard hunks 141→142/TrustKey 83→84, reject→unbilled settle, served re-arm only on sent ping, keep-warm requires cacheTtl ON.

## WELD CARRIES (CLEAN → pre-state stands)
- Pick range b503bb054..35ba713a8, EXACTLY 3 commits, EXCL-start; expect Chat.php/HandlesRunControl adjacency + en.php collisions (master moved), resolve by content.
- DUR: RE-TIME KeepWarmTest row 14→17 (junit testcase-SUM, median-of-3); CacheHealthWatchTest 16→20 (builder's +4 methods are in eb5108c9c — CHECK current tsv row: fixer added 0 new methods there, only KeepWarmTest grew).
- §7: +18T/+~104A (builder +14T: 17−14 KeepWarm +4 CHW; fixer +0T? — recount: KeepWarmTest 14→17 = +3 fixer methods (cap-control, cap-bind, bleed), builder net +14 incl CHW +4; +104A-ish; union re-pin at weld with the staleness pair).
- §4 mark text must include: deviations (a)-(g) headline + "spend cap binds keep-warm tick (:501, gate before latch, :320/:336 pair)" + "single anchor producer/tick ($anchorTs threaded, null-anchor refused)" + "r1-brief MAJOR-2 retracted as confabulated — REVIEW-1.md 8050B is the authority".
- Evidence already on-branch (35ba713a8); copy THIS REVIEW-3.md (and the fuller 6160B disk FIX-1.md if the collector prefers it over the 4098B branch copy — disclose which) onto cc/C-CTX-5.d pre-prune, then worktree+branch removal per lane law.
