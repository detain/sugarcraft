# C-TUI-5.c — REVIEW-3 (round 3, fix verification)

VERDICT: CLEAN (r2 fix landed + complete) — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local: fixer
disclosed watch figures undercount the landed tests by 1 — true counts supplied below for the
weld DUR MINT) / 0 NIT. Both MAJOR-1 and MAJOR-2 cures are present on-branch, load-bearing,
docs-truthful, and pinned by discriminating tests; the MINOR stat-comment fix landed.

## Commits / ancestry / hygiene
- Branch `cc/C-TUI-5.c` @ `/home/sites/cc-cmp-C-TUI-5.c`, EXACTLY 3 commits atop base
  `b77a18838` (all 4 SHAs `git cat-file -t`=commit; `git log --oneline b77a18838..HEAD` = the 3):
  `0e8c488cc` (watch+settle+/settings reload) + `49072ea07` (content-hash rewrite, both new test
  files) + `6b8fee691` "review fixes (round 1)".
- `6b8fee691` = 8 files +204/−26, `%ae`/`%ce` = detain@interserver.net, zero Co-Authored trailers
  (disk re-proved). Files: README.md, docs/SETTINGS.md, src/Chat.php,
  src/Chat/Handlers/HandlesTurnCompletion.php, src/Cli/Bootstrap.php, src/Config/SettingsFileWatch.php,
  tests/Chat/SettingsReloadTest.php, tests/Config/SettingsFileWatchTest.php.
- NO touches to suite-figure.json / durations.tsv / dist (numstat master...HEAD confirmed) — §1.8/§7 law held.
- `git status --porcelain`: only the two permitted untracked evidence paths. On-branch evidence dir
  carries REPORT.md only; REVIEW-1/2/FIX-1 live in the MAIN tree (welder collect, not a finding).

## MAJOR-1 — provider/layout falsely counted "applies now" — CURED
- New `private const SETTINGS_DOOR_ONLY = ['provider', 'layout'];` (Chat.php, landed blob
  `git show 6b8fee691:...Chat.php`). The apply chain now routes it to `$restart` BEFORE the
  ENGINE_SETTINGS branch and the `$now[] = $key;` catch-all:
  `if (\in_array($key, self::SETTINGS_DOOR_ONLY, true)) { $restart[] = $key; continue; }`
  placed after the `mode !== Live` gate, so only a Live-keyed hand edit is re-routed. Catch-all
  no longer lies.
- Genuinely no runtime apply arm (blob-proved): `applySettings()` branches are theme /
  maxCostUsd / mouse / statusLine only. `provider` = ApplyMode::Live, applied only by `/model`
  (SettingsWriter sessionRefusal → no view path); `layout` = ApplyMode::Live +
  UiEditability::ReadOnly (InterfaceSettings.php:80-89) with reader `Bootstrap::app()`→dock,
  applied only by the pane shell. Neither is reachable through the settings view; only the file
  watch / `/settings reload` (a hand edit) carries them here. Roster is complete (no third Live
  key lacks an arm).
- Docs truthful, both name theme only + state the door exception:
  README.md:1258 "…a hand-edited `live` key is in force by the next turn while `restart` keys
  keep waiting… — and so do `provider` and `layout`, the two live keys only their own doors
  (`/model`, the pane shell) apply, because an edit on disk opens no door."
  SETTINGS.md:1616 "A hand edit to a `live` key — `theme` among them — takes effect by the next
  turn. The two keys that are live through a DOOR are the exception… so a file edit to either is
  counted with the keys that wait for the next launch, not as one that applies now."
- Pin `testAHandEditedProviderOrLayoutIsNotCountedAsAppliedNow` (SettingsReloadTest) covers BOTH
  the reload path (`/settings reload`) and the settle path (AssistantMsg), three distinct disk
  values per key (launch/reloaded/settled), asserting "reloaded from disk" + "needs a restart" +
  key name + NOT "applies now". Mutation-reason (static — reviewer map denies lane edits):
  drop 'provider' from SETTINGS_DOOR_ONLY → provider falls to the catch-all `$now[]` → the
  reload+settle "needs a restart"/not-"applies now" asserts go RED; same for 'layout'. Pin
  discriminates the exact lie. Not vacuous: it is the only guard on the routing arm.

## MAJOR-2 — lazy first seed swallows idle-window edit — CURED + SCOPE ADJUDICATION
- `SettingsFileWatch::seedAtLaunch()` (landed blob :145-148) = `self::seed(self::watchedFiles())`,
  records fingerprint + mergedSnapshot, reports nothing. Called once from `Bootstrap::chat()`
  (src/Cli/Bootstrap.php, landed :1283-1286) AFTER `$chat = self::buildChat(...)` and before
  `return $chat`. Placed after the build because buildChat is what settles the project root +
  trust the watched set derives from.
- ROOT CAUSE genuinely removed (reachability, honest): the watch is settle-driven and the app only
  settles on activity (a turn boundary or `/settings reload`). Before the fix, if no turn had
  completed since launch, the FIRST observation IS the first look — so a disk edit that landed
  while the freshly-launched session sat idle is folded into that seed → reported zero → the
  "hand edits apply at the next turn boundary" promise silently fails. `Bootstrap::chat()` is the
  real launch path (confirmed in the landed blob), so the boot seed takes the first observation at
  launch (reporting nothing, by construction) and the later idle edit becomes a hash move that
  the next settle/reload sees. Load-bearing: without it the idle-window edit is never observed.
