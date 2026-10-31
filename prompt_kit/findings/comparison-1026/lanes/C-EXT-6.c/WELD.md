# C-EXT-6.c WELD record (welder session, 2026-10-31)

## Step 0 — preflight (VERIFIED FROM COMMANDS)
- `git rev-parse master` = `9a0c569eb796a00a9f1ae1fb3296375018b6d59a` — matches lead's stated base (tip = `weld: C-WEB-5.b — §4 mark + evidence + run-state`; log also carries `weld: C-EXT-6.b` at `def8d7c9f`/`75520589f`).
- Branch = `master`; tree dirt only in `prompt_kit/findings/comparison-1026/lanes/` + lead-side `.opencode`/WELD dirt (never staged).
- Landed-blob `^#### ✅` count BEFORE = **294** (`git show master:plan_comparison.md | grep -c '^#### ✅'`).
- Lane picks FROM DISK (`git log --reverse 288b81f11..cc/C-EXT-6.c`): THREE commits —
  `ac7ce47a76dbcbf034a2b5f1d383cf779dc6e24b` C-EXT-6.c: `type: http` hooks
  `969ee98b3ec594a2dad4386558c19843f034982f` C-EXT-6.c: pin the http hook's every refusal, and fix a type the parser could not refuse
  `f895fc2ed5d603e10b88ca4d940db0b67303159e` C-EXT-6.c: review fixes (round 1)
  Lead's dispatch said "five landed shas" in §7 wording — DISK says 3 picks + tip f895fc2ed; disk wins (anti-phantom law). Heading will name the THREE landed shas.
- Lane worktree `/home/sites/cc-cmp-C-EXT-6.c` porcelain 0, tip `f895fc2ed`.
- Evidence on disk: REPORT.md 12,937 B · REVIEW-1.md 13,323 B · FIX-1.md 4,312 B · REVIEW-2.md 7,773 B — all lead-statted values match.

