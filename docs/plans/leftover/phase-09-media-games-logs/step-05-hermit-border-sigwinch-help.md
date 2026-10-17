# Step 09.05 — sugar-quickpick border/Style (sprinkles) + SIGWINCH (pty) + help/status bar

**Source:** `leftover_updates_later.md` sugar-quickpick §3.4-6
**Branch:** `ai/hermit-border-sigwinch-help`

## Deliverable

- Border/Style — consume candy-sprinkles, **don't** reinvent.
- Window auto-resize on SIGWINCH — route through
  `\SugarCraft\Pty\SignalForwarder`, **not** `stty`.
- Help / status bar.

## Files

**Modify:**

- `sugar-quickpick/src/Hermit.php` — accept
  `\SugarCraft\Sprinkles\Border\Border` and `Style`.
- `sugar-quickpick/src/Hermit.php` — SIGWINCH handler attaches via
  `SignalForwarder::attachSigwinch`.
- `sugar-quickpick/composer.json` — add `candy-sprinkles` + `sugar-pty`.

**Create:**

- `sugar-quickpick/src/HelpBar.php`, `sugar-quickpick/src/StatusBar.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-quickpick && vendor/bin/phpunit` green.
- `grep -n "stty\|shell_exec" sugar-quickpick/src` clean.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
