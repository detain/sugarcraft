# WELD-1030W2 — C-TUI-3.d (idle Esc Esc message picker) — fresh-successor rolling weld

Full story incl. the lead's mid-run re-anchor. NEVER pushed (lead owns push). Author law: split-var MAIL + GIT_* env + verify %ae/%ce separately — every weld commit detain 2/2, zero Co-Authored trailers.

## Provenance (why W2)
- Prior 1030W dispatch carried a stale 2-commit brief (builder had landed a 3rd REPORT-only commit 2593d9294). OWNER stopped it mid-dispatch; lead forensics: it landed NOTHING (master tip unchanged, no WELD-REPORT, §4 marks 0).
- This successor verified from disk at start: lane = exactly 3 commits on cc/C-TUI-3.d (fa3487bb2/8e1ed751d/2593d9294, porcelain clean, all cat-file-verified), master had moved ad60f7a3f (WELD-1030V triple weld + riders landed beneath).
- Lead re-anchor mid-run confirmed STEP 1 already done BY THIS SESSION (reflog: the three cherry-picks below are mine) — correctly ordered NEVER to re-pick (phantom law); remaining set re-derived from disk: evidence collect, gates (nearly all already run green here), DUR, mark, fold, report, absorption proof, prune. Each proven-landed item skipped; everything else completed.

## Step 1 — picks (EXCLUSIVE-START 6fb38eef4..2593d9294, exactly 3; done pre-re-anchor, disk-confirmed, not re-applied)
- fa3487bb2 -> e6e701c82 (Chat.php auto-merge disjoint over C-SES-8.b settle-arms)
- 8e1ed751d -> 77199e554 (README + en.php auto-merge = UNION of C-TUI-2.e diffReview rows and new messagePicker rows — gate-proven below)
- 2593d9294 -> f11a0b019 (lane report; main-tree untracked REPORT.md was byte-identical to the branch blob (cmp) — removed, pick recreated it tracked)
- patch-id SAME 3/3; App.php / KeyBindingRegistry.php / Tui/Renderer.php / both Commands tests unchanged between lane base and pre-pick tip -> clean applies. Expected collisions never materialized.

## Step 2 — merged-tree gates (plain pipe, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS)
- MessagePickerWiringTest: OK 11T/106A (exact)
- EscapeSoftCancelTest: OK 15T/42A (exact)
- KeyBindingDriftTest|KeyBindingRegistryTest: OK 271T/3340A — THE BASE-RED CURE: RegistryTest was RED at 6fb38eef4 (pins 156/156, live 163, 15 vs 16 groups — C-TUI-2.e un-carried rows) and STILL red at pre-pick ad60f7a3f (blob-proof: pre-pick test file pins 156; KeyBindingRegistry.php diff-empty between); lane carries it to 168/168/17-groups -> GREEN (RegistryTest alone 33T/1382A)
- ReadmeRosterDriftTest: OK 10T/78A (exact)
- DuplicatedTestHelperDriftTest: OK 14T/203A — green shape confirmed after picks; lane's 2 test files minted no byte-identical helper duplication; NO roster rows added (nothing to date)
- protocol/generator trio --check once: gen-protocol-schema / gen-command-docs / gen-tool-docs / gen-settings-doc ALL rc=0 "up to date" (zero schema bytes declared — confirmed; no regen pair needed, no foreign staleness seen at this window)
- KeyHelpTest: foreign standing family UNCHANGED-SHAPE 5F+1R (63T) — re-verified during this weld under concurrent-lane load (CI-1030 + C-TUI-5.c batteries running); my ids absent per lane deviation 5. Listed, not chased.

## Step 3 — evidence + DUR (commit fc608096f)
- Collected main-tree lanes/C-TUI-3.d/REVIEW-1.md (6818B, stat-proven, r1 CLEAN 0C/0M) onto master; REPORT.md already on-branch via pick f11a0b019.
- DUR MINT: candy-code/tests/App/MessagePickerWiringTest.php = 2.402/11 (median-of-3 JUNIT-TESTCASE-SUM via --log-junit: 2.342 / 2.402 / 6.858 — run3 contention spike rejected by median; builder's ~7.5s was WALL, unused per law).
- RE-TIME (re-anchor check ordered it): EscapeSoftCancelTest case count MOVED 9 -> 15 -> row 0.012/9 -> 0.093/15, relocated to sorted position (90127b4ad precedent). TSV now 1,664 rows.

## Step 4 — §4 mark (this commit)
- `#### ✅ f11a0b019 C-TUI-3.d` + full Weld 1030W note incl. base-red cure + §7 +17T (11 new + 6 in EscapeSoftCancelTest). Path-scoped `git commit -o -- plan_comparison.md` (root plan TRACKED; prompt_kit copy absent by design).

## Step 5 — run-state fold + absorption proof + prune
(appears in the close rider — recorded last with real hashes)
