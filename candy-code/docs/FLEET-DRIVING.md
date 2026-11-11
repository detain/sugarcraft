# Fleet driving — `candy-code -p` as a machine surface

This page is the playbook for driving candy-code **headlessly** from scripts and
orchestrators: one process per task, JSON envelopes on stdout, exit codes as
gate signals. It records what is safe to depend on today, what is advisory, and
where the two known honesty gaps are. Everything here was verified against the
live tree; the source cites point at the enforcing code.

## Canonical invocation

```sh
HOME=/tmp/fleet/home1 php /abs/path/to/candy-code/bin/candy-code \
    -p 'Summarize CHANGELOG.md in three bullets' \
    --output-format json \
    --root /tmp/fleet/sandbox1
```

Rules that make a fleet reproducible:

- **Absolute paths.** Invoke the real `bin/candy-code` file path, never a PATH
  lookup or a relative one — shards launched from different working directories
  must load the same code.
- **Isolated `HOME`.** All state (sessions, workflows, agents, skills) lives
  under `$HOME/.candy-code`. One `HOME` per isolation domain; `--config` moves
  only the config file, not the state directories under `HOME`.
- **`--root` sandbox.** The workspace the agent may touch is set with
  `--root` (src/Cli/ArgvParser.php:362); give every fleet lane its own.
- **Permissions.** Unattended lanes that must not stop at an approval prompt
  pass `--permission-mode` (src/Cli/ArgvParser.php:498). The mode decision is
  the permission system's, and it honors the flag — a stalled fleet is a
  permission-mode mistake, not a hang.

## The stdout envelope (`--output-format json`)

A json run writes exactly one JSON document to stdout, on every exit path.
Success shape (assembled at src/Cli/NonInteractive.php:2371):

| Key | Presence | Meaning |
| --- | --- | --- |
| `schema` | always | Envelope version; `1`. |
| `result` | always (success) | The final assistant text. |
| `refusals` | always (success doc) | Denials recorded during the run; `[]` when there were none. |
| `session_id` | when present | 16-hex id of the session that served the turn. |
| `usage` | when present | Token/cost record for the turn; fields declared at src/Usage.php:140-233 (`totalTokens`, `costUsd`, `inputTokens`, `outputTokens`, `cacheReadTokens`, `cacheCreationTokens`, `reasoningTokens`, `delegatedTokens`, `unpricedModel`, `webSearchRequests`). |
| `steps_truncated` / `length_stopped` / `loop_guard_stopped_by` | only when true | The stop-reason trio: a step budget cut, an output-length stop, or a loop-guard intervention. |
| `json_schema_applied` | only when `false` | Appears only when a schema was requested and the answer rode a tool result instead — the schema was not enforced on that turn. |

Error shape (src/Cli/NonInteractive.php:1587): `{"schema":1,"result":null,
"error":{"type":...,"message":...}}` with an optional `provider` key; here
`refusals` stays conditional — present only when denials were recorded
(src/Cli/NonInteractive.php:1608). A machine error document is only emitted
under json/stream-json output — plain-text mode dies with prose on stderr
instead (guard at src/Cli/NonInteractive.php:1598).
Provider failures surface as `error.type` `backend` with a raw English message
(connection refused, HTTP status) — not locale-tied, safe to pattern-match.

Drivers must treat **stdout as the only truth channel**. stderr carries launch
notices and advisories — e.g. the autoReview cost warning behind the
`autoReview` setting (src/Config/Settings/Definitions/PermissionSettings.php:71,
read at src/Cli/Bootstrap.php:7637) — and is *advisory*: never gate on it,
never parse it for the result. One probe run paid ~20.7k cold input tokens and
then cache reads on follow-ups; expect the first turn of a fresh model pairing
to be the expensive one.

## The `stream-json` envelope

`--output-format stream-json` emits NDJSON progress events
`{sessionId, type, ts, durable, data}` (thin writer at
src/Cli/StreamJsonEmitter.php:164) and then **the last line is the plain json
success document above** (src/Cli/NonInteractive.php:776). Event vocabulary
observed: `assistant.delta`, `reasoning.delta`, `tool.started`, `tool.progress`
(tail-bounded, 4096-byte budget), `tool.finished`. Gate on the final line only;
the preceding lines are a live tail, not the verdict.

## Exit-code battery (measured)

