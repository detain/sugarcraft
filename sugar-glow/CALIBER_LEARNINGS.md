# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:glow]** WidthHelper uses `mb_strwidth('UTF-8')` for all width calculations. Never use `strlen()` or `mb_strlen()` for visual width — they count code units, not visual columns. Full-width CJK characters return 2, combining marks return -1 (which collapses to 0 in truncation).
- **[pattern:glow]** FileWatcher::watch() is a Generator that runs indefinitely. Always consume it inside a loop with a termination condition or stream context cancellation — never `foreach` it directly outside a coroutine dispatcher or you will block forever.
- **[pattern:assert-golden-ansi]** Use `assertGoldenAnsi` for any new `render()` test. Fixture files live in `tests/fixtures/` with a `.golden` extension. Re-record goldens with `UPDATE_GOLDENS=1 vendor/bin/phpunit` after intentional output changes. Mirrors: `docs/repo_map_step_28.md`.
- **[rule:step-29]** Don't call `getenv()` or read terminfo directly for terminal capability probing — go through `\SugarCraft\Palette\Probe\TerminalProbe::run()` and handle `\Throwable` gracefully (fall back to lowest-common-denominator rendering). RenderCommand::loadInput() demonstrates the pattern with a defensive `terminalSupportsColor()` wrapper.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
