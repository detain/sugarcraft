# candy-vcr Research: Session Recorder Tools

## Executive Summary

**candy-vcr** is a PHP port of charmbracelet/x/vcr for recording/replaying candy-core TUI sessions. This research compares approaches across Go (upstream), Rust, and other ecosystems to identify improvements and priorities.

**Current Status:** candy-vcr is functionally complete with:
- JSONL + YAML cassette formats
- ByteAssertion + ScreenAssertion (via candy-vt)
- CLI tools: inspect, replay, diff
- Event kinds: resize, input (msg-form + raw byte), output, quit
- Streaming JSONL recorder with crash-safety

**Key Gaps vs Upstream:**
- No Shirley (Go TUI session recorder companion to vcr)
- No hooks/filters for sanitization
- No custom matcher support
- No compression support
- Limited MsgSerializer coverage

---

## 1. Current Implementation Analysis

**Source:** `/home/sites/sugarcraft/candy-vcr/src/`

### 1.1 Core Components

| Component | File | Description |
|-----------|------|-------------|
| `Recorder` | `Recorder.php` | Streaming JSONL writer, crash-safe |
| `Player` | `Player.php` | Event dispatcher with SPEED_INSTANT/REALTIME modes |
| `Cassette` | `Cassette.php` | Header + event list value object |
| `CassetteHeader` | `CassetteHeader.php` | Version, created, cols, rows, runtime |
| `Event` | `Event.php` | Timestamp + kind + payload |
| `EventKind` | `EventKind.php` | Enum: resize, input, output, quit |
| `ByteAssertion` | `Assert/ByteAssertion.php` | Exact byte comparison with hex window diff |
| `ScreenAssertion` | `Assert/ScreenAssertion.php` | Cell-grid diff via candy-vt Terminal |
| `JsonlFormat` | `Format/JsonlFormat.php` | Primary streaming format |
| `YamlFormat` | `Format/YamlFormat.php` | Human-readable fixture format |
| `BuiltinSerializer` | `Msg/BuiltinSerializer.php` | Msg→array for KeyMsg, MouseMsg, WindowSizeMsg, etc. |
| `Cli/Application` | `Cli/Application.php` | Subcommand router |

### 1.2 Recording Format (JSONL)

```json
{"v":1,"created":"2026-05-13T12:00:00Z","cols":80,"rows":24,"runtime":"sugarcraft/candy-vcr@dev"}
{"t":0.001,"k":"output","b":"\u001b[1mHello\u001b[0m\n"}
{"t":0.150,"k":"resize","cols":120,"rows":40}
{"t":0.200,"k":"input","msg":{"@type":"KeyMsg","type":"enter","rune":"","alt":false,"ctrl":false,"shift":false}}
{"t":1.500,"k":"quit"}
```

**Source:** `candy-vcr/src/Format/JsonlFormat.php:L14-L19`

### 1.3 Playback Accuracy

Current approach uses `stream_socket_pair` for IPC:
- Input written to socket → program reads via stream watcher
- Raw byte input bypasses stream watcher via `InputReader::parse()` + `program->send()`
- Output captured via `php://memory` stream
- 5ms yield between events in INSTANT mode to allow render ticks

**Key Issue:** First resize is skipped because "the replay program emits its own startup WindowSizeMsg from its tty.size() lookup" — this is a known gotcha.

**Source:** `candy-vcr/src/Player.php:L113-L118`

---

## 2. Upstream: charmbracelet/x/vcr

**Repository:** `github.com/charmbracelet/x/vcr`

### 2.1 Overview

Go library for HTTP recording/playback — not TUI-specific. candy-vcr ports its **cassette model** but not its HTTP semantics.

### 2.2 Key Features

- **Cassette format:** YAML-based with HTTP request/response pairs
- **Matcher:** Custom request matching (method, URL, body)
- **Hooks:** `AfterCaptureHook`, `BeforeSaveHook`, `BeforeResponseReplayHook`, `OnRecorderStop`
- **Modes:** `ModeRecordOnly`, `ModeRecordOnce`, `ModeReplayOnly`, `ModeReplayWithNewEpisodes`, `ModePassthrough`
- **Filters:** Request/response sanitization before saving

### 2.3 Source Files

