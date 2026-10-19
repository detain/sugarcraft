# Step 00 — Bootstrap & smoke-check

**Branch:** `ai/plan-bootstrap`
**Depends on:** *(nothing — this is the first step)*
**Blocks:** every other step

## Goal

Verify the refactor's instruction artifacts are in place and the supervisor's spawning machinery works end-to-end before any code changes happen. This is a dry-run: no source files change.

## Files expected to be created

*(none)*

## Files expected to be modified

*(none — this step ends with `git status` clean and an empty PR or NO-OP)*

## Acceptance criteria

- [ ] All 45 artifact files exist at `docs/repo_map_*` paths (1 prompt, 1 supervisor, 1 updates, 8 roles, 34 steps).
- [ ] `docs/repo_map_supervisor.md` contains 34 unchecked `- [ ]` entries.
- [ ] `docs/repo_map_updates.md` has empty Active + Resolved sections.
- [ ] Each role file is readable and non-empty.
- [ ] Each step file follows the template (Goal / Acceptance criteria / Coder brief / Tester brief / Scribe brief / Ship brief sections present).
- [ ] `git status` clean on `master` at end.

## Coder brief

This step doesn't write code. The Coder agent's job is to **verify file presence and structure**:

```bash
cd /home/sites/sugarcraft

# Count expected artifact files
ls docs/repo_map_plan_prompt.md docs/repo_map_supervisor.md docs/repo_map_updates.md 2>/dev/null | wc -l   # expect 3
ls docs/repo_map_role_*.md 2>/dev/null | wc -l                                                              # expect 8
ls docs/repo_map_step_*.md 2>/dev/null | wc -l                                                              # expect 34

# Verify supervisor checklist
grep -c '^- \[ \]' docs/repo_map_supervisor.md   # expect 34

# Verify updates scratchpad shape
grep -q '^## Active Items' docs/repo_map_updates.md && grep -q '^## Resolved Items' docs/repo_map_updates.md && echo OK

# Verify every step file has the canonical sections
for f in docs/repo_map_step_*.md; do
  for h in '## Goal' '## Acceptance criteria' '## Coder brief' '## Tester brief' '## Scribe brief' '## Ship brief'; do
    grep -q "^$h" "$f" || echo "MISSING $h in $f"
  done
done
```

If anything is missing, append a `BLOCKING: bootstrap missing <description>` line to `docs/repo_map_updates.md` and return the same string. Otherwise hand off saying "Bootstrap OK — 45 artifact files present, supervisor has 34 unchecked steps".

## Tester brief

No tests to write — there's no code in this step. Run `find docs -name 'repo_map_*' -type f | wc -l` and confirm it returns 46 (the 45 artifacts + the source `docs/repo_map_update.md`). Coverage step is a no-op.

## Scribe brief

No docs to write. Confirm `docs/repo_map_update.md` (the analysis doc) is unmodified.

## Ship brief

This is almost certainly a **NO-OP** — no source files changed, nothing to commit. Run `git status` and if it shows no changes:

```
NO-OP — nothing to ship for step-00. Bootstrap verified.
```

Skip branch creation, commit, push, PR entirely. Confirm `git rev-parse --abbrev-ref HEAD` returns `master`. The supervisor will mark this step ✅ on receipt of the NO-OP return.

If `git status` is unexpectedly dirty (e.g., earlier files got auto-modified), halt with `BLOCKING: bootstrap found dirty tree at start: <files>`.
