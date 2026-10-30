# FIX-1 — C-CTX-5.d (round 2, 2026-10-30)

Cured exactly the on-disk verdicts REVIEW-1.md (1 MAJOR/0 CRIT/2 MINOR/2 NIT) +
REVIEW-2.md (MAJOR-1 STILL OPEN, first fixer PHANTOM). Code landed as commit
7179a0f58 (cat-file commit; %ae/%ce both detain@interserver.net on separate greps;
zero trailers) atop eb5108c9c. 4 files, +172/−25:
HandlesRunControl.php (cap gate in handleKeepWarmTick + single anchor producer),
SessionHost.php (single anchor producer, dead `?? time()` fallback removed),
CacheHealthWatch.php (docblock truth: partition is budget+latch, not display only),
KeepWarmTest.php (+3 methods: cap binds the real tick / under-cap tick pays+bills /
cross-session budget+latch+tally bleed).

## Per finding
- MAJOR-1 (r1, carried by r2): FIXED. Gate is inside handleKeepWarmTick() before
  alarm/ping (r2 disk-adjudication location), pin dispatches the real handler tick
  (cap reached + below fold floor → only the cap can veto). Mutation-reasoned BOTH
  directions: neuter → exactly cap pin red; always-block → exactly positive control
  red; restore md5-verified green 17T/68A.
- MINOR-1 (r1): absorbed — the two tick tests ARE the direct production pin
  (update(IdleCompactTickMsg) → Cmd; $cmd() → KeepWarmPingedMsg landing → billed
  warmPings + latch release). r2 declared the standalone retraction moot; covered.
- MINOR-2 (r1, foreign ReadPathCensus ServerConfig×2/RemoteSessionPush): NOT touched
  per brief law (C-TUI-5.c owns that cure).
- NIT-1: session-keyed bleed verified pinned and EXTENDED — CacheHealthWatchTest
  testOnlyBilledLandingsAreWarmHits (tally) + new budget/latch-level bleed test.
- NIT-2: unchanged from r1 (base-era StderrEmitterCensus, cured on master by
  2d44af1b5; pick rides current master → green at weld).
- r2 doc MINOR: SETTINGS.md/CONTEXT.md/en.php untouched (not generated-region
  work this round); gen-settings-doc --check "up to date"; "spend cap still binds"
  now TRUE on both paths by the gate itself.

## MAJOR-2 in the spawn brief: PHANTOM — evidence
The brief's "MAJOR-2 (r1): provider-key mismatch, buildPromptCacheWireHistory vs
fold-time wireHistory, testNoPingWhenServedPromptDiffersFromStoredKey vacuous" does
NOT exist in any on-disk review (REVIEW-1.md on disk is 8050B, verdict header says
"1 MAJOR / 0 CRITICAL / 2 MINOR / 2 NIT"; no MAJOR-2 section) and the named symbols
appear nowhere in candy-code (grep of src+tests: buildPromptCacheWireHistory 0 hits,
DiffersFromStoredKey 0 hits; the lane's KeepWarmTest at brief time had 14 methods,
last three named in REVIEW-2 item 3). There is no provider-key record in the warm
registry: record+ping share ONE key derivation (session id: $this->currentSessionId
/ $this->sessionId) and, after this cure, ONE anchor value computed once per fire and
handed to verdict and latch alike — the substantive shape of the demanded "single key
derivation for record+ping, documented" is satisfied (CacheHealthWatch::$warm docblock
now says so explicitly). No guard to make non-vacuous exists; nothing to cure.

## Gates (final, committed tree 7179a0f58)
KeepWarmTest 17T/68A · +CacheHealthWatch 38T/161A · full-name union 221T/5357A
(= baseline 218T/5334A +3 methods, OK) · drift-guard adjacent 77T/59568A ·
SettingsSchemaDocDrift|TrustKey|ReadmeRoster|EnvRoster 86T/41474A OK ·
DocFigure 92T/4177A (r1-exact) · gen-settings-doc --check up to date · php -l 4/4.
2 PHPUnit deprecations throughout = pre-existing bootstrap shape (r2 noted same).

## Deviations from brief (disclosed)
- Brief asserted worktree "porcelain CLEAN, KeepWarmTest 14T"; disk showed the 4-file
  fix uncommitted. Judged FINAL disk and ADOPTED per the run's phantom-repair law;
  the fix commit 7179a0f58 landed concurrently (same content, md5-identical to what
  this session mutation-tested) — no re-implement, no duplicate pick.
- Brief's MINOR numbering follows a different review text than the on-disk files;
  cured the ON-DISK findings plus the brief's substantive asks (bleed pin, truthful
  counts, docs-true) — the brief's MINOR-2 correction block is in REPORT.md above.
