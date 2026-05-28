# Step 11.04 — Write plans/candy-flip-mosaic-split.md

**Source:** `leftover_updates_later.md` §6 strategic decisions
**Branch:** `ai/plan-flip-mosaic-split`
**This step writes a plan file only.**

## Deliverable

Create `plans/candy-flip-mosaic-split.md` — defines the responsibility
boundary between candy-flip (frame decoding) and candy-mosaic
(rendering / protocol output).

The plan file should cover:

- **Today's overlap** — both libs ship image-output paths (candy-flip
  research P10 mentions Kitty/WezTerm output; candy-mosaic already
  owns Kitty/iTerm2/Sixel rendering).
- **Recommended split** — candy-flip emits frame bytes; candy-mosaic
  owns terminal-protocol output and consumes flip's frames.
- **API contract** — `candy-flip::Frame[]` → `candy-mosaic::Animation`
  → `Renderer`.
- **Migration plan** — what moves from flip to mosaic, what stays in
  flip.
- **Dependencies** — step 07.15 (mosaic animation) consumes this
  decision.

## Files

**Create:** `plans/candy-flip-mosaic-split.md` (~200-400 lines).

## Acceptance

- `ls plans/candy-flip-mosaic-split.md` returns the file.
- Boundary is clearly documented so steps 09.01-03 and 07.15 can
  proceed without re-litigating it.

## Notes

- This plan should land **before** step 07.15 (mosaic animation) and
  ideally before step 09.03 (flip adaptive cell size). The supervisor's
  sequence puts it in phase 11 which is later — that's a sequencing
  bug. Recommend moving this step earlier (between phases 06 and 07)
  during plan review.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
