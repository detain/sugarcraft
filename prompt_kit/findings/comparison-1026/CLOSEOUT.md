# plan_comparison.md — campaign closeout (§7)

Run: `/home/sites/sugarcraft`, master base at §7 entry `86695f57d` (= origin at
dispatch; all 328 genuine steps welded, waves 1..42 closed, marks 329 incl the
ad-hoc CI-1030 record mark). This file is the §7(6) ledger; the §7 gate itself
ran as the LOOP prescribed by plan §1.6 — steps 1–4, fix every red/drift,
re-run until a full pass is green, then steps 5–7. Evidence: `GATE/REPORT.md`
(incremental per round), `GATE/battery-1/`, `GATE/isolate1.log`,
`GATE/iso3.log`, `GATE/TRIAGE.md`, `GATE/serial1.{log,xml}`,
`GATE/serial2.{log,xml}`, `GATE/dregen/`, `GATE/battery-2/`,
`GATE/vitest-2.log`, `GATE/step-ledger.tsv`.

## 1. Step → commit ledger

`GATE/step-ledger.tsv` — 329 rows, `wave <TAB> step <TAB> master-commit <TAB>
first-sha-in-mark`, derived live from plan §4 `#### ✅` marks. Every row
resolves to a master commit; the ten marks whose leading hex token is a lane
tip (not a master ancestor) were mapped to their weld commits (C-TUI-5.d,
C-CTX-4.a, C-EXT-3.b → `041cf425a`; C-WEB-4.b → `c0a462bc9`; C-CTX-2.f →
`b138e5c73`; C-CTX-3.b → `d665c01e5`; C-SES-7.e → `bb8a83ce0`; C-CTX-5.a →
`2123c6582`; C-EXT-4.c → `5f9f6a488`; C-AGT-6.c → `978ff2889`). Law honored:
§4 marks quote lane-tip SHAs by design; master ancestry is the discriminator.

Per-wave step counts: W1–W27 = 10 each; W28 6, W29 3, W30 3, W31 2, W32 1,
W33 8, W34 6, W35 7, W36 5, W37 3, W38 2, W39 4, W40 4, W41 3, W42 2 — 329
total (328 genuine + the ad-hoc mark).

## 2. Per-wave review-loop rounds (from `lanes/W<n>/WAVE-REVIEW-*.md` counts)

W1 1 (FINDINGS 0C/0M/1MINOR/2NIT) · W2 1 CLEAN · W3 3 (r3 FINDINGS) · W4 2
CLEAN · W5 2 CLEAN · W6 1 CLEAN · W7 2 CLEAN · W8 2 CLEAN · W9 0 (no
wave-review file; step-level proofs only — recorded honestly) · W10 2 CLEAN ·
W11 2 CLEAN · W12 1 CLEAN · W13 2 CLEAN · W14 1 FINDINGS · W15 2 CLEAN · W16 1
CLEAN · W17 2 CLEAN · W18 2 (incl WAVE-REVIEW-3 CLEAN) · W19 1 CLEAN · W20 3
(CLEAN at r3, tip 86f80fef8) · W21 2 CLEAN · W22 1 CLEAN · W23 2 CLEAN · W24 1
CLEAN · W25 2 (r2 FINDINGS) · W26 3 CLEAN · W27 2 (r2 FINDINGS) · W28 3 CLEAN ·
W29 2 CLEAN · W30 1 CLEAN · W31 2 (r2 FINDINGS) · W32 1 CLEAN · W33 4 (r4
FINDINGS) · W34 1 (odd-format VERDICT row) · W35 2 CLEAN (r2 C=0 M=0 MINOR=1
NIT=0, §3 flip committed at a4bec3d57) · W36 1 CLEAN · W37 1 CLEAN · W38 1
(FINDINGS C=0 M=0 MINOR=2) · W39 1 (FINDINGS C=0 M=0 MINOR=1) · W40 1 · W41 1
(CLEAN 0C/0M/0m/1NIT-record-only) · W42 2 (r1 CLEAN).

