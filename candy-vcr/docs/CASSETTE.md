# Cassette Format

candy-vcr records PTY sessions as streaming JSONL (newline-delimited JSON).
Each line is a self-contained event. The format is append-only and
flushes after every event so a crash mid-recording does not lose the
cassette.

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
| `env` | `object` | Optional. Captured host environment, filtered through the secret regex. |

---

## Event Lines

Every event line contains at least `t` (seconds since cassette start, ms precision) and `k` (event kind).

### `output` — terminal output bytes

```jsonl
{"t":0.001,"k":"output","b":"\u001b[2J\u001b[H"}
```

| Field | Type | Description |
|-------|------|-------------|
| `t` | `float` | Compressed timestamp (seconds since cassette start). |
| `tRaw` | `float` | Present only when idle-trim has been applied after a prior event gap exceeded the threshold. Carries the original wallclock-relative timestamp. |
| `k` | `"output"` | Kind discriminant. |
| `b` | `string` | Raw output bytes written to the PTY master. ANSI escape sequences are included verbatim. |

### `input` — input bytes fed to the PTY slave

```jsonl
{"t":0.450,"k":"input","b":"j"}
```

| Field | Type | Description |
|-------|------|-------------|
| `t` | `float` | Timestamp. |
| `tRaw` | `float` | Present only on trimmed events (see above). |
| `k` | `"input"` | Kind discriminant. |
| `b` | `string` | Raw bytes written to the PTY slave (keystrokes). |

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

`windowSize` is a legacy alias for `resize` used by some upstream vcr
implementations. candy-vcr emits `resize` but accepts both when replaying.

### `quit` — session end

```jsonl
{"t":1.201,"k":"quit"}
```

| Field | Type | Description |
|-------|------|-------------|
| `t` | `float` | Timestamp of the final observed event + pump teardown. |
| `k` | `"quit"` | Kind discriminant. No additional payload. |

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
