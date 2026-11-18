# Background work

candy-code runs work in the background in two different ways. They use different
machinery and answer to different commands, so check which one you need:

| | Background **shells** | Background **sessions** |
|---|---|---|
| What it is | One shell command, running detached while the agent carries on | A separate candy-code process that runs a task to completion |
| Started by | The model, with `Bash` and `run_in_background: true` | You, with `/bg <task>` |
| Read or stop with | `ShellOutput`, `ShellSend`, `ShellStop` (model); `/shells`, `/tasks` (you) | `/bg stop <id>`; `/tasks` lists them |
| Lives until | Its command exits, the 30-minute lifetime wall, or the launch ends | The task finishes or you stop it |
| Ownership | Rows are tagged with the session that started them | Tracked by the background supervisor |

`PtyRun` is neither. It drives a terminal program in the foreground, step by step,
and returns when its script ends. It is covered in the tool catalog, not on this page.

## Background shells

### Start one

The model starts a shell by calling `Bash` with `run_in_background: true`. The call
returns at once with a `shell_id` such as `sh-1a2b3c4d`, and the command keeps running.

```text
Bash(command: "npm run dev", run_in_background: true, lifetime_seconds: 3600)
  -> shell_id sh-1a2b3c4d
```

- `lifetime_seconds` sets how long the shell may live before it and its whole process
  group are killed. The default is 1800 (30 minutes). The value is clamped to 60–21600
  seconds. A shell cannot outlive its wall. To keep one longer, restart it with a
  larger value.
- Add `interactive: true` to start a live terminal (80×24) that can be typed into.
  The model then drives it with `ShellSend`.
- Each launch tracks at most 64 shell rows, settled ones included. A `run_in_background`
  call beyond that is refused with the cap in its error text. The cap counts table
  rows, not live processes, so a settled shell still uses a slot for this launch.

### Read, type, stop

| Tool | Slash form | What it does |
|---|---|---|
| `ShellOutput` | `/shells output <id> [cursor]` | Returns the output written since `cursor`. Each answer ends with a `[cursor: <stdout>.<stderr>]` token. Pass that token back to read only the new bytes |
| `ShellSend` | `/shells send <id> <keys…>` | Types keys into an interactive shell. The keys use the same grammar as `PtyRun` (`<Enter>`, `<C-c>`, `<Up>`, `\xHH`). The decoded bytes are capped at 4096 |
| `ShellStop` | `/shells stop <id>` | Stops the shell. It sends TERM to the process group, waits 2 seconds, then sends KILL |
| — | `/shells` or `/shells list` | Lists the shells this session can see |
| — | `/tasks`, `/tasks tail <id>`, `/tasks stop <id>` | One list of background shells, background sessions and sub-agent runs. `tail` and `stop` work on shells only |

Reads are bounded. One `ShellOutput` returns 64 KiB by default and at most 256 KiB
(`max_bytes` tunes this within that range). When a fresh read finds more output than
the budget, it returns the newest bytes and says how many older bytes it skipped.
Once you thread a cursor, the read is pagination. Every byte is delivered, in order,
across as many calls as it takes.

Output goes to files, not pipes. Each shell's stdout and stderr are capped at 64 MiB
per stream. When a stream reaches the cap, its recorder is stopped. The shell's exit
code then reads 153 when the writer itself carried the signal, and `ShellOutput`
reports `output limit exceeded` from the size of the file either way.

### Who can see a shell

A shell is tagged with the session that started it. Reads and stops from another
session get the same answer as an unknown id, so one session cannot list or stop
another's shells. Shells started before any session was bound are visible to every
session. `/shells` and `/tasks` show your session's shells and the untagged ones.

### Where the files live

Shell output is written to `~/.candy-code/shells/<session>/`. The directory is
created with mode 0700, and each file is 0600. Each shell has an `<id>.exit` file,
written last, which marks the shell as settled. Files for shells the launch no
longer tracks are removed once they have been unchanged for 24 hours.

### When the launch ends

When candy-code exits, every shell that is still running is killed with its process
group. This is the guarantee in `BackgroundShellRegistry::teardown()`. It does not
depend on the parent process staying alive: the wrapper runs the command under
`timeout -s KILL` where the host supports it, so the kernel enforces the lifetime
even after a hard kill of candy-code itself.

A shell started where a process group cannot be created (no `setsid`, and no
`ext-posix` with `ext-pcntl`, or on Windows) is recorded as not grouped. A stop then
signals only the wrapper's own process, and the stop answer says so. Child processes
the command started can outlive it on those hosts.

## Background sessions

`/bg <task>` hands a task to the background supervisor. The task runs in its own
candy-code process, and the prompt comes back to you immediately. The answer,
which is either a session id or the reason none could be started, arrives as a
separate line when the process is up.

```text
/bg run the test suite and summarize the failures
/bg stop <session-id>
```

- `/bg stop` takes exactly one token that the supervisor knows, or one shaped like its
  ids. Anything else after `/bg` is read as a task. A bare `/bg stop` prints usage and
  the ids that are active.
- A background session has no one at its terminal to answer a permission prompt. A
  call that needs approval is refused at the daemon's console approver, unless your
  permission rules already allow it. Set those rules before you start the session.
- `/fork` shares the spawn path, and `/bg` has no argument-less form. An empty `/bg`
  prints usage. It does not background the live conversation.
- The supervisor keeps each session's socket, buffer and secret token in a per-process
  directory under the system temporary directory, created 0700. Its heartbeats mark a
  session that has stopped responding as stalled.

## Related

- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): a shell that will not start, or one that
  reports `output limit exceeded`.
- [PERMISSIONS.md](PERMISSIONS.md): the rules a background session runs under.
- [GC.md](GC.md): reclaiming disk from sessions, separate from the shell files above.
- [ENVIRONMENT.md](ENVIRONMENT.md): `CANDY_CODE_BACKGROUND` sets the terminal colour
  background. It has nothing to do with background work.
