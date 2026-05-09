# candy-vcr Caliber Learnings

Accumulated patterns and gotchas for this library.

---

## Cassette format is JSONL, not JSON

Each line is a self-contained JSON document. The first line is the
header (`{"v":1, ...}`); subsequent lines are events keyed by `k`
(`resize`, `input`, `output`, `quit`). This lets a recorder flush
each event as it happens without needing to rewrite a closing `]`,
and lets a player stream the file rather than load it whole.

Trailing newlines are tolerated. Empty lines between events are
ignored. Lines past the header that don't parse as JSON throw —
silent skip would mask cassette corruption.

## `Event::payload` is an associative array, not a typed value object

Each `EventKind` carries different fields (`resize` → cols+rows,
`output` → bytes, `input` → msg envelope, `quit` → empty). Encoding
those as separate classes would multiply the surface area for what
boils down to a JSON map. PR3 introduces `MsgSerializer` for the
`input` payload's `msg` envelope; until then, payload is opaque.

## `end()` and other by-reference array functions don't work on readonly properties

`end($this->events)` errors with "Cannot modify readonly property" because
`end` takes its argument by reference (it advances the internal array
pointer). Same trap for `reset`, `next`, `prev`, `array_pop`, `array_shift`,
`sort`, etc. on a `public readonly array $events`. Use index access
(`$this->events[count($this->events) - 1]`) or `array_key_last()` instead.
The error file:line points at the line that calls the by-ref function,
not at any assignment, which can be confusing.

## Msg serializers use unqualified class names as `@type` for builtins

The `BuiltinSerializer` tags candy-core Msg classes by their unqualified
name (`KeyMsg`, `WindowSizeMsg`, etc.) rather than FQCN. Cassettes stay
readable, and the cassette format spec in `plans/x-vcr.md` shows this
form. `JsonableSerializer` (the catch-all for user Msgs) DOES use FQCN
to avoid collisions across user namespaces; the two coexist because
`canDecode()` for the builtin checks against an explicit allowlist via
`classForTag()`, so it never accidentally claims a user FQCN.

## JsonableSerializer round-trips via named-arg constructor expansion

User Msgs implementing `\JsonSerializable` get encoded as
`{"@type": "App\\\\Foo\\\\MyMsg", "data": {…}}` where `data` is the
`jsonSerialize()` result. Decoding does `new $class(...$data)` which
relies on PHP's named-arg unpacking — the `data` array's string keys
must match constructor parameter names. The common case
`__construct(public readonly string $foo, …)` paired with
`jsonSerialize(): ['foo' => $this->foo, …]` round-trips with no extra
plumbing. Classes that need a different shape register a dedicated
serializer ahead of the catch-all.

## Test fixtures shared between test files need their own files

PSR-4 autoload requires one class per file with name = basename. Putting
`UserJsonableMsg` inside `JsonableSerializerTest.php` works ONLY because
that file gets `require`'d when PHPUnit instantiates the test class —
side-effect-loading the fixture classes too. But if another test
(`RegistryTest.php`) references the fixture and runs first in
isolation, autoload fails. Move shared fixtures into their own
PSR-4-conformant files (`tests/Msg/UserJsonableMsg.php`) so they're
discoverable on demand.

## Recorder is an interface in candy-core, impl in candy-vcr

To avoid candy-core depending on candy-vcr (it's the other way around),
candy-core ships a `SugarCraft\Core\Recorder` interface that
`SugarCraft\Vcr\Recorder` implements. `Program::withRecorder()` accepts
the interface. Other consumers — debug tap, network mirror, crash bundle
— can implement it and slot into the same hook without pulling cassette
plumbing.

## record* methods no-op after close, never throw

`Program` calls `recordQuit()` then `close()` from inside `dispatch(QuitMsg)`,
but `teardownTerminal()` runs AFTER that and still emits output bytes
(unicode-off, alt-screen-leave, etc.). Each of those goes through
`writeOutput → recorder->recordOutput`. To keep the cassette clean
(ending on the `quit` line, not on environmental teardown noise), all
record* methods short-circuit when the recorder is closed. Throwing
would force every call site to guard, which defeats the tee point.

## `t` is rounded to 3 decimals (ms) on write

Floating-point seconds with sub-microsecond precision serialize as
`0.000123456789012` and lose to round-off on read. JsonlFormat
rounds to 3 places on write — matches the plan's "ms precision" and
keeps cassettes diff-friendly.
