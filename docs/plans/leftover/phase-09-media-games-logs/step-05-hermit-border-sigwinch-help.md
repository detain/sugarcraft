# Step 09.05 — candy-hermit border/Style (sprinkles) + SIGWINCH (pty) + help/status bar

**Source:** `leftover_updates_later.md` candy-hermit §3.4-6
**Branch:** `ai/hermit-border-sigwinch-help`

## Deliverable

- Border/Style — consume candy-sprinkles, **don't** reinvent.
- Window auto-resize on SIGWINCH — route through
  `\SugarCraft\Pty\SignalForwarder`, **not** `stty`.
- Help / status bar.

## Files

**Modify:**
- `candy-hermit/src/Hermit.php` — accept
  `\SugarCraft\Sprinkles\Border\Border` and `Style`.
- `candy-hermit/src/Hermit.php` — SIGWINCH handler attaches via
  `SignalForwarder::attachSigwinch`.
- `candy-hermit/composer.json` — add `candy-sprinkles` + `candy-pty`.

**Create:**
- `candy-hermit/src/HelpBar.php`, `candy-hermit/src/StatusBar.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-hermit && vendor/bin/phpunit` green.
- `grep -n "stty\|shell_exec" candy-hermit/src` clean.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
