# candy-vcr — Developer Guide

`candy-vcr` provides extension points for custom recording behaviour,
sanitisation, metadata injection, and custom message serialisation.

---

## Extension points

### `Format` interface

The `Format` interface (`SugarCraft\Vcr\Format\Format`) is the primary
extension point for cassette serialization:

```php
use SugarCraft\Vcr\Format\Format;
use SugarCraft\Vcr\Cassette;

interface Format
{
    public function write(Cassette $cassette, string $path): void;
    public function read(string $path): Cassette;
    public function encode(Cassette $cassette): string;
    public function decode(string $contents): Cassette;
}
```

**Built-in implementations:**

| Format | Timestamp field | Use case |
|--------|----------------|----------|
| `JsonlFormat` | `t` (absolute) | Default; playback timing |
| `RelativeFormat` | `dt` (delta) | Deterministic replay; asciinema v3 compat |
| `AsciinemaFormat` | import only | Ingest asciinema v3 `.cast` files |
| `YamlFormat` | `t` (absolute) | Hand-written test fixtures |
| `CompressedJsonlFormat` | `t` (absolute) | 5–10× smaller cassettes |

`Recorder::withFormat(Format $f)` selects which format to use at record time.
`Player::open()` auto-detects `RelativeFormat` vs `JsonlFormat` by looking
for the `dt` field on the first event line — callers do not need to specify
a format when opening a cassette for replay.

### `Recorder` interface

```php
use SugarCraft\Vcr\Recorder;
use SugarCraft\Vcr\Cassette;

$recorder = Recorder::open('/tmp/session.cas');
$recorder->recordInput(string $bytes): void;
$recorder->recordOutput(string $bytes): void;
$recorder->recordResize(int $cols, int $rows): void;
$recorder->recordQuit(): void;
$recorder->close(): Cassette;
```

`Recorder::open(string $path): Recorder` opens (or creates) a cassette for
writing. All `record*` methods are idemponent — calling them after `close()`
is a no-op.

### Hook system

Hooks intercept and transform events during recording. They implement
`SugarCraft\Vcr\Hook\Hook`:

```php
use SugarCraft\Vcr\Hook\Hook;

interface Hook
{
    public function process(string $kind, array $event): array;
}
```

`process()` receives the event kind and a mutable event array. Return the
(eventually transformed) array to continue; throw to suppress the event.

**Built-in hooks:**

| Hook | What it does |
|------|-------------|
| `SanitizingHook` | Removes keys or replaces patterns via regex |
| `MetadataHook` | Injects metadata into the first output event |

**Using hooks:**

```php
use SugarCraft\Vcr\Recorder;
use SugarCraft\Vcr\Hook\SanitizingHook;
use SugarCraft\Vcr\Hook\MetadataHook;

$recorder = Recorder::open('/tmp/session.cas')
    ->withHook(new SanitizingHook(
        removeKeys: ['API_KEY', 'SECRET_TOKEN'],
    ))
    ->withHook(new MetadataHook([
        'CI_RUN_ID' => getenv('GITHUB_RUN_ID'),
    ]));
```

### Msg serializers

`SugarCraft\Vcr\Msg\Registry` handles encoding/decoding of candy-core `Msg`
objects into the cassette `input` event envelope format. The default
registry ships with:

- **`BuiltinSerializer`** — handles 14 built-in candy-core Msgs: `KeyMsg`,
  `MouseClickMsg / MotionMsg / WheelMsg / ReleaseMsg`, `WindowSizeMsg`,
  `FocusMsg`, `BlurMsg`, `PasteStartMsg / EndMsg / Msg`,
  `BackgroundColorMsg`, `ForegroundColorMsg`, `CursorPositionMsg`.
- **`JsonableSerializer`** — catch-all for any `Msg` implementing
  `\JsonSerializable`. Tag is the FQCN; `data` is the
  `jsonSerialize()` result. Round-trip requires constructor parameter
  names to match `jsonSerialize()` keys.

**Registering a custom serializer:**

```php
use SugarCraft\Vcr\Msg\Registry;

$registry = Registry::default();
$registry->register(new MyCustomSerializer());
```

---

## Backend conventions

There are no pluggable backends in candy-vcr — recording always goes through
the candy-pty `PosixPump` with a `Recorder` tap. The PTY session is managed
by `candy-pty` internally.

---

## Testing your extension

Use `Recorder::filteredHostEnv()` directly in unit tests to verify your
regex or hook behaves correctly without spawning a PTY subprocess:

```php
use SugarCraft\Vcr\Cli\RecordCommand;

$env = RecordCommand::filteredHostEnv('/^(HOME|PATH)$/');
$this->assertSame(['HOME' => '/home/me', 'PATH' => '/usr/bin:/bin'], $env);
```

To test the full record → replay round trip, use `Player::play()` with a
shared `ProgramOptions` factory and either `ByteAssertion` (exact match) or
`ScreenAssertion` (cell-grid equality, tolerates ANSI reordering).

---

## Versioning policy

| Surface | Stability |
|---------|------------|
| `Recorder`, `Player`, `Cassette`, `Format\*` classes | Stable |
| `Hook\*` interfaces | Stable |
| `Msg\Registry`, `Msg\Serializer\*` | Stable |
| Internal `RecorderTee` (not in public namespace) | `@internal` |
| Cassette format `v` field | Only additive changes (new optional fields are backwards-compatible) |