| Situation | Exit | stdout |
| --- | --- | --- |
| Turn completed | 0 | success envelope |
| Provider unreachable (dead baseUrl in a `--config` copy) | 1 | machine error doc, `error.type` `backend` |
| `session show` on an unknown target | 1 | prose on stderr, no envelope |
| Unknown flag (`--nosuchflag`) | 2 | nothing parseable — `candy-code: unrecognized option` on stderr |
| `--append-system-prompt` without `-p` | 2 | config refusal |
| Bare `--resume` without `-p` | 2 | config refusal |
| `--resume=` with empty value | 2 | config refusal |
| `--json-schema` pointing at a bad path | 2 | config refusal |

`EXIT_OK` / `EXIT_FAILURE` / `EXIT_CONFIG` are the three constants a driver
needs (src/Cli/NonInteractive.php:107, src/Cli/NonInteractive.php:146,
src/Cli/NonInteractive.php:186). `2` means your command line was wrong — fail
loud and fix the launcher; `1` means the run itself failed, and the envelope
tells you why.

Stdout cells above describe plain mode. Under `--output-format json` a machine
error document accompanies the 1 and 2 exits alongside the stderr prose —
`error.type` `usage` for the exit-2 cases above (including unrecognized
options), `not-found` for an unknown `session show` target.

## Driver flags queued for merge (weld-queued)

Collected from the driver-lane report; these describe the `wf/driver` branch.
Until that weld lands, both options exit 2 as unrecognized on shipped builds.

- `--session-id <16hex>` — pre-allocates the NEW one-shot session id so drivers
  can key artifacts before the run completes; refuses (exit 2) if the id is
  already stored or the value is not 16 lowercase hex; use `--resume` to reopen
  a stored session. Never beside `-c`/`--resume`/`--incognito`/a subcommand or
  without a prompt.
- `--input-format stream-json` — each piped `{"type":"user","content":…}`
  NDJSON line becomes its own user turn before the `-p` prompt; a malformed
  JSON line exits 2 naming the line number; unusable shapes are counted and
  named once on stderr while the run continues; the 10 MiB stdin cap counts raw
  bytes pre-parse; requires a prompt; default `text` keeps the raw-prepend path.

## Follow-up turns and lineage

- `--resume=<session_id>` continues the same conversation and keeps the same
  session id (flag plumbing at src/Cli/ArgvParser.php:153 and :921-925). A measured
  follow-up turn finished in ~1.7 s against the same `session_id`.
- Post-hoc lineage: `session show <id> --output-format json` returns the full
  record — including `parent_id` and `parent_call_id`, so delegated sub-agent
  sessions are reconstructible after the fact. Verb roster at
  src/Cli/Subcommands.php:934; `list` flags at src/Cli/Subcommands.php:1011
  (`--all`, `--archived`, `--children`, `--limit`).
- Cost rollups across a fleet: `session import` exists for migrating stores
  (src/Cli/Subcommands.php:1018), and the usage surface answers
  `--output-format json` with a machine rollup shaped `scopeAll` / `windowDays`
  / `days` — the shape is built at src/Cli/Subcommands.php:1568 and served
  through the host-command facts helper at src/Cli/Subcommands.php:1587.

## Structured answers: `--json-schema`

Pass `--json-schema /abs/path/schema.json` (src/Cli/ArgvParser.php:653) and a
schema-capable provider enforces it at request level. The local sglang provider
sends `response_format.json_schema` (capability probe at
src/Providers/SglangProvider.php:1087); the measured result came back as
`result` containing the schema-shaped JSON **stringified inside the string** —
drivers decode twice. When the answer follows a tool result the provider may
skip enforcement; that is exactly the case the `json_schema_applied: false`
key flags, so always check it before trusting the shape.

## Piped stdin is context, not input

Running `-p` with stdin closed or empty is the normal shape. Piping bytes to
stdin prepends them to the prompt as context — bounded by a 10 MB cap
(src/Cli/NonInteractive.php:193) and a two-second first-byte grace
(src/Cli/NonInteractive.php:215). It is **not** an envelope channel: never feed
JSON *into* the process expecting a request protocol, and never leave stdin
open in a wrapper that has nothing to send — the grace window stalls the turn
instead.

## Fan-out safety

