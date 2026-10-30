# C-TUI-5.c — REVIEW-1 (round 1, reviewer lane)

VERDICT: FINDINGS — 0 CRITICAL / 2 MAJOR / 1 MINOR (step-local) / 0 NIT

Lane: branch `cc/C-TUI-5.c` @ `/home/sites/cc-cmp-C-TUI-5.c`, commits `0e8c488cc` + `49072ea07`
(exactly 2 atop master-at-cut `b77a18838`; all SHAs cat-file verified; ancestry re-proved by this
reviewer: `git log --oneline b77a18838..HEAD` = the two). Authors: both commits
`%ae`/`%ce` = detain@interserver.net, zero Co-Authored trailers (re-verified).
`git status --porcelain` clean except the two permitted untracked evidence paths.

## Scope conformance

`git diff --stat b77a18838..HEAD` = 10 files, +698/−12, exactly the design block's
`### C-TUI-5.c` files list (design-tui.md read verbatim). No unnamed files;
suite-figure.json and durations.tsv untouched (§1 law respected). Chat.php diff is
add-only inside handleSettingsCommand's declared region + docblock; HandlesTurnCompletion
settle arm is an insertion; no reordered hunks in either hot file.

## Gates (lane worktree, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)

- `--filter 'SettingsFileWatchTest|SettingsReloadTest'` → **Tests: 16, Assertions: 48, OK**
  (decomposition matches claim exactly: 9T/14A + 7T/34A; 2 PHPUnit deprecations pre-existing).
