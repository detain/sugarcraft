# C-EXT-2.c — WELD (2026-10-28, master 132c12c27 base)

**Pick:** `4ef0283d7` → `8ad2de199` on master. Single-commit lane; clean apply,
**zero conflicts** — three files auto-merged with master (`README.md`,
`lang/en.php`, `tests/Config/DocFigureProseDriftTest.php`), all soft-append /
disjoint-hunk regions; the 31 master commits since base `b985fbe51` touch none
of the hot-claimed src regions (`App.php`, `EngineBackend.php`, `TaskTool.php`
applied verbatim). Author/committer `detain@interserver.net` (`%ae`/`%ce`
separate greps, 1 each), zero trailers.

**RouteTraitParity double-cure probe:** the lead flagged a possible overlap with
the `TelemetryRetryTickMsg` roster cure C-PRV-5.c landed on master
(`dd4cddfb2` → `candy-code/tests/Chat/RouteTraitParityTest.php:84`). Verified
NOT materialized: `git diff b985fbe51 4ef0283d7 -- tests/Chat/RouteTraitParityTest.php`
is empty — the lane never carried the hunk (r2 amend dropped it), so there was
no already-applied/empty-hunk case to resolve. Master's cure row stands once;
`--filter RouteTraitParityTest` → `Tests: 4, Assertions: 44` OK on the merged tree.

**Regeneration (weld duty per plan §2):** `gen-command-docs`/`gen-tool-docs`/
`gen-settings-doc` `--write`: all up to date, zero diff. `gen-protocol-schema
--check`: up to date (not a protocol lane). `php tools/gen-docs.php --check`
(repo root): rc 0 — "ok: all 62 pages + 52 redirect stubs + index.html counts +
search index match generated output". No regen commit needed; the SKILLS.md/
README/TROUBLESHOOTING edits are hand-page prose, not generated regions.
No web-side regen (lane is PHP-only; dist ride happens at the C-WEB-7.b weld).

**Durations:** new rows `candy-code/tests/Tools/BuiltIn/SkillToolForkTest.php
0.589 9` + `candy-code/tests/Skills/SkillForkTest.php 0.125 7` (junit
per-testcase sums at merged tip 8ad2de199, single-file filter run 16T; desc
neighbors 0.592/0.585 and the 0.125 tie kept in-file order; set-equality
1474+2=1476 both directions). `suite-figure.json` untouched (closeout re-pin
owner).

**Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS,
PtyRunToolTest never invoked):**
- `--filter 'SkillFork|SkillToolFork|InertFrontmatterDocumentationDrift|DocFigureProseDrift'`
  → `Tests: 115, Assertions: 4118` OK. Reviewer-exact figure was 114T/4099A;
  the +1T/+19A is fully accounted: `BinCandyCodeWiringTest`'s getcwd-walk
  provider scans `src/` per file, so the lane's NEW `src/Skills/SkillFork.php`
  adds one derived dataset row whose name matches the `SkillFork` filter
  (the run's class breakdown: SkillFork|SkillToolFork 17 = 7+9+1, Inert
  7, DocFigure 91 — DocFigure method count identical at base/tip/master).
- `--filter 'BootstrapLaunchNoticeRouting'` → `Tests: 22, Assertions: 148` OK
  (brief figure EXACT; the r2 M-1 in-step guard cure holds on the merged tree).
- `--filter 'RouteTraitParityTest'` → 4T/44A OK (above).
- 2 PHPUnit deprecations throughout = base shape per both reviews.

**Closeout roster (collected here per lead instruction — none weldable):**
- `candy-code/src/Cli/Bootstrap.php:5955` comment still cites `context: fork`
  as an inert-key example — stale example wording only (lane REPORT seam,
  hub file outside lane ownership). Owed by a later micro or closeout.
- `ChatHostLangCatalogueTest` standing red on `chat.effort.launch_unshaped`
  (asker `LaunchContext.php:2109` outside `sources()`) — byte-identical at
  base `b985fbe51`, C-AGT-7.b seam → closeout roster (already recorded in
  run-state `standing_reds_b985fbe51`).
- `docs/TROUBLESHOOTING.md` `allowed-tools` / `effort` half of the :163-166
  list left byte-untouched per ruling (pre-existing at base, C-EXT-2.b seam) —
  owed by closeout.

**Absorption proof:** `git cherry master cc/C-EXT-2.c` → `- 4ef0283d7…`
(clean pick; no conflict resolution invented content, so the '-' is the whole
proof). Worktree `../cc-cmp-C-EXT-2.c` removed, branch `cc/C-EXT-2.c` deleted.
