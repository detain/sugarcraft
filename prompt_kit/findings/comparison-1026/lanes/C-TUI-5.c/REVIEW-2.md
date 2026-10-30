# C-TUI-5.c — REVIEW-2 (round 2, reviewer lane)

VERDICT: FINDINGS — 0 CRITICAL / 2 MAJOR / 1 MINOR (step-local) / 0 NIT (lane needs fixer round 2; both r1 MAJORS RE-CONFIRMED on disk, cure work LOST)

## (A) State of the lane — fixer round 1 died, work NOT on disk

- `git -C /home/sites/cc-cmp-C-TUI-5.c log --oneline master..HEAD` = exactly `49072ea07` + `0e8c488cc`
  (ancestry re-proved: `git log --oneline b77a18838..49072ea07` = the same two; base = b77a18838).
- `git status --porcelain` = only the permitted untracked evidence paths. `git diff --stat` and
  `git diff --cached --stat` = EMPTY. The claimed fixer commit object `c11207a22`: `git cat-file -t`
  → "Not a valid object name". The two stashes are foreign residues (stash@{0} = cc/C-CTX-5.c
  recovery, contents also at /tmp/opencode/cc-cmp-lanes/P-AGENT.b/foreign-stash-residue/;
  stash@{1} = old pre-merge master WIP — its file list contains ZERO lane files: no
  SettingsFileWatch.php, no Chat.php, no Settings docs/tests).
- **Conclusion: BOTH MAJOR CURES AND THE MINOR ARE UNCOMMITTED-AND-GONE. The reviewed build is
  the 2-commit r1 state; fixer round 2 must re-do everything from scratch.**

## (B) MAJOR-1 re-derived on landed blobs — provider has no apply arm yet counts "applies now"

Disk-proof chain: `provider` is `ApplyMode::Live` at
`candy-code/src/Config/Settings/Definitions/ModelProviderSettings.php:41`; `Chat::ENGINE_SETTINGS
= ['maxToolSteps', 'reasoningEffort']` (`src/Chat.php:7819`) — provider not in it; the Live chain in
`applySettings()` has explicit arms ONLY for `theme`/`maxCostUsd`/`mouse`/`statusLine`
(`src/Chat.php:7934-7967`) and then falls through to the catch-all `$now[] = $key;` (`src/Chat.php:7968`).
Both new callers feed changedKeys() straight into applySettings: settle arm
`src/Chat/Handlers/HandlesTurnCompletion.php:310-313` and `/settings reload`
`src/Chat.php:12622-12629`. A hand-edited `provider` therefore rides into "N applies now" while the
running EngineBackend keeps the launch provider (in-session door is `/model` — SettingsWriter.php:447
story, SETTINGS.md:1582 "provider and layout are live through their own doors"). The new prose
`docs/SETTINGS.md:1616` — "A hand edit to a `live` key — `theme` and `provider` among them — takes
effect by the next turn" — repeats the false claim. None of the 16 lane tests covers provider.

FIX (exact, lane-owned):
1. `src/Chat.php` applySettings: before the fall-through `$now[] = $key;` at :7968, gate on an
   applied-live roster — route Live keys with NO apply arm (at minimum `provider`; cleanest is a
   const `APPLIED_LIVE_KEYS = [ENGINE_SETTINGS..., 'theme','maxCostUsd','mouse','statusLine']` plus
   any key a branch actually mutates) to `$restart[] = $key; continue;`. The toast already counts
   restart keys honestly (`testARestartKeyReloadedStillOnlyRestarts` shape, SettingsReloadTest:219-232).
2. `docs/SETTINGS.md:1616`: drop provider from the hand-edit live sentence — name `theme` only and
   keep provider's word as `/model` (consistent with :1582; do NOT touch the :1544/:1238 table rows —
   the `live` badge describes the SAVE path and :1448 already says provider's writer is `/model`).
3. Pin (SettingsReloadTest): hand-edit `provider` on disk → `/settings reload` toast must say
   "needs a restart", must NOT say "applies now" — mirror the titleModel restart-key test exactly.

## (B2) MAJOR-2 re-derived — lazy first seed swallows every pre-first-settle hand edit forever

Disk-proof: `SettingsFileWatch::$fingerprint` starts `[]` (`src/Config/SettingsFileWatch.php:64`);
the first `changedKeys()` observation hits the set-move branch, SEEDS and returns `[]` (:91-95,
`seed()` :178-186). The ONLY seed triggers are `HandlesTurnCompletion.php:310` (first settle) and
`Chat.php:12622` (first `/settings reload`) — grep over `src/`+`bin/` shows no boot seed. So an edit
landing between the launch config read and the first observation is baked into `$mergedSnapshot`;
its diff is empty and STAYS empty until a LATER edit moves a hash — the first edit never reaches
applySettings. The class docblock premise (:40-43, "the launch merge and the seed snapshot are read
from the same files") is false for that window (all idle time before the first turn boundary).
The pin locking the wrong premise: `tests/Chat/SettingsReloadTest.php:205
testTheFirstForcedLookOnlyBaselinesTheFiles` — asserts theme stays 'dark' and toast "Nothing
changed" while disk says 'dracula' (the task brief misfiled it under SettingsFileWatchTest; the
name lives in SettingsReloadTest.php — fixer take note).

