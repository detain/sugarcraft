# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:candy-sprinkles]** Theme's `primary`/`secondary` and `accent`/`muted` slots are passed the **same** Color value in all 10 named constructors — they are true aliases, not distinct colour choices. Consumers reading `$theme->accent` get `$theme->primary`. Do not add new semantics to one without the other.
- **[pattern:candy-sprinkles]** Theme `adaptive()` uses `$bg >= 8 ? light() : dark()` where 8 is the boundary between ANSI bright-black (dark bg) and the first light ANSI colour. This matches common terminal emulator behaviour for COLORFGBG.
- **[pattern:candy-sprinkles]** Theme is the SSOT for theming across consumer libs (sugar-dash, sugar-charts in Phase 03). Any new colour-slot addition must be propagated to all named constructors — there is no default fallthrough; omitting a slot from a constructor leaves it at `\x00` (no colour).
