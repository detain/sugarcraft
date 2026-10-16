# Step 10.22 — sugar-navstack Closable + lifecycle + URL derivation + separator escape + semantic HTML

**Source:** `leftover_updates_later.md` sugar-navstack P2-P6
**Branch:** `ai/navstack-closable-url-semantic`

## Deliverable

- `Closable` interface + lifecycle hooks `onEnter` / `onLeave`.
- URL / path derivation methods on `NavStack`.
- Separator escaping in titles.
- `aria-current` / semantic HTML rendering for accessibility.

## Files

**Modify / Create:** `sugar-navstack/src/Closable.php` interface,
`Url.php` derivation, escaping helper, semantic-HTML renderer.

## Tests

- One per feature.

## Acceptance

- `cd sugar-navstack && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