FIX (exact, lane-owned):
1. Seed at construction: one `SettingsFileWatch::changedKeys();` call in the launch path AFTER the
   session's config is first read (Chat/Bootstrap launch — e.g. `Bootstrap::chat()` right after its
   `readUserConfig()`/LayeredSettings read; it returns `[]` there by construction and its first
   observation makes seed bytes == launch bytes). Preferred shape: a small public
   `SettingsFileWatch::seedAtLaunch(): void` delegating to the same first-look, so intent is named
   and the census/roster rows are untouched (no new file reads added — seed already hashes).
2. Flip `testTheFirstForcedLookOnlyBaselinesTheFiles` polarity: post-seed hand edit must be
   RELOADED (write theme=dracula AFTER the launch-seeded look → `/settings reload` toasts
   "reloaded from disk" and theme becomes dracula). Keep a separate honest pin that launching WITH
   dracula already on disk toasts nothing (launch bytes == seed bytes — that premise becomes TRUE
   once boot seeds).
3. Truth-flip the class docblock :40-43 to the boot-seed law ("the launch merge and the seed
   snapshot are read from the same files — the boot look guarantees it").
4. `tests/Config/SettingsFileWatchTest.php:57` testTheFirstObservationSeedsTheFingerprintAndReportsNothing
   STAYS — the unit-level first-look-seeds law (incl. re-seed on set move) is unchanged.

## (B3) MINOR (step-local) — three stale "stat" comments (the stat rung commit-2 deleted)

- `src/Chat/Handlers/HandlesTurnCompletion.php:299-301`: "stat unless a stat moved, hash unless a
  hash moved, merge-diff only then" — no stat rung exists at HEAD (every settle hashes).
- `tests/Config/SettingsFileWatchTest.php:19`: "quiet files cost a stat".
- `tests/Config/SettingsFileWatchTest.php:88`: "The stat gate lets it through; the hash gate is
  what answers".
Fix: reword all three to the content-hash ladder matching SettingsFileWatch.php's class docblock
(:22-36).

## (C) Gate reproduction on the CURRENT (r1-state) worktree — cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe

- `--filter 'SettingsFileWatchTest|SettingsReloadTest'` → **Tests: 16, Assertions: 48, OK** (2
  pre-existing PHPUnit deprecations). EXACT r1 match.
- `--filter 'CommandDocsDrift|SettingsSchemaDocDrift|ReadmeRosterDrift|ReadmeSettingsTierClaim|LangParity|ReadPathCensus'`
  → **67T/4347A OK**. EXACT r1 match.
- `--filter 'DocFigureProseDriftTest'` → **92T/4165A OK**. EXACT r1 match.
- `--filter 'ReadPathCensus'` alone → 19T/729A OK. `--filter 'SettingsWriterCensus'` alone → 5T/5039A OK.
  `--filter 'EnvRosterDrift'` alone → 37T/40550A OK.
- `--filter 'KeyHelp'` → **65T, 5 Failures** — the foreign standing red family (builder REPORT
  proves identical 5 arms red at base b77a18838 and tip; e.g. KeyHelpTest.php:3945 ellipsis arm). CONFIRMED foreign.
- DISCREPANCY (lead-note, not a lane finding): the brief's "ReadPathCensus 194T/8718A" does NOT
  reproduce for ANY exact-class run (alone = 19T/729A; the six-class union = 178T/50684A). The
  figure likely rode the dead fixer's report; the r1 REVIEW-1 gate set above is the authority and
  it reproduces byte-exactly.

## (D) r1 adjudications kept (NOT re-litigated)

Deviations (i)-(ix) stand as SOUND per REVIEW-1 §Deviation adjudications (content-hash-per-settle
law-call (viii), file_get_contents census row (i), lang-key collision rename (ii), own-view-save
idempotence (v), foreign ReadPathCensus cures = W25-m1 SATISFIED). Only (vi) remains overturned —
it is MAJOR-2 above.

## (E) CARRIES for fixer round 2 / weld

- Branch state to build on: `cc/C-TUI-5.c` @ 49072ea07 (fixer adds commits ON TOP; pick range at
  weld stays EXCL-start `b77a18838..<new-tip>`, cherry-pick never tree-copy).
- Fixer will collide with current master (ad60f7a3f+): Chat.php/SETTINGS.md/README/COMMANDS/census
  rows may have moved — collision-resolve at weld per lead law.
- DUR MINT: both new test files likely gain 1 pin each (provider-restart + boot-seed flip) →
  re-count junit-testcase-sum for durations.tsv rows at re-review; §7 figure +16T (+ any new T).
- Mutation-discrimination expected of the fixer: neuter the provider-restart routing → the new pin
  reddens; neuter boot-seed → flipped SettingsReloadTest pin reddens. Prove both red-then-green.
- Collect lane REPORT.md + REVIEW-1.md + this REVIEW-2.md pre-prune.

