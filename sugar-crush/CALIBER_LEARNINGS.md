# sugar-crush Caliber Learnings

## JSON on the wire — the two shapes that cost a whole afternoon

These produced 400 Bad Request from SGLang on *every* message and on *every*
tool-calling turn respectively, with no useful server-side detail. Both are
PHP-encoding problems, not protocol problems.

- **An empty PHP array encodes as `[]`, never `{}`.** `json_encode([])` is
  `[]`. A JSON-Schema `"parameters"` object with no properties therefore goes
  out as an array, and a strict server rejects the **entire request** — not
  just the offending tool. Force the object shape: `new \stdClass()`, or
  `json_encode($x, JSON_FORCE_OBJECT)` on that node only. Assert the encoded
  *bytes* in the test, not the PHP array — the array assertion passes while the
  wire payload is wrong.
- **An object with private/protected properties encodes as `{}`.**
  `json_encode()` only sees public state. Hand a value object straight to
  `json_encode` and it silently becomes an empty object — which is exactly what
  happened to `tool_calls`. Give such types an explicit `toArray()`/
  `JsonSerializable` and encode that.

Corollaries:

- When a provider returns 400 with no detail, dump the actual request body
  before touching the provider logic. The bug is usually one node.
- Three provider tests **pinned the broken shape** — they asserted
  `formatMessages()` returned the raw `ToolCall` objects, so the encoding bug
  was protected by its own suite. An existing green test is not evidence the
  wire format is right unless it asserts the encoded bytes.
- `SglangProvider`, `OpenAIProvider` and `CustomProvider` all had the identical
  defect. When one OpenAI-compatible provider has a wire bug, check the others
  before declaring it fixed.

## candy-core key normalization — Ctrl+C is `ctrl` + rune `c`

`InputReader` normalizes every control byte `0x01`–`0x1a` into
`(KeyType::Char, chr(0x60 + code), ctrl: true)`. A real terminal therefore
delivers `^C` as rune `'c'` **with the ctrl flag**, and never as the raw
`"\x03"`. Code that tests only for `"\x03"` cannot be quit with Ctrl+C on the
live path — and its unit tests still pass, because they synthesize the raw rune
directly. Accept both encodings; test both.

## Raw ANSI inside markdown-rendered text surfaces as literal `[33m`

Assistant text goes through CandyShine's Markdown renderer before it reaches
the buffer. Raw ANSI embedded in that text — e.g. a CLI-shaped slash command
that `echo`s escapes, captured with `ob_start()` and stored as
`Message::assistant()` — gets partially consumed: the `\x1b` is eaten and the
parameter bytes show up on screen as the literal string `[33m`.

Fix it at the source, not with a blanket strip on the way in: a strip hides the
*next* offender instead of surfacing it. Emit **markdown** and let CandyShine
do the colouring (inline code for a literal command, emphasis for a
`<placeholder>`). A test asserting no source file emits raw escapes keeps the
next one failing in CI rather than in someone's terminal.

## Private-use block collision: image markers vs mouse zones

candy-core's image placeholders and candy-mouse's zone sentinels **both**
allocate out of U+E000–U+F8FF. `Renderer::maskImageMarkers()` exists solely to
mask that block out of the copy the mouse `Scanner` reads, so an image is not
parsed as a zone. Any new rendered glyph must stay outside U+E000–U+F8FF.

## `Chat` is immutable — thread every field through `mutate()`

`Chat::mutate()` rebuilds the object from a `constructorProps` map. A new
`readonly` field that is not added to that map is **silently dropped on every
state transition** — the constructor default reappears on the next keypress.
This fails in a way no single-`update()` test catches; drive at least two
transitions when testing a new field.

## `App` wears two hats — do not "retire" it

`SugarCraft\Crush\App\App` is the live **engine state object** (`Runtime::run(App
$app, …)` and `EngineBackend` both take it, carrying tools/hooks/skills) *and*,
since the pane-shell migration, the root TUI `Model`. Plan documents that
describe "the dead `App`/`Tui\Renderer` system" are wrong about the first half.
What was genuinely unreachable was the **pane layer** (`Tui\Renderer` and its
`Tui\Components\*`), which the migration wired up rather than deleted.

## "Implemented" is not "reachable" — test the boot path

