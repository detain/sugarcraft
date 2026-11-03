# SugarLog Caliber Learnings

[syslog-levels] — Level enum uses charmbracelet/log-aligned int deltas (-4/0/4/8/12) rather than sequential integers — deliberately NOT RFC 5424 severities — making threshold comparisons clean without value collisions. (2026-11 truth-flip: 'syslog-aligned' overstated it.)

[probe-color] — Color is a two-step decision: the Logger constructor consults Probe::colorProfile()->allowsColor() (NO_COLOR / CLICOLOR_FORCE — FORCE_COLOR is not a fleet knob), and every emit passes the destination through ColorGate so files and pipes receive plain bytes regardless (CLICOLOR_FORCE opts captures back in). Do not hard-code color decisions.

[pattern:callerformatter-internal-skip] — `CallerFormatter::find()` walks `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20)` to skip frames inside the `SugarCraft\Log` package and return "file:line" of the first external caller. Used by formatters to show the true log call site rather than internal library frames.

[pattern:hook-registry-callable] — `HookRegistry::onLevel(Level, callable): int` stores callbacks in a per-level array and returns a sequential int ID. `remove(int $id): void` deregisters a handler by dropping its slot outright (unset — no tombstone to skip). Removal by ID is intentional — never wrap the ID in `Closure::fromCallable`, which only accepts callables.

[pattern:partsorder-config-dto] — `PartsOrder` is a config DTO for log-part ordering: `list<PART_*>` consts (PART_TIMESTAMP, PART_LEVEL, PART_PREFIX, PART_CALLER, PART_MESSAGE, PART_FIELDS), a `readonly array $parts` property, nullable constructor defaulting to standard order, and static factories (`default()`, `syslog()`, `messageFirst()`). The constructor rejects unknown or duplicated parts by name (2026-11, M15). Immutable config DTO — plain constructor, no with* setters.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
