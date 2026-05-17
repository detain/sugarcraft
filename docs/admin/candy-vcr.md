# candy-vcr — Hub Admin Guide

`candy-vcr` records PTY sessions into JSONL cassette files. It runs as a
CLI tool (`vendor/bin/candy-vcr record`) and is suitable for capturing
session replays for bug repro, regression testing, and demo capture.

---

## Operational concerns

### What the process does

`candy-vcr record` spawns a fresh master/slave PTY, drops the host stdin
into raw mode, and runs the recorded command under a candy-pty byte pump with
a `Recorder` tee'd onto every stdin/master-output chunk. The cassette is
append-only and flushes after every event — a crash mid-recording does not
lose previously written events.

### Signals handled

| Signal | Behaviour |
|--------|-----------|
| `SIGTERM` / `SIGHUP` | `RecordCommand` restores the host TTY termios then re-raises with the default handler so the process exits with the expected status. |
| `SIGINT` | Reaches the recorded child when `--no-ctty` is NOT set. The recorder still finalises the cassette cleanly on the way out. |
| `SIGKILL` | **Cannot be intercepted.** A rescue marker file is dropped at `sys_get_temp_dir() / 'candy-vcr-rescue.<pid>'` containing the host TTY device path. If a hard kill leaves your terminal stuck in raw mode, run `stty sane < /dev/pts/X` using the path in that file. |

### Files written

| Path | When | Contents |
|------|------|----------|
| `session-<timestamp>.cas` (or `--output`) | on record start | Streaming JSONL cassette — header line + event lines |
| `sys_get_temp_dir() / candy-vcr-rescue.<pid>` | while recording | Rescue marker with TTY path + pid + start time |

The rescue marker file is cleaned up automatically on every clean exit
(including exceptions). It persists only if the process is hard-killed.

---

## Configuration

### Env vars

| Var | Effect |
|-----|--------|
| (none required) | All configuration is via CLI flags |

### CLI flags for `record`

| Flag | Description |
|------|-------------|
| `--output PATH` | Cassette file path (default: `session-<timestamp>.cas`) |
| `--cols N` / `--rows N` | Initial terminal dimensions (default: 80×24) |
| `--no-ctty` | Spawn without a controlling terminal — Ctrl+C will NOT reach the recorded child |
| `--shell` | Spawn `$SHELL -l` instead of a positional command |
| `--env` | Capture the host environment into the cassette header (opt-in; keys matching `/(SECRET\|TOKEN\|KEY\|PASSWORD\|API\|CRED\|AUTH\|PRIV)/i` are stripped by default) |
| `--env-regex=PATTERN` | Override the secret-stripping regex; implies `--env` |
| `--env-allow-secrets` | **DANGEROUS** — disable all secret-key filtering; cassette will contain credentials verbatim; only for fully isolated environments |
| `--idle-trim N` | Compress inter-event gaps longer than N seconds (asciinema-style) |

---

## Monitoring

- Cassettes written by `record` are valid JSONL — use `vendor/bin/candy-vcr inspect <path>` to sanity-check event counts and structure.
- `vendor/bin/candy-vcr stats <path>` prints event tallies by kind, total duration, and output byte counts.
- `vendor/bin/candy-vcr diff a.cas b.cas` compares two cassettes structurally.

---

## Failure modes

| Failure | Result |
|---------|--------|
| Recorded child exits non-zero | Cassette is finalised; exit code is propagated to the caller |
| `SIGKILL` on the parent | Host TTY may be left in raw mode — use the rescue marker to recover |
| Invalid `--env-regex` | `InvalidArgumentException` thrown before recording starts |
| `--cols` / `--rows` ≤ 0 | `InvalidArgumentException` thrown before recording starts |
| PHP fatal error during recording | `register_shutdown_function` fires `rescueRestore()` before PHP exits; cassette is flushed up to the last successful write |

---

## Backup / migration

Cassettes are self-contained JSONL files and can be committed to git,
backed up normally, or transferred between machines. The cassette format
version is stored in the `v` field of the header line. Use
`candy-vcr migrate <path>` to upgrade cassettes when the format version
changes.
