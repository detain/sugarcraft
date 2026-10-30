# C-CTX-5.d — Opt-in prompt-cache keep-warm — LANE REPORT

VERDICT: COMPLETE. Commit **eb5108c9c5457e131cae1b53c0444d36b813b615** on cc/C-CTX-5.d (one commit ahead of base b503bb054; 13 files, +1176/−59; author+committer detain@interserver.net verified via separate %ae/%ce greps, zero Co-Authored trailers; cat-file'd in the same shell that cited it).

## What shipped

- NEW `candy-code/src/Context/KeepWarmPolicy.php` — pure predicate (mirrors IdleCompactionPolicy): `SETTING='promptCache.keepWarm'`, `MAX_PINGS=24` clamp, `limitFrom()` fail-closed, `shouldPing()` gate order: cap<1 → turn-in-flight/latch → no cache clock (`IdleCompactionPolicy::cacheTtlSecondsOf()===null`) OR fold took the tick → `tokenLimit<=0`/no anchor/prefix under `CacheHealthWatch::BREAK_MIN_TOKENS` (1024 — providers cache nothing smaller) → stretch cap → spacing (`now - max(anchor,lastPing) > ttl - MARGIN`, strict `>`).
- NEW `candy-code/src/App/KeepWarmPingedMsg.php` — landing msg carrying `?Usage`.
- `src/Backend/CacheHealthWatch.php` — warm-hit registry: `noteWarmPing` (latch, stretch rolls with its anchor), `settleWarmPing(billed)` (total counts billed landings only), `warmPings()/warmStretchPings()/warmPingInFlight()/lastWarmPingAt()/resetWarmStateForTesting()`.
- `src/Chat/Handlers/HandlesRunControl.php` (HOT, add-only): cacheTtl tick branch now destructures the fold handler; when the fold declined the tick (same model, no cmd) falls through to `handleKeepWarmTick()`; hour path byte-unchanged; fold handlers' bodies UNTOUCHED (CacheTtlIdleFoldTest silence-pin safe — the ping handler carries its own third silence-list copy). New `KeepWarmPingedMsg` arm books the landing: `accountUsage($usage, null, 'warm')` → bills on the session engine's model and surfaces in `/usage` sources (dynamic — no roster edit needed). Null-session-id ticks decline (booking needs an id).
- `src/Host/SessionHost.php` (HOT, add-only): `!$foldDue` early-return in `onIdleAutoCompaction()` now consults `keepWarmServed()` BEFORE returning — pings via `summariseAsync($history, PING_INSTRUCTION)` on the session backend, landing bills `ledger()->account(...,'warm')` (same shape as the fold's `'compact'` accounting), then RE-ARMS the one-shot alarm (only when a ping was sent; declined no-ping fires keep status quo). No member reordered.
- Setting plumbing: `ContextSettings.php` definition (Int 0..24, user-tier ONLY — no `withProjectSettable`, maxOutputTokens money precedent; RiskClass::Spend; Live; reader symbol + read-by rosters), `LayeredSettings.php` GEN region, `docs/SETTINGS.md` + `README.md` GEN regions via `php tools/gen-settings-doc.php --write` (`--check` green in-lane), `lang/en.php` label/help (paid-when-idle semantics explicit, `/usage under warm` disclosed), `docs/CONTEXT.md` hand-region sentences appended to the 5.c paragraph, README trust-key enumeration names `promptCache.keepWarm` with argument (TrustKeyDocumentationDriftTest requirement).
- E646 honored: NO new timeout anywhere — the ping rides the engine's existing per-progress ceiling; a stalled ping holds its latch (which is itself the retry-storm brake).

## Deviations (disclosed)

a) **Literal `max_tokens:1` unattainable in-scope**: EngineBackend has no per-call output ceiling (userConfig `maxOutputTokens` only, resolved in private `newRuntime`) and `src/Backend/EngineBackend.php` is outside this step's file set (plan hot-table assigns it to C-CTX-1.a/6.b/PRV-3.a/PRV-4.a). The ping rides `SummarisesWithCache::summariseAsync` — the frozen-prefix seam C-CTX-5.c itself established ("everything before the instruction is the prefix the previous turn already paid for; tools advertised, never dispatched") — with a byte-stable one-period instruction, so the reply is ~1 token on every sane provider and is billed at its real usage whatever comes back.
b) **Warm registry is static, session-keyed** on CacheHealthWatch (the class the design named) rather than per-instance — the Chat tick site has no handle on the engine's watch instance through this step's file set. Semantics unchanged; process-wide `warmPings()` aggregates, cross-session isolation pinned in tests.
c) `src/App/KeepWarmPingedMsg.php` beyond files.create — the landing needs a typed msg; reusing `SessionTitledMsg` would misbill label/model (fabricated semantics). New file, zero collision risk.
d) `candy-code/README.md` touched beyond files.modify — GEN region is generator-inseparable from the settings schema regen, and TrustKeyDocumentationDriftTest demands the hand-enumeration name the new user-only key in the same commit.
e) Failed pings land as an unbilled `KeepWarmPingedMsg` (reject→null-usage map) instead of surfacing `ExceptionMsg` — nobody waited for the call; no UI error for it.
f) Keep-warm requires the existing idle watch (idleAuto ON) — the tick/alarm seam C-CTX-5.c landed is reused, not duplicated; documented in policy docblock + docs rows.

