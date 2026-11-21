# Usage, spend, and the cap

candy-code keeps three different views of what the model cost you. They answer
different questions, and they can disagree on purpose:

| View | Where it lives | Answers | Survives a restart |
|---|---|---|---|
| The **ledger** | `usage_records` in the session database | What every launch has cost, per day and model | Yes |
| The **session tracker** | Memory, one per launch | What this launch has spent so far, and what the spend cap sees | No |
| The **report** | `/insights`, or the `insights-<date>.html` file it writes | Activity and spend across the store, over a window | Yes, the file is kept |

The ledger is the record. The tracker is what the cap is checked against while a
launch runs. They are both written from the same provider-reported numbers, but
the tracker starts at zero each launch, so a total from `/budget` will not match
a total from `/usage` once you have restarted.

## The ledger: `candy-code usage`

Every settled provider call appends a row to `usage_records`. The row holds the
session, the time, the model, the token counts (total, input, output, cache read,
cache creation and reasoning where the provider reports them) and the cost in
USD.

```sh
candy-code usage                       # every session, the whole ledger
candy-code usage 7                     # the last seven whole UTC days
candy-code usage 30 --session my-work  # one session, the last thirty days
candy-code usage all --output-format json
```

- The window is `7`, `30` or `all`. `all` is the default.
- `--session <target>` narrows the report to one session. The target is an id,
  a name, or a unique id prefix. An ambiguous or unknown target is refused.
- `--output-format json` returns the same figures as one contract document.
- `usage` only reads the store. It does not prune sessions, so asking for a
  report never deletes anything.

The figures are provider-reported totals, folded by day and by model. They are
not a measurement from the launch you are looking at.

### Lower bounds

A model with no price on file still records its tokens. Its calls bill as
`$0.00` lower bounds, not as free calls, and any figure that includes them is
marked with `≥`. A total marked `≥` is never presented as exact.

To price a model, declare it under `modelPrices` in `~/.candy-code/config.json`,
with USD per million tokens under the keys `input` and `output`. Spend totals
and the spend cap are under-counted for as long as the model has no price.

## In the session: `/usage`

`/usage` shows the same ledger inside the TUI:

| Command | Shows |
|---|---|
| `/usage` | This session's rows |
| `/usage all` | Every session the ledger holds |
| `/usage 7` or `/usage 30` | The last seven or thirty whole UTC days, this session |
| `/usage 30 all` | The same window across every session |
| `/usage sources` | The same ledger folded by the source of each settle |

## The session tracker: `/budget`

`/budget` shows where this launch stands against the spend cap:

```text
/budget          # spend so far this launch, and the cap if one is set
/budget 5        # set this session's cap to $5
/budget off      # clear this session's cap
```

- The tracker counts what this launch has reported. It resets on restart. Use
  `/usage` for the durable total.
- A session that has used an unpriced model shows its spend with `≥`.
- With no usage reported yet, the line says "not reported" rather than `$0`.
  That is an honest answer for an offline run or a provider that sends no usage
  block.
- The cap can be set at launch with `--max-cost <amount>` or the environment
  variable `CANDY_CODE_MAX_COST`. `/budget` changes it for the current session.
- On a `candy-code serve` session, `/budget <amount>` and `/budget off` are
  refused. The cap of a hosted server is set when the server starts and is
  shared by every session on it, so one client cannot move it for the others.
  The bare `/budget` still answers.

Once the cap is reached, a new prompt is refused and the reply says the spend
cap is reached. `/budget` shows the cap, and `/budget <amount>` raises it.

## Reports: `/insights`

`/insights [days]` builds a report across the sessions in the durable store for
the last N whole UTC days, today included. The default is 30.

It prints the report in the transcript and writes the same figures to
`insights-<UTC date>.html` in `~/.candy-code/exports/`. The file is a single
self-contained page with no external assets. Its permissions are `0600`, the
same as `/share` output. The report covers:

- sessions and turns per day,
- spend per day and per model,
- the busiest projects,
- the split between session kinds, and how many sessions carry lower-bound spend.

If the file cannot be written, the report still prints, and the reply says why
no file landed.

## Choosing the right view

- **What did last week cost?** `candy-code usage 7`, or `/usage 7 all` inside a session.
- **Why did this run stop?** `/budget` shows the cap and what this launch has
  spent. A `≥` means an unpriced model is under-counting the total.
- **Which model is expensive?** `/insights` (its spend-by-model section) or
  `usage ... --output-format json` for a script.
- **Set a hard limit for an unattended run:** `--max-cost` at launch. See
  [CI.md](CI.md) for the GitHub Action's own `max-cost` input.

## See also

- [SETTINGS.md](SETTINGS.md): `modelPrices` and the spend-related keys
- [ENVIRONMENT.md](ENVIRONMENT.md): `CANDY_CODE_MAX_COST`
- [COMMANDS.md](COMMANDS.md): every slash command, argument by argument
- [GC.md](GC.md): reclaiming the storage the ledger's sessions use
- [OBSERVABILITY.md](OBSERVABILITY.md): exporting the same events to a collector
