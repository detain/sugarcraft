# Step 38 — Root docs sweep

**Branch:** `ai/docs-root`
**Depends on:** all prior code steps ✅ (this is the final documentation pass)
**Blocks:** step-39 (depends on root docs being current)

## Goal

Sweep root-level documentation files to reflect the post-refactor reality: 8 new shared foundation packages, all consumer migrations complete, new cross-references between libs.

## Files expected to be modified

- root `README.md` — lib-list table includes the 8 new packages; "Foundation packages" enumeration updated.
- `docs/MATCHUPS.md` — every row that changed status updated; new packages have rows.
- `PROJECT_NAMES.md` — Candy* table has the 8 new entries.
- `CONTRIBUTING.md` — if a "current libraries" enumeration exists, update it.
- `AGENTS.md` — if any reference to the layout/parser code locations is outdated post-extraction, fix it.
- **NOT modified**: `CLAUDE.md` (Caliber-owned on other machines).

## Acceptance criteria

- [ ] root README lists all 8 new libs in the appropriate table sections.
- [ ] MATCHUPS.md has the 8 new rows + updated status for migrated consumers (🟢 → 🚀 where applicable, e.g., candy-testing is 🚀).
- [ ] PROJECT_NAMES.md Candy* table has the 8 new slug → namespace mappings.
- [ ] CONTRIBUTING.md monorepo for-loop example includes the new foundation libs.
- [ ] AGENTS.md "Source-of-truth files" + reconnaissance references current.
- [ ] No CLAUDE.md edits.
- [ ] `git status` clean on master.

## Coder brief

This is a doc-heavy step — primarily the Scribe's work but a Coder is spawned to do the path-checking and tabular updates. Use the Scribe role for the actual prose writing.

1. **Audit** which root docs reference any of: the 8 new package names, the consumer libs that migrated, MATCHUPS status emojis.
2. Make the updates per the acceptance list.
3. Run `php tools/check-path-repos.php` as a final sanity check (no composer changes here, but a good last-mile).
4. **Do NOT** modify CLAUDE.md. If a finding requires CLAUDE.md update, append to `docs/repo_map_updates.md` and skip.

## Tester brief

No tests. Verify markdown lints clean (run a markdown linter or grep for table-syntax errors). Scribe handles content correctness.

## Scribe brief

This step is mostly Scribe-driven; the Coder is a placeholder for any small file edits. The Scribe is the main worker.

- Update each root doc per acceptance.
- Maintain prose tone consistent with existing files (terse, technical).
- For new lib rows in MATCHUPS, pick the right emoji per status (🚀 candy-testing/candy-input/candy-async — no upstream; 🟢 candy-ansi/candy-buffer/candy-layout/candy-mouse/candy-fuzzy — port of upstream).

## Ship brief

- **PR title**: `docs: root sweep — 8 new packages + consumer migrations`
- **PR body**:
  ```
  ## Summary
  - Root README, MATCHUPS.md, PROJECT_NAMES.md, CONTRIBUTING.md, AGENTS.md updated for the post-refactor state.
  - 8 new lib rows added; existing rows' status emojis refreshed.
  - CLAUDE.md untouched (Caliber-owned on other machines).

  ## Test plan
  - [x] Markdown lints clean on all touched docs
  - [x] php tools/check-path-repos.php
  - [x] CLAUDE.md untouched (confirmed via git diff)

  Refs: docs/repo_map_step_38.md
  ```
- Commit subject: `docs: root sweep for shared-foundation refactor`.
