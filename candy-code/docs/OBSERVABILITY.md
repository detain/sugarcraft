# Observability: OpenTelemetry export

candy-code can send a trace of each agent turn to an OpenTelemetry collector over
OTLP/HTTP. Export is **off unless you set `OTEL_EXPORTER_OTLP_ENDPOINT`**. Nothing
in this page is enabled by default, and no setting in `config.json` turns it on.
The variables are read from the process environment only, so a launch can be
pointed at a collector without touching anyone's configuration files.

This page covers what is sent, what is never sent, how failures behave, and how
to see whether export is working. The variable reference is in
[ENVIRONMENT.md](ENVIRONMENT.md#opentelemetry-export-variables).

## Turn it on

```sh
export OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
export OTEL_EXPORTER_OTLP_HEADERS='Authorization=Bearer abc123'   # optional
candy-code
```

The endpoint is the collector's base URL. candy-code posts to
`<endpoint>/v1/traces`, so a trailing slash is harmless. Setting the variable to an
empty or whitespace value leaves export off. Presence is the only switch.

`OTEL_EXPORTER_OTLP_HEADERS` takes comma-separated `key=value` pairs, sent verbatim
on every export POST. Pairs without `=` or with an empty key are dropped. Header
values can carry credentials, so candy-code never prints the endpoint or the headers
in its diagnostics.

The exporter is written by hand on the Guzzle client candy-code already uses. It does
not depend on any `open-telemetry/*` package.

Three paths export the same events:

- the interactive TUI,
- the headless `-p` run,
- `candy-code serve`, where each served session gets its own sink.

## What is sent

Each model step becomes one `chat.turn` span. The step's `StepStarted` event opens it,
and its `UsageUpdated` event (or a `SpendCapBreached`) closes it. A closed span
carries:

- token totals: input, output, cache-read and cache-creation tokens, under the
  `gen_ai.usage.*` attribute names,
- the cost of the step,
- tool activity, as identifiers and counters,
- the model name. A model with no price entry is recorded under
  `candy_code.model.unpriced`, so a dashboard can tell a free model from a missing price.

Every safety verdict also rides the span as a timestamped span event named
`candy_code.safety_event`:

- a **denial** of a tool call (`decision=deny`), with the denial kind and the tool,
- an **ask** for permission (`decision=ask`), with the tool, source and mode,
- a **rejection** (`decision=reject`, answered "no") or a **cancel**
  (`decision=cancel`, nobody answered).

An approval, or a tool call the rules allowed, emits no safety event.

The encoder's rule is that identifiers, counters, durations, token totals and costs
are exported. Payloads are not.

## What never leaves the machine

The encoder is written to keep these out of every attribute and event:

- prompts and reasoning text,
- tool arguments and tool output tails,
- media frames,
- task descriptions.

Character counts of streamed text are exported where they help size activity, because
a count reveals no content. The reason text of a denial is never exported, since it can
quote the refused command. Only the denial's kind token and the first word of its fixed
prefix go out.

The export is therefore a record of what the agent did and what it cost. It is not a
record of what the agent read or wrote. The endpoint still learns the timing, the model
names, the tool names and the token counts, so point it only at a collector you trust.

## Timeouts and batching

| Setting | Default | Range | Meaning |
|---|---|---|---|
| `CANDY_CODE_OTEL_TIMEOUT` | `3.0` s | positive | Allowance for one export POST. Non-numeric, zero and negative values fall back to the default |
| `CANDY_CODE_OTEL_BATCH_SIZE` | `64` | 1–2048 | Events buffered before an export. Integers outside the range are clamped; non-integer values read as the default |

Batched exports go out without blocking. They run on the TUI's event loop through
`React\Http\Browser`, and the timeout on that path is at most one second. A slow or
unreachable collector therefore does not stall key handling. The shutdown flush is the
one synchronous export, because the process may exit before the loop runs again.

## When the collector refuses or is down

- A batch the endpoint refuses is kept and retried. The TUI checks the retry queue
  every 15 seconds, and only while bodies are waiting. A healthy stream does no
  extra I/O on those ticks.
- The retry queue holds at most one batch. When it is full, the oldest body is
  evicted and counted as lost.
- A batch that cannot be encoded is dropped and counted. It is not queued, because
  encoding closes the spans, and the raw events cannot be replayed.
- The first failure prints one line to stderr that names `OTEL_EXPORTER_OTLP_ENDPOINT`.
  Later failures are counted, and a summary is printed once at shutdown.
- At shutdown, the flush re-posts any body still awaiting a response. If the collector
  stored a body but the response was lost, that body arrives twice. Telemetry accepts
  a duplicate over a silent loss.

None of these failures stops the agent. Telemetry is best effort by design.

## Check that it is working

```sh
candy-code doctor
```

`doctor` has an `otlp export` row:

| Status | When |
|---|---|
| `OK` | Export is off (`event export is off`), or it is on and this process has recorded no failures |
| `WARN` | Losses were recorded: bodies refused, batches that failed to encode, bodies evicted from the retry queue, or bodies undelivered at shutdown |

A fresh `candy-code doctor` always starts its counts at zero, because it is a new
process. Inside a session, `/doctor` reports that session's losses. A `WARN` with
refusals usually means the endpoint or its headers are wrong.

## Related

- [ENVIRONMENT.md](ENVIRONMENT.md#opentelemetry-export-variables): the variable table.
- [SERVER.md](SERVER.md): how `serve` builds its per-session sinks.
- [SECURITY.md](SECURITY.md): the trust boundaries the export crosses.
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): symptom-first recovery.