- `--filter 'CommandDocsDrift|SettingsSchemaDocDrift|ReadmeRosterDrift|ReadmeSettingsTierClaim|LangParity|ReadPathCensus'`
  → **67T/4347A OK** (builder's figure is the family total — honest).
- DocFigureProseDriftTest → **92T/4165A OK**.
- ApplySettingsTest → 14T/166A **2F** at tip — matches builder's disclosed base-proven
  apply-arm/session-tier foreign-red family; green-equivalent per lead roster.
- `tools/gen-command-docs.php --check` not runnable here (reviewer permission map denies bare
  `php`/`cp`/edit in lane trees); the green CommandDocsDrift+SettingsSchemaDocDrift guards are
  the same re-derive-and-compare law; generator claims corroborated.

## Fingerprint re-derivation (core, verified from landed blobs)

`git show 49072ea07:candy-code/src/Config/SettingsFileWatch.php`: process-global
`$fingerprint[path]=sha256(contents)` (`''` when absent/unreadable) + `$mergedSnapshot`.
changedKeys(): watched-path SET move → silent reseed, return []; else hash every watched
file (bounded: config.json, user settings.json, trusted project files — 2–4 small files);
no hash move → []; hash move → `Bootstrap::readUserConfig()` re-merge + loose != key diff
(add/remove/edit both directions) + snapshot swap. seed-first reports nothing. Logic correct
for add/remove/edit of project files; watchedFiles() existence-independent fixed path set
means appearance/disappearance rides the hash rung (`''` ↔ digest), reported — pinned by
testTheAppearanceOfAFileIsReported/testTheDisappearanceOfAFileIsReported. Trust gate via
LayeredSettings::projectFiles, not re-derived — good.

## Deviation adjudications

- **(i) file_get_contents+hash instead of hash_file — SOUND.** ReadPathCensusTest
  FUNCTION_SINKS (:76) lists file_get_contents, not hash_file; McpDecision.php:566-568 +
  ReadPathCensusTest:377 establish hash_file as outside the sink vocabulary; lane added row
  `Config/SettingsFileWatch.php|file_get_contents => CALLER_SUPPLIED` in-step with truthful
  boundary justification; @-suppression checked against `false` return.
- **(ii) lang keys `chat.settings.reload.disk`/`.unchanged` — SOUND.** Genuine collision with
  ApplyMode::Reload keys; LangParity green; no stale-key references tree-wide.
- **(v) own-view saves re-apply idempotently + repeated toast — ACCEPTABLE.** Literal design
  consequence (the save moves the file bytes; the design's settle arm fires identically); the
  fingerprint cannot know its writer; disclosed in class docblock :51-55.
- **(vi) first forced look baselines → 'Nothing changed' — NOT sound; MAJOR-2 below.**
- **(viii) content-hash replacing design's "cheap stat compare" — SOUND, design-conformant.**
  The design's own fingerprint tuple includes `hash`, so a content read per settle is mandated;
  a stat-only rung is genuinely unsafe (same-second, same-size edits missed permanently); cost
  is bounded to the settings files at the settle boundary only (the same files the engine reads
  per turn anyway). Class docblock :25 states the honesty argument.

## Findings

### MAJOR-1 — `provider` falsely counted "applies now"; new SETTINGS.md sentence names it
- `candy-code/docs/SETTINGS.md:1616` (new prose): "A hand edit to a `live` key — `theme` and
  `provider` among them — takes effect by the next turn".
- But `provider` is `ApplyMode::Live` (`ModelProviderSettings.php:41`) with **no apply arm**:
  `Chat::applySettings()`'s Live chain covers only theme/maxCostUsd/mouse/statusLine
  (`src/Chat.php:7934-7967`), and the catch-all `$now[] = $key;` (`src/Chat.php:7968`) counts
  `provider` into "N applies now" while the running EngineBackend keeps the launch provider.
  `SettingsWriter.php:447` is the blob proof the in-session door is `/model` ("which rebuilds
  the backend"); `withEngineSettings()` (`src/Chat.php:8065+`) re-applies only maxSteps+effort.
  The old text's "waits for next launch" story for provider was TRUE; this lane moved it into
  the live story without the arm. None of the lane's 16 tests covers a provider hand edit.
- Fix (lane-owned): in the two new callers (or in applySettings) route Live keys with no apply
  arm — at minimum `provider` — to `$restart` (the toast already counts them honestly), or
  filter them from `$reloadedKeys`/`$changed` with the restart wording; correct the SETTINGS.md
  sentence to name `theme` only (provider's word stays `/model`); add a pin: hand-edit
  `provider` → settle/reload must NOT claim applies-now.

### MAJOR-2 — lazy first seed swallows pre-first-settle edits forever
- `SettingsFileWatch::changedKeys()` first observation is lazy: first settle or first
  `/settings reload` ever (`src/Config/SettingsFileWatch.php:83-95`, `seed()` :178 — only
  callers are HandlesTurnCompletion.php:310 and Chat.php:12622; no boot seed). An edit landing
  between the launch's config read and that first observation is baked into the baseline seed,
  so its changedKeys() diff is empty — and stays empty until ANOTHER later edit moves a hash;
  the first edit never reaches applySettings. The class docblock premise "the launch merge and
  the seed snapshot are read from the same files" (:40-43) is false for that reachable window
  (all idle time before the first turn boundary). `testTheFirstForcedLookOnlyBaselinesTheFiles`
  pins the wrong premise (asserts theme stays 'dark' while disk says 'dracula').
- Feature promise ("a hand edit reaches the running session at the next turn boundary") is
  broken for exactly the first hand edit when it precedes the first settle.
- Fix (lane-owned): seed eagerly at boot where the session's settings are first read (e.g. one
  `SettingsFileWatch::changedKeys()` call in the Chat/Bootstrap launch path after the config
  read — it returns [] there by construction), so first-observation bytes ARE the launch bytes;
  flip the baseline test's polarity to post-seed-manual-edit-reloads.

### MINOR (step-local) — three stale "stat" comment sites from the commit-1 ladder commit-2 deleted
- `src/Chat/Handlers/HandlesTurnCompletion.php:299-301`: "stat unless a stat moved, hash unless
  a hash moved, merge-diff only then" — no stat rung exists at HEAD (every settle hashes).
- `tests/Config/SettingsFileWatchTest.php:19`: "quiet files cost a stat".
- `tests/Config/SettingsFileWatchTest.php:88`: "The stat gate lets it through; the hash gate is
  what answers".
Fix: reword all three to the content-hash ladder matching SettingsFileWatch.php's class docblock.

## Mutation-reason (vacuity control; runtime mutations impossible — reviewer map denies
`cp`/edit in lane trees; disclosed, static trace from the landed test sources)

- Neuter `changedKeys()` → always `[]`: RED — SettingsFileWatchTest
  testAHandEditIsReportedOnce, testTheAppearanceOfAFileIsReported,
  testTheDisappearanceOfAFileIsReported, testAChangeOfTheWatchedSetReseedsSilently (second
  leg), + SettingsReloadTest settle-adopt, park-under-carried-turn, slash-reload, alias/case,
  second-reload-says-nothing-changed (post-edit leg), restart-key tests. GREEN (expected-[]
  negative controls): quiet-set, first-baseline. Detector is load-bearing; not vacuous.
- Neuter park guard (`if ($chat->inFlight)` → false, src/Chat.php:7925): RED —
  SettingsReloadTest::testAnEngineKeyUnderACarriedTurnParksOnTheShelf (pendingSettingsApply
  assertion + "1 when this turn ends" toast) and the existing ApplySettingsTest park pins.

## ReadPathCensus / in-step guard law

Lane rows in-step (above). **Foreign cures verified against blobs**: at `b77a18838` the census
roster has NO ServerConfig/RemoteSessionPush rows (git-show exit=1) while the sinks exist at
base (ServerConfig.php 2× file_get_contents, RemoteSessionPush.php 1×); tip rows land →
**master's standing ReadPathCensusTest red is cured once these picks land → W25-m1 fix_queue
item: SATISFIED.** (Lead's citation 42860d6f1 is not a valid object — rewritten-era reference,
not a lane finding; actual foreign cure SHAs 3eac7826c-era content rides 0e8c488cc.)

## CARRIES for weld/fixer round 2

- Pick range stays EXCL-start `b77a18838..49072ea07`, 2 commits, cherry-pick never tree-copy;
  expect README/COMMANDS/census collision-resolves vs current master.
- DUR MINT unchanged: SettingsFileWatchTest (9) + SettingsReloadTest (7), junit-testcase-sum
  median-of-3; §7 +16T.
- MAJOR-1 + MAJOR-2 + MINOR fixes ride fixer; both new-test files likely gain 1-2 pins each —
  re-count DUR MINT figures at re-review.
- Collect lane REPORT.md + this REVIEW-1.md pre-prune.