The recurring failure mode in this library's history is a subsystem with a
green unit suite that no real run can reach: session store never seeded, mouse
mode never enabled, skills registry never populated, `/bg` with no supervisor.
Unit tests construct the collaborator themselves and so can never detect it.
`tests/Integration/` asserts the chain from `bin/sugarcrush` →
`Bootstrap::app()` → the subsystem instead. When adding a feature, ask what
constructs it on a real launch, and write that test first.

Corollary: a reachability test that passes because it was written *around* the
gap is worse than no test. If the chain is genuinely broken, report it.

## Researched recommendations are not guaranteed correct

`crush_feat.md`'s recommendations came from a comparison study, not from this
codebase. Three did not survive contact:

- It suggested binding the session picker to `Ctrl+O`, which an earlier section
  of the same document had already bound to tool-output expansion. `Ctrl+R` was
  used instead (and mirrors `--resume`).
- Its `extra_body` prescription for SGLang had to be corrected against the
  actual deployment.
- A companion plan called the whole `App`/`Tui\Renderer` layer dead (see above).

When a recommendation does not match reality, record the correction here rather
than silently working around it.

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

## MCP client (stdio transport)

- **`McpClient`** spawns a child process via `proc_open` with piped stdio; non-blocking reads via `stream_set_blocking(false)` keep the TUI loop responsive.
- **JSON-RPC 2.0 framing**: messages are newline-delimited (`$message->toJson() . "\n"`); `readMessages()` splits on `\n` and parses each chunk via `McpMessage::parse()`.
- **`McpMessage`** covers all four JSON-RPC 2.0 packet types: request, response, notification, error. Factory methods: `request()`, `notification()`, `success()`, `error()`.
- **`McpMessage::parse()`** validates `jsonrpc: "2.0"` presence and returns `null` for malformed input — callers handle null gracefully.
- **Polling loop** with `usleep(10000)` (10 ms) waits up to 100 attempts for a matching response id — avoids blocking the TUI while still being responsive.
- **`McpClient::forClaudeCode()`** factory provides the canonical `command: 'claude', args: ['--mcp']` invocation for the official Claude Code MCP server.
- **`disconnect()`** closes pipes and calls `proc_close()` in a loop; `__destruct()` ensures cleanup if the client is abandoned.

## Buffer diffing

- `Renderer` holds a `?Buffer $previousFrame`; on each render it diffs against the prior frame and emits only delta ops via `DiffEncoder`.
- Reset `previousFrame` on window resize, cursor-position-lost, or first paint — diffing across these boundaries produces visual corruption.
- **Source:** step-27 ai/buffer-diff-consumers
- **Behaviour tests** for `Chat` drive `update()` with scripted `KeyMsg` / `MouseMsg` / `Tick` objects and assert the `[Model, ?Cmd]` tuple shape.
- **Coercion tests** for `Session` feed edge cases (missing file, empty string, wrong type) and assert the no-op / clamp / fresh-session outcome.
- **Generator tests** for `StreamingDirectoryLister` assert the yielded `[index, absolutePath]` pairs and handle early-exit by exhausting the generator normally.

## SugarCrush Slash Command Pattern (2026-08-12)

### Adding a new slash command

**Three-step checklist:**

1. **CommandRegistry::all()** — Add `CommandSpec::new()` entry with command name, description, category, and argumentHint
2. **Chat::submit()** — Add `str_starts_with($text, '/<command>')` dispatch check returning `$this->handle<Command>Command($text)`
3. **Chat handle*Command()** — Add private method that:
   - Parses args after the command prefix
   - Uses `ob_start()`/`ob_get_clean()` to capture command output (buffering happens HERE, not in the command class)
   - Returns `[$nextChat, null]` or `[$this, static fn() => print $output]` on error

### WebSearch Command (SearXNG integration)

**Endpoint configuration pattern:**
```php
new WebSearch(?string $endpoint)
// Uses SUGARCRUSH_SEARCH_ENDPOINT env var as fallback
// Default: http://skynet2.interserver.net:8080/search
```

**Command class pattern:**
- Command class uses DIRECT `echo` statements for output
- Output buffering (`ob_start()`/`ob_get_clean()`) happens in Chat handler
- Command returns exit code: 0 = success, 1 = failure

**Input schema requirements:**
- `query` (required): The search query string
- `description` (required): "Web search: $query" or similar
- `safesearch` (optional): 0-2 filter level
- `time_range` (optional): "day", "month", or "year"

**Flag parsing:** Position-independent — flags (`--safesearch`, `--time-range`) can appear anywhere in arg list

**Query validation:**
- Non-empty after trim
- Max 2000 characters
