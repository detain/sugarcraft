# Cassette Format

candy-vcr records PTY sessions as streaming JSONL (newline-delimited JSON).
Each line is a self-contained event. The format is append-only and
flushes after every event so a crash mid-recording does not lose the
cassette.

> **⚠ Security.** A cassette is *data*: replaying one never executes a
> program. The `.tape` files that cassettes are compiled from are not —
> `Set Shell`, `render-tape --shell`, and `--exec` render a tape by
> spawning a real PTY shell that runs every typed line. Treat third-party
> `.tape` files the way you treat a Makefile or an npm `preinstall`
> script. See the README's exec-mode section.

---

## Header (line 1)

```jsonl
{"v":1,"created":"2026-05-07T10:00:00Z","cols":80,"rows":24,"runtime":"sugarcraft/candy-vcr@record"}
```

| Field | Type | Description |
|-------|------|-------------|
| `v` | `int` | Cassette format version. Current: `1`. |
| `created` | `string` | ISO-8601 / RFC3339 UTC timestamp of recording start. |
| `cols` | `int` | Initial terminal columns. Overridden by the first `resize` event if present. |
| `rows` | `int` | Initial terminal rows. Overridden by the first `resize` event if present. |
| `runtime` | `string` | Identifier of the recording runtime — e.g. `sugarcraft/candy-vcr@record`. |
| `timestampMode` | `string` | Optional. `absolute` (default) or `relative` (interval-since-previous-event, asciinema v3 style). |
| `env` | `object` | Optional. Captured host environment, filtered through the secret regex (`/(SECRET\|TOKEN\|KEY\|PASSWORD\|API\|CRED\|AUTH\|PRIV)/i` by default). `record --env-all` captures the full host environment unfiltered — cassette files are written `0600` for precisely this reason. |
| `typingSpeed` | `number` | Optional. Milliseconds between typed keystrokes, carried from a `.tape`'s `Set TypingSpeed`. Absent on cassettes recorded from a live PTY. |
| `theme` | `string` | Optional. VHS theme name carried from a `.tape`'s `Set Theme`. Absent on cassettes recorded from a live PTY. |

`created` is the wire key; the PHP DTO property is
`CassetteHeader::$createdAt`. The remaining header fields on a
tape-compiled cassette (`playbackSpeed`, `fontSize`, `fontFamily`,
`widthPx`, `heightPx`) live on the DTO in memory but are not written to
line 1. On the live-recording path, `cols`/`rows` are read from the PTY
winsize at the moment recording starts.

---

## Event Lines

The full set of kinds is the `EventKind` enum: `output`, `input`,
`resize`, `quit`, `snapshot`, `hide`, `show`.

Every event line contains at least one timestamp and `k` (event kind).
Absolute-mode cassettes carry `t` (seconds since cassette start, ms
precision); relative-mode cassettes (`"timestampMode":"relative"`,
asciinema v3 style) carry `dt` (interval since the previous event)
instead:

```jsonl
{"dt":0.001,"k":"output","b":"\u001b[2J\u001b[H"}
```

`Player::open()` content-sniffs the file and selects the `RelativeFormat`
decoder when it sees `dt`; the extension is never consulted.

### `output` — terminal output bytes

```jsonl
{"t":0.001,"k":"output","b":"\u001b[2J\u001b[H"}
```

| Field | Type | Description |
|-------|------|-------------|
| `t` | `float` | Compressed timestamp (seconds since cassette start). |
| `tRaw` | `float` | Present only when idle-trim has been applied after a prior event gap exceeded the threshold. Carries the original wallclock-relative timestamp. |
| `k` | `"output"` | Kind discriminant. |
| `b` | `string` | Output written to the PTY master. ANSI escape sequences survive intact (they are valid ASCII and JSON-escaped); the payload is JSON-encoded with `JSON_INVALID_UTF8_SUBSTITUTE`, so well-formed UTF-8 round-trips byte-exact but invalid bytes land as U+FFFD — lossy for 8-bit streams by design (the Recorder defers torn multibyte tails; base64 transport was declined). |

### `input` — input bytes fed to the PTY slave

```jsonl
{"t":0.450,"k":"input","b":"j"}
```

An input event may instead carry a structured `msg` envelope — a decoded
Msg object — in place of raw `b` bytes:

```jsonl
{"t":0.450,"k":"input","msg":{"@type":"KeyMsg","key":"j"}}
```

