# Step 11.05 — Write plans/sugar-input-escape-decoder-split.md

**Source:** review-pipeline queue row `sugar-input:SI-41` (owner ruling 7a —
**SCHEDULE** the god-class split in docs; the implementation itself stays
gated: `SI-41 NO-DEFAULT`)
**Branch:** `ai/plan-sugar-input-ed-split`
**This step writes a plan file only.** No code changes.

## Deliverable

Create `docs/plans/sugar-input-escape-decoder-split.md` — the decomposition
plan for `sugar-input/src/EscapeDecoder.php`, the one god-class the shipped
input path hangs off.

The plan file should cover:

- **Today's shape** — `EscapeDecoder.php` is 2,343 lines in one `final`
  class: the state machine, the OSC/DCS/APC/PM string-drain cluster
  (~250 lines of drain/acccumulate/terminate logic), the SGR-mouse family
  (1000/1006 coordinates, wheel press/release synthesis), DECRPM
  acknowledgement counting, bracketed-paste accounting, and the
  deferred-escape flush timer, all interleaved around `decode()`/
  `decodeClean()`.
- **Recommended split** — two extractions, each behind a dedicated
  collaborator class under `src/Support/` (house naming for internals):
  first the OSC/DCS/APC/PM string-drain cluster, then the SGR-mouse
  family. `EscapeDecoder` keeps the state machine and dispatch and becomes
  a thin orchestrator over the collaborators.
- **The frozen seam** — the public API is byte-pinned by the shipped
  suite and MUST not move: `decode(string): array`, `remainder()`,
  `drainedReplyCount()`, `droppedUnknownCount()`,
  `discardedPasteBytes()`, `flushDeferredEscape()`, `reset()`, and the
  `?EscapeDecoderOptions` constructor. Consumers (sugar-core's program
  loop, candy-code) read only these.
- **Migration plan** — one extraction per commit; each commit keeps the
  suite green with ZERO golden moves (516 tests / 43,127 assertions as
  measured on 2026-11-13 — re-measure at execution time and treat any
  drift as owned by whatever landed in between); counter semantics stay
  identical (a moved counter must answer the same numbers); docblocks keep
  citing `Mirrors charmbracelet/bubbletea` sequence-parser structure.
- **Dependencies** — all prerequisite SI rows (SI-01..SI-40) landed; this
  row's extraction order is independent of the rest of the queue.

## Files

**Create:** `docs/plans/sugar-input-escape-decoder-split.md` (~150-300 lines).

## Acceptance

- `ls docs/plans/sugar-input-escape-decoder-split.md` returns the file.
- The frozen public seam is stated method-by-method so no later step can
  "improve" it while extracting.
- The extraction order (string-drain first, SGR-mouse second) and the
  one-extraction-per-commit rule are explicit.

## Notes

- This is a SCHEDULING row: owner ruling 7a scheduled the split instead of
  funding it (`NO-DEFAULT` gate). Executing the split still needs the
  owner's go on the plan itself — the supervisor should treat "plan
  written" as this step's complete deliverable, not "split approved".

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