| File | Purpose |
|------|---------|
| `recorder.go` | Main recorder with functional options |
| `cassette.go` | In-memory cassette with interactions |
| `hooks.go` | Header filtering hooks |
| `matcher.go` | Custom request matchers |
| `marshaler.go` | YAML marshaling customization |

### 2.4 Charmbracelet Teatest (TUI Testing)

**Repository:** `github.com/charmbracelet/x/exp/teatest`

Not VCR per se, but the TUI testing companion:
- `NewTestModel()` — wraps tea.Model in test harness
- `WaitFor()` — poll output until predicate
- `FinalOutput()` — captured after Quit
- `FinalModel()` — get final model state for View assertion

**Issue:** `WaitFor` consumes output, so intermediate + final checks are tricky. Workaround: capture snapshot inside WaitFor predicate, then check after Quit.

---

## 3. Go VCR: Shirley (TUI Session Recorder)

**Note:** "Shirley" appears in search results but no exact match in charmbracelet/x. May be:
1. An internal/codename that didn't ship
2. Misidentified reference to vcr or teatest

**Search Result Evidence:**
- `teatest: Obtaining final state of terminal · Issue #212` discusses terminal state capture
- `Issue #11: Improve testing for model` requests intermediate snapshot capability

### 3.1 What Shirley Would Need (Based on candy-vcr Gaps)

If Shirley exists or is planned, it would provide:
- TTY session recording (not HTTP)
- PTY-based input capture
- Timing preservation for replay
- Screen-state snapshots at checkpoints

---

## 4. Rust: Session Recording Tools

### 4.1 asciinema v3 (Complete Rewrite)

**Repository:** `github.com/asciinema/asciinema` | **Stars:** 17K | **Language:** Rust

**The** terminal session recorder. Rewritten in Rust (v3.0.0).

#### asciicast v3 Format
```json
{"version": 3, "term": {"width": 220, "height": 50}, "timestamp": 1700000000}
[0.248, "o", "$ "]
[1.002, "o", "ls -lh\r\n"]
```

- **Event types:** `o` (stdout), `i` (stdin), `x` (exit)
- **Relative timestamps** (intervals) vs absolute — easier editing
- **Header restructuring:** terminal metadata grouped under `term`
- **Idle time trimming** option
- **Environment variable capture** option
- **Compression:** raw format (`.raw`) stores stdout only

#### Key Features
| Feature | Status |
|---------|--------|
| Record to file | ✅ |
| Live streaming | ✅ (local + remote relay) |
| Replay with speed control | ✅ |
| Format conversion | ✅ (v2↔v3, txt, raw) |
| Append to recording | ✅ |
| Custom stdin recording | ✅ |
| Library API | Partial (asciicast crate) |

#### What candy-vcr Could Learn
- **Idle time trimming:** skip long pauses in SPEED_REALTIME
- **Environment capture:** record ENV for replay fidelity
- **Exit status event:** `x` event captures session exit code

### 4.2 replay_rs

**Repository:** `github.com/replay-rs/replay_rs` | **Crate:** `replay_rs`

```rust
use replay_rs::{Recorder, Player};

// Record
let mut recorder = Recorder::new("session.log", "session.log.timing")?;
recorder.record_command(cmd, false)?;

// Replay
let player = Player::new("session.log.timing", "session.log")?;
player.replay(1.0)?; // speed multiplier
```

- **Binary + text format** options
- **Speed control** during playback
- **Cross-platform** (macOS, Linux, Unix)
- **ANSI cleanup** for problematic sequences
- **No external dependencies**

### 4.3 term-transcript

**Repository:** `crates.io/crates/term-transcript` | **Version:** 0.4.0

Snapshotting for CLI/REPL apps:

```rust
// Capture transcript
let transcript = Capturer::new().capture()?.with_rendering();

// Save as SVG for embedding
transcript.save_svg("demo.svg")?;

// Parse transcript
let parsed = SvgTranscript::from_svg(svg_string)?;
```

- **SVG output** for documentation embedding
- **Color capture** (ANSI-compatible)
- **Snapshot testing** integration with `insta`
- **PTY support** via `portable-pty` feature
- **Handlebars templates** for custom rendering

### 4.4 ratatui_testlib

**Repository:** `crates.io/crates/ratatui_testlib` | **For:** Ratatui TUI framework