Honesty note: every wave closed (waves_closed = [1..42] contiguous, verified by
the lead before §7 entry). Where the LAST recorded verdict line reads FINDINGS,
its charged items were 0C/0M minors/NITs closed on the lead side by cure
commits absorbed in later welds; no wave was declared clean on a fabrication.

## 3. §7 gate loop record (steps 1–4)

Loop rounds: battery-1 (K=24, unique `--out`) + isolate-1/iso2/iso3 triage
runs → fix rounds 1–10 (roster/lattice cures, one commit each) → hand-bump
pair → full serial-1 (`30171T/751467A/11F/8S/2R`) → serial-1 cure batch (fix
rounds 11–14) → full serial-2 (figures in §5) → battery-2 conservation
(figures in §5). Gate fix commits, `86695f57d..HEAD`, oldest first:
7c19eb681 (vitest idempotencyKey four-spec flip) · ec86bc080 (README voice
keys, TrustKey guard) · c8bdf19a6 (SelfUpdate fourteen-verbs) · 4755ca05e
(MemoryPromptWiring client builders) · e921cf63e (--worktree OPTIONS + 12 lang
files) · 449ea5302 (ChatTest /re slash-menu re-derive) · 4f14ce37b (Palette
clickable/superset rosters) · a922ad16e (ShellContrast diff-vocabulary
delegation) · b179f4690 (LspLauncher sandbox default) · 279bd598c (DoctorChecks
PDO→SqliteConnect routing + census) · 5d7eb2fab (ProcessUniqueTempName six
sites) · e8ae7a3de (ProjectTierRefusal 95/64/35/29 lattice) · af3095b98
(ContainedPath routed 67/34 + hand-spelled 21/15) · f6755b134 (JSON contract
README + NonInteractive index) · 89477c9ea (SwallowingCatch capture-then-assert
pair) · fff428089 (StdinConstantReader + bare-empty-catch rosters) · 0f55a9a93
(FixtureLifetime concat cure) · 160b056d0 (RefusalStderrSurface re-measure) ·
653d5bb4f (worktree-jail + menu-bar-label rosters) · 32b913fea (gate evidence) ·
17b903693 (ScriptBridge frame-cap rename out of the engine family) · 2fef82b65
(EnumSpelling memory-tier reader re-pin) · bfec0ada2 (launch-notice cap census
+ user-tool skip aggregate) · a46bc0b90 (ColdResume production-shape re-seq) ·
02f9216d3 (ForeignAgentPreset reader roster verdicts) · 9c260c6b2 (keepWarm
Restart badge + ApplySettings family) · 9db066db5 (CompactionService carries
thinkingBlocks+recallTitles — REAL src defect exposed) · f19859183 (reaper
adoption + clearstatcache shrink-pin cure) · 276ec9047 (PtyRelay convention:
scanner learns the parent-guard shape) · c5f3f7cd9 (ProviderConnectTimeout
proxy=false env-honesty) · 1efca51cc (PathGlob docblock figure re-derive
312,477=561×557) · 3de78fd2e (RIDER: RESTRICTED_TOOL_NAMES += 'Script') ·
9e4fb5c5a (REAL defect: session.list spelled `search` param restores
attach-by-id under the FTS index — C-SES-2.c drift) · 250143f1e
(LaunchContextDelegates egress-armed-door allowance) · af61e710b
(OneSidedHome six migrations to HomeSandboxTrait) · ccfa28a90 (SettingsEditor
git-category search re-derive) · d446f4b68 (nineteen exec rm-sweeps `2>&1`) ·
ef3ece989 (KeyHelpTest six-leg live re-pin) · 1236307f6 (--help
CANDY_CODE_SERVICE_TIER entry) · d8ed6fc19 (§7(2) hand-bump tests 30,171) ·
80ce217bf/cc205b0b2/b43dccb88 (gate evidence) · 9c7f880db (§7(5) COMPARISON
refresh) · 731fb970b (SessionAffinity resume-guard disarm) · 3ad98c3d6
(PaletteClick quadratic sweep cure) · 1b8cf6e37 (launch-format bound follows
the 37-tool sample: PAGE_QUOTE_FIELD_BYTES 322→394, README coincidence lapsed) ·
66e080e67 (ChatInputCursor /recap) · f90e5823c (DoctorProbes openDsn pin) ·
9ca23114a (DreamPass echo-arm honest oracle: state-file absence) · 68d6fba7e
(CommandFilter /re 8 rows) · 3f06900df (ReadLedger five carriers) · 83fd97f9f
(InterpolationOpener KNOWN_GAPS two selection-side walkers) · 1cd7a283c
(RendererTest slash marker ▸/recap) · 80bab5722 (ShellMenu PLACEMENT for five
late rows).

