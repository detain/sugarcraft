---
name: audit-walkthrough
description: Processes AUDIT_*.md files by marking items ✅ inline with one-line summary right where they live (don't move them — readers see history in place). Skips items tagged 'credit upstream author' or 'UPGRADE_GUIDE' (out of scope pre-1.0). Splits multi-lib audits into one PR per lib/phase sequenced by dependency order (candy-core → candy-sprinkles → honey-bounce → leaf libs). Use when user says 'work through audit', 'process AUDIT_*.md', 'fix audit findings', or references plans/AUDIT_TESTS_PROMPT.md. Do NOT bundle multiple libraries into one PR, do NOT use for net-new feature work, do NOT use to retroactively rewrite already-merged audit PRs.
paths:
  - plans/AUDIT_*.md
  - AUDIT_*.md
  - **/AUDIT_*.md
---
# Audit Walkthrough

Drives `AUDIT_*.md` files end-to-end across the SugarCraft monorepo: read items in order, fix or skip each one, mark ✅ inline where it lives, and ship per-lib PRs sequenced by dependency order. Audit prompts live under `plans/` (e.g. `plans/AUDIT_DOCS_PROMPT.md`, `plans/AUDIT_QUALITY_PROMPT.md`).

## Critical

- **Mark items in place** — never move or reorder audit lines. Append `✅ <one-line summary>` to the SAME line/bullet where the item was originally written. Readers track history by reading top-to-bottom.
- **Skip out-of-scope items, don't delete them** — items tagged `credit upstream author`, `UPGRADE_GUIDE`, or any "credit/license/attribution to the upstream author" wording are out of scope before 1.0. Mark `⏭️ skipped — out of scope` inline. Do not silently drop them.
- **One PR per lib/phase, never bundled** — if `AUDIT_*.md` touches multiple libs, ship `candy-core` work first, then dependents, one PR each. NEVER pack `candy-core` + `sugar-bits` + `sugar-charts` into a single PR even when convenient.
- **Dependency order is fixed**: `candy-core` → `candy-sprinkles` → `honey-bounce` → `candy-zone` → `sugar-bits` → leaf libs (`sugar-charts`, `sugar-prompt`, `candy-shell`, `candy-shine`, `candy-kit`, `candy-freeze`, `sugar-glow`, `sugar-spark`, `candy-wish`, `sugar-wishlist`, `candy-metrics`, `candy-mold`, `candy-tetris`, `super-candy`, `sugar-crush`). Run `composer install` from each lib dir so path-repo symlinks resolve.
- **Tests must pass before commit** — `composer test` in the affected lib dir. Audit work without a passing suite does not ship.
- **Author every commit** as `Joe Huss <detain@interserver.net>`. Don't skip pre-commit hooks (`caliber` runs there).

## Instructions

1. **Locate the audit file.** Default is under `plans/AUDIT_*.md`. If the user names a different path, use that. Read the entire file with the Read tool — do not Grep for a subset, you'll lose the ordering and the inline status markers.

   Verify: you can quote the first and last items of the audit before moving on.

2. **Group items by lib and dependency tier.** Walk every item and bucket by `<lib-slug>`. Sort the buckets using the dependency order in the Critical section. Build a TodoWrite list with one task per `(lib, phase)` bucket so the user sees the planned PR sequence.

   Uses Step 1's read of the audit. Verify: every audit item is assigned to exactly one bucket, or marked skip-eligible.

3. **Pre-filter skip-eligible items.** For each item whose text contains `credit upstream author`, `attribute upstream`, `UPGRADE_GUIDE`, or any "out of scope before 1.0" flagged credit/license rewording, append inline:

   ```
   ⏭️ skipped — out of scope
   ```

   Use the Edit tool. Do this in a single edit pass per file so unrelated diffs don't pile up.

   Verify: `git diff plans/AUDIT_*.md` shows only `⏭️` additions, no line reordering, no deletions.

4. **Process the first lib bucket only.** Pick the highest-priority lib from Step 2. Do NOT touch other libs yet — wait until this lib ships.

   For each remaining audit item in the bucket: implement the fix in `<lib>/src/` and `<lib>/tests/` following the immutable+fluent + `declare(strict_types=1);` + PSR-12 + `final class` conventions from `AGENTS.md`.

   As each item is completed, edit the audit line in place to append `✅ <verb> <what> (<file location>)`. Examples:

   ```
   ✅ added KeyMsg coercion clamp (sugar-bits/src/Model.php:142)
   ✅ snapshot test for empty view (test class ::test_empty_view)
   ```

   Verify after each item: re-read the audit line and confirm `✅` is on the original line, not a new line above/below.

5. **Run the lib's test suite.** From the lib dir:

   ```sh
   cd <lib> && composer install --quiet && composer test
   ```

   If transitive `@dev` deps fail to resolve, the lib's `composer.json` `repositories` array is missing a path-repo — copy the full block from a working leaf lib's composer manifest and re-run. Do NOT pass `--no-dev` or skip tests.

   Verify: PHPUnit reports `OK` with non-zero test count. Capture the count + suite name for the PR body.

6. **Check the Caliber pre-commit hook.** Before staging:

   ```sh
   grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
   ```

   - If `hook-active`: commit normally, Caliber syncs automatically.
   - If `no-hook`: run

     ```sh
     caliber refresh && git add CLAUDE.md .claude/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null
     ```

     first.

7. **Commit and ship one PR for this lib.** Branch name `ai/<slug>-audit-<short>` (e.g. `ai/sugar-bits-audit-coercion`). Title format `<lib>: <summary> (audit #<N or range>)`. Body MUST end with a `## Test plan` section citing the PHPUnit count + suite name from Step 5.

   ```sh
   git checkout -b ai/<slug>-audit-<short>
   git add <lib>/ plans/AUDIT_*.md
   git commit -m "<lib>: <summary> (audit #N)"
   git push -u origin ai/<slug>-audit-<short>
   unset GITHUB_TOKEN && gh pr create --title "<lib>: ... (audit #N)" --body "$(cat <<'EOF'
   ## Summary
   - <bullet>
   ## Test plan
   - `cd <lib> && composer test` → <count> tests, <suite name>
   EOF
   )"
   gh pr merge <n> --merge --delete-branch
   git checkout master && git pull --ff-only
   ```

   Verify: `git log --oneline -1` shows the merge on master locally. PR is closed/merged on GitHub.

8. **Move to the next lib bucket.** Return to Step 4 with the next lib in dependency order. Do NOT batch — each lib gets its own commit/push/PR/merge cycle.

   Verify on completion of all buckets: every audit item has either `✅` or `⏭️` inline, no item is missing a status marker.

9. **Final audit-file sanity pass.** Read the audit file one last time. Confirm:

   - No item is unmarked.
   - No item was moved out of its original position.
   - Bundle 2–4 related audit items per PR was respected (a one-line cosmetic fix on its own is too churny; combine with sibling fixes from the same lib).

## Examples

### Example 1: Multi-lib audit, work `candy-core` first

User: "Work through `plans/AUDIT_QUALITY_PROMPT.md`"

Actions taken:

1. Read `plans/AUDIT_QUALITY_PROMPT.md` — 18 items across `candy-core` (6), `sugar-bits` (5), `sugar-charts` (4), `sugar-prompt` (3).
2. TodoWrite: 4 buckets in order `candy-core` → `sugar-bits` → `sugar-charts` → `sugar-prompt`.
3. Pre-filtered 2 `credit upstream author` items → marked `⏭️ skipped — out of scope` inline.
4. Started `candy-core` bucket. For each remaining item, fixed in `candy-core/src/` + test in `candy-core/tests/`, appended `✅ <summary>` inline.
5. `cd candy-core && composer install && composer test` → 142 tests OK.
6. Branch `ai/candy-core-audit-quality`, commit, push, `gh pr create`, `gh pr merge --merge --delete-branch`, pull master.
7. Move to `sugar-bits` bucket. Repeat.

Result: 4 PRs land in dependency order. `plans/AUDIT_QUALITY_PROMPT.md` has every line marked ✅ or ⏭️ in place. No reordering. No bundled-lib PRs.

### Example 2: Inline marker shape

Before (audit line):

```
- [ ] sugar-bits: Model::update() should clamp negative cursor index to 0 (currently no-ops only on >=len)
```

After (correct):

```
- [ ] sugar-bits: Model::update() should clamp negative cursor index to 0 (currently no-ops only on >=len) ✅ added clamp + test (sugar-bits/src/Model.php:88, test class ::test_negative_cursor_clamps_to_zero)
```

After (wrong — DON'T do this):

```
## Completed
- [x] sugar-bits: Model::update() clamp negative cursor — DONE

## Pending
- [ ] ...
```

### Example 3: Skip marker shape

Before:

```
- [ ] candy-core: add credit to upstream author in README
```

After:

```
- [ ] candy-core: add credit to upstream author in README ⏭️ skipped — out of scope
```

## Common Issues

- **"composer install fails: Could not find package sugarcraft/candy-core matching @dev"** — the consuming lib's `composer.json` `repositories` array is missing a path-repo for the transitive dep. Open a working leaf lib's composer manifest, copy its full `repositories` block, paste into the failing lib's manifest, re-run `composer install`. This is expected when audit work introduces a new cross-lib dep.
- **"composer validate complains about every @dev sibling"** — expected before 1.0. Drop the `--strict` flag. `composer validate` (without `--strict`) is the canonical command.
- **"PR title rejected / linter wants `<lib>:` prefix"** — title MUST start with the lib slug + colon (e.g. `sugar-bits: clamp negative cursor (audit #7)`). Rename via `gh pr edit <n> --title "..."`.
- **"gh pr create errors with permission denied"** — `unset GITHUB_TOKEN` first (the env var overrides the gh auth token in this repo's workflow).
- **"Caliber not found at commit time"** — read `.agents/skills/setup-caliber/SKILL.md` and install it, or run `/setup-caliber`. Do NOT bypass with `--no-verify`.
- **"I accidentally moved audit items into a 'Completed' section"** — revert that change to the audit file (`git checkout -- plans/AUDIT_*.md`), then re-apply only the inline `✅` markers on the original lines. Readers rely on positional history.
- **"Sub-agent ran in parallel and the audit file has merge conflicts"** — never run audit sub-agents in parallel (`AGENTS.md` Gotchas). Resolve by keeping the inline markers from both edits in their original positions, drop the bottom-moved duplicates.
- **"CI matrix didn't run my new lib's tests"** — the CI workflows under `.github/workflows/` are hand-maintained, not glob-driven. Add the lib slug to both matrices. This is a common audit-item ✅ candidate itself.
