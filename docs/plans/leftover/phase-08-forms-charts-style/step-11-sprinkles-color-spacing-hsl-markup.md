# Step 08.11 — candy-sprinkles Color string parse + spacing + HSL + markup parser

**Source:** `leftover_updates_later.md` candy-sprinkles #2, #3, #4, #6
**Branch:** `ai/sprinkles-color-misc`

## Deliverable

Four enhancements:

- `Color::parse('cyan')` — string color name → Color.
- Spacing param in `Layout::join*` joins (separator between joined
  segments).
- HSL color space (`Color::hsl(h, s, l)`).
- Markup parser `[bold red]…[/]` — Rich-style. Distinct from
  StyleParser; uses tag pairs instead of `[text](style)`.

## Files

**Modify:**
- `\SugarCraft\Core\Util\Color` — `parse(string)` accepts named colors
  (CSS names + ANSI 8-color names).
- `\SugarCraft\Sprinkles\Layout` — `join*` methods accept
  `?int $spacing = null` parameter.

**Create:**
- `candy-sprinkles/src/Hsl.php` — `Color::hsl()` factory.
- `candy-sprinkles/src/Markup.php` — Rich-style markup parser.

## Tests

- Per feature: `tests/{ColorParse,LayoutSpacing,Hsl,Markup}Test.php`.

## Acceptance

- `cd candy-sprinkles && vendor/bin/phpunit --filter "ColorParse|LayoutSpacing|Hsl|Markup"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
