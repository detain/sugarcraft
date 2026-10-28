# Schedules

A schedule is one prompt that repeats: a name, a cadence, and the task. candy-code
runs rows two ways, and the difference matters more than it looks — one kind is a
file every program on your machine reads, the other is a private arrangement
between you and the window you typed it in.

| | **File row** | **`/loop` row** |
|---|---|---|
| Created by | the `Schedule` tool, or you editing `schedules.json` by hand | `/loop 30m <prompt>` in a running session |
| Lives in | `~/.candy-code/schedules.json` | this process's memory only |
| Runs | as a supervised background session (a `schedule:<name>` daemon) | as a queued turn **in that window** |
| Belongs to | the project named by its `root` field | the session that made it, and dies with it |
| Survives a restart | yes | no |

Rows are evaluated by the same pumps that poll background sessions — the TUI's
pump and `candy-code serve`'s background events. There is no daemon, no timer per
row and no cron anywhere: a tick asks the roster which rows are due.

---

## The file

`~/.candy-code/schedules.json`, written `0600`, one JSON document:

```json
{
  "version": 1,
  "schedules": [
    {
      "name": "nightly-tests",
      "every": 86400,
      "prompt": "Run the full test suite and summarise any failure.",
      "root": "/home/me/projects/sugarcraft",
      "permissionMode": "accept-edits",
      "enabled": true
    }
  ]
}
```

`version` is checked, not negotiated: a document that declares a version this
build does not write refuses to load, and says so in `/schedules` rather than
half-trusting the rows.

A hand-edited file is picked up on the next pump — the roster reloads whenever the
file's bytes actually change, so a row you add while the program is open fires on
its own schedule without a restart. The pump has two cadences: anything live or
enabled keeps the fast two-second tick, and a file-backed roster with nothing live
and nothing enabled rides a slow thirty-second watch, so even a fully-disabled
file revives itself from a hand-edit within half a minute of waking — never a
relaunch.

## The row

