# candy-vcr — Developer Guide

`candy-vcr` provides extension points for custom recording behaviour,
sanitisation, metadata injection, and custom message serialisation.

See also the operational counterpart: [Hub Admin Guide](../admin/candy-vcr.md).

---

## Extension points

### `Format` interface

The `Format` interface (`SugarCraft\Vcr\Format\Format`) is the primary
extension point for cassette serialization:

```php
use SugarCraft\Vcr\Cassette;

// The real SugarCraft\Vcr\Format\Format interface, spelled out:
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

### `Player` interface

`Player` drives a fresh `Program` through a recorded cassette:

```php
use SugarCraft\Vcr\Player;

$player = Player::open('/tmp/session.cas');
$result = $player
    ->withIdleTrim(0.5)         // clamp pauses > 500ms to 500ms in SPEED_REALTIME
    ->play(
        programFactory: fn($in, $out, $loop) => new Program($model, $opts),
        speed: Player::SPEED_REALTIME,
    );
```

> **Path note:** `/tmp/session.cas` is illustrative. Long-running recorders
> should write cassettes to a durable, private directory: `systemd-tmpfiles`
> (enabled by default on most distros) reaps stale `/tmp` entries mid-run,
> and a fixed world-visible temp name collides between concurrent users.
> Cassettes are written `0600` by `Recorder::open()` regardless of umask.

`withIdleTrim(?float $seconds)` is a fluent setter — it returns a new
`Player` instance with the idle-gap threshold set. Pass `null` to disable
trimming. The threshold can also be passed directly to `play()` via
`$idleThresholdSeconds`; the explicit parameter wins over the fluent setting.

### Hook system

Hooks intercept and transform events during recording. They implement
`SugarCraft\Vcr\Hook\Hook`:

```php
use SugarCraft\Vcr\Event;

// The real SugarCraft\Vcr\Hook\Hook interface, spelled out:
interface Hook
{
    /** Called before an event is written to the cassette. */
    public function beforeSave(Event $event): ?Event;

    /** Called after an event has been written (fire-and-forget). */
    public function afterCapture(Event $event): void;
}
```

`beforeSave()` returns a (potentially modified) `Event` to continue, or
`null` to skip writing that event entirely. `afterCapture()` is
fire-and-forget — throwing there does not affect the recording.

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

`SugarCraft\Vcr\Msg\Registry` handles encoding/decoding of sugar-core `Msg`
objects into the cassette `input` event envelope format. The default
registry ships with:

- **`BuiltinSerializer`** — handles 14 built-in sugar-core Msgs: `KeyMsg`,
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
the sugar-pty `PosixPump` with a `Recorder` tap. The PTY session is managed
by `sugar-pty` internally.

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
