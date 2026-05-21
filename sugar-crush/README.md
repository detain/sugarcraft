<img src=".assets/icon.png" alt="sugar-crush" width="160" align="right">

# SugarCrush

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-crush)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-crush)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-crush?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-crush)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/chat.gif)

Chat-shell TUI for AI coding assistants — port of [`charmbracelet/crush`](https://github.com/charmbracelet/crush). Pluggable backends (ship your own Anthropic / OpenAI / Ollama / shell-out adapter), Markdown rendering of replies via CandyShine, scrollback above a fixed input box.

```
┌─ SugarCrush ───────────────────────────────────────┐
│ user> explain fiber-based scheduling in PHP        │
│                                                    │
│ assistant                                          │
│ ## Fibers (PHP 8.1+)                               │
│                                                    │
│ Fibers are cooperative units of execution …        │
└────────────────────────────────────────────────────┘
┌────────────────────────────────────────────────────┐
│ > how do they relate to ReactPHP?█                 │
└────────────────────────────────────────────────────┘
 Enter to send · Esc / ^C to quit
```

## Run it

```bash
composer install
./bin/sugarcrush
```

By default it ships with `EchoBackend` so the binary is runnable offline (the assistant just echoes what you typed). To wire it to a real LLM, set `$SUGARCRUSH_BACKEND_CMD` to a command that reads JSON history on stdin and writes the reply to stdout:

```bash
export SUGARCRUSH_BACKEND_CMD=~/bin/anthropic-stream.sh
./bin/sugarcrush
```

### Sample wrapper script (Anthropic)

```bash
#!/usr/bin/env bash
# ~/bin/anthropic-stream.sh
payload=$(jq -nc --argjson h "$(cat)" \
  '{model: "claude-opus-4-7", max_tokens: 4096, messages: $h}')

curl -sN https://api.anthropic.com/v1/messages \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d "$payload" \
  | jq -r '.content[0].text'
```

`chmod +x ~/bin/anthropic-stream.sh` and you're done.

The wrapper-script approach is deliberate: keeps the PHP package network-dep-free, lets you swap providers without changing PHP code, and makes prompt-engineering iteration as fast as editing a shell script.

## Writing a custom Backend

If you'd rather skip the shell-out dance and integrate the SDK directly, implement the `Backend` interface in PHP:

```php
use SugarCraft\Crush\{Backend, Chat, Message};

final class MyBackend implements Backend {
    public function complete(array $history): Message {
        $reply = /* your call here, returning a string */;
        return Message::assistant($reply);
    }
}

(new Program(new Chat(backend: new MyBackend())))->run();
```

## Architecture

| File                         | Role                                                           |
|------------------------------|----------------------------------------------------------------|
| `Role` enum                  | system / user / assistant — matches every API's wire vocab     |
| `Message`                    | VO: role, content, createdAt; `toWire()` for adapters          |
| `Backend` interface          | `complete(list<Message>): Message`                             |
| `Backend\EchoBackend`        | Offline default — echoes the last user message                 |
| `Backend\CommandBackend`     | Shells out via proc_open; JSON history → stdin → stdout reply  |
| `Backend\StreamingCommandBackend` | Streams backend output line-by-line as it arrives     |
| `StreamingDirectoryLister`    | Generator-based lazy directory listing — memory-safe for huge dirs |
| `Compactor`                  | Groups small files by extension/type to reduce visual clutter |
| `CompactedGroup`             | Value object: label, paths, and isCompact flag per group     |
| `AssistantMsg`               | Internal `Msg` — fires when a backend completion arrives       |
| `Chat`                       | SugarCraft Model — history, input buffer, inFlight gate         |
| `Renderer`                   | Pure view fn — CandyShine-rendered scrollback + input box      |
| `Session`                    | Persists UI state to ~/.config/sugarcraft-crush/session.json |
| `CommandParser`              | Parses `/command` slash-input; extracts name + shell-quoted args |
| `ParsedCommand`               | Result VO: `name` (lowercase) + `args` list                   |
| `ToolRegistry`               | Registry of slash-commands; 5 built-ins (filter/sort/goto/select/quit) |
| `Tool`                       | Registered tool: name, signature, execute handler               |
| `ToolSignature`              | Tool arg spec: positional names + named flags + description    |
| `ToolCall`                   | VO: name, arguments, optional id — from AI backend              |
| `ToolResult`                 | VO: name, result, optional error, optional id — to AI backend   |

## Session persistence

`Session` stores and restores the file-browser state across invocations:

- `cwd` — current working directory
- `selected` — list of selected file paths
- `filter` — active filter string
- `sortColumn` / `sortDir` — sort preferences
- `activePane` — active pane identifier

`Session::load()` and `Session::save()` are called by the application entry point. Missing or corrupted session files yield a fresh empty session silently rather than propagating errors.

## Test plan

- 128 tests / 340 assertions
- `Message`: factories, wire shape, custom timestamps
- `EchoBackend`: echoes most recent user, handles empty history
- `CommandBackend`: history is JSON-piped to stdin, exit code surfaced as error message, missing command handled gracefully
- `Session`: load returns fresh on missing/corrupted file, save creates directory hierarchy, with*() builders are immutable and fluent, home-directory resolution via $HOME / posix_getpwuid / getcwd fallback
- `Chat`: type accumulation, space, UTF-8-aware backspace, Enter submits + clears + arms inFlight, empty submit no-op, AssistantMsg appends + clears inFlight, keystrokes ignored while inFlight, Esc quits, full echo round-trip via the real `EchoBackend`
- `StreamingDirectoryLister`: lazy yield, empty/non-dir/no-handle handled gracefully, listFiles filters correctly, count scans without loading entries
- `Compactor`: threshold partitions small vs large, extension maps to correct category, maxPerGroup splits oversized buckets, CompactedGroup value object methods
- `CommandParser`: slash vs plain text discrimination, name normalization (lowercase/alphanumeric/hyphens), colon/space delimiter parsing, shell-quoted arg splitting (single/double quotes, whitespace separation), empty/no-op edge cases
- `ToolRegistry`: register overrides existing, get/has/execute/all, 5 built-in tools (filter/sort/goto/select/quit) with correct signatures and execute output
- `ToolCall`: fromArray/toArray round-trip, defaults for optional fields
- `ToolResult`: ok() / error() factories, isError() predicate, toWire() role/tool_call_id shape

## Status

Phase 9+ entry #17 — first cut. Single-shot replies (no streaming yet); `StreamingBackend` interface is the obvious follow-up. Markdown rendering, persistent input buffer, and the inFlight gate are all wired.
