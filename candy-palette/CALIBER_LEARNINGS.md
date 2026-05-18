# CALIBER_LEARNINGS — candy-palette

## Patterns

- `[probe-ssot]` — `Probe::colorProfile()` + `ColorProfile` enum is the SSOT for terminal-color env detection. Other libs (candy-log, candy-mosaic, candy-freeze, candy-vt) consume it directly; do not re-implement detection logic in consumers.

- `[infocmp-phase2]` — `Probe::infocmpUpgrade()` silently upgrades `Ansi → TrueColor` when infocmp reports `Tc` or `RGB` capability. This is a best-effort heuristic — infocmp availability is not guaranteed in all environments (checked against `/usr/bin/infocmp` and `/bin/infocmp`).
