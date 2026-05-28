# Step 07.22 — candy-vcr FocusLostMsg coverage in BuiltinSerializer

**Source:** `leftover_updates_later.md` candy-vcr H4
**Branch:** `ai/vcr-focuslostmsg`

## Deliverable

`BuiltinSerializer` should cover `FocusLostMsg` and `FocusGainedMsg`
(from step 07.06's candy-vt focus-events work). Today they may not
round-trip correctly.

## Files

**Modify:**
- `candy-vcr/src/BuiltinSerializer.php` — add cases for
  `\SugarCraft\Vt\Msg\FocusInMsg` and `FocusOutMsg`.
- `candy-vcr/composer.json` — confirm `sugarcraft/candy-vt` is in
  `require-dev`.

## Tests

- `candy-vcr/tests/BuiltinSerializerFocusTest.php` — round-trip both
  Msgs.

## Acceptance

- `cd candy-vcr && vendor/bin/phpunit --filter Focus` green.

## Notes

- Depends on step 07.06 having shipped (candy-vt focus events).

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
