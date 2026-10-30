# C-TUI-2.e — REVIEW round 2 (reviewer lane, 2026-10-30)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 minor step-local / 2 NIT (carried, non-gating).

## Commits (cat-file-verified, all 3 on cc/C-TUI-2.e)
1e40e6df7 / 9643543f8 / ecaeff725. `git rev-list --count 80fe2275b..ecaeff725` = **3** (exclusive-start range exact). Authors: %ae and %ce greps separately = detain@interserver.net on all three; `%(trailers:only,unfold)` empty on all three (no Co-Authored-By). Lane law: `git show --name-only` per commit — zero touches to suite-figure.json, durations.tsv, candy-code-web/dist across all 3.

## MAJOR-1 cure (DiffReviewWiringTest:145) — CONFIRMED in committed blob
`$this->assertSame($app->diffReview, $clicked->diffReview, 'the band is keyboard-only: clicks are inert');` — compares SLOT objects, not App clones. Clicking-inert pinned meaningfully: App.php:3441-3443 click arm returns `[$this, null]` (so $clicked === $app by construction); if clicks ever began driving the band, driveDiffReview mints a fresh DiffReview via the model's mutate → the reference-identity assertSame goes RED. The wheel leg (distinct: clones) is pinned separately by the scroll/clamp assert above it. Meaningful both directions.

## MAJOR-2 cure (UndoRedoDiffCommandsTest) — CONFIRMED in committed blob
Six flips `'/diff'`→`'/diff --print'` (methods at :129/:140/:150/:161/:173/:188 = the five host-print subjects + `/diff 2` older-window leg); class docblock records the split ("the bare form's shell slot (C-TUI-2.e) is pinned in tests/App/DiffReviewWiringTest.php"). Bare-form coverage genuinely lives in DiffReviewWiringTest (door/overlay legs). :151 keeps bare `/diff 0`/`-1`/`last` — usage-noise rows that must ride the fall-through-to-host path; correct, not an omission.

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, linked vendor)
- DiffReviewWiringTest: **9T/87A OK** (2 deprecations = house baseline).
- UndoRedoDiffCommandsTest: **10T/97A OK**.
- KeyBindingDriftTest: **233T/1917A OK**.
- ReadmeRosterDrift|CommandDocsDrift|DocFigureProseDrift combined: **108T/4246A OK** (= r1 per-file 10+6+92, exact).
- DiffReview|DiffGutter|DiffSplit (C-TUI-2.c/.d neighbors + this lane): **70T/1977A OK**.

## Reachability re-check at tip (light — ecaeff725 diff vs 9643543f8 on src/ is ZERO lines)
Chat overlay door present at src/Chat.php:16956 (`Cmd::send(new OpenDiffReviewMsg($review))`); serve door intact: DiffCommand :29-33 filters `--print` and always prints, CommandMethods exec route never enters handleDiffCommand (r1 full trace stands; byte-identical src). Typed+palette routes unchanged.

## Vacuity
- Re-flip to bare `/diff` in UndoRedo → RED: trait fixture carries `EnhancedSessionStore` (:59) + `currentSessionId: 'undo-session'` (:102) → door opens, no transcript rows (r1 mechanism, re-verified on the trait).
- Click arm replacing the slot → RED (slot-identity leg above).
- Both cured assertions discriminate; no fabricated paths.

## NITs (carried from r1, non-gating)
- NIT-1 step-local: durations.tsv DUR MINT for NEW tests/App/DiffReviewWiringTest.php + suite-figure +10T (9T file + 1T UndoRedo flip) are WELD carries per rubric §7.
- NIT-2 out-of-scope: inline `\SugarCraft\Code\...` FQNs where house pattern is `use` imports.

## Weld carries (rubric)
Pick range 80fe2275b..ecaeff725 (3 commits, OLD base) → merged tree: expect clean text merges; re-run the full gate set above post-pick. DUR MINT DiffReviewWiringTest 9T (median-of-3, junit-testcase-sum). §7 carry +10T. Collect REVIEW-1.md + REVIEW-2.md + FIX-1.md (and the lane REPORT if any) onto the branch pre-prune: disk-verified that NONE is committed on ecaeff725 (`git show ecaeff725:prompt_kit/.../lanes/C-TUI-2.e/` → path not in tree; `git log --all` for the path → zero commits) — they exist only as untracked files in the main tree lane dir + worktree copy; the welder must `git add -N`/commit them alongside the pick.