- SCOPE: src/Cli/Bootstrap.php is NOT in the design block's declared file list (fixer disclosed).
  Adjudication (per REVIEW-2 pre-ruling): a declared file could not carry this — the seed MUST run
  at launch after buildChat, and Bootstrap::chat() is that choke point; no Chat.php method is a
  launch hook, so a Boot.php call is the minimum fix. Accepted as the necessary cure; NOT a MAJOR
  scope violation. → WELD-CARRY NOTE below (the §4 mark must disclose the Bootstrap.php extension).
- Pins (all on-disk green): flipped `testTheBootSeedMakesAnEditLandingBeforeTheFirstLookVisible`
  (SettingsReloadTest, asserts the idle-window dracula edit becomes theme + "reloaded from disk"
  + "1 applies now"); new watch pins `testTheBootSeedMakesAnEditFromTheIdleWindowTheFirstReport`
  and `testASecondBootSeedBaselinesOnTheBytesItFound`; wiring pin
  `testTheLaunchPathItselfTakesTheFirstObservation` (source-scan of Bootstrap::chat() body).
- Unchanged semantics verified: never-seeded suite chats keep first-look behavior —
  `testTheFirstObservationSeedsTheFingerprintAndReportsNothing` still green; `/settings reload`
  "Nothing changed" on a quiet disk — `testASecondReloadSaysNothingChanged` still green (8/8 reload pass).

## MINOR — stale "stat" comments — FIXED (all 3 sites, landed blob `git show 6b8fee691:...`)
- HandlesTurnCompletion.php:299-301 → "the content hash of every watched file, and the merge
  re-derived only once a hash has moved" (stat-rung wording gone).
- SettingsFileWatchTest.php class docblock → "quiet files cost the hash of a small JSON file".
- SettingsFileWatchTest.php `testRewritingIdenticalBytesAnswersNothing` → "An mtime gate would
  accuse this file; the content hash is what answers". All match the r2 content-hash ladder.

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; PtyRun expect-miss excluded)
- `--filter 'SettingsFileWatchTest|SettingsReloadTest'` → **Tests: 20, Assertions: 71, OK (+2 deprecations)**.
- guard family `CommandDocsDrift|SettingsSchemaDocDrift|ReadmeRosterDrift|ReadmeSettingsTierClaim|LangParity|ReadPathCensus`
  → **67T/4347A OK**. DocFigureProseDriftTest → **92T/4165A OK**.
  DuplicatedTestHelperDriftTest + DuplicatedDocBlockLine → **18T/226A OK** (the 2 new test FILES
  share NO byte-identical helper: distinct private names — writeWatched vs writeDisk/draftChat/
  settlingChat/reloadToast — and the guard is green, so no step-local drift finding).
- ApplySettingsTest → 14T/166A **2F** = disclosed foreign apply-arm/session-tier standing red
  (unrelated diff context: autoTitle/promptSuggestionHistory roster). BootstrapLaunchFormatConstantsTest
  → 18T/271A **1F** = foreign `docs/DOCKER.md` standing red (unrelated to this change). KeyHelp 5F
  foreign standing (not run; slow; listed, not chased).

## MINOR (step-local) — fixer-disclosed watch figures undercount the landed tests
- FIX-1 / this step's brief cite `SettingsFileWatchTest 11T/17A` and combined `19T/67A`. The LANDED
  file is **12 tests / 21 assertions** (`--testdox` enumerated), and the combined run is **20T/71A**.
  `testTheLaunchPathItselfTakesTheFirstObservation` (the wiring pin) was not counted in the fixer's
  11. Not a correctness defect (all 12 green, all discriminating) — the welder's DUR MINT + §7 must
  use the true figure, not 11. Corrected: SettingsFileWatchTest **12**, SettingsReloadTest **8**,
  combined 20T/71A; §7 net-new is +4T (boot-seed×2 + wiring in watch, boot-seed flip pin in reload),
  16 builder + 4 = **20 tests** across the two new files.

## WELD CARRIES (record verbatim; welder executes — pick range uses the OLD base)
- Pick range EXCL-start `b77a18838..6b8fee691`, EXACTLY 3 commits (0e8c488cc, 49072ea07, 6b8fee691).
  OLD base b77a18838 — master is at 616d27864+: expect README/Chat/SETTINGS/Bootstrap collisions;
  resolve UNION preserving C-TUI-2.e / C-SEC-7.e / C-CTX-6.e regions. Cherry-pick, never tree-copy.
- DUR MINT (JUNIT-TESTCASE-SUM, median-of-3 via --log-junit, NOT wall): SettingsFileWatchTest **12**
  (true figure — see MINOR above; do NOT mint 11) + SettingsReloadTest **8**.
- §7: +20T total across the two new files (16 builder + 4 net-new pins). Recount confirmed on landed
  tests; state 20, not the brief's 19.
- BOOT-SEED reachability note + SETTINGS_DOOR_ONLY roster + the src/Cli/Bootstrap.php scope
  extension (load-bearing, not in the design list) all ride the §4 mark text.
- Collect REVIEW-1.md, REVIEW-2.md, REVIEW-3.md, FIX-1.md, lane REPORT.md onto the branch pre-prune.
