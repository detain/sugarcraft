VERDICT: CLEAN — 0C/0M/0m, round-1 gaps closed (r1: 0C/1M/1m+2nits)

Lane: cc/C-SEC-7.b @ 56aa4987f (base 484f313a9, 3 commits f0db09a45 + 60e99cc20 + 56aa4987f). Reviewed from landed blobs (`git show`); lane worktree never edited. Live gates run read-only in the worktree (plain pipe, env -u LINES -u COLUMNS, cwd=candy-code -c phpunit.xml).

## MAJOR-1 fix — VERIFIED
`testSafeModeRefusesTheForeignSkillBandAndKeepsNativeManifests` (blob diff @14): REAL `new SkillManager(new SkillLoader(reportSkips:false), new SkillRegistry())->loadAll($projectRoot)` walks, fresh loader/registry per walk — not reflection, not scans. Covers BOTH foreign legs (project `.claude/skills/sec7b-proj-foreign` + user `$HOME/.claude/skills/sec7b-user-foreign`, HomeSandboxTrait home) AND the native-kept leg (`.candy-code/skills/sec7b-native`, whose tier list `$native = $this->loader->manifestTiers(...)` is unconditional — blob SkillManager.php:124, r1's asymmetry). Unarmed control FIRST pins all three load (vacuity door); armed asserts null/null/native-kept. Choke real: `Bootstrap::safeMode()` read once into `$safe`, four `$safe ? [] :` legs (:119-123). Mutation-reason: neutering all four legs unconditionally re-arms exactly the `.claude` tier → both armed assertNull legs of THIS test only → exactly 1F (fixer-proved live; reviewer map denies php exec, reasoning + fixture-band analysis recorded). File now 14 methods (grep on blob) / **84 assertions — live run `OK (14 tests, 84 assertions)` EXACT**. REPORT.md carries the dated FIX-1 correction block on-branch; the bad "13 methods / 80 assertions" sentence kept as history inside it ✓.

## MINOR-1 fix — VERIFIED, pin shape honest
`testTheRestrictedDropRunsAfterTheProjectTierReport`: trusted-project user config + project `disabledTools:["Grep"]`; expected row rebuilt via `Lang::t('cli.launch.tool_removal')` + the leaving-list, `assertIsString($source)` refuses a vacuous fixture; row asserted BYTE-EXACT via assertContains on launchNotices() UNARMED (proves the report sees the fixture at all) AND ARMED after `resetNoticeShelves()` (fresh shelf, so the armed assert is about THIS call). Live gate output shows the exact row `... disabled 1 of the 5 tools your own settings left — Grep — leaving: Read, Edit, Task, Bash.` twice (unarmed+armed legs). Mutation-reason: drop moved above `reportProjectTierToolRemovals()` → report diffs an already-restricted set → row becomes 3-of-5 `Grep, Task, Bash` — armed `assertContains($expectedRow)` + the leaving-list both flip red; unarmed leg unaffected. Order halves now pinned: this AFTER-half + r1's pre-existing before-empty-warn half.

## NIT-1 fix — pure move PROVEN
Fix-commit Bootstrap.php is numstat 26/26, TWO hunks only (full diff printed): the const+docblock deleted from between filterToolSet's `@return`/`*/` and its signature, re-inserted byte-identically (line-by-line comparison of the printed +/- blocks; same docblock ends `@var list<string>`) above filterToolSet's docblock. Blob placement: const :9567, filterToolSet docblock :9570-9622, signature :9624 adjacent to `@return` — r1's orphan dead. Arithmetic closure: in_array usage :9641 = pre-move 9667−26 with the +26 re-inserted above → nothing else shifted. Semantic spot-check post-move: bundles gate :2636, latches :4324-4355, trustedProjectRoots safe-gate :7095/:7108, restricted drop :9638 — all r1-verified content, unchanged.

## NIT-2 fix — comment-only
StderrEmitterCensusTest +2/−1 inside the class docblock (sentence wrap). Census gate re-run: **95T/22549A OK** (2 pre-existing deprecations, r1-era shape) — counts unmoved.

## Gates (reviewer-run, lane worktree tip)
- RestrictedSafeModeFlagsTest: **14T/84A OK** (exact fixer claim)
- seam-census trio BootstrapLaunchNoticeCapCensus|Routing|BootstrapTranscriptSeamCallSiteCensus: **48T/824A OK** (r1-exact; move inert)
- StderrEmitterCensus: **95T/22549A OK**
- SkillManager|ForeignSkillDiscovery|SkillLoader|AgentsTreeAndLegacyOpencode: **150T/551A OK** (exact)
- LaunchFlags|ArgvParser|SessionRelaunch: **323T/1205A OK** (exact)
- doc-guard union DocFigureProseDrift|GlobDialect|ReadmeRosterDrift|EnvRosterDrift|ChildWallClock: **164T/57510A OK** (fixer said 163T/57507A — one derived provider row greener since; zero reds)
- DuplicatedTestHelperDrift: **14T/198A 1F** = the foreign `HEAVY_CHARS` Chat/ResumeCostGuardTest vs Host/ResumeCostEstimateTest row ONLY — fixer-disclosed base-repro, cured master-side per standing-reds roster; lane adds NO rows (`writeSkillFixture`/`walkSkillRegistry` tree-wide unique, grep :439/:452 sole sites).

## Laws sweep — fix commit 56aa4987f
`%ae=%ce=detain@interserver.net` (separate fields, all three commits), trailers grep over 484f313a9..56aa4987f = 0. Full-range file set (25 paths): zero touches to suite-figure.json, parallel-tests-durations.tsv, candy-code-web/dist; FIX-1.md+REPORT.md ride the branch as worktree copies (main-tree FIX-1.md absent, main-tree REPORT.md is the STALE pre-fix copy — treat the branch copy as truth). `gen-settings-doc --check` not execable on this map (php denied, same as r1); static proof re-landed: ContextSettings.php:264 passes `Bootstrap::LAUNCH_NOTICE_LIMIT` BY REFERENCE, const=52 (:277), SETTINGS.md:1021 cites the const name — 52-row claim is structural, run --check post-pick at weld anyway.

## WELD CARRIES (record)
- Pick range is **484f313a9..56aa4987f = 3 commits** (the spawn brief's `f0db09a45..56aa4987f` is off-by-one — excludes the code commit; f0db09a45^ == 484f313a9 proven). Cherry-pick onto MOVING master, expect zero conflict (r1 overlap scan still holds: r1..aa5ddf638 movers were README.md commands-roster region + en.php :1356/:2192, lane hunks disjoint; RE-PROVE vs live tip pre-pick).
- DUR row `RestrictedSafeModeFlagsTest` = **14T** (median-of-3 at weld; 12T superseded).
- §7 figure carry = **+16 methods** (14 + ArgvParser 1 + SessionRelaunch 1); StderrEmitterCensusTest fix is comment-only (+0T).
- gen-settings-doc: claim 52, const-by-reference — prove with `--check` post-pick.
- Collect REVIEW-1.md (main-tree, 6902 B) + REVIEW-2.md onto the branch pre-prune; do NOT overwrite branch REPORT.md with the stale main-tree copy.
- StderrEmitterCensus / seam-census / channel-5 rosters landed in-step at r1 and stayed green post-move.
