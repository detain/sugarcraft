# Step 02.01 — Build Theme class in candy-sprinkles

**Source:** `leftover_updates_later.md` SSOT-01a + candy-sprinkles research High #1
**Branch:** `ai/sprinkles-theme`
**Bundle hint:** standalone (foundational; blocks SSOT-01b..h and Dash-03)

## Deliverable

Create the canonical `Theme` class in `candy-sprinkles`. Today
sugar-dash has a 349-line `Foundation/Theme.php` and no other lib has
one — but every dashboard/widget consumer needs themes. After this
step, every other lib imports `\SugarCraft\Sprinkles\Theme`; sugar-dash
will retire its bespoke version in step 03.05.

## Files

**Create:**
- `candy-sprinkles/src/Theme.php` — final class, immutable, public
  readonly properties for palette slots:
  - `Color $foreground, $background, $accent, $muted, $error,
    $warning, $success, $info, $border, $separator, $cursor`.
  - Convenience: `$primary, $secondary` as aliases.
- Named-constructor factories:
  - `Theme::dark(): self`
  - `Theme::light(): self`
  - `Theme::dracula(): self`
  - `Theme::tokyoNight(): self`
  - `Theme::oneDark(): self`
  - `Theme::githubDark(): self`
  - `Theme::solarizedDark(): self`
  - `Theme::solarizedLight(): self`
  - `Theme::ansi(): self` (terminal-default 8-color)
  - `Theme::adaptive(): self` — reads `COLORFGBG` env var; falls back to dark.
- Fluent withers (each returns new instance via `mutate()`):
  - `withForeground(Color)`, `withBackground(Color)`, … for every slot.
- Doc-comment cites: `Mirrors charmbracelet/lipgloss.Theme`.

**Tests:**
- `candy-sprinkles/tests/ThemeTest.php` — assertions:
  - Each factory returns expected slot colors.
  - Withers return new instance (instance !== original).
  - `adaptive()` correctly picks based on `COLORFGBG` (test both
    `COLORFGBG=15;0` → dark and `COLORFGBG=0;15` → light).

## Acceptance

- `cd candy-sprinkles && vendor/bin/phpunit --filter Theme` green.
- `php tools/check-path-repos.php` green.
- Every named constructor returns a Theme with all eleven slots
  populated.
- `Theme::dracula()` colors match Dracula's published palette spec.

## Notes

- This is the new SHARED home for themes. sugar-dash will adopt it in
  step 03.05; sugar-charts will pick it up via SSOT-05 in step 03.13.
- Use `\SugarCraft\Core\Util\Color` for color values, not a new color
  type.
- This class is the precondition for downstream sugar-dash and chart
  work. Keep API stable from day one.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
