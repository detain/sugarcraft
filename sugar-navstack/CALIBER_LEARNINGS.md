# Caliber Learnings — sugar-navstack

Accumulated patterns and gotchas from building and shipping this library.

---

## [pattern:zone-marking-composition]

**Zone-marking composition — self-contained click regions via sugar-mouse**

`sugar-navstack` renders breadcrumbs as plain strings by default. To enable
mouse-click routing, a self-contained `Scanner` from `sugarcraft/sugar-mouse`
is attached via `Breadcrumb::withScanner(?Scanner)`.

When attached, each crumb item is wrapped in a named zone marker via
`Mark::wrap()`, and the zone id carries the item's index in the FULL stack
(`crumb-2` = stack item 2 even when `setMaxWidth()` elided items 0-1), so the
parent can `scan()` the rendered output for bounding boxes, `hit($col, $row)`
to resolve which crumb was clicked, and `popTo($index)` to route the click
without silent misroute. The scanner is COPIED in at `withScanner()` — two
breadcrumbs seeded from one `Scanner` never share or clobber each other's
zones; enumerate via `Breadcrumb::scanner()`. This keeps zone tracking out of
the crumb renderer itself — composition over inheritance.

The pattern: renderer holds an optional `?Scanner` reference, wraps each item
during render, and the caller is responsible for `scan()` / `hit()` on the
output string.

See: `Breadcrumb::withScanner()`, `Breadcrumb::scan()`, `Breadcrumb::hit()`,
`Breadcrumb::wrapAllCrumbs()`.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

## Mouse hit-testing

- Mouse hit-testing self-contained via sugar-mouse. No external zone manager
  is needed — attach a `Scanner` with `withScanner()`.
