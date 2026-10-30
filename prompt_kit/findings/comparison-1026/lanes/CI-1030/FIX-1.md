# CI-1030 — FIX-1 (fresh fixer round, 2026-10-30)

ANTI-PHANTOM note: the prior fixer round (claimed a76ec03c7 + FIX-1.md) never
existed on disk; this file is that round's replacement, written only from
commits made and gates run in this session. Branch cc/CI-1030, base tip d9c381689,
fix commits atop it as listed. Master at re-check: 616d27864.

## MAJOR-1 — ThirteenVerbs roster — CLOSED

Commit `438f61a09098fae43074ba4d631c852cd4c44d94` (candy-code/tests/Cli/SelfUpdateTest.php):
`testTheRosterCarriesTwelveVerbs` → `...ThirteenVerbs`; list now
acp, attach, bundles, completion, doctor, login, lsp, mcp, models, self-update,
serve, session, usage (index 6 = lsp, alpha order preserved). Proven in-step:
`git show 616d27864:candy-code/src/Cli/ParsedArgs.php` SUBCOMMANDS = exactly 13
verbs incl. lsp (line 56 region). Master's own copy still asserts ElevenVerbs/11
= standing red at 616d27864 (only d7a2efbf1 touched the pair since 59ea47407 and
never updated it); this pick is that red's cure when welded — weld-note duty.
Worktree gate: `--filter testTheRosterCarriesThirteenVerbs` →
`Tests: 1, Assertions: 5, Failures: 1` (lane-local ParsedArgs still has 12 —
base-era constant; EXPECTED here, green status deferred to the throwaway-merge
run below).

## MAJOR-2 — userConfig baseline count — CLOSED (reviewer finding refuted, correction landed)

Two commits: `2e89918baace5101d4bb2729ff65b39442315c05` first applied r1's 5→4;
measured outcome on this worktree: **15 errors, including the non-ignorable**
`ignore.count ... expected to occur 4 times, but occurred 5 times` (Chat.php:2962)
plus a re-reported `Chat/Handlers/HandlesTurnCompletion.php:328` neverNull.
Root cause: the r1/r2 claim "real sites are 4 on every tree" counts textual
sites inside Chat.php only; PHPStan 2.1 matches this baseline entry by MESSAGE
across the project, and HandlesTurnCompletion.php:328 (`userConfig: $done->workspace?->userConfig ?? []`)
carries the identical message — 4 (Chat.php: 2962/13653/16951/20202) + 1 (HTC)
= 5 occurrences, verified with the entry stripped (`--error-format=json` run:
exactly those 5 lines). Site census identical (4+1) on base 59ea47407, lane tip
and master 616d27864 — so the merged shape needs 5 too.
Correction commit `3b4405f9d6d80911090ae3ee23c0f88ab2208bd0` restores count: 5
and adds a neon why-comment so a future auditor cannot re-"fix" it to 4.
Worktree gate: phpstan count:5 → `Found 13 errors`, grep of output for
`userConfig|ignore.count|HandlesTurnCompletion` → 0 matches. The 13 == the
builder's disclosed in-flight residue (EngineBackend 1, Chat.php 6,
Bootstrap 1, Subcommands 2, SessionHost 3) — every one a src site neither this
lane nor the count edit introduces; no NEW unmatched baseline entries
(ignore.count is the only "unmatched entry" genre and it is gone).
Merged-shape re-run: throwaway merge below.

## MINOR-1 — SessionTab armDeck regression pin — CLOSED

Commit `8d7646c9941fdc6979ef0a5ee03dc7491d782fa3`: ONE pin
`testArmDeckSeedsTheActiveTabSessionNameNotTheDefault` added to
candy-code/tests/App/AppModelTest.php (existing file — NO new test file, no
durations row owed). Drives the fixed path: Chat seeded with a named launch tab
→ `withChat(...)->withTabFactory(...)` → `update(new WindowSizeMsg(100,30))`
arms the deck → asserts `$deck->tabs()->activeTab()->sessionName` carries the
launch name, plus an assertNotSame against `Lang::t('tabs.name_new')`.
Gates: worktree `--filter <that name>` → `Tests: 1, Assertions: 5` OK
(2 PHPUnit Deprecations are pre-existing in this suite, present on untouched
tests). Mutation: reverting App.php armDeck to the pre-fix
`$activeTab?->name ?? Lang::t('tabs.name_new')` → exactly
`Tests: 1, Failures: 1` at AppModelTest.php:161; restore → green,
`git status` clean. Reviewer's "revert reddens exactly this pin" reproduced.

