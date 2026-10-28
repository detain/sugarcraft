# C-TUI-7.b — WELD (2026-10-28, master base 4b771634d)

**Pick:** `29d37b8ed` → `ea1f22bf7` on master (base `581ac6ede`, ancestor of
master). Single-commit lane; clean apply, **zero conflicts**. Master since the
lane base (`581ac6ede..4b771634d` = C-PRV-5.c/C-TUI-1.a/C-SEC-5.b/C-WEB-7.a/
C-EXT-2.c era) touches none of the 7 lane files — the review's weld-safety
check (`Bootstrap.php`/`ENVIRONMENT.md`/`EnvRosterDriftTest` log-empty) carried.
Patch-id IDENTICAL both sides (`1d433ebac3a2f79468da6376510923e5366be5f1`).
Author/committer `detain@interserver.net` (`%ae`/`%ce` separate greps, 1 each
at lane commit and pick), zero trailers.

**Dispatch-brief erratum (disk-anchored):** the lead's weld brief cited commit
`f5a4b09c5` — that object does NOT exist (`git cat-file -t` fails; phantom-law
record, ghost-hash class #30). Disk truth: branch `cc/C-TUI-7.b` carries
exactly ONE commit `29d37b8ed`, which is the commit REVIEW.md (r1 CLEAN) was
written against and audited line-by-line. Welded that. Likewise the brief's
"no new phpunit test file → NO durations row" is contradicted by disk: the
commit ADDs `candy-code/tests/Support/MacAltNoticeStampTest.php` (A, 5T) —
the r1 review explicitly names "the durations row" as a gate duty. Per the
AGENTS.md zero-shards law and the C-EXT-2.c precedent (welder mints the row in
a preceding `scripts:` commit), this weld minted it: row `0.063 5` in commit
`789cb7a4a` (junit per-testcase sum at merged tip ea1f22bf7, single-file run
OK 5T/18A; tie with SettingsEditorTest 0.063 kept in-file order; set-equality
1476+1=1477; 4 pre-existing desc violations unchanged, zero added).

**Regeneration (weld duty per plan §2):** no-op VERIFIED —
`gen-command-docs`/`gen-tool-docs`/`gen-settings-doc` `--write` all report
"up to date", zero diff (ENVIRONMENT.md edit is hand prose; EnvRosterDrift
guard green on merged tree). No `src/Protocol/` and no `candy-code-web/`
files in the pick → schema + npm legs skipped by scope. No regen commit.

**Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS,
PtyRunToolTest never invoked):**
- `--filter 'MacAltNotice'` → `Tests: 12, Assertions: 56` OK — reviewer-exact
  (12th row = PathJail root-resolution provider auto-derived for the new src
  file, per r1 NIT-2; the builder's stale 11T/50A claim was already recorded
  as report-accuracy-only).
- `--filter 'EnvRosterDrift|ReadPathCensus|TuiLang|LangParity|DocFigureProseDrift|ReadmeRosterDrift|TrustKeyDocumentationDrift'`
  → `Tests: 185, Assertions: 47212` OK, 0F — union of the r1 sets (EnvRosterDrift
  37T/37715A, ReadPathCensus 19T/620A, doc-guard set 157T, plus TuiLang|LangParity
  per the weld brief); merged-tree counts ride the lane-tip numbers because the
  filters are disjoint-class sums with the +1 provider row inside MacAltNotice.
- 2 PHPUnit deprecations throughout = tree-wide ambient base shape.

**Carries (closeout sweep — per lead instruction, no weld-time fix):**
- NIT1: `candy-code/src/Support/MacAltNotice.php:13` docblock cites
  "plan_comparison 6.15" — the live plan §6 stops at 6.3; dangling cite copied
  from the pre-existing `MacAltNoticeTest.php` header convention, so BOTH are
  dangling. Fix at the closeout docs sweep, not this weld.
- NIT2: builder REPORT gate arithmetic (11T/50A) superseded by r1 + this weld
  measurement (12T/56A) — record-accuracy note only, no code change.

**Absorption proof:** `git cherry master cc/C-TUI-7.b` → `-` (patch-id proof
above; no conflict resolution invented content, so '-' is the whole proof).
Worktree `../cc-cmp-C-TUI-7.b` removed, branch `cc/C-TUI-7.b` deleted.

**Chain:** pick `ea1f22bf7` → scripts `789cb7a4a` → this weld commit (§4 mark
✅ ea1f22bf7 + evidence + run-state welded 113).