serial-2 round: bec7a505a (§7(2) re-pin pair 30,171/751,878, README twin same
commit) · b518164d7 (§7(2) durations wholesale regen, 1,828 rows) · f417c6436
(WebPushSenderTest EC-pad flake cure) · a52ff5a1b (shrink-pin recorder-race
cure) — battery-2c CONSERVATION PASS closes the loop.

## 4. Rider dispositions (OPEN LEAD riders at §7 entry)

- RESTRICTED_TOOL_NAMES += 'Script' — **LANDED** at 3de78fd2e per the
  C-TOOL-6.d r1 review recommendation (the bridge runs operator-unread PHP;
  `--restricted` exists to drop exactly that class). Mirror guard flipped in
  the same commit (`testTheRestrictedRosterIsTheShellPairPlusTheFiveCodeShapers`);
  README + docs/PERMISSIONS.md drop-lists name Script.
- web.yml gen:strings CI step — **LEDGER-RESIDUAL**: no `web.yml` workflow
  exists in `.github/workflows/`; the gen-web-strings `--check` gates
  (php + mjs) pass at this closeout and ran at every weld; minting a whole CI
  job was out of §7 scope. Recorded, not fabricated.

## 5. §7(2) re-pin + conservation (live figures)

- serial-2 (GATE/serial2.log): **Tests 30,171 / Assertions 751,878 / 0
  failures / 0 errors / Skipped 8 / Risky 0**, wall 44:46, plain-pipe
  `env -u LINES -u COLUMNS`, nohup — the loop's green-serial authority.
- suite-figure.json / README headline pair after
  `refresh-suite-figure.php GATE/serial2.xml`: **30,171 / 751,878** (skipped 8
  carried by the refresh; README twin same commit bec7a505a).
- durations.tsv regenerated wholesale from the green serial-2 junit
  (`scripts/parallel-tests.sh --junit GATE/serial2.xml --out GATE/dregen`):
  fixes the two deleted-file rows (SkillWorkshopTest, SkillsHistoryUndoTest),
  the ~153 missing new-file rows, and the BinCandyCodeDispatchTest 131→138
  stale row.
