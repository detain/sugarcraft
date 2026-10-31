# WELD.md — C-WEB-5.g (W33 LATE, first LATE weld)

Welded by cc-cmp-welder on 2026-10-31. Master before: `e78d5dc14` (guards: cure the two pre-existing master reds — DuplicatedTestHelperDrift + TreeWideGuardRoster; owner-released precondition, lead re-ran both families green).

## Step 0 — preflight
- `git branch --show-current` = master; top-level `/home/sites/sugarcraft`.
- Tree dirt (not staged, lead-owned / junk): `.opencode/agents/cc-cmp-lead.md` M, `prompt_kit/findings/comparison-1026/lanes/run-state.json` M, untracked `lanes/**` dirs + run-state .bak-tNNN copies, junk `"[input removed due to failed tool call]"`, AGENT_LAW_SNIPPET.md, LEAD_LAWS.md.
- Lane picks cat-file-verified: `323c05519` (4 candy-code-web/src-web files), `2c86755a6` (aboutModal.spec.ts only). Both A==C==detain@interserver.net (separate greps, count=1 each), zero Co-Authored trailers.
- Lane branches off `288b81f11`; master since moved to `e78d5dc14` (MASTER cure touched tests/Chat/AwayRecapTest.php, tests/Support/DuplicatedTestHelperDriftTest.php, tests/TreeWideGuardRosterTest.php — disjoint from this lane's files).

## Step 1 — cherry-picks (explicit shas in order, NEVER a range)
- `323c05519` → **`5d1a8b35e`** "C-WEB-5.g: Confirmed Stop server control for Admin tokens in About overlay" (4 candy-code-web/src-web files, 213+/16−). A==C==detain (separate greps count=1), zero trailers.
- `2c86755a6` → **`e0e172322`** "C-WEB-5.g: review fixes (round 1)" (aboutModal.spec.ts, 57+/4−). Law identical.
- Zero conflicts (master moved e78d5dc14 with the MASTER cure — disjoint files, confirmed by `git diff e78d5dc14..HEAD --name-only` = 0 non-web files).

## Step 2 — regeneration proof (zero-drift shape confirmed, NO regenerate commit owed)
- `php scripts/gen-protocol-schema.php --check` → "protocol schema: up to date" rc0
- `php tools/gen-command-docs.php --check` → "command docs: up to date" rc0
- `php tools/gen-settings-doc.php --check` → "settings docs: up to date" rc0
- `php tools/gen-tool-docs.php --check` → "tool docs: up to date" rc0
- `npm run gen:protocol` → wrote `src-web/protocol/generated.ts` with ZERO git diff.

## Step 3 — dist rebuild (weld-only duty)
- `npm run build` rc0 (pre-seeded node_modules; NEVER npm ci/install). Bundle hashes rotate: +index-BQRHrys5.css +index-CtqEFhtc.js, −index-B19khMFL.css −index-BN5PG01v.js, index.html/manifest.json updated. Lands in the record commit.

## Step 4 — merged-tree gates
- vitest `--no-file-parallelism` on the three lane specs: **3 files / 18 passed (18)** — matches post-fix figure (17→18).
- `npx vue-tsc --noEmit`: exit 0, zero errors. `npm run typecheck` (vue-tsc --build): exactly 21 `error TS` lines = r2's foreign baseline; ZERO in the lane's four changed source/test files; the one lane-family hit `dormantRoster.spec.ts(60,14)` verified by `git blame` at HEAD = `770f8ee9bc` 2026-10-28 on a statement the lane never touched (r2 adjudication reproduced).
- PHP, cwd=candy-code `-c phpunit.xml` `env -u LINES -u COLUMNS` plain pipe, PtyRun expect-miss excluded (never swept by these filters):
  - `(ProtocolSchemaDriftTest::|SettingsRemoteAllowlistTest::|Server\Drain::)` loose-`Drain` sibling sweep deconstructed to the reviewer's exact 8 Drain/Protocol/Settings classes summing **45T/1316A, all OK** — reproduces r2's figure.
  - `CliLangCatalogue|LangParity`: 15T/3611A **OK**.
  - Cured guards STILL GREEN at merge: `DuplicatedTestHelperDriftTest::` 14T/204A OK · `TreeWideGuardRosterTest::` 17T/3847A OK.
  - Foreign red set re-surveyed, NOTHING moved: ApplySettingsTest 14T/1F (promptCache.keepWarm badge) · KeyHelpTest 63T/5F/1R · HelpTest[CANDY_CODE_SERVICE_TIER] 1T/1F · BinCandyCodeDispatchTest 137T/1F · AttachPagingTest 2F · ReadmeSuiteFigureDrift 4T/2F staleness pair (§7 GATE re-pin duty; no new *Test.php in this lane — no durations row minted by it).
  - Note: "DuplicatedTestHelperDrift"/"--worktree" labels in the acceptable list were the MASTER-era spellings; the keepWarm/Dispatch reds verified as the same families.

## Step 5 — §4 mark
- Heading at plan_comparison.md:3641 (as of `288b81f11` it was ~:3712 — plan file shifted under merged marks). Flipped to `#### ✅ 5d1a8b35e,e0e172322 C-WEB-5.g — …` + dated disclosure HTML-comment (r1 M1 false-vue-tsc-claim cured typed-properly no casts; M2 vacuity cured per-line pin; NIT -32003; two-pick shape; dist at weld; next-welder re-derivation WARN for en.php ordering + README/ARCHITECTURE/DocFigure counters, +5/+6/+9A drift normal). Proof from landed blob below.
