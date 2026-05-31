# Caliber Learnings — sugar-crumbs

Accumulated patterns and gotchas from building and shipping this library.

---

## [pattern:zone-marking-composition]

**Zone-marking composition — click regions via candy-zone**

`sugar-crumbs` renders breadcrumbs as plain strings by default. To enable
mouse-click routing, a `Manager` from `sugarcraft/candy-zone` is attached via
`Breadcrumb::withZoneManager(?Manager)`.

When attached, each crumb item is wrapped in a named APC zone marker
(`crumb-0`, `crumb-1`, …) via `Manager::mark()` so the parent can
`Manager::scan()` bounding boxes and route `MouseMsg` through
`Manager::anyInBoundsAndUpdate()`. This keeps zone tracking out of the
crumb renderer itself — composition over inheritance.

The pattern: renderer holds an optional `?Manager` reference, calls
`$manager->mark("zone-name", $content)` per item during render, and the
caller is responsible for `scan()` / mouse dispatch on the output string.

See: `Breadcrumb::withZoneManager()`, `Breadcrumb::wrapAllCrumbs()`.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

## Mouse hit-testing

- Mouse hit-testing self-contained via candy-mouse. Don't pass Managers around for new code.
- `withZoneManager()` is kept as deprecated back-compat; internally delegates to own `Scanner`