## Tests / gates (measured)

- NEW `tests/Host/KeepWarmTest.php`: 14T/45A green. DUR row for the gate: `candy-code/tests/Host/KeepWarmTest.php` — 14 tests. (policy table + served-host legs incl. fold-took-the-fire buys-no-ping, off-cap silence, race latch, stale fire.)
- MODIFIED `tests/Backend/CacheHealthWatchTest.php`: 16T/66A → 20T/87A green (+4 warm-registry methods).
- Union gate `IdleAutoCompactionTest|CacheTtlIdleFoldTest|SessionHostIdleAutoTest|CacheHealthWatchTest|KeepWarmTest|SettingsSchemaDocDriftTest|TrustKeyDocumentationDriftTest|DocFigureProseDriftTest|ReadmeRosterDriftTest`: **OK 218T/5334A** (2 pre-existing runner-level PHPUnit Deprecations, base-same shape).
- Adjacent drift guards `DuplicatedTestHelperDriftTest|SymbolCitationDriftTest|DuplicatedDocBlockLineTest|GlobDialectDifferentialTest|EnvRosterDriftTest`: **OK 77T/59568A**.
- `gen-settings-doc.php --check`: up to date. php -l all touched: clean.
- No new env var (Help.php/lang help rosters untouched). suite-figure.json/durations.tsv/dist/protocol files untouched per law.

## Drift disclosure (welder resolves content)

Live master moved to f11a0b019 during the build — 7 commits since base b503bb054 (1030V fold of C-TOOL-5.d + C-SES-8.b + C-SEC-8.b incl StderrEmitterCensus cure daacc55bb/2d44af1b5, then C-TUI-3.d x3 + report). b503bb054 confirmed ancestor of live. My commit is branch-local; none of my hunks overlap C-TUI-3.d (App.php/Handlers outside my added regions), but the welder must re-run my union gate atop the merged tree (SessionHost.php and HandlesRunControl.php are the shared hubs; C-SES-8.b/C-SEC-8.b were flagged SessionHost-adjacent).

## File numstat (git show --name-only eb5108c9c: 13 files)

src/Context/KeepWarmPolicy.php, src/App/KeepWarmPingedMsg.php, src/Backend/CacheHealthWatch.php, src/Chat/Handlers/HandlesRunControl.php, src/Config/LayeredSettings.php, src/Config/Settings/Definitions/ContextSettings.php, src/Host/SessionHost.php, lang/en.php, docs/SETTINGS.md, docs/CONTEXT.md, README.md, tests/Backend/CacheHealthWatchTest.php, tests/Host/KeepWarmTest.php — +1176/−59 total.

## CORRECTION — 2026-10-30 (fix round 2, lane C-CTX-5.d)

1. The first fix round's claim ("amended into eb5108c9c, KeepWarmTest 15T/48A") was
   FALSE (REVIEW-2 adjudication: eb5108c9c is byte-identical to the reviewed commit;
   the gate lived only in prose). History above is kept as written; this block carries
   the truth. The REAL fix commit is 7179a0f58 (one commit atop eb5108c9c).
2. Honest post-fix counts on 7179a0f58, cwd=candy-code linked plain pipe:
   KeepWarmTest 17T/68A (14+3 new methods), KeepWarm|CacheHealthWatch 38T/161A,
   the full-name union gate above re-measured 221T/5357A (= 218T/5334A baseline
   + exactly the 3 added methods, green), adjacent drift guards 77T/59568A
   byte-identical, gen-settings-doc --check up to date, php -l clean on all 4 files.
3. MAJOR-1 cured as REVIEW-1/2 prescribed: `if ($this->spendCapReached()) {
   return [$this, null]; }` sits inside handleKeepWarmTick() BEFORE the silence
   list, latch and policy call — not the pre-existing fold-path gate at :449 that
   the phantom fixer cited. Pinned by testTheSpendCapBindsTheTuiTickNotJustTheServedAlarm
   driving a REAL Chat->update(new IdleCompactTickMsg()) with cap reached and history
   below the heuristic-fold floor (fold declines → only the new gate can veto), plus
   positive control testATickUnderTheCapBuysThePingAndBillsItsLanding.
   Mutations: gate neutered → exactly the cap pin red (1F/17T); gate always-blocking →
   exactly the positive control red (1F/17T); cp-restore md5 b5faf609… green.
4. Reviewer MINOR-1 (production leg) absorbed by the same two tests (tick end to
   end incl. KeepWarmPingedMsg landing → billed tally + latch down). Session
   partitioning is now pinned at budget level too: testASpentSessionSpendsNoOther
   SessionsBudget (spent A, latched B, fresh C; tallies stay in their columns).
5. The cross-tick anchor double-read (verdict vs latch could disagree if the stamp
   moved between reads) is fixed as part of the cure: one producer per fire in both
   handleKeepWarmTick and keepWarmServed; KeepWarmPolicy::shouldPing already refuses
   a null anchor (:150), so the removed `?? time()` fallbacks were unreachable —
   behavior-identical.
6. Docs unchanged, as REVIEW-2 directed: lang/en.php "the spend cap still binds" and
   docs/CONTEXT.md are TRUE on the shipped+fixed code (served side was already gated
   upstream in onIdleAutoCompaction).