Parallel `-p` invocations against the **same** `HOME` are safe: measured two
concurrent runs both exited 0 with distinct `session_id`s, zero lock errors,
and both hit the shared prompt cache (~20544 cache reads each). Scale as N
plain processes — that is the batch mode. Two hygiene rules per lane: its own
`--root`, and (if lanes write state you care about) its own `HOME` only when
you actually want isolation; identical `HOME` for identical cache warmth.

## What does *not* exist (don't improvise flags)

Measured absent: `--allowedTools` / `--disallowedTools` (tool narrowing is the
`permissionRules` config surface), `--session-id` on shipped builds (ids are
minted, or supplied to `--resume`; a pre-allocation variant is weld-queued
under "Driver flags queued for merge" above), `--values` flag (params ride the native verb: `candy-code workflow run
--param k=v`; the top-level `workflow` subcommand itself exists since W-1 —
run, status, resume; pause and list stay `/workflow` slash commands), and any `--auto-review` flag (`autoReview` is a
config key only — src/Config/LayeredSettings.php:589). The saved-workflow DSL
lives at `~/.candy-code/workflows/<name>.php`; pause records live under
`.running/` beside them.

## Headless workflow execution: the native verb, and the model-mediated path

Driving a saved workflow through `-p '/workflow run <name>'` is **not** a
machine pathway today. The headless model receives the request and
re-translates the PHP DSL into its own `WorkflowTool` YAML plan — in the
measured probe it read the engine source and rebuilt a plan of its own
choosing. The verification machinery that makes workflows trustworthy —
`withVerification` verdict scanning, the `ESCALATE:` stop-for-human rule,
`outputSchema` enforcement, `reject()` retry routing — may therefore **never
run**. The escalated probe run exited 0 with "completed successfully". Until a
native headless workflow verb lands, do **not** gate a fleet on the exit codes
or prose of headless workflow runs: drive the stages yourself as N parallel
`-p` lanes (Task-style invocations) with file-protocol handshakes, and keep
`/workflow` for interactive use. W-1 has since landed the machine pathway:
`candy-code workflow run <name>` executes the saved workflow through the
engine directly — verification machinery, `ESCALATE:` scanning and
`outputSchema` enforcement included — with gateable exit codes (0/1/2/3,
exit 3 = stopped-for-a-person; see docs/WORKFLOWS.md "Running from the CLI
(headless)"). The `-p '/workflow'` route above remains model-mediated by
design.

## Killing workflow runs: the native verb pauses resumably; the model path does not

A `SIGTERM` (or `SIGINT`) delivered to the MODEL-MEDIATED path — the
`Workflow` tool reached through `-p '/workflow run <name>'` — exits 143 with
an **empty stdout and no pause file**. The root cause is on the `WorkflowTool`
path, which calls the engine's non-interactive entry
`WorkflowEngine::runWorkflow()` with the interruptibility argument pinned off;
`installInterruptHandlers()` runs only on interruptible paths, so the process
dies on the raw signal. That is by design: the tool executes inside a forked
turn whose cancellation kills the child tree wholesale.

The native verb is different. `candy-code workflow run` calls
`WorkflowEngine::run()`, which takes the interruptible default: a signal
writes the cooperative pause record first, and the verb's pause observer
(`setSignalPauseHandler()`) emits the machine document before exiting 3, so
`candy-code workflow resume <id>` has a resume point. The interactive
`/workflow run` path likewise pause-writes and keeps the raw 130/143 exit.

## Driver checklist

1. Absolute bin path, per-lane `HOME` policy, per-lane `--root`.
2. `--output-format json`; parse stdout **only**; expect one document always.
3. Gate on exit code: `0` trust envelope; `1` read `error.type`; `2` fix the
   launcher. Check `json_schema_applied` when you asked for a schema. For
   `candy-code workflow`, `3` = stopped for a person (escalated verification
   or signal pause — resume with `candy-code workflow resume <id>`).
4. `session show --output-format json` and the usage rollup for post-hoc
   lineage and spend.
5. Fan out with plain processes; no batch-mode flag exists.
6. For saved-workflow fan-out prefer the native `candy-code workflow run` verb
   over hand-rolled `-p` lanes; signal-stopping it leaves a resume point
   (`candy-code workflow resume <id>`). The model-mediated `-p '/workflow'`
   route still has no resume point — budget time there.