## Step 1 — review-loop facts (ALL read from disk evidence this session)
- r1 REVIEW-1.md (13,323 B): VERDICT FINDINGS C=0 M=1 MINOR=1 NIT=2. MAJOR M1: `pinnedPost()` claimed a `Host:` pin in THREE sites (HttpHook class docblock :37-39, pinnedPost docblock :633-636, new ReadPathCensusTest fopen-row sentence) while the real wire wrote none — PHP defaulted Host to the bracketed dial literal, breaking advertised vhost routing, and the named test asserted only the request-array `host` KEY = whole-wire vacuity class.
- FIX-1.md (4,312 B), pick f895fc2ed: wire cured with guard-parallel `$hostHeader` (default-port omission mirroring PublicUrlGuard::transferPinned :444/:453) + `'header' => "Host: $hostHeader\r\n"`; NEW STRUCTURAL source-scan pin `HttpHookTest::testTheRealWireCarriesTheHostPinTheClaimSitesPromise` (house precedent PublicUrlGuardTest per-read-transport-bound arm), cp+md5 mutation red at the `'header' => "Host: ` lead-in arm (1F at :288). n1/MINOR redirect-comment overstatement cured truthfully (comment-only: the `<200||>=300` arm already denies a 302, the 3xx arm's job is the distinct reason word). Claim sites: ZERO prose edits DELIBERATELY — affirmative claims became TRUE as written; flipping text would mint the opposite falsehood.
- r2 REVIEW-2.md (7,773 B): VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0. Q3 AGREED the declined three-site rewrite (not a finding). Q2 pin LOAD-BEARING by byte-exact-needle + guard-precedent arms (mutation not executed by r2 — cp denied in its map; fixer's executed proof on record). Q4 fix-pick inventory: tests 33+/0−, src 13+/3− (comment lines + header lead-in). Q5 scope: 15 files, all in design set + ReadPathCensusTest row; forbidden files absent from name-diff.
- r2 gates for reconciliation: Hooks namespace OK 1099T/3724A; new pin 1T/7A; HttpHookTest 24T/171A; guard battery 287T/50963A 4F = 2× DuplicatedTestHelperDrift + 2× TreeWideGuardRoster naming Cli/LoginSubcommandTest.php (BOTH cured on master e78d5dc14 — expect GREEN post-merge, red = real finding STOP).
- §7 durations rows (r2 LIVE counts): tests/Hooks/HttpHookTest.php 24T/171A · tests/Hooks/HookEnvelopeTest.php 20T/70A. +1T method goes to closeout suite-figure/README re-pin (welder never edits those files).
- NIT n2 (r1 Q6, narrative): PathGlob derived pair at lane scope is 559×555 = 310,245; brief's "557×560 = 311,360" arithmetically impossible. Merged-tree figure must be RE-DERIVED live by the failing test itself (my accepted GlobDialect 1F shape), never asserted from arithmetic.
- REPORT: creates src/Hooks/{HttpHook,HookEnvelope,HookPayload}.php; modifies ScriptHook/HookConfig/HookManager/ClaudeSettingsHooks/lang/en.php/docs/HOOKS.md + tests incl DocFigureProseDriftTest entry-key arm seven→eleven (in-step).

## Step 2 — cherry-picks (explicit SHA list, NO -x, NEVER a range) — LANDED, ZERO CONFLICTS
| lane sha | landed sha | patch-id | subject |
|---|---|---|---|
| ac7ce47a7 | **c4f0c2f98** | SAME | C-EXT-6.c: `type: http` hooks (13 files) |
| 969ee98b3 | **f248bb592** | SAME | C-EXT-6.c: pin the http hook's every refusal… (3 files) |
| f895fc2ed | **54036ef6d** | SAME | C-EXT-6.c: review fixes (round 1) (2 files) |
Commits carried NO `(cherry picked from` trailer (grep count 0 on P1 body; P2/P3 picked without -x), author/committer %ae/%ce = detain@interserver.net each, author-dates preserved.

## Step 3 — merged-tree re-derivation proofs (from commands)
- en.php APPEND UNION: `git diff --stat 9a0c569eb..HEAD -- candy-code/lang/en.php` = 17 insertions(+); case-insensitive dup-key scan over ALL `'…' =>` key tokens in the merged file → **0 duplicates** (`sort | uniq -d | wc -l` = 0). Families live together on merged tree (key-prefix counts): host.help. 2 · host.budget. 2 · cli.sub.import. 35 · cmd.recap. 1 · chat.recap. 1 · cli.serve. 74 · cmd.hooks. 30 (C-EXT-6.b weld present) · hooks.http. 12 (this lane; r1's "12 lang rows"). NOTE: there is NO `hooks.envelope.` key family on disk (lead's family-name list corrected by dupscan+counts, per the run's key-family lesson); envelope payload has no lang surface.
- TreeWideGuardRosterTest THREE-row UNION verified in the working tree AND to be re-verified in the landed blob at the end: :587 Agents/Live/AgentLiveRegistryTest.php · :645 Cli/LoginSubcommandTest.php (e78d5dc14 cure) · :801 Tools/BashSandboxTest.php.
- Lane range vs forbidden files: name-diff carries ZERO src/Protocol/*, ZERO candy-code-web/*, ZERO suite-figure.json/durations.tsv/PathGlob.php/plan_comparison.md.

## Step 4 — merged-tree gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, PtyRun expect-miss never in any filter, no full suite)
- `--filter 'SugarCraft\\Code\\Tests\\Hooks\\'` → **OK 1128T/3853A** (2 run-level Deprecations = standing bootstrap noise per r1/r2). Reconciles EXACTLY: r2 1099T/3724A + 29T/129A = the C-EXT-6.b weld's HookLog 23T/98A + HookLogConcurrentAppend 6T/31A landing inside the same namespace since the lane base. Disclosed, not chased.
- New pin `HttpHookTest::testTheRealWireCarriesTheHostPinTheClaimSitesPromise` → **1T/7A OK** (r2-EXACT); `HttpHookTest` → **24T/171A OK** (r2-EXACT); `HookEnvelopeTest` → **20T/70A OK** (r2-EXACT).
- Guard battery (DocFigure|GlobDialect|ReadPathCensus|DuplicatedTestHelperDrift|TreeWideGuardRoster|StderrEmitterCensus|SymbolCitation|SuiteSkipRoster) → **288T/51311A 1F**: the ONLY failure is GlobDialectDifferentialTest::testTheCorpusFigureInTheDocBlockIsTheDerivedOne. r2's 4F (2× DTH + 2× TWGR) collapsed to 0 in both families by the merge — **DuplicatedTestHelperDriftTest 14T/204A GREEN** and **TreeWideGuardRosterTest BARE 17T/3869A GREEN** (exactly what e78d5dc14 predicted; NOT a new finding, NOT cured by me). Battery +1T/+348A vs r2's 287T/50963A = merged-tree walk drift (sibling DocFigure/roster arms + scan corpora), identical-disclosure pattern to the +54/+99/+126 runs.
- GlobDialect LIVE re-derivation (quoted from the failing test's own message, not arithmetic): doc-block quotes **310,245** pairs vs corpora deriving **311,360 (560 patterns × 556 paths)** — the standing foreign §7 battery rider already logged by the C-EXT-6.b and C-WEB-5.a marks. NOT cured, shape unchanged.
- `CliLangCatalogueTest|LangParityTest` → **15T/3645A OK** (en.php union + sorted-run guard green on merged tree).
- `ReadmeSuiteFigureDriftTest` → 4T/26A **2F** = the staleness pair (pinned floor vs live enumeration; this weld's +1T method rides it) — §7 re-pin duty, accepted.
- `php ../tools/check-one-type-per-file.php` → 62 libs / **3,334** psr-4 files / 0 problems (+16-file walk drift vs C-MCP-5.a's 3,318 — merged tree).
- Generators `--check`: gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema ALL "up to date" → **NO `weld: W33 regenerate` leg**; web chain UNOWED — name-diff of the 3-pick range: ZERO src/Protocol/*, ZERO candy-code-web/* paths, no dist rebuild.
- Accepted foreign reds NOT re-run (lane files reach none of their fixtures; shapes carried per r2's combined residual record 330T/101596A 13F/1Risky): ApplySettingsTest keepWarm 1F · 5 KeyHelpTest datasets (+1 host-load Risky, r2-recorded) · HelpTest[CANDY_CODE_SERVICE_TIER] 1F · BinCandyCodeDispatchTest --worktree 1F :1893 · tests/Server/AttachPagingTest 2F (lead base-proven AT 364a52876) · OneSidedHomeSandboxTest.php:435 1F · staleness pair (re-verified above, pair-shape) · GlobDialect 1F (re-derived above). NO new family, NO shape change.

## Step 5 — §4 mark
- Heading flipped BY TEXT (`#### C-EXT-6.c — \`type: http\` hooks (LATE)`, unique, grep count 1 pre-edit) to `#### ✅ c4f0c2f98,f248bb592,54036ef6d …` with the full dated parenthetical (r1→fix→r2 byte counts, Host cure + structural pin, reasoned decline, n1, +1T/2 durations rows, merged counters + disclosed drift, three-row UNION, WARN NEXT WELDER). `git diff --numstat` = exactly 1+/1− line; working-tree `^#### ✅` = 295 (was 294).
- §3 table UNTOUCHED (W33 row stays open; that flip is the lead's at wave close). No push/fetch/pull attempted.

## Step 6 — commits (author law: split-var MAIL, GIT_AUTHOR_*/GIT_COMMITTER_* env + --author, -o explicit paths, %ae/%ce separate greps)
- Commit 1 `weld: C-EXT-6.c`: plan_comparison.md + lanes/C-EXT-6.c/{REPORT,REVIEW-1,REVIEW-2,FIX-1,WELD}.md.
- Commit 2 `C-EXT-6.c: run-state fold`: prompt_kit/findings/comparison-1026/lanes/run-state.json VERBATIM as the lead folded it (no edits by me to its contents).