| Field | Required | Meaning |
|---|---|---|
| `name` | yes | Identity, and the stem of the row's lock file. Lowercase letter or digit first, then lowercase letters, digits, `.`, `_`, `-`; 64 characters at most. No slash, no colon, no leading dot — the grammar is what makes path traversal impossible rather than merely unlikely. |
| `every` | yes | Cadence in whole seconds: from 10 up to 31,622,400 (366 days). Sub-second repetition is a rate-limit incident waiting to happen. |
| `prompt` | yes | The task the daemon runs. Never empty, never re-split on whitespace — a multi-line prompt stays multi-line. |
| `root` | **yes, to fire** | The project directory the row belongs to. See [The project binding](#the-project-binding). |
| `provider` | no | Pin the provider for this row's runs. Absent means whatever the host session's roster resolves. |
| `permissionMode` | no | One of the [permission modes](PERMISSIONS.md) (`default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`) — minus the two the ceiling refuses: a row that SPELLS `dont-ask` or `bypass-permissions` is skipped by name, because a schedule fires unattended and those modes answer a permission ask with no human in the loop. Absent — or spelled unrecognisably — means the floor; see [The permission floor](#the-permission-floor). |
| `nextDue` | no | Unix seconds at which the row next becomes due. A row without it is armed one interval out, so a file you write at midnight does not fire immediately at midnight. |
| `enabled` | no | `false` parks the row without deleting it. Defaults to `true`. |
| `timeoutSeconds` | no | Wall-clock ceiling on one fired run, 60 up to 31,622,400 seconds. Absent means the background session's default of 3600. This bounds the daemon's whole run; no timeout anywhere on the schedule path bounds a model request. |
| `createdBy` | no | The creation tag (C-AGT-5.a). The only spelling a row may carry is `model`, written by the `Schedule` tool; absence means the row arrived by a human hand. The tool's delete guard reads the tag and the binding: it removes its own rows from this project freely, and refuses an untagged row — or its own row bound to another project root — unless the call opts in. A forged or misspelled tag is refused at load by name — the field exists precisely to police that forgery. |
| `kind` | no | `task` or `heartbeat` (C-AGT-5.c). Absent means `task`: every settled result is announced, as always. A `heartbeat` row watches instead of answers — its fired prompt carries one appended instruction, and the single reply `HEARTBEAT_OK` means silence: no announcement turn, no ring. Anything else it settles with is announced AND rings the `notify` setting. Any other spelling is refused by name, at create and at load. See [The heartbeat kind](#the-heartbeat-kind). |

An unknown key is tolerated; a malformed known key is not. A row that fails to
parse is skipped **by name** while its siblings keep running, and the refusal is
recorded as a note — one bad line in a hand-edited file never bricks the whole
roster, and never crashes a launch.

## The `heartbeat` kind

A `task` row answers: every fired run settles into an announcement turn,
whatever it produced. A `heartbeat` row watches: the fired prompt is the
recorded one with one instruction appended — when nothing needs attention,
reply exactly `HEARTBEAT_OK` — and the pump reads the answer back. A run that
settles completed with exactly that word found nothing, and it adds nothing:
no announcement turn is queued and nothing rings; the ordinary status
transition still reads in the transcript like any other session's. Any other
settled answer — text, or a run that failed, stopped, or timed out before it
could answer — means the check wants a human: the result is announced like any
other background answer, and the terminal rings through the `notify` setting
(`off`, `bell`, `osc9` — `docs/SETTINGS.md`), the same end-of-turn channel the
turn notification already uses, gated the same way: no ring while a turn is
mid-flight, because that turn's own end-notify already speaks for it.

The kind is only a row field; firing, locking, the permission floor, the mode
ceiling, and the cap do not read it. And nothing here moves a timeout: a
heartbeat run is bounded by the same `timeoutSeconds` wall-clock ceiling as a
task run, and a run that times out answers nothing — that silence is a settled
failure, which the pump announces and rings rather than misreading as "nothing
to report". On a `serve` window the transcript pump does not run: the row
fires into its host as always and its answer arrives as an ordinary
`bg.completed` wire row (`docs/SERVER.md`) — the heartbeat is not a second
protocol. The `Schedule` tool's `kind` parameter and
`/schedules add … [--heartbeat]` both write it; the row carries it from there.

This is unrelated to the liveness heartbeat inside a running background
session (stall detection while output is silent). The words collide; the
clocks do not.

## The project binding

**One `schedules.json` is read by every candy-code on your machine**: every TUI in
every checkout, and every `serve`. A row that does not say which project it is
about would run in whichever host happened to wake up first — a schedule you wrote
while working on one repository would find itself firing its prompt against
whatever another window had open.

So a persisted row carries `root`, and it is the only directory that row fires in:

- **A row bound to this project** fires here, in a daemon whose working directory
  is that root.
- **A row bound to a different directory** is left completely alone by this host:
  it claims no lock and moves no clock, so the project that owns the row keeps its
  schedule exactly as it wrote it. Nothing about it is announced in the window
  that skipped it — a row that is not yours is not your noise.
- **A row with no `root`** (written before the field existed, or written by hand
  without one) belongs to nobody. It loads, `/schedules` lists it as `unbound`,
  and no host fires it — ever. Its note tells you the fix:
  `schedule 'nightly-tests' is not bound to a project; add "root" to the row — it
  will not fire.`
- **A `root` that does not exist** is refused the way any untrustworthy value is:
  the row is skipped by name, the note names the missing directory, and its
  siblings keep running.

`/schedules` shows the binding on every line, so the answer to "whose is this?" is
never guesswork:

```
[on] (file) nightly-tests — every 1d, next 2026-10-28 03:00:00 @ /home/me/projects/sugarcraft: Run the full test suite…
[on] (file) old-clean — every 6h, next 2026-10-27 18:40:00 @ unbound: Tidy the artifacts directory…
[on] (file) weekly-audit — every 1w, next 2026-11-01 09:00:00 @ /home/me/projects/sugarcraft: Audit the dependency tree… — last: skipped: another host holds the lock
```

### If a row stopped firing

1. `/schedules` — is the row listed at all, and what does it say after `@`?
2. `unbound` or a load note? Add (or correct) `root` on that row.
3. Bound to the right directory but you never see it in *this* window? Then it is
   the other window's row: open the project it names, or re-point `root`.
4. Bound and due but the `last:` suffix keeps saying `skipped`? Another host
   holds its run: `/bg list` shows the `schedule:<name>` daemon that is still
   up, and the row resumes once it settles. The suffix records what this
   process's pump actually decided — fired, an error, or which refusal — so
   the answer is on the line, not in the log.

## The permission floor

A scheduled run records its permission mode **when the row is created** and rides
that recording on every later fire. Escalating `/permissions` afterwards cannot
widen a cron that was written narrower, and relaxing it cannot widen a row that
spelled its own mode.

A row that names no mode — or an unknown spelling — lands on `dont-ask`, the
non-interactive floor: read-only tools run, and anything that would need a human
answer is refused loudly by the daemon's headless approver instead of blocking on
a question nobody is there to read. If your row spelled a mode this build does not
recognise, it still loads and fires on the floor, and `/schedules` announces the
substitution beside the row by name. The floor never rewrites what you asked for
in silence.

The floor, however, is the ceiling too. A file row that SPELLS a bypass-class mode
— `bypass-permissions`, or the floor's own name `dont-ask` — is REFUSED at load:
skipped by name, with the reason in the notes, exactly the posture the webhook
loader takes (`SCHEDULES`-rows fire unattended, and those two modes answer a
permission ask without a human; `--allow-bypass` is a promise about sessions a
person drives, not about clocks). The distinction is trust, not capability: the
floor is granted by the build's code to rows that say nothing, while a spelled
mode would be granted by the file — and an edit to a file on disk, however it got
there, is not a human answering a prompt. Omit `permissionMode` to get the floor.
Rows the tool writes serialise the floor as a null `permissionMode` for the same
reason: the file never carries a bypass-class spelling out of a healthy build.

## Locks, and why the same row fires once

Before a host fires a persisted row it claims that row's own lock under
`~/.candy-code/schedules/<name>.lock`. Two hosts that both find the row due at the
same instant get these outcomes:

- the **winner** advances the clock, persists, and spawns;
- the **loser** skips **without advancing** — the winner's write lands and a reload
  picks up the new `nextDue` on the next tick;
- a row whose previous run is still up is skipped and its clock *is* moved, so a
  wedged daemon cannot pile up a backlog of fires;
- a row that turned out stale under the claim (deleted, disabled, already fired by
  a peer, or re-bound to another project in the meantime) is skipped without
  touching the clock.

Every write to the file — an enable, a delete, a clock move — takes the shared
`.file` lock first and re-bases only the row it touches, so a busy host's clock
handling cannot roll back an edit another host just made.

Missed slots collapse. A host that was down for a week fires **once** when it
comes back and re-arms for the next live slot; the number of runs is bounded by
the number of due slots and never by a timer on the firing path, and a slow
daemon cannot make a row fire twice. What does bound a fire is the ordinary
background-session ceiling on the daemon itself: 3600 seconds unless the row
raises its own with `timeoutSeconds` — a wall-clock cap on the whole run, never
a timeout on any individual model request.

## The `Schedule` tool

The model can create, list and delete rows too — through the `Schedule` tool
(Ask class, position 41), which is what `schedule:` daemons actually need: a
turn that finishes before its next interval should not exist, so there is
nothing here to keep a clock. The tool writes PERSISTED, ROOT-BOUND rows —
the same file, the same lock, the same grammar this page describes — and every
host then reloads by digest, so a row made in one window fires in whichever
window owns the project it names.

The bounds are deliberate, and they exist because the caller is a model that
may itself have been talked into something by a prompt it read:

- **Every call asks.** `Ask` permission class: each create, delete — even a
  `list` — appears to the human before it runs. A schedule is a promise that
  outlives the turn, and the approval is where that promise is looked at.
- **The mode ceiling.** A created row's permission mode is settled at
  creation to the stricter of what the model asked for and what the session
  itself runs under — and never to a bypass-class spelling: naming
  `bypass-permissions` or `dont-ask` in `permission_mode` is refused by name,
  and omitting it lands on the read-only `dont-ask` floor the code grants. A
  scheduled run therefore answers its own permission asks with refusals unless
  a human deliberately spelled something looser at the create approval.
- **The row cap.** At most 8 model-created (`createdBy: "model"`) rows per
  project root. Operator rows never count against it.
- **The kind.** `create` may spell `kind`: `task` (also the absent default) or
  `heartbeat`, whose silence contract is spelled by
  [The heartbeat kind](#the-heartbeat-kind). An unknown spelling is refused by
  name.
- **The delete guard.** The tool removes rows it created for *this project*; an
  operator-written row (untagged) — or a model row bound to another project root,
  `unbound` included — is refused unless the call spells `include_operator_rows:
  true` — a sentence the Ask approval shows verbatim, so forcing it is the
  asking, not a bypass. `/schedules delete <name>` remains the human surface,
  root-scoping free for a human who names the row.
- **No self-widening.** A fired run executes under its recorded floor, where
  anything requiring a human answer is refused — so the clock cannot mint
  another clock or escalate its own mode.

`docs/PERMISSIONS.md` covers what each mode admits; nothing about the tool
changes the floor, the ceiling, or the lock discipline described above.

## `/schedules`

```
/schedules            # same as list
/schedules list
/schedules add <name> <interval> <prompt> [--mode <mode>] [--provider <name>] [--heartbeat]
/schedules enable <name>
/schedules disable <name>
/schedules delete <name>
```

`add` (C-AGT-5.b) goes through the `Schedule` tool's own factory — the same
bounds, ceiling, mode-settling, per-project model cap and creation tag — so the
command and the tool cannot drift into two grammars. The interval reads like
`/loop`'s (`60`, `90s`, `15m`, `2h`, `1d`), the prompt is everything that follows
minus any `--mode`/`--provider`/`--heartbeat` flag, and the row lands bound to
this project's root with its first fire one interval out. `--heartbeat` takes no
value: it stamps the created row's kind, exactly as the tool's `kind` parameter
does. Rows may of course still arrive by
writing the file: the config-authority posture governs hand-written rows, the
factory governs created ones. `list` reads this process's roster and the one
`0600` file and changes nothing, so it works in a read-only window; adding,
enabling, disabling and deleting persist consent-bearing rows that later turns
will act on, so they do not. Enable, disable and delete write the file back
before answering, so the change survives the session; on a `/loop` row they
touch only memory and say so.

Once a row has been evaluated by this process's pump, `list` also appends its
last fire outcome — `fired`, an error, or which refusal stopped it (`skipped:
bound to another host`, `skipped: the previous run is still going`, and the
like). The outcome lives in memory only: it is this host's answer to "why isn't
it firing here?", and a fresh process lists the row bare again until its own
pump speaks for it.

## `/loop` on a served session

In `candy-code serve` a `/loop` row means the same thing per session: the row
remembers the id of the session that registered it, and the server's pump calls
every open host at most once a second, firing only the rows stamped with its own
id — a browser tab never runs another tab's loop, and rows carry no stamp the
serve side ignores. The prompt queues as a follow-up exactly as in the TUI: it
becomes the session's next turn when idle and waits behind a running turn when
not. Closing a session — or the hub evicting its host — drops its rows, so a
served loop dies with the session that promised it. The rate rides the pump's
existing clock, never a timer per row, here either.

## See also

- [PERMISSIONS.md](PERMISSIONS.md) — what each mode allows, and the headless
  approver behind the floor.
- [COMMANDS.md](COMMANDS.md) — `/loop`, `/schedules`, `/bg` argument-for-argument.
- [SERVER.md](SERVER.md) — running `serve`, which evaluates the same rows as the
  TUI.
- [WORKFLOWS.md](WORKFLOWS.md) — one repeatable multi-stage run, as opposed to one
  prompt that repeats.
