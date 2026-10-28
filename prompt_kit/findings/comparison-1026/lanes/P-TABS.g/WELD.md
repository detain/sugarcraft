# P-TABS.g — WELD (2026-10-29)

- **Pick:** lane `d8732079268397e9120cf06e91262bca6cf9a49c` (cc/P-TABS.g @ base 6638d6415, r2-amended, single commit, 15 files +1112/−43) → master `d489f425f407c2768d9679a70dde6b271edee6f9` on base b5e157d81. Auto-merge clean on README.md + lang/en.php; zero conflicts, zero hand edits.
- **Author law:** `%ae`/`%ce` = detain@interserver.net (separate greps), zero trailers.
- **No regen riders:** diff contains no `src/Protocol/**` and no web files — suite-figure.json/dist untouched (plan §2 weld step 2 N/A).

## Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

1. `--filter 'WindowStrip|PaneTest|PaneReverseCycle|TuiLang|MouseModal|WindowTitle|TabAttention'` →
   **OK (228 tests, 4887 assertions, 2 PHPUnit deprecations [pre-existing family])** — REVIEW-1's 227T + the amended standdown pin (1T/+31A).
2. `tests/App tests/Commands` → **1374 tests** (count exact), 29682 assertions, **1 failure: `MenuBarLabelDerivationTest::testEverySlashOnlyRowReadsAsThisTable` — FOREIGN-INHERITED**: the `'pr' => 'Push the current'` row from C-AGT-6.d is missing from the pinned table. Attribution proof: `git diff b5e157d81 d489f425f` is EMPTY for every input this test reads (MenuBarLabelDerivationTest.php, CommandRegistry.php, Specs, BuiltIn) — the red is byte-identical at the pre-pick tip; assertion delta +47 vs the lane claim = foreign welds since the lane's base. Report-only, not touched (standing-red law).
3. Guard family `--filter 'ReadmeRosterDrift|EnvRosterDrift|DocFigureProseDrift|GlobDialectDifferential|StderrEmitterCensus|TreeWideGuardRoster|LangParity|RegistryTranslation|KeysReload'` → **OK (288 tests, 71688 assertions)** — count exact vs claim; assertions +322 are foreign-era drift.

## Durations (separate `scripts:` commit `50164befb`)

Per-file junit sums (`--log-junit /tmp/opencode/cc-cmp-junit/ptabsG.xml`, files run once): WindowStripShellTest **8T**/0.744s (fixer note 8 supersedes REVIEW-1 MINOR-2's 7 — junit confirms), TabAttentionTest **6T**/0.013s, WindowTitleTest **4T**/0.019s. tsv 1523→1526 live recount; desc-invariant placement (the file's 4 pre-existing tail inversions are untouched — zero new inversions introduced).

## Absorption + cleanup

- `git cherry master cc/P-TABS.g` → `-` (patch-id absorbed). No conflict resolutions, so no content comparison beyond the proof; file list + stat re-verified (`git show --stat` 15 files).
- Pruned: `git worktree remove ../cc-cmp-P-TABS.g`, `git branch -D cc/P-TABS.g`.
- §3 W16 row NOT marked here (lane is 1/10 of W16; next lead closes the wave). §4: full-hash ✅ on the pick.
