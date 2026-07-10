# Caliber Learnings — sugar-crumbs

Accumulated patterns and gotchas from building and shipping this library.

---

## [pattern:zone-marking-composition]

**Zone-marking composition — self-contained click regions via candy-mouse**

`sugar-crumbs` renders breadcrumbs as plain strings by default. To enable
mouse-click routing, a self-contained `Scanner` from `sugarcraft/candy-mouse`
is attached via `Breadcrumb::withScanner(?Scanner)`.

When attached, each crumb item is wrapped in a named zone marker
(`crumb-0`, `crumb-1`, …) via `Mark::wrap()` so the parent can `scan()` the
rendered output for bounding boxes and `hit($col, $row)` to resolve which
crumb was clicked. This keeps zone tracking out of the crumb renderer
itself — composition over inheritance.

The pattern: renderer holds an optional `?Scanner` reference, wraps each item
during render, and the caller is responsible for `scan()` / `hit()` on the
output string.

See: `Breadcrumb::withScanner()`, `Breadcrumb::scan()`, `Breadcrumb::hit()`,
`Breadcrumb::wrapAllCrumbs()`.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

## Mouse hit-testing

- Mouse hit-testing self-contained via candy-mouse. No external zone manager
  is needed — attach a `Scanner` with `withScanner()`.
