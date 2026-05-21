# sugar-crush Caliber Learnings

## Session persistence

- **Graceful degradation**: `Session::load()` never throws. Missing file, unreadable file, malformed JSON, or wrong decode type all return a fresh `new self()`. This avoids disrupting the user session with errors from stale/corrupt session files.
- **Home-directory resolution order**: `$HOME` env var → `posix_getpwuid(posix_geteuid())['dir']` → `getcwd() ?: '/tmp'`. Always resolve through `homeDirectory()` rather than hardcoding `~/.`.
- **Directory creation**: `save()` creates `~/.config/sugarcraft-crush/` via `@mkdir($dir, 0755, true)` with error suppression. The `@` prevents warnings if the directory already exists or permissions are unexpected.
- **Immutable + fluent `with*()` pattern**: Every `withCwd()`, `withSelected()`, `withFilter()`, `withSort()`, `withActivePane()` returns `new self(...)` with the updated field and all others carried forward. No mutator methods.
- **Readonly properties with constructor promotion**: `public readonly string $cwd`, etc. Written once at construction time by `load()` or `with*()` builders. No setters.

## JSON handling

- Use `JSON_THROW_ON_ERROR` flag with `json_decode()` / `json_encode()` so failures throw `\JsonException` rather than returning `null` silently.
- Pass `JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR` to `json_encode()` in `save()` for human-readable session files that are also valid JSON.

## Test patterns

- **Snapshot tests** for renderers assert raw `\x1b[...m` SGR bytes directly.
- **Behaviour tests** for `Chat` drive `update()` with scripted `KeyMsg` / `MouseMsg` / `Tick` objects and assert the `[Model, ?Cmd]` tuple shape.
- **Coercion tests** for `Session` feed edge cases (missing file, empty string, wrong type) and assert the no-op / clamp / fresh-session outcome.
