# WELD — C-PRV-5.d (welder, 2026-10-28)

**PICKED.** Master base `b38baf0f8`; lane commit `5da428301` cherry-picked clean (zero
conflicts, single commit) → master pick `e07d05447`. Author `%ae` and `%ce` grepped
separately: both `detain@interserver.net`; zero Co-Authored/Signed-off trailers.
Diff `--stat` identical to the lane: 5 files, +451/−21.

**Regeneration:** NOT run — lane touches no protocol/schema/SERVER.md/dist surface
(files list + SETTINGS.md section verified outside every generated `<!-- settings:* -->`
region at REVIEW-1 §3). gen-protocol-schema untouched.

**Durations:** new test file `candy-code/tests/Providers/GeminiResponseSchemaTest.php`
row added to `scripts/parallel-tests-durations.tsv`: `0.097  6` (junit per-testcase sum
at merged tip, single-file run 6T/41A); canonical sort position verified (duration-desc;
14 pre-existing tiebreak imperfections unchanged, zero new); set-equality both directions
1465+1=1466. `suite-figure.json` untouched (staleness pair is standing base-inherited red).

**Merged-tree gates** (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
- `--filter 'GeminiResponseSchemaTest|GeminiProviderTest'` → OK 29T/147A (2 ambient AccessorDeprecation deprecations).
- provider family `GeminiResponseSchemaTest|GeminiProviderTest|VertexProviderTest` → OK 184T/554A — EXACT vs REVIEW-1.
- doc-guard battery `ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducer|DocFigure|DuplicatedTestHelper|GlobDialect` → OK 193T/42854A (reviewer's elided filter reported 192T/42851A — +1T/+3A attribution to filter reconstruction, near-exact).
- `--filter 'SettingsSchemaDocDrift'` → OK 19T/179A.
- Standing base-inherited red families (StderrEmitterCensus 3F, TreeWideGuardRoster 2F, ReadmeSuiteFigureDrift pair, ProjectTierRefusalInventory 2F) not in these filters; untouched, not ours.

**Nits:** NIT-1/NIT-2 skipped per brief (documented as accepted; NIT-1 noted for the AUT-4 area).

**Marks:** §4 heading `#### ✅ e07d05447 C-PRV-5.d — …` (rides this weld commit, per
717e7fda1/307ffb44f precedent — plan_comparison.md is TRACKED, contradicting the brief's
"untracked" note; precedent followed). run-state: C-PRV-5.d inflight→welded, 102→103,
inflight 9; no stage=done entries remained in inflight.

**Absorption proof:** `git cherry master cc/C-PRV-5.d` → `-` (patch-id identical,
conflict-free fast pick, no conflict-resolution `+` content to re-verify). Worktree
`/home/sites/cc-cmp-C-PRV-5.d` removed; branch `cc/C-PRV-5.d` deleted -D after proof.
Never pushed.
