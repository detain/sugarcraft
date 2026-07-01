# CALIBER_LEARNINGS — candy-palette

## Patterns

- `[probe-ssot]` — `Probe::colorProfile()` + `ColorProfile` enum is the SSOT for terminal-color env detection. Other libs (candy-log, candy-mosaic, candy-freeze, candy-vt) consume it directly; do not re-implement detection logic in consumers.

- `[infocmp-phase2]` — `Probe::infocmpUpgrade()` silently upgrades `Ansi → TrueColor` when infocmp reports `Tc` or `RGB` capability. This is a best-effort heuristic — infocmp availability is not guaranteed in all environments (checked against `/usr/bin/infocmp` and `/bin/infocmp`).

 - **[pattern:async-gap]** The library is entirely synchronous despite being in the ReactPHP ecosystem. `Probe::colorProfile()` uses blocking `shell_exec('infocmp ...')` calls. Consumers needing async terminal detection should use the future `AsyncProbe` (see `plan_candy-palette.md` Item 3.1). Do not assume any public method is non-blocking.

 - Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
