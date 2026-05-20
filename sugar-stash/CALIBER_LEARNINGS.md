# SugarStash — Caliber Learnings

Accumulated patterns and gotchas discovered while building and auditing
sugar-stash.

---

## [pattern:inline-commit-collection] Inline commit message collection via key dispatch

When a TUI needs a multi-character string input (e.g. a commit message) without
spawning a sub-process or blocking, use a dedicated boolean flag + accumulator
string in the Model state:

1. **`collectingCommit: bool`** — gates all normal key handling; when `true`
   only Escape/Ctrl+C (cancel) and Enter (confirm) are processed.
2. **`commitMessage: string`** — appended character-by-character via each
   `KeyMsg` rune in update loop: `withCommitMessage($this->commitMessage . $msg->rune)`.
3. **Cancel path** — Escape resets both flags and clears the accumulator.
4. **Confirm path** — Enter calls `GitDriver::commit()` then exits collection
   mode (sets `collectingCommit=false, commitMessage=''`).

This keeps the model entirely immutable — every keystroke returns a fresh App
with the updated accumulator. No external readline or $stream_get_contents
calls needed.

The same pattern applies to any inline text input: patch messages, branch names,
tag names, etc.

---

## [pattern:sugar-stash-i18n] Per-library i18n facade

When adding i18n to sugar-stash (a leaf app lib that shells out to `git`),
follow the canonical pattern established by sugar-wishlist, sugar-calendar,
and sugar-toast:

1. **`lang/en.php`** — flat `array<string, string>` keyed by translation
   key (e.g. `'status.clean'`, `'help.keyhints'`). Return type hint via
   `@return array<string, string>` docblock. `declare(strict_types=1)` at
   top.

2. **`src/Lang.php`** — `final class Lang` wrapping
   `SugarCraft\Core\I18n\T`. Bakes the library namespace (`'stash'`) into
   every key. Mirrors the sugar-wishlist/calendar/toast facade pattern.
   Registers the namespace + lang dir on every call (safe; `T::register`
   is idempotent).

3. **Translation keys** — use dot notation (`status.clean`), not camelCase.
   Avoid generic keys like `'label'` — prefix with context (`ui.error_prefix`).

4. **`LangCoverageTest`** — scans `src/` for `Lang::t()` call patterns
   and verifies every key exists in `lang/en.php`. Prevents silent missing
   translations.

5. **`candy-core` dep** — the `T` class lives in `candy-core`, so every
   i18n lib needs `sugarcraft/candy-core: dev-master` in `require` plus a
   path-repo entry in `repositories`.

   6. **Keys in sugar-stash** — `git.spawn_failed`, `git.error`,
    `cli.not_a_repo`, `ui.error_prefix`, `status.clean`, `branches.empty`,
    `log.empty`, `help.keyhints`, `help.context_general`, `help.quit`,
    `help.refresh`, `help.switch_pane`, `help.move_cursor`, `help.close_help`,
    `help.pane_navigation`, `help.pane_status`, `help.pane_branches`,
    `help.stage_single`, `help.stage_all`, `help.checkout`, `help.commit`,
    `checkout.no_branch`, `commit.prompt`, `commit.empty_message`.