- battery-2c (K=24 `--against-json`, unique --out, `env -u LINES -u COLUMNS`,
  durations regen in place): all 24 done markers; shard sum
  30,171 tests / 751,910 assertions (+32 tolerated drift) / 0E / 0F / 8S vs
  the figure 30,171/751,878/0/0/8 — **CONSERVATION PASS**. The two earlier
  runs each exposed one real fixture flake, cured in their own commits:
  f417c6436 (WebPushSenderTest unpadded EC coordinates) and a52ff5a1b
  (BackgroundShellRegistryTest shrink pin racing the recorder's open-truncate).

## 6. §7(3)/(4) regen + drift checks

gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema
`--check` all rc0; `npm run gen:protocol` byte-clean; `npm run build` leaves
`candy-code-web/dist` porcelain-clean (dist is tracked);
`check-path-repos --no-lib-path-repos`, `check-child-lifetimes` (61 libs / 33
proc_open sites / 9 findings, all rostered), `check-one-type-per-file` (0
problems) rc0. vitest-2 `--no-file-parallelism`: 921/921 pass (104 files); the
changed web flows (shells input, processes section) are covered by their own
green specs (e2e spot verdict). Drift sweep (ReadmeRoster/EnvRoster/TrustKey/
ConfigWriteProducer/KeyBinding/ToolDoc/ProtocolSchema/LangParity/DocFigureProse/
GlobDialect) final figures: see §5 row (ran green at closeout).

## 7. §7(5) COMPARISON.md

Refreshed at 9c7f880db: §3 cells and Appendix A twins re-scored with equal
glyphs (CMD-01 built-ins 64→75 = 67 slash + 8 palette-only + 6 aliases; web
cell 67-listed/55-server-run/12-ui_only; MOD-15 ❌→🟡, GIT-10 ❌→🟡; palette
census 50 rows/25 survivors re-derived live). Closed §6 gaps deleted
unannotated (§6.4 Agents line, §6.5 git/review row, Automation/Shell/Editing/
Sessions/Observability/Distribution/Permissions clauses, §6.7 items 2–3).
Scoreboard re-derived via apply_comparison; `--check` returns 356 rows,
0 changed.

## 8. Residuals (honest list)

- P-MENU.e review proof: its step-level r2 CLEAN verdict was DEBUNKED in the
  lane's own REPORT (it reviewed commits that never existed); the fixer
  re-established the real chain (`8eab506d9` weld-P-MENU.d → `c905705e7`, tree
  `ae1f7c2a2`, author/committer detain, zero trailers). W7/WAVE-REVIEW-2 is
  CLEAN over the adjacent era but names P-MENU.e only as a live lane; no CLEAN
  review directly certifies `c905705e7`. Recorded as the run's one review-proof
  gap; the §7 battery/serial green over the full tree is the compensating check.
- C-SES-9.e: PHANTOM-ID lane — its own REPORT adjudicates it 'not a step of
  plan_comparison.md, stopped before any work'; not a missing proof.
- W41 r1 race: concurrent independent r1 overwrote the 10,556 B CLEAN file at
  the same path (both concur 0C/0M); errata commits a3615a76f + 477cfd0f4; the
  lost CLEAN+REVIEW-2 fragment files are this closeout's record instead.
- Two PRE-EXISTING git stashes left untouched (stash@{0} cc/C-CTX-5.c
  mis-pop recovery; stash@{1} local-wip-before-merge) — program-era residue,
  not §7's to drop.
- Main-tree lanes-dirt (M lanes/*/WELD.md set, M run-state.json,
  M C-TUI-6.h/REVIEW-1.md, M .opencode/agents/cc-cmp-lead.md, ?? LEAD_LAWS.md,
  ?? AGENT_LAW_SNIPPET.md) deliberately never staged, per dispatch law.
- Env laws discovered at the gate (recorded for future runs): this box exports
  proxy env vars, so Guzzle origin-dial pins need explicit `'proxy' => false`
  (c5f3f7cd9); long phpunit jobs must run under nohup with a file redirect
  (SIGHUP reaps shell-session backgrounders at ~10 min); `ReadmeSuiteFigureDrift`
  compares README↔json pair + tests-vs-live only (no live-assertion leg).
- Attach cure note: `session.list` gained a spelled `search` parameter
  (9e4fb5c5a) because C-SES-2.c (pre-base) had repurposed `query` into a
  body-only FTS search while attach-resolution kept dialing it — attach-by-id
  was silently broken whenever the FTS index was healthy. COMPARISON session
  parity wording reflects the search-vs-query split.

## 9. Push

Origin at dispatch: `86695f57d`. Push performed after the full green pass per
law: `git ls-remote origin master` → `git fetch` → `git pull --ff-only` →
`git push`, both ends verified equal same-shell. Final: see git log.
