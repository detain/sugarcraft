# C-TOOL-3.d (W14 — /shells command) — REVIEW-2 (round-2 cure review)

Reviewer: cc-cmp step reviewer, round 2. Date 2026-10-28.
Object judged: tip `1de8e9266c09c7ed0c8ed98d9b138594d5f7f200`, branch `cc/C-TOOL-3.d`, worktree `/home/sites/cc-cmp-C-TOOL-3.d`.
Pre-checks (phantom law): cat-file object exists ✓; `git rev-list --count master..HEAD` = 1 ✓ (amended in place, single-commit law held); porcelain 0 lines ✓. Author+committer `detain@interserver.net` on SEPARATE greps = 1 each, `%(trailers)` empty ✓. Diff vs master: 12 files, +576/−4 — identical fileset to the r1-verified one; no forbidden file touched (suite-figure.json, durations.tsv, candy-code-web/dist/, protocol trio absent from the stat).

## Cure 1 — MAJOR (DuplicatedTestHelperDrift) — GENUINE
Cure diff `ab80609d7 → 1de8e9266` (old tip still reachable as a dangling object; direct blob diff, not worktree trust): `ShellsCommandTest.php` helper `registry()` → `shellsRegistry()` at the definition (:233 region) plus exactly 7 call sites (test bodies :88/:100/:124/:161/:177/:196/:216). Blob greps at tip: `grep -c 'private function registry('` = **0**; `grep -c shellsRegistry` = 8 (1 def + 7 uses). Session label `cc-cmp3d-launch` kept (rename, not a fifth byte-identical twin — matches the preferred fix). Guard reproduced by me:
`env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter 'DocFigureProseDriftTest|GlobDialect|DuplicatedTestHelperDrift|ChildWallClock'` (cwd worktree candy-code, plain pipe) → **OK: Tests: 130, Assertions: 16141, PHPUnit Deprecations: 2**, 0F, 29.4s — exact claimed shape; the guard was 1F at the old tip, so the rename is the discriminating change. No PtyRunToolTest in the filter (never run per brief).

## Cure 2 — MINOR (provenance honesty) — GENUINE
`DocFigureProseDriftTest.php` lane diff vs master = exactly the 5-insertion dated-cure hunk (`git diff --stat`: 5 +++++, 0 deletions). The false "C-TOOL-3.c era / Shell-family directory grew" comment is deleted in the cure diff; the new comment credits **C-AGT-5.a (5cd68af7e)**. Independent re-verification: `git -C /home/sites/sugarcraft log -S'thirty-seven' --oneline -- candy-code/docs/` names `5cd68af7e` as the docs re-spell commit, and `git show --stat 5cd68af7e` proves it added `src/Tools/BuiltIn/ScheduleTool.php` (36→37 scandir growth) and flipped AGENTS_AUTHORING.md + ARCHITECTURE.md thirty-six→thirty-seven (diff hunks -thirty-six/+thirty-seven seen directly). C-TOOL-3.c (`1f1db4f97`) touched only the three existing BuiltIn shell-family files — adds no directory entry, exactly as r1 said. No "forty-six history" claim text anywhere in the lane hunk; the :6365 `'forty-six' => 46` line is pre-existing, outside every diff (confirmed by the lane guard diff covering :5422-5427 only). Map entry `$wordNumbers['thirty-seven'] = 37;` byte-identical — content-identity for the W13 collision plan preserved. DocFigure AK-family arm green inside the reproduced 130T gate.

## Cure 3 — NIT (report arithmetic) — GENUINE
`lanes/C-TOOL-3.d/REPORT.md` carries a "## CORRECTIONS (FIX-1, 2026-10-28)" block naming the amended tip, the **19**-row en.php count, and the **10T/51A** live filter shape (file-only vs derived-census-row decomposition explained); `FIX-1.md` carries the same. Blob recount: `git diff master...HEAD -- candy-code/lang/en.php | grep -c '^+.*=>'` = **19** (2 cmd.shells.* + 17 host.shells.*), matching the correction.

## Full-lane re-verification at the amended tip
- `--filter ShellsCommand` → **OK: Tests: 10, Assertions: 51** +2 deprecations (9 lane tests + derived BinCandyCodeWiring census row), 5.5s.
- `--filter 'MenuBarLabelDerivation|PaletteSupersetDispatch'` → **OK: Tests: 17, Assertions: 368** +2 deprecations (no PaletteSuperset wall-clock flake this run).
- Ownership law unchanged: cure diff touches ONLY the two test-side sites (method-name tokens + comment prose). All r1 §1–§3 production findings (ShellsCommand.php, Chat.php arms, Bootstrap workspace/memoized registry, spec file, docs) ride the amended commit byte-shifting only via the test rename — r1's CLEAN verdicts carry.
- Foreign standing reds (report-only, not re-litigated): ReadmeSuiteFigureDrift staleness pair; the thirty-seven word-map on master (still red at master — W13 loop owner; the lane's dated cure is the branch-side arm); TreeWideGuardRoster walker; ChatHostLangCatalogue 3-orphan trio; KeyHelpTest; ProjectTierRefusal 8 dot-paths; DreamPass. r1's 8-failure Chat|SessionHost attribution set stands.

## Weld notes (carried for WELD)
1. durations.tsv needs ONE row: `candy-code/tests/Host/Commands/ShellsCommandTest.php` (10T junit recount at weld).
2. `php tools/gen-command-docs.php --check` at weld (README/COMMANDS rows; in-suite CommandDocsDrift+ReadmeRosterDrift were green at r1).
3. W13 thirty-seven collision: if the W13 wave-loop cure lands the same `$wordNumbers['thirty-seven'] = 37;` + a differently-worded comment, cherry-pick conflict in DocFigureProseDriftTest.php is expected — content-identical entry, **keep either** comment.

## Verdict
All three round-1 findings cured genuinely and reproduced on disk at 1de8e9266: guard 1F→0F with exact figures, provenance independently confirmed against master-line git history, report corrections present and numerically true. No new findings; no scope creep; single-commit/author/hygiene laws hold.

VERDICT: CLEAN