```rust
let mut harness = TuiTestHarness::new(80, 24)?;
harness.spawn(cmd)?;
harness.wait_for(|state| state.contents().contains("Welcome"))?;
harness.send_text("hello")?;
let contents = harness.screen_contents();
```

- **Headless CI/CD** (no X11/Wayland)
- **Snapshot testing** with `insta`
- **Async runtime** (Tokio)
- **Sixel graphics** position testing
- **PTY-based** terminal emulation

---

## 5. Comparison: Recording Formats

### 5.1 Format Comparison Matrix

| Library | Format | Compression | Human-Readable | Streaming | Timestamp |
|---------|--------|-------------|----------------|-----------|-----------|
| **candy-vcr** | JSONL | None | Partial (YAML opt) | Yes | Absolute (s) |
| **go-vcr** | YAML | None | Yes | No | Duration (ms) |
| **asciinema v3** | JSONL | gzip/zstd | No | Yes | Relative (interval) |
| **replay_rs** | Binary + timing | None | No | No | Timing file |
| **term-transcript** | SVG | N/A | No | No | Absolute |

### 5.2 Recording Event Model

| Library | Input | Output | Resize | Exit | Timing |
|---------|-------|--------|--------|------|--------|
| **candy-vcr** | ✅ (msg + raw bytes) | ✅ | ✅ | ✅ | ✅ |
| **asciinema v3** | ✅ (stdin) | ✅ (stdout) | ❌ | ✅ (exit event) | ✅ |
| **replay_rs** | ✅ (command) | ✅ | ❌ | ❌ | ✅ |
| **term-transcript** | ❌ | ✅ | ❌ | ❌ | ❌ |

### 5.3 Recommendations for Format Improvements

**Priority 1: Timestamp Precision & Relative Mode**
- Add relative timestamp mode (like asciinema v3 interval) for easier editing
- Configurable: `CassetteHeader::$timestampMode = 'absolute' | 'relative'`

**Priority 2: Streaming Compression**
- Add optional gzip/zstd compression for JSONL
- Stream compressor wrapper that flushes per-line
- See: `replay_rs` binary format approach

**Priority 3: Environment Block**
- Capture `ENV` snapshot in header (like asciinema `--env` option)
- Useful for deterministic replay of environment-dependent TUIs

---

## 6. Comparison: Playback Accuracy

### 6.1 Assertion Types

| Library | Byte-Exact | Screen-Diff | Timing-Tolerant | Custom Matcher |
|---------|------------|-------------|-----------------|----------------|
| **candy-vcr** | ✅ ByteAssertion | ✅ ScreenAssertion | ⚠️ via speed param | ❌ |
| **go-vcr** | N/A (HTTP) | N/A | ✅ (skip latency) | ✅ |
| **teatest** | ❌ | ❌ | ✅ | N/A |

### 6.2 Screen Assertion Implementation

**candy-vcr ScreenAssertion** uses candy-vt Terminal:
```php
$expectedTerminal = Terminal::create($this->cols, $this->rows);
$expectedTerminal->feed($expected);
$expectedTerminal->flush();
$changes = $expectedScreen->diff($actualScreen);
```

**Source:** `candy-vcr/src/Assert/ScreenAssertion.php:L41-L59`

**Strengths:**
- Cell-grid equality tolerates ANSI reordering
- Detects semantically invisible differences

**Gaps:**
- No partial match / contains assertion
- No regex/pattern assertion on output
- No diff output save (only summary string)

### 6.3 Recommendations for Playback Improvements

**Priority 1: Regex Assertion**
```php
interface Assertion {
    public function compare(string $expected, string $actual): array;
    public function name(): string; // 'bytes' | 'screen' | 'regex'
}

final class RegexAssertion implements Assertion {
    public function __construct(
        private readonly string $pattern,
        private readonly bool $multiline = false,
    ) {}
    public function compare(string $expected, string $actual): array {
        // Check actual output matches pattern
    }
}
```

**Priority 2: Partial Output Assertion**
```php
// Assert output contains substring (not exact match)
final class ContainsAssertion implements Assertion {
    public function compare(string $expected /* substring */, string $actual): array;
}
```

**Priority 3: Annotated Diff Output**
- Save full unified diff to file on failure
- Show before/after ANSI-colored output

---

## 7. Comparison: CLI Tools

### 7.1 CLI Feature Matrix

