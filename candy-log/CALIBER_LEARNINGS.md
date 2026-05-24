# CandyLog Caliber Learnings

[syslog-levels] — Level enum uses syslog-aligned int values (-4/0/4/8/12) rather than sequential integers, making threshold comparisons and external integration (syslog, log aggregators) cleaner without value collisions.

[probe-color] — Color is determined by Probe::colorProfile()->allowsColor() in the Logger constructor, which respects NO_COLOR and FORCE_COLOR environment variables — do not hard-code color decisions.

[pattern:callerformatter-internal-skip] — `CallerFormatter::find()` walks `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20)` to skip frames inside the `SugarCraft\Log` package and return "file:line" of the first external caller. Used by formatters to show the true log call site rather than internal library frames.

[pattern:hook-registry-callable] — `HookRegistry::onLevel(Level, callable): int` stores callbacks in a per-level array and returns a sequential int ID. The original `remove(int $id)` method was broken (Closure::fromCallable rejects int) and was removed — the step only required `onLevel` and `fire`; no removal API is needed.

[pattern:partsorder-config-dto] — `PartsOrder` is a config DTO for log-part ordering: `list<PART_*>` consts (PART_TIMESTAMP, PART_LEVEL, PART_PREFIX, PART_CALLER, PART_MESSAGE, PART_FIELDS), a `readonly array $parts` property, nullable constructor defaulting to standard order, and static factories (`default()`, `syslog()`, `messageFirst()`). Immutable + fluent pattern.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