| Field | Type | Description |
|-------|------|-------------|
| `t` | `float` | Timestamp. |
| `tRaw` | `float` | Present only on trimmed events (see above). |
| `k` | `"input"` | Kind discriminant. |
| `b` | `string` | Raw bytes written to the PTY slave (keystrokes). Present when no `msg` is. |
| `msg` | `object` | Serialized Msg envelope (`@type` names the class). The Player decodes it through `Msg\Registry` and feeds the typed object to the program. Emitted by session-host pipelines; the `.tape` Compiler emits only `b`. |

### `resize` — terminal size change

```jsonl
{"t":0.000,"k":"resize","cols":80,"rows":24}
```

| Field | Type | Description |
|-------|------|-------------|
| `t` | `float` | Timestamp. |
| `k` | `"resize"` | Kind discriminant. |
| `cols` | `int` | New column count. |
| `rows` | `int` | New row count. |

### `windowSize` — alias for `resize`

`windowSize` is a legacy alias for `resize` used by some other recorder
implementations. candy-vcr emits and replays `resize`.

### `quit` — session end

```jsonl
{"t":1.201,"k":"quit"}
```

| Field | Type | Description |
|-------|------|-------------|
| `t` | `float` | Timestamp of the final observed event + pump teardown. |
| `k` | `"quit"` | Kind discriminant. No additional payload. |

### `snapshot` — screenshot directive

```jsonl
{"t":2.500,"k":"snapshot","path":"frame-001.png"}
```

| Field | Type | Description |
|-------|------|-------------|
| `t` | `float` | Timestamp. |
| `k` | `"snapshot"` | Kind discriminant. |
| `path` | `string` | Output path requested by the `.tape`'s `Screenshot` directive. Replay pauses the program at this event and captures the frame; rendering to GIF uses the path for per-frame emission. |

### `hide` / `show` — timing markers

```jsonl
{"t":3.000,"k":"hide"}
{"t":3.400,"k":"show"}
```

Kind discriminants `hide` and `show` carry no payload. They mirror the
`.tape` `Hide`/`Show` directives: during GIF rendering, output produced
between a `hide` and its `show` is skipped from the frame sequence.

---

## Dual-Timestamp (`t` + `tRaw`) Format

candy-vcr uses a **compressed timeline** by default: `t` is the number of
seconds since cassette start, squashed to millisecond precision. When
`--idle-trim N` is active, any inter-event gap that exceeds `N` seconds
is reduced to `min(N, 0.5)` seconds, and the events following the gap carry
a second timestamp field:

| Field | Meaning |
|-------|---------|
| `t` | Compressed (timeline-shifted) timestamp used by default replay. |
| `tRaw` | Original wallclock-relative timestamp. Present only on events that follow a trimmed idle gap. |

Replay with `--no-trim` (CLI) or `useRawTimestamps: true` (PHP API) to
honour `tRaw` and restore the real inter-event cadence. This is useful
for demos and for reproducing race conditions where timing matters.

**Example — trimmed 30-second idle gap:**

```jsonl
{"t":0.000,"k":"output","b":"pre\r\n"}
{"t":0.500,"k":"output","b":"post\r\n","tRaw":30.234}
{"t":0.515,"k":"quit","tRaw":30.249}
```

- The 30.234-second gap was compressed to 0.500 seconds.
- `tRaw` allows a replay to expand the gap back to the real elapsed time.

---

## Complete Example

```jsonl
{"v":1,"created":"2026-05-07T10:00:00Z","cols":80,"rows":24,"runtime":"sugarcraft/candy-vcr@record","env":{"HOME":"/home/me","LANG":"en_US.UTF-8","PATH":"/usr/bin:/bin","TERM":"xterm-256color"}}
{"t":0.000,"k":"resize","cols":80,"rows":24}
{"t":0.001,"k":"output","b":"\u001b[?1049h\u001b[2J\u001b[H$ "}
{"t":0.200,"k":"input","b":"e"}
{"t":0.201,"k":"output","b":"e"}
{"t":0.500,"k":"output","b":"c\n"}
{"t":1.201,"k":"quit"}
```

Line 1: header with env captured.
Lines 2–7: events with `t` timestamps.
The `output` byte `\x1b[?1049h` is the alternate-screen enter (CSI ?1049h);
the matching `\x1b[?1049l` appears when the program exits alternate screen.
