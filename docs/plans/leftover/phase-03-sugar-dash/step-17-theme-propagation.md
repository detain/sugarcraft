# Step 03.17 — sugar-dash Theme propagation through widgets

**Source:** `leftover_updates_later.md` Dash-14
**Branch:** `ai/dash-theme-propagation`

## Deliverable

No widget currently receives a Theme — colors are hardcoded hex literals
in each component's render. Add `Drawable::withTheme(Theme): self`
(default pass-through). Layout containers fan the theme down to their
children.

Once landed, dashboards can switch dark/light at runtime via a Msg.

## Files

**Modify:**
- `sugar-dash/src/Foundation/Drawable.php` — add
  `withTheme(\SugarCraft\Sprinkles\Theme): self`. Default abstract impl
  returns `$this`. Subclasses opt-in by storing the theme in a
  readonly property and using its colors in `render()`/`draw()`.
- `sugar-dash/src/Layout/{StackedGrid,Boxer,TileLayout,FlexLayout,
  GridLayout,Stack,VStack,HStack,ZStack,Split,Panel,Frame}.php` — when
  the container's `withTheme` is called, fan to every child via a
  `mutate()` that produces new instances with the theme applied.
- Bulk find/replace in **every widget** under `src/Components/` that
  has a `render()` hardcoding colors:
  - Replace `#FF0000` etc with `$this->theme->error->hex()` or
    equivalent.
  - Replace direct `Style::fg(...)` calls referring to named colors
    with the appropriate Theme slot.

**Modify:**
- `sugar-dash/examples/dashboard-live.php` — bind `Ctrl-T` to toggle
  between `Theme::dark()` and `Theme::light()`. Demonstrates runtime
  switching.

## Tests

- `sugar-dash/tests/Foundation/DrawableThemeTest.php` — fixture
  drawable: `withTheme()` returns new instance, theme propagates to
  children when wrapped in a layout container.
- `sugar-dash/tests/Components/*/RenderUsesThemeTest.php` — sample
  test per family asserting the rendered output respects theme.

## Acceptance

- `grep -rn '#[0-9a-fA-F]\{6\}' sugar-dash/src/Components` returns
  near-nothing (only documentation strings).
- `Ctrl-T` toggles dashboard between dark and light.
- `cd sugar-dash && vendor/bin/phpunit` green.

## Notes

- This is a bulk refactor across many widgets. Take care to preserve
  rendering shape — only colors should differ between themes.
- If a widget intentionally uses a non-theme color (a logo, a brand
  accent), keep it; document why with a `// theme-exempt: …` comment.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