| Library | inspect | replay | diff | record | convert | stats |
|---------|---------|--------|------|--------|---------|-------|
| **candy-vcr** | ✅ | ✅ | ✅ | ❌ (API only) | ❌ | ❌ |
| **asciinema** | ❌ (cat) | ✅ | N/A | ✅ | ✅ | ❌ |
| **teatest** | N/A | N/A | N/A | N/A | N/A | ✅ (FinalOutput) |
| **replay_rs** | ❌ | ✅ | N/A | ✅ | ❌ | ❌ |

### 7.2 candy-vcr CLI Architecture

```php
// Cli/Application.php
$this->commands = [
    'inspect' => new InspectCommand(),  // Dump cassette JSONL/YAML
    'replay'  => new ReplayCommand(),   // Play cassette with Program
    'diff'    => new DiffCommand(),     // Compare two cassettes
];
```

**Source:** `candy-vcr/src/Cli/Application.php:L19-L23`

### 7.3 Recommendations for CLI Improvements

**Priority 1: Add `stats` subcommand**
```bash
candy-vcr stats session.cas
# Events: 47 (input: 12, output: 33, resize: 1, quit: 1)
# Duration: 4.232s
# Input msgs: KeyMsg(8), MouseClickMsg(4)
# Output bytes: 2,847
# Avg output/event: 86.3 bytes
```

**Priority 2: Add `record` subcommand**
```bash
candy-vcr record --output session.cas -- php examples/myapp.php
# Attaches recorder to Program, writes cassette
```

**Priority 3: Add `filter` subcommand**
```bash
candy-vcr filter input.cas output.cas --remove-env --sanitize-keys=API_KEY
# Apply transforms to cassette for sharing
```

---

## 8. Specific Improvements with Effort Estimates

### 8.1 High Priority (1-2 days each)

| # | Improvement | Description | Effort | Impact |
|---|-------------|-------------|--------|--------|
| **H1** | **Add `stats` CLI command** | Event tallies, duration, byte counts | 1 day | Debugging, CI output |
| **H2** | **RegexAssertion** | Pattern match on output | 1 day | Flexible testing |
| **H3** | **Annotated diff file** | Save unified diff on failure | 1 day | Debugging speed |
| **H4** | **BuiltinSerializer: add FocusLostMsg** | Missing from current coverage | 0.5 day | Completeness |

### 8.2 Medium Priority (2-4 days each)

| # | Improvement | Description | Effort | Impact |
|---|-------------|-------------|--------|--------|
| **M1** | **Relative timestamp mode** | Interval-based like asciinema v3 | 2 days | Editable cassettes |
| **M2** | **Gzip compression** | Streaming gzip for large cassettes | 2 days | CI storage, git size |
| **M3** | **ContainsAssertion** | Partial output match | 1 day | Flexible testing |
| **M4** | **Cassette version migration** | Format v1→v2 upgrade tool | 2 days | Future-proofing |
| **M5** | **Custom Matcher support** | Configurable event matching | 2 days | Advanced users |

### 8.3 Lower Priority (1 week+)

| # | Improvement | Description | Effort | Impact |
|---|-------------|-------------|--------|--------|
| **L1** | **Shirley-style PTY recorder** | Record command execution (vs API) | 1 week | UX, standalone use |
| **L2** | **Asciinema format import** | Load .cast files as cassettes | 1 week | Ecosystem interop |
| **L3** | **Idle time trimming** | Configurable in SPEED_REALTIME | 3 days | Faster CI tests |
| **L4** | **Hook system** | BeforeSave, AfterCapture hooks | 1 week | Sanitization, logging |

---

## 9. Hooks & Sanitization System

### 9.1 Go VCR Hook Pattern

```go
// hooks.go - charmbracelet/x/vcr
func hookRemoveHeaders(keepAll bool) recorder.HookFunc {
    return func(i *cassette.Interaction) error {
        if keepAll { return nil }
        for k := range i.Request.Headers {
            if _, ok := headersToKeep[k]; !ok {
                delete(i.Request.Headers, k)
            }
        }
        return nil
    }
}
```

