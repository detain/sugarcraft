# WELD.md — C-TUI-6.i (W33 LATE)

Welded by cc-cmp-welder on 2026-10-31. Master before: `6fd79a345` (weld: bookkeeping — run-state fold).
Lane base `288b81f11`; master since took `e78d5dc14` (master guard cure: DuplicatedTestHelperDrift + TreeWideGuardRoster), `db6cadab1` (weld: C-WEB-5.g), `c821a5124` (W31+W28 §3 flips), `6fd79a345` (bookkeeping).

## Step 0 — preflight
- `git rev-parse --show-toplevel` = /home/sites/sugarcraft; `git branch --show-current` = master.
- Tree dirt (lead-owned/junk, NEVER staged): `M .opencode/agents/cc-cmp-lead.md`, `M prompt_kit/findings/comparison-1026/lanes/C-WEB-5.g/WELD.md`, `M prompt_kit/findings/comparison-1026/lanes/run-state.json` (to be committed VERBATIM as lead folded, step 4), untracked junk `"[input removed due to failed tool call]"`, AGENT_LAW_SNIPPET.md, LEAD_LAWS.md, lanes/** evidence dirs + .bak-tNNN copies.
- QUIESCENCE: `git worktree list` = main + cc-cmp-{C-EXT-6.b,C-EXT-6.c,C-MCP-5.a,C-TOOL-4.b,C-TUI-6.b,C-TUI-6.i,C-WEB-5.b}. The lane's 13-file diff set is entirely under candy-code/; NONE of it appears modified in the main tree (dirty paths are .opencode + prompt_kit only). Siblings in flight (C-EXT-6.b/.c/C-WEB-5.b, read-only reviewers) own different files.
- REVIEW-2 re-statted from disk: `prompt_kit/findings/comparison-1026/lanes/C-TUI-6.i/REVIEW-2.md` = 4,715 B; line 3 reads `VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0`. r1 (REVIEW-1.md, 6,657 B) found 2 MAJORs; FIX-1.md 12,414 B.
- PICKS (cat-file-verified this shell, order = git log --reverse master..cc/C-TUI-6.i):
  1. `9d34122df97fd80845e36b24b5b4a718423a4f86` — screen-reader core + screenReader setting
  2. `156f0a5c785e398c62431666187b90dbeda25925` — pin the settings memo + helper shape edges (tests-only +80/−0, LEAD-RULED KEEP)
  3. `1c366fd1bc9b9cb9d4ef385b98df7f579b416c5a` — TreeWideGuardRosterTest licence row (MAJOR-1 cure)
  4. `0471efde5d8f54b41ba68b66e4347785463a2fd6` — README never-project-settable clause (MAJOR-2 cure) = tip
- Conflict-risk pre-audit: lane hunk in tests/TreeWideGuardRosterTest.php sits at ~:577 (Agents/* rows); master's e78d5dc14 hunk sits at ~:623 (Cli/* rows) — disjoint. candy-code/lang/en.php unchanged on master since base ⇒ lane append lands clean.

## Step 1 — cherry-picks (explicit shas, NEVER a range)
- `9d34122df` → **`1e772aa23`** "C-TUI-6.i: screen-reader core + screenReader setting" — 12 files +487/−22 (matches lane builder stat).
- `156f0a5c7` → **`ad91178bd`** "pin the settings memo and the helper shape edges (round 1)" — tests/Tui/ScreenReaderModeTest.php +80/−0 (LEAD-RULED KEEP: r2 verified every leg discriminates; memo had ZERO coverage before).
- `1c366fd1b` → **`59577fafb`** "review fixes (round 1) - GlobDialect-roster licence row" — tests/TreeWideGuardRosterTest.php +8 (MAJOR-1 cure). Auto-merged clean (master's e78d5dc14 hunk is in the Cli/* region, disjoint).
- `0471efde5` → **`0e8de26e0`** "review fixes (round 1) - name screenReader in the README…" — README.md +1/−1 (MAJOR-2 cure). = new master tip.
- Author law, all four: `%ae` count=4 detain, `%ce` count=4 detain (SEPARATE greps), `%(trailers)` empty, zero Co-Authored (grep -c = 0). All 4 landing + 4 source shas `git cat-file --batch-check` = commit, same shell.
- en.php append union: no conflict — master unchanged in en.php since base; `settings.screenReader.label`/`.help` land at :2198/:3978, all master keys preserved.

## Step 2 — regeneration (all four generators --check, cwd candy-code)
- `gen-protocol-schema --check` → "protocol schema: up to date" rc0 · `gen-command-docs --check` → "command docs: up to date" rc0 · `gen-settings-doc --check` → "settings docs: up to date" rc0 · `gen-tool-docs --check` → "tool docs: up to date" rc0.
- ZERO drift ⇒ NO `weld: W33 regenerate` commit owed (drift was PLAUSIBLE — new settings key + doc rows — but the lane committed its own regenerated SETTINGS.md/README regions inside pick 1 and master never touched those regions since base; predicted-drift-does-not-materialize shape reproduced from the last two welds).
- Protocol outputs unmoved ⇒ web chain (`npm run gen:protocol`, `npm run build`) NOT run: lane diff set contains zero candy-code-web files; dist untouched (correct — no dist change belongs in this weld).

## Step 3 — merged-tree gates (cwd candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored exact-class filters; TreeWideGuardRosterTest BARE; PtyRun expect-miss never in these filters)
- Batch A (ScreenReaderMode|AgentLiveRegistry|ApplySettings|SettingsSchema{,DocDrift}|ReadmeSettingsTierClaim|ReadmeRosterDrift|UiSettings|CliLangCatalogue|ChatHostLangCatalogue|TuiLang|LangParity|EnvRosterDrift|TrustKeyDocumentationDrift): **220T/49,766A, 1F = accepted foreign keepWarm only** (`promptCache.keepWarm`). Lane-report figure 206T/49,170A: +14T = fixer's +2 in-lane + merged-tree census wobble on the doc-family guards; assertion-only drift disclosed, not chased.
- `TreeWideGuardRosterTest` (bare): **OK 17T/3,850A** — lane's licence row AND master's e78d5dc14 cure both live; r2's lane-tree foreign LoginSubcommand 2F correctly GONE post-merge. (+3A vs C-WEB-5.g's 3,847A = the licensed-loop arm iterating the new row — same shape r2 measured on-lane.)
- `(DuplicatedTestHelperDriftTest::)`: **OK 14T/204A** — foreign pair stays cured.
- `(DocFigureProseDriftTest::|GlobDialectDifferentialTest::|ReadmeSuiteFigureDriftTest::)`: **109T/4,582A, 2F = staleness PAIR only** (README headline + artifact: pinned 25,537 vs live 29,132 — §7 gate re-pin duty). DocFigure arms GREEN on merged tree ⇒ subcommand (FOURTEEN)/built-in/settings word-number re-derivation satisfied by guard, not by hand; GlobDialect green ⇒ lane's zero-regex claim holds.
- Accepted-red set re-survey (single run, file /tmp/opencode/ctui6i-redsurvey.txt): **312T/101,396A, 10F+1R** — exact shape unchanged: HelpTest[CANDY_CODE_SERVICE_TIER] 1F · BinCandyCodeDispatchTest completions-verb 1F · KeyHelpTest 5F+1R · AttachPagingTest 2F (standing, lead reproduced at 364a52876) · OneSidedHomeSandboxTest :435 1F (+ the batch-A keepWarm above). NO new red family, NO shape change.
- vitest: not required — zero candy-code-web/protocol movement at this weld.

## Step 4 — §4 mark (proved from landed blob)
- Heading line (located by text, was :3584): flipped to `#### ✅ 1e772aa23,ad91178bd,59577fafb,0e8de26e0 C-TUI-6.i — Screen-reader core + \`screenReader\` setting` + dated disclosure comment (r1→fixes→r2 CLEAN with REVIEW-2 4,715 B welder-statted; tests-only keep-decision ruled by the lead, verified by the fresh r2 reviewer; step-local TWGR licence row + upheld refusal to roster ScreenReaderModeTest; README screenReader reason-clause cure; renderer wiring owned by sibling C-TUI-6.j so helpers are caller-less BY DESIGN; merged-tree en.php union + counter re-derivation + the +14T/merged-census drift; §7 riders).
- Proof: `git show master:plan_comparison.md | grep -n '#### .*C-TUI-6.i'` → `3584:#### ✅ 1e772aa23,ad91178bd,59577fafb,0e8de26e0 C-TUI-6.i — Screen-reader core + \`screenReader\` setting`.
- §3 UNTOUCHED: line 169 still `| W33 (LATE) | 8 | …` — no ✅ (W33 not closing: several members unwelded).

## Step 5 — record commit + absorption + prune
- **`9e6538b49` "weld: C-TUI-6.i"** — A==C==detain@interserver.net (separate greps), zero trailers. `git show --name-only`: **7 paths** = plan_comparison.md, lanes/C-TUI-6.i/{REPORT,REVIEW-1,REVIEW-2,FIX-1,WELD}.md, lanes/run-state.json (VERBATIM as the lead folded — incl. note_t612 + anomaly entries; welder made zero content edits to it). (Amended once to carry this finished WELD.md; path set identical.)
- Absorption proof: `git cherry master cc/C-TUI-6.i` → all FOUR rows `-` (9d34122df, 156f0a5c7, 1c366fd1b, 0471efde5), zero `+`, rc 0. No manual conflict resolutions were made anywhere (the single auto-merge was the TWGR disjoint-hunk case) ⇒ content equivalence holds by construction.
- Prune (after proof): `git worktree remove /home/sites/cc-cmp-C-TUI-6.i` + `git branch -D cc/C-TUI-6.i`. No other cc/* lanes or worktrees touched.

## Step 6 — ledger for the lead
- Master chain of this weld: `6fd79a345` → `1e772aa23` → `ad91178bd` → `59577fafb` → `0e8de26e0` → `9e6538b49`. NOT PUSHED (push = lead, wave-close only).
- §7 GATE riders: NEW test file `candy-code/tests/Tui/ScreenReaderModeTest.php` needs a `scripts/parallel-tests-durations.tsv` row (live 11T/54A); suite-figure/README staleness pair re-pin owed (pinned 25,537 vs live 29,132 at this weld).
- Forbidden-path discipline: no §3 flip, no push/pull, no suite-figure.json/durations.tsv edit, no caliber; `.opencode/agents/cc-cmp-lead.md`, `lanes/C-WEB-5.g/WELD.md` dirt, and root junk files left untouched/unstaged.
