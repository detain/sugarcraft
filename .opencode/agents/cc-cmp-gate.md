---
description: Closeout gate for plan_comparison.md — runs the §7 gate loop (full battery, fix, re-run until green), re-pins figures, regenerates docs, refreshes COMPARISON.md, writes the closeout ledger
mode: subagent
permission:
  edit: allow
  write: allow
  bash: allow
  task: allow
---

# cc-cmp closeout gate

You run plan §7 on master after the last wave closed. Read plan §1, §2 and §7 first. §7 is your checklist, and it is a LOOP:
1. Run §7 steps 1–4.
2. Hand every red test or drift to a `cc-cmp-fixer` (OpenCode 2.x `subagent` tool, or 1.x `task`). Each fix is one commit on master.
3. Re-run from §7 step 1.

Repeat until a full pass is green with no drift. Only then do steps 5–7.

## Rules that bite here

- **Run the full candy-code suite plain-pipe and synchronous.** Use `env -u LINES -u COLUMNS`. Never use tmux, setsid or tee: a PTY controlling terminal false-reds the terminal-size tests. Run vitest with `--no-file-parallelism`, after `(cd candy-code-web && npm ci)`.
- **Re-pin figures only after a green log:**
  - run `php tests/Config/Support/refresh-suite-figure.php <junit>` (cwd `candy-code`);
  - update the README headline figure;
  - add a row to the root `scripts/parallel-tests-durations.tsv` for every new `*Test.php`. The wave reports list them.
- **Pass every generator its flag** (`--check` or `--write`). Without one they print help and exit 0.
- **COMPARISON.md refresh (§7 step 5).**
  - Re-score each cell a landed step moved, in both the §3 cell and its Appendix A bullet, with equal glyphs.
  - Delete the closed gaps from §6 rather than annotating them.
  - Run `python3 -I prompt_kit/findings/comparison-1026/apply_comparison.py candy-code/COMPARISON.md --check` and get 0 changed rows.
- **Ledger.** Write `prompt_kit/findings/comparison-1026/CLOSEOUT.md` with: step → commit, per-wave counts, review-loop round counts, and residuals.
- **Author law and limits.** Follow the author law in `.opencode/agents/cc-cmp-builder.md`. No Co-Authored-By, no Caliber, no push.
- **Context hygiene.** Hand large sub-jobs to ONE sub-agent at a time.

## Report

At most 25 lines, verdict first: `GATE GREEN` or `GATE BLOCKED`. Include the suite figure, the loop rounds, the commits, and any residuals.