**Source:** [github.com/charmbracelet/x/vcr/hooks.go](https://github.com/charmbracelet/x/blob/main/vcr/hooks.go)

### 9.2 PHP Implementation Design

```php
interface Hook {
    public function beforeSave(Event $event): Event;
    public function afterCapture(Event $event): void;
}

final class SanitizingHooks implements Hook {
    public function __construct(
        private readonly array $removeKeys = [],
        private readonly array $replacePatterns = [],
    ) {}
    
    public function beforeSave(Event $event): Event {
        // Apply sanitization to event payload
    }
}

final class HookableRecorder extends Recorder {
    /** @var list<Hook> */
    private array $hooks = [];
    
    public function addHook(Hook $hook): void {
        $this->hooks[] = $hook;
    }
    
    private function writeEvent(string $kind, array $payload): void {
        $event = new Event(microtime(true), EventKind::$kind, $payload);
        foreach ($this->hooks as $hook) {
            $event = $hook->beforeSave($event);
        }
        // write...
    }
}
```

### 9.3 Use Cases

- **Remove env vars** from header before sharing cassettes
- **Replace API keys** with placeholders in input events
- **Strip timing** for deterministic diffs
- **Add metadata** (test name, CI run ID)

---

## 10. Custom Matchers

### 10.1 Go VCR Matcher Pattern

```go
// matcher.go - charmbracelet/x/vcr
func customMatcher(t *testing.T) recorder.MatcherFunc {
    return func(r *http.Request, i cassette.Request) bool {
        if r.Body == nil || r.Body == http.NoBody {
            return cassette.DefaultMatcher(r, i)
        }
        var reqBody []byte
        reqBody, _ = io.ReadAll(r.Body)
        r.Body.Close()
        r.Body = ioutil.NopCloser(bytes.NewBuffer(reqBody))
        return r.Method == i.Method && r.URL.String() == i.URL && string(reqBody) == i.Body
    }
}
```

**Source:** [github.com/charmbracelet/x/vcr/matcher.go](https://github.com/charmbracelet/x/blob/main/vcr/matcher.go)

### 10.2 TUI Session Matcher Use Cases

For TUI sessions, matchers would operate on events:

```php
interface EventMatcher {
    public function matches(Event $recorded, Event $actual): bool;
}

final class PassthroughMatcher implements EventMatcher {
    public function matches(Event $recorded, Event $actual): bool {
        return $recorded->kind === $actual->kind;
    }
}

final class TimingTolerantMatcher implements EventMatcher {
    public function __construct(
        private readonly float $timingTolerance = 0.1, // 100ms
    ) {}
    
    public function matches(Event $recorded, Event $actual): bool {
        if ($recorded->kind !== $actual->kind) return false;
        return abs($recorded->t - $actual->t) <= $this->timingTolerance;
    }
}

final class ContentMatcher implements EventMatcher {
    public function matches(Event $recorded, Event $actual): bool {
        if ($recorded->kind !== $actual->kind) return false;
        return $recorded->payload === $actual->payload;
    }
}
```

---

## 11. Dependency Analysis

### 11.1 Current Dependencies

```json
{
  "require": {
    "php": "^8.3",
    "sugarcraft/candy-core": "dev-master"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "sugarcraft/candy-vt": "dev-master",
    "symfony/yaml": "^6.4 || ^7.0"
  }
}
```

### 11.2 Optional Enhancement Dependencies

| Enhancement | Package | Use Case |
|-------------|---------|----------|
| Compression | `php-ext-zlib` or `symfony/yaml` | Already have symfony/yaml for YAML |
| SVG output | `m逢tholt/php-svg` | term-transcript-style rendering |
| JSON-LD | `ml/json-ld` | Standard cassette format |
| MsgPack | `msgpack/msgpack` | Binary cassette format |

---

## 12. Test Patterns for TUIs

### 12.1 VCR Round-Trip Pattern

```php
public function testReplayMatchesRecording(): void
{
    // 1. Record
    $cassettePath = __DIR__ . '/fixtures/myapp.cas';
    $recorder = Recorder::open($cassettePath);
    (new Program(new MyModel()))->withRecorder($recorder)->run();
    $recorder->close();
    
    // 2. Replay
    $player = Player::open($cassettePath);
    $result = $player->play(fn($in, $out, $loop) => new Program(
        new MyModel(),
        new ProgramOptions(input: $in, output: $out, loop: $loop)
    ));
    
    // 3. Assert
    $this->assertTrue($result->ok, $result->diffSummary());
    $this->assertEquals(12, $result->inputCount);
}
```

### 12.2 Snapshot Testing Pattern

```php
public function testViewOutput(): void
{
    $program = new Program(new MyModel(), new ProgramOptions(
        output: fopen('php://memory', 'w+b')
    ));
    $program->run();
    
    $output = stream_get_contents($program->output(), -1, 0);
    
    // Snapshot assertion using ScreenAssertion
    $assertion = new ScreenAssertion(cols: 80, rows: 24);
    $verdict = $assertion->compare($this->loadFixture('expected.ans'), $output);
    
    $this->assertTrue($verdict['ok'], $verdict['diff']);
}
```

### 12.3 Asciinema Integration Pattern

```php
public function testImportAsciinemaCast(): void
{
    // Convert asciinema v3 cast to candy-vcr cassette
    $cassette = AsciinemaImporter::importFile('session.cast');
    
    // Replay with candy-vcr
    $player = new Player($cassette);
    $result = $player->play($programFactory);
    
    $this->assertTrue($result->ok);
}
```

---

## 13. Recommendations Summary

### 13.1 Immediate (Next Sprint)

1. **Add `stats` CLI** — low effort, high debug value
2. **BuiltinSerializer: add FocusLostMsg** — 0.5 day, completeness
3. **Annotated diff file on failure** — save unified diff for CI

### 13.2 Short-term (This Quarter)

4. **RegexAssertion + ContainsAssertion** — flexible testing
5. **Gzip compression** — reduce cassette size
6. **Relative timestamp mode** — editable cassettes

### 13.3 Medium-term

7. **Hook system** — sanitization, logging
8. **Custom matchers** — timing tolerance, content match
9. **Asciinema import** — ecosystem interop

### 13.4 Future/Exploration

10. **Shirley-style PTY recorder** — standalone record CLI
11. **Idle time trimming** — faster CI via skip long pauses
12. **MsgPack binary format** — compact storage

---

## 14. References

### 14.1 Source Files (candy-vcr)

- `Recorder.php` — `/home/sites/sugarcraft/candy-vcr/src/Recorder.php`
- `Player.php` — `/home/sites/sugarcraft/candy-vcr/src/Player.php`
- `Cassette.php` — `/home/sites/sugarcraft/candy-vcr/src/Cassette.php`
- `Event.php` — `/home/sites/sugarcraft/candy-vcr/src/Event.php`
- `ByteAssertion.php` — `/home/sites/sugarcraft/candy-vcr/src/Assert/ByteAssertion.php`
- `ScreenAssertion.php` — `/home/sites/sugarcraft/candy-vcr/src/Assert/ScreenAssertion.php`
- `JsonlFormat.php` — `/home/sites/sugarcraft/candy-vcr/src/Format/JsonlFormat.php`
- `YamlFormat.php` — `/home/sites/sugarcraft/candy-vcr/src/Format/YamlFormat.php`
- `BuiltinSerializer.php` — `/home/sites/sugarcraft/candy-vcr/src/Msg/BuiltinSerializer.php`
- `Cli/Application.php` — `/home/sites/sugarcraft/candy-vcr/src/Cli/Application.php`
- `CALIBER_LEARNINGS.md` — `/home/sites/sugarcraft/candy-vcr/CALIBER_LEARNINGS.md`

### 14.2 Upstream References

- **charmbracelet/x/vcr** — https://github.com/charmbracelet/x/vcr
- **charmbracelet/teatest** — https://github.com/charmbracelet/x/exp/teatest
- **dnaeon/go-vcr** — https://github.com/dnaeon/go-vcr (v4)
- **asciinema** — https://github.com/asciinema/asciinema (v3.0.0 Rust rewrite)
- **replay_rs** — https://docs.rs/replay_rs/latest/replay_rs/
- **term-transcript** — https://crates.io/crates/term-transcript
- **ratatui_testlib** — https://docs.rs/ratatui-testlib

### 14.3 Key Learned Patterns

From `CALIBER_LEARNINGS.md`:
- **Do NOT close recorder on QuitMsg** — teardown bytes must land in cassette
- **5ms yield in INSTANT mode** — without it, render tick never fires
- **Bypass stream watcher for raw-byte input** — async race avoidance
- **ByteAssertion hex window starts at divergence** — not before

---

*Research compiled: 2026-05-13*
*Next steps: Select priority items from Section 13 for implementation plan*
