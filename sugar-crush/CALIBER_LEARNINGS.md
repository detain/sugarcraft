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

## Generator-based directory listing

- **`StreamingDirectoryLister`** uses `opendir`/`readdir` inside a `Generator` — entries are yielded lazily so even directories with thousands of files never cause memory exhaustion.
- `closedir` must run in a `finally` block to guarantee cleanup if the generator is abandoned mid-iteration.
- Skip hidden entries (`.` prefix) including `.` and `..` — `str_starts_with($entry, '.')` catches both Unix dotfiles and the directory self/parent entries.
- `count()` does a single-pass scan without building an array — `readdir` loop increments a counter; no `scandir()` or `glob()` that would load everything into memory.

## File compaction

- **`Compactor`** groups files below a byte threshold (default 1 KB) into typed buckets (images, docs, code, audio, video, archives, data, config) to reduce visual clutter in directory listings.
- Bucket overflow is handled by `array_chunk()` with sub-bucket naming `$category_0`, `$category_1`, etc. — preserves compact groups up to `$maxPerGroup` items.
- `CompactedGroup` is a `readonly` value object with three fields: `label` (category name or single file path), `paths` (list of absolute paths), and `isCompact` (true for grouped small files, false for single large files).
- `categoryFor()` falls back to `'other'` for unknown extensions — callers should handle this edge case.

## Slash-command parsing

- **`CommandParser`** detects `/`-prefixed input, strips leading whitespace before the slash, then extracts name + args.
- Name termination: first `:` or whitespace; name normalized to lowercase alphanumeric + hyphens via `preg_replace` + `strtolower`.
- Args split respecting single- and double-quote boundaries (quote chars stripped from tokens); whitespace (space/tab) separates positional tokens; unclosed quotes are silently kept as part of the token.
- Empty input, pure whitespace, lone `/`, or `/` followed only by whitespace all return `null` — callers should treat null as ordinary text input.
- `ParsedCommand` is a simple readonly VO with `withArgs()` factory for derived instances.

## Tool registry and tool calls

- **`ToolRegistry`** holds named `Tool` instances; `register()` overwrites on collision, enabling override of built-ins.
- Each `Tool` carries a `ToolSignature` (positional param names, named flags with bool value-requirement, description) and a closure execute handler.
- Built-in tools: `filter <expr>`, `sort [-r] [-n]`, `goto <line>`, `select <start> <end>`, `quit`.
- **`ToolCall`** and **`ToolResult`** are plain readonly VOs with `fromArray`/`toArray` serialization and `ok()`/`error()` factories.
- `ToolResult::toWire()` formats as `['role' => 'tool', 'tool_call_id' => $id, 'name' => $name, 'content' => $result]` — matches the OpenAI/Anthropic tool-result wire format.

## Test patterns

- **Snapshot tests** for renderers assert raw `\x1b[...m` SGR bytes directly.
- **Behaviour tests** for `Chat` drive `update()` with scripted `KeyMsg` / `MouseMsg` / `Tick` objects and assert the `[Model, ?Cmd]` tuple shape.
- **Coercion tests** for `Session` feed edge cases (missing file, empty string, wrong type) and assert the no-op / clamp / fresh-session outcome.
- **Generator tests** for `StreamingDirectoryLister` assert the yielded `[index, absolutePath]` pairs and handle early-exit by exhausting the generator normally.
