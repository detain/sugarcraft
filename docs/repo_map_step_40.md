# Step 40 — CI / workflow updates

**Branch:** `ai/docs-ci`
**Depends on:** step-39 (site docs done; this is the last operational step before retrospective)
**Blocks:** step-41 (retrospective)

## Goal

Finalise CI/workflow files for the post-refactor ecosystem: codecov flags for the 8 new packages, `.github/workflows/vhs.yml` `all=(...)` array entries for any new visual libs (most shared foundation libs are non-visual), and sanity-run of `scripts/affected-libs.php` + `tools/check-path-repos.php`.

## Files expected to be modified

- `codecov.yml` — add 8 new flags for candy-ansi, candy-buffer, candy-layout, candy-testing, candy-mouse, candy-input, candy-fuzzy, candy-async.
- `.github/workflows/vhs.yml` — `all=(...)` typically not extended for these (non-visual foundations); audit each new lib to be sure. Visual libs that may have gained `.vhs/*.tape` files in any step get added.
- `.github/workflows/tests.yml` — confirm SVN creds remain HARDCODED per CALIBER_LEARNINGS (this isn't an edit, it's a verification — don't accidentally secretify them).
- `scripts/affected-libs.php` — verify it auto-discovers the 8 new libs (it should; if not, document why in CALIBER_LEARNINGS).
- `tools/check-path-repos.php` — verify it walks the full transitive closure including the 8 new libs.

## Acceptance criteria

- [ ] `codecov.yml` has 8 new `- name: <slug>\n  paths: <slug>/` flags.
- [ ] `vhs.yml` `all=(...)` array audited; any new visual lib added (most likely none from the shared foundations).
- [ ] `scripts/affected-libs.php` enumerates all 8 new libs when run.
- [ ] `tools/check-path-repos.php` exits 0 across the whole repo.
- [ ] SVN creds in tests.yml still HARDCODED.
- [ ] `git status` clean on master.

## Coder brief

1. **codecov.yml**: add the 8 entries. Match the format of existing entries exactly.
2. **vhs.yml**: read it, list the 8 new libs; for each, check if it has `.vhs/*.tape` files. If yes → add to `all=(...)`. If no → no edit.
3. **Verify scripts**:
   ```bash
   php scripts/affected-libs.php
   # Should list all 8 new libs in its output.
   php tools/check-path-repos.php
   # Should exit 0.
   ```
4. **Confirm tests.yml** unchanged on the SVN cred lines.
5. Run check-path-repos one more time as the final verification.

## Tester brief

- Verification, not new tests. Confirm the 4 acceptance criteria via the commands above.

## Scribe brief

No new docs. The repo_map_update.md status section (step-39) already covers what shipped. CALIBER_LEARNINGS in root: optional one-line note "Adding a new lib? It auto-discovers in scripts/affected-libs.php; codecov.yml + vhs.yml are hand-maintained — update them too."

## Ship brief

- **PR title**: `ci: codecov flags + vhs.yml audit for 8 new shared foundation libs`
- **PR body**:
  ```
  ## Summary
  - codecov.yml: 8 new flags (candy-ansi, candy-buffer, candy-layout, candy-testing, candy-mouse, candy-input, candy-fuzzy, candy-async).
  - vhs.yml: audited; <X> new libs added to all=(...) array (most foundations non-visual).
  - scripts/affected-libs.php auto-discovers the 8 new libs (verified).
  - tools/check-path-repos.php exits 0 (verified).
  - SVN creds in tests.yml confirmed still HARDCODED per CALIBER_LEARNINGS.

  ## Test plan
  - [x] php scripts/affected-libs.php outputs 8 new libs
  - [x] php tools/check-path-repos.php exits 0
  - [x] codecov.yml has 8 new flags
  - [x] git diff .github/workflows/tests.yml is empty (SVN creds untouched)

  Refs: docs/repo_map_step_40.md
  ```
- Commit subject: `ci: codecov + vhs audit for 8 new shared foundation libs`.