## MINOR-2 — REPORT.md erratum — CLOSED

Commit `4e61c687a3ce9dca2560d3437d76668addcdd887` (prompt_kit/findings/.../CI-1030/REPORT.md
tracked in this branch): dated 2026-10-30 erratum appended, wrong sentences kept as
history. Truths pinned there: MediaSettingsTest / MenuBarLabelDerivationTest durations
rows DO exist at base lines 1135/1256 (master 1140/1260) — the "no row → runs in zero
shards" claim was false; the REAL stale rows are the `candy-code/tests/Skills/...`
paths (master 937/966) pointing at moved files. Those rows live in
scripts/parallel-tests-durations.tsv = lane-forbidden file → §7 closeout rider.

## Merged-shape re-run (throwaway, 2026-10-30)

`git checkout -b tmp/ci1030-mc && git merge --no-commit --no-ff 616d27864` —
auto-merged CLEAN (zero conflicts; only candy-code/lang/en.php + src/App/App.php
needed auto-merging). On that merged tree:
- phpstan → `Found 15 errors`, `userConfig|ignore.count|HandlesTurnCompletion`
  grep = 0 (our count:5 entry is merged-clean). Delta vs worktree 13 = exactly
  two NEW errors at `Cli/Subcommands.php:4452/4457` — `$lspInstaller`
  property/parameter phpDocType wording from MASTER's own C-TOOL-5.d code;
  master 616d27864's baseline (which still carries the stale count: 3 userConfig
  entry, i.e. master's own phpstan leg is standing-red just like its
  ElevenVerbs roster) has no entries for them. NOT produced by any pick of this
  branch (they are master's lines); welder's re-derivation owes +2 Subcommands
  baseline entries (or a master code fix) and will re-pin userConfig at 5.
- `--filter testTheRosterCarriesThirteenVerbs|testArmDeckSeedsTheActiveTab...` →
  `Tests: 2, Assertions: 12` OK. MAJOR-1 roster GREEN against merged reality.
Then `git merge --abort`, back on cc/CI-1030 @ 3b4405f9d, worktree clean.

## §7 riders — LISTED, deliberately NOT fixed by this lane (closeout ledger)

Verbatim dispositions from r2 REVIEW-2.md (this fixer round leaves all five to
the welder/closeout; none is fixable without touching lane-forbidden files):
1. scripts/parallel-tests-durations.tsv stale rows — candy-code/tests/Skills/
   SkillsHistoryUndoTest.php + SkillWorkshopTest.php paths (master lines 937/966)
   point at moved files and kill 2 of 4 shards rc=2; refresh at §7 closeout.
2. suite-figure.json + README suite-figure staleness pair — closeout re-pin duty
   (lane-forbidden file; any new-test-file count moves it).
3. Ad-hoc owner-lane §4 mark: lead marks '✅ d9c381689(+fix) CI-1030 (ad-hoc
   owner lane)' under the nearest §4 heading only if the house convention file
   exists, else skips (disclosed); `grep CI-1030 plan_comparison.md` = 0 hits;
   mark owed at weld via path-scoped `git commit -o -- plan_comparison.md`.
4. candy-code-web eslint leg — blocked by host-wide composer/npm breakage
   (composer SIGSEGV); Codacy/codecov token reds are external infra, not ours.
5. New weld datum: this branch's ThirteenVerbs pick CURES master's standing-red
   ElevenVerbs roster at 616d27864 — call it out in the weld note; userConfig
   count: 5 likewise cures master's stale count: 3 (two cure-at-weld facts from
   one lane, disclose both).

## Verdict

All four review items CLOSED. Fix-tip: 3b4405f9d (5 commits atop d9c381689).
Zero new test files (pin rode existing AppModelTest). Zero forbidden files
touched (suite-figure.json, parallel-tests-durations.tsv, candy-code-web/dist
untouched; master untouched; no rebase; pick range for the welder stays
EXCL-start 59ea47407..3b4405f9d = 12 commits).
