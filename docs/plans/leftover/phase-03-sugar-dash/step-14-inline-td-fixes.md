# Step 03.14 — sugar-dash inline technical-debt fixes (TD-1 through TD-8)

**Source:** `leftover_updates_later.md` Dash-12 + dash plan TD-1..TD-8
**Branch:** `ai/dash-td-fixes`
**Bundle hint:** standalone; 8 independent fixes; bundle as one PR

## Deliverable

Knock out the 8 documented technical-debt items from
`plans/dash_update_claude.md`:

1. **TD-1** — `readonly` constructor-promoted properties + clone-mutate
   withers fail at runtime. Audit every `final class` with `withFoo()`;
   convert to private non-readonly + public accessors, or use
   `new self(...)`.
2. **TD-2** — Dual-state collections (Console/Log keep filtered +
   unfiltered) — withers must update both. Add
   `private function rebuildFiltered()`.
3. **TD-3** — Chart size defaults `?? 20` literals clip data. Replace
   with `$width ?? max(20, count($data))`.
4. **TD-4** — Treemap cell gap `+$cellWidth + 1` overflows. Track
   cumulative width via running total.
5. **TD-5** — `str_pad` byte-counts ANSI-prefixed lines. Replace with
   `\SugarCraft\Core\Util\Width::string()`-aware padder; extract to
   `src/Output/PadAnsi.php`.
6. **TD-6** — Inline secondary classes break PSR-4. Audit per
   `grep -nE '^(final )?class ' src/**/*.php | awk -F: '$3 != ""'`;
   split each file with >1 class.
7. **TD-7** — OHLC chart `chartHeight × pointCount` loop instead of
   `chartHeight`. Fix outer loop bound; regression test at 50×20.
8. **TD-8** — Y-axis label inversion (top-down grid, bottom-up labels).
   Flip the label loop; snapshot test at 5×10.

## Files

Touches many; one focused commit per TD item is fine within a single
PR. Use git per-file staging.

## Tests

For each TD item, add a regression test that catches the original
bug. `cd sugar-dash && vendor/bin/phpunit` green afterwards.

## Acceptance

- All 8 TD items closed with regression tests.
- `php tools/check-path-repos.php` green.

## Notes

- These fixes target a single PR per CLAUDE.md "bundle 2-4 related
  items". They're all small, all related, all bug-fixes — natural
  bundle.
- If any TD item turns out to be already fixed (the file has been
  touched since the plan was written), note in `updates.md`
  Carry-forward and skip — don't reverse-engineer good code.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
