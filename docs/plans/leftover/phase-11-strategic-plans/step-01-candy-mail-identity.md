# Step 11.01 — Write plans/candy-mail-identity.md

**Source:** `leftover_updates_later.md` §6 strategic decisions
**Branch:** `ai/plan-candy-mail-identity`
**This step writes a plan file only. No code changes to candy-mail itself.**

## Deliverable

Create `plans/candy-mail-identity.md` — a decision document for candy-mail.

Research describes a social-media TUI; shipped lib is an email client.
The decision must be made before any feature work continues.

The plan file should cover:

- **Current state** — what shipped (email client functionality).
- **Option A — Stay email client.** What's needed to be feature-
  complete in that role; what features from the research doc get
  permanently shelved.
- **Option B — Pivot to social-media TUI** (charmbracelet/post style).
  What's needed; what carries forward; cost.
- **Option C — Both** (one repo, two binaries). Cost analysis.
- **Decision criteria** — questions the user needs to answer to
  decide.
- **Recommendation** — author's recommendation with rationale, but
  marked as awaiting user decision.

## Files

**Create:** `plans/candy-mail-identity.md` (~200-400 lines).

## Acceptance

- `ls plans/candy-mail-identity.md` returns the file.
- File is structured per above and clearly marks the user-decision
  blocker.

## Notes

- After landing, the user reviews and picks A/B/C. Subsequent
  candy-mail feature work then flows from the chosen path.
- Do **not** touch `candy-mail/src/` in this step.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
