<img src=".assets/icon.png" alt="sugar-crush" width="160" align="right">

# SugarCrush

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
use CandyCore\Crush\{Backend, Chat, Message};

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
| `AssistantMsg`               | Internal `Msg` — fires when a backend completion arrives       |
| `Chat`                       | CandyCore Model — history, input buffer, inFlight gate         |
| `Renderer`                   | Pure view fn — CandyShine-rendered scrollback + input box      |

## Test plan

- 21 tests / 43 assertions
- `Message`: factories, wire shape, custom timestamps
- `EchoBackend`: echoes most recent user, handles empty history
- `CommandBackend`: history is JSON-piped to stdin, exit code surfaced as error message, missing command handled gracefully
- `Chat`: type accumulation, space, UTF-8-aware backspace, Enter submits + clears + arms inFlight, empty submit no-op, AssistantMsg appends + clears inFlight, keystrokes ignored while inFlight, Esc quits, full echo round-trip via the real `EchoBackend`

## Status

Phase 9+ entry #17 — first cut. Single-shot replies (no streaming yet); `StreamingBackend` interface is the obvious follow-up. Markdown rendering, persistent input buffer, and the inFlight gate are all wired.
