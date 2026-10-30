# C-TUI-2.e — Diff-review wiring (App slot, `/diff`, keys)

STATUS: IN PROGRESS (commit-1 landed 1e40e6df7; commit-2 wiring being written)
WORKTREE: /home/sites/cc-cmp-C-TUI-2.e  BRANCH: cc/C-TUI-2.e  BASE: 80fe2275b

## Goal
plan_comparison.md C-TUI-2 (sources GAP-GIT-09): in the TUI, a bare `/diff [n]`
opens the welded C-TUI-2.d review as an App shell slot; `/diff [n] --print` keeps
today's fenced rows; serve keeps printing; `DiffCommand` tolerates `--print` so the
shared hint stays valid on serve.

## Landed
- 1e40e6df7 `C-TUI-2.e: DiffCommand accepts and ignores --print; hint [n] [--print]; COMMANDS regen`
  (src/Host/Commands/DiffCommand.php, lang/en.php cmd.diff.hint, docs/COMMANDS.md regen).
  Gates at this commit: DiffCommand 11T/103A, CommandDocsDrift 6T/11A, ReadmeRosterDrift 10T/78A (baseline pair held).

## In flight (commit-2)
App slot (ctor+mutate+OpenDiffReviewMsg arm+wheel+key branch+yields+walk-gate+
open/close/drive/apply/dial), Chat::handleDiffCommand overlay branch,
KeyBindingRegistry diffReview() context+rows, en.php keys rows, README rows,
Tui/Renderer renderView arm + renderDiffReview(), tests (drift observations,
DiffReviewWiringTest, UndoRedo print-case flips).

## Decisions/deviations so far
- Model (welded C-TUI-2.d) has NO Enter / ← / → bindings; design text's arrow/Enter
  verbs are superseded by its -/+ and j/k family (brief: read real APIs). Shipped:
  ↑/↓ (j/k) file, PgUp/PgDn body, Home/End ends, -/+ base, t turn, Esc close; wheel
  pages the body. Will disclose in final section.
- Split/highlight path is private in src/Renderer.php (one-lane file, forbidden here);
  overlay paints unified body with the SAME colour idiom copied into Tui/Renderer.
- Chat string-reason fallback → host rows (keeps serve-identical words + existing
  no-checkpoint/usage tests green); App arm still consumes reason → footer StatusMsg.
