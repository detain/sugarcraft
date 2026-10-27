# Schedules

A schedule is one prompt that repeats: a name, a cadence, and the task. candy-code
runs rows two ways, and the difference matters more than it looks — one kind is a
file every program on your machine reads, the other is a private arrangement
between you and the window you typed it in.

| | **File row** | **`/loop` row** |
|---|---|---|
| Created by | you, editing `schedules.json` | `/loop 30m <prompt>` in a running session |
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

A hand-edited file is picked up on the next tick — the roster reloads whenever the
file's bytes actually change, so a row you add while the program is open fires on
its own schedule without a restart.

## The row

| Field | Required | Meaning |
|---|---|---|
| `name` | yes | Identity, and the stem of the row's lock file. Lowercase letter or digit first, then lowercase letters, digits, `.`, `_`, `-`; 64 characters at most. No slash, no colon, no leading dot — the grammar is what makes path traversal impossible rather than merely unlikely. |
| `every` | yes | Cadence in whole seconds: from 10 up to 31,622,400 (366 days). Sub-second repetition is a rate-limit incident waiting to happen. |
| `prompt` | yes | The task the daemon runs. Never empty, never re-split on whitespace — a multi-line prompt stays multi-line. |
| `root` | **yes, to fire** | The project directory the row belongs to. See [The project binding](#the-project-binding). |
| `provider` | no | Pin the provider for this row's runs. Absent means whatever the host session's roster resolves. |
| `permissionMode` | no | One of the [permission modes](PERMISSIONS.md) (`default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`). Absent — or spelled unrecognisably — means the floor; see [The permission floor](#the-permission-floor). |
| `nextDue` | no | Unix seconds at which the row next becomes due. A row without it is armed one interval out, so a file you write at midnight does not fire immediately at midnight. |
| `enabled` | no | `false` parks the row without deleting it. Defaults to `true`. |

An unknown key is tolerated; a malformed known key is not. A row that fails to
parse is skipped **by name** while its siblings keep running, and the refusal is
recorded as a note — one bad line in a hand-edited file never bricks the whole
roster, and never crashes a launch.

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
```

### If a row stopped firing

1. `/schedules` — is the row listed at all, and what does it say after `@`?
2. `unbound` or a load note? Add (or correct) `root` on that row.
3. Bound to the right directory but you never see it in *this* window? Then it is
   the other window's row: open the project it names, or re-point `root`.
4. Bound and due but skipped silently every tick? Another host holds its run:
   `/bg list` shows the `schedule:<name>` daemon that is still up, and the row
   resumes once it settles.

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
the number of due slots, never by a timeout, and a slow daemon cannot make a row
fire twice.

## `/schedules`

```
/schedules            # same as list
/schedules list
/schedules enable <name>
/schedules disable <name>
/schedules delete <name>
```

There is deliberately no `add` verb: rows arrive by writing the file, the same
config-authority posture the user-scope `mcp.json` takes, rather than a command
parser re-inventing JSON grammar. `list` reads this process's roster and the one
`0600` file and changes nothing, so it works in a read-only window; the mutating
verbs do not, because they persist consent-bearing rows that later turns will act
on. Enable, disable and delete write the file back before answering, so the change
survives the session; on a `/loop` row they touch only memory and say so.

## See also

- [PERMISSIONS.md](PERMISSIONS.md) — what each mode allows, and the headless
  approver behind the floor.
- [COMMANDS.md](COMMANDS.md) — `/loop`, `/schedules`, `/bg` argument-for-argument.
- [SERVER.md](SERVER.md) — running `serve`, which evaluates the same rows as the
  TUI.
- [WORKFLOWS.md](WORKFLOWS.md) — one repeatable multi-stage run, as opposed to one
  prompt that repeats.
