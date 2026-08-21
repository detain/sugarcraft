# Hooks

A hook is a shell command SugarCrush runs at a lifecycle point, whose **exit
code is a verdict**. It is the escape hatch for a rule a permission mode cannot
express: "ask me before anything touches production", "block any `Edit` under
`vendor/`", "rewrite this tool's arguments".

Hooks come from two YAML files. One of them is in your home directory and is
loaded on trust; the other arrives with a repository and is not.

---

## The two files

| File | Loaded |
|---|---|
| `~/.sugar-crush/hooks.yaml` | always |
| `<root>/.sugar-crush/hooks.yaml` | only if this root is listed under `trustedProjectHooks` |

Honouring a project hook file means running shell this repository's author
wrote, every time you open it. So it is opt-in per project root:

```json
{ "trustedProjectHooks": ["/home/you/src/myproject"] }
```

The refusal is **not** a silent drop — it prints one stderr line naming the
canonical path to add, at most once per path per process. (Once, because an
interactive launch builds two hook managers: `Chat`'s own chain and the engine
backend's. A notice you meet twice a run for doing nothing wrong is a notice you
learn to scroll past.)

The two candidates are de-duplicated **by real path**, because they are not
always two files: run `sugarcrush` in your own home directory and both name
`~/.sugar-crush/hooks.yaml`. Loading it twice would trip the
already-registered guard and kill the launch over a collision that does not
exist. See [`PERMISSIONS.md`](PERMISSIONS.md#the-four-trustedproject-keys) for
the properties this trust key shares with the other three.

---

## The file format

```yaml
hooks:
  PreToolUse:
    - name: confirm-deploy            # optional; defaults to `command`
      matcher: '^Bash$'
      command: ./hooks/confirm-deploy.sh
      description: Ask before anything touches production
      disabled: false                 # optional; true keeps it out of the chain
      timeout: 30                     # optional seconds; default 60
  PostToolUse:
    - matcher: 'Read|Write/Edit'
      command: ./hooks/log-touch.sh
```

Those six are the **only** keys an entry may carry. An unrecognised key is
refused rather than ignored.

`name` matters because `HookRegistry` keys hooks by name: without it, two
entries sharing a command on one event silently collapse into a single
registration.

### Absence is silent; everything else is loud

This is a security surface, so every "we could not use what you wrote" case
**stops the launch with exit 2** rather than degrading to a shorter hook chain.
That covers a YAML syntax error, an unknown event name, an uncompilable
matcher, and an unrecognised entry key. It used to do the opposite — all three
came back as "no hooks", with nothing printed anywhere.

A guard silently missing from the chain is the one failure mode a guard must not
have, and it is invisible exactly when it matters: the tool call the hook
existed to stop is the one that runs.

Only a file that is **not there** is a no-op. A path that exists and is *not a
readable regular file* — a directory, a dangling symlink — throws, because "not
a hook file" is not the same as "no hook file". And a path with an
unsearchable ancestor directory also refuses the launch: whether hooks are
configured there is *unknowable*, and reading unknowable as absence would run a
shorter guard chain and say nothing.

### `matcher:` delimiters

The matcher is a PCRE fragment, matched case-insensitively against the tool
name. You write the pattern **without delimiters**; `HookConfig::pattern()`
picks the first of `/ # ~ % ! @ ; : | + =` that your pattern does not contain.

That is why `matcher: 'Read|Write/Edit'` works. Under a fixed `/` delimiter it
compiled to `/Read|Write/Edit/i`, whose delimiter closes at the slash — a valid
regex that made `bin/sugarcrush` exit 2 over a slash. Picking an absent
delimiter is both simpler and safer than escaping, which has to reason about
already-escaped `\/` and gets it wrong at the edges.

One definition serves the config's own validation **and** both matchers
(`HookRegistry::matcherMatches()` and `HookDispatcher::matcherMatches()`), since
a pattern validated under one delimiter and matched under another is a hook that
loads and never fires.

### A loaded hook may only add to the chain

`HookRegistry::register()` keys by event + name and overwrites, so an entry that
reuses a registered hook's name **on that hook's own event** would uninstall it —
a config file switching a guard off by naming it. `HookManager::loadEntries()`
refuses that. It also makes the outcome independent of load order: a project file
cannot disarm a hook you wrote in your home directory by reusing its name, in
either registration order.

**The name to avoid is the one `name()` returns, not the class name.** This trips
people up because two of the three built-ins are not named after their file:

| Class | `name()` returns | `event()` returns |
|---|---|---|
| `BuiltIn\ProtectFilesHook` | `protect-files` | `PreToolUse` |
| `BuiltIn\ConfirmRemoveHook` | **`confirm-rm`** | `PreToolUse` |
| `BuiltIn\AuditHook` | `audit` | **`PostToolUse`** |

So `name: confirm-remove` is **accepted** — it collides with nothing, and a hook
file using it gets a second, additional hook rather than the refusal its author
probably expected. `name: confirm-rm` on `PreToolUse` is the entry that is
refused.

And because the key is event **plus** name, the same name can be free on one
event and taken on another. Measured on this tree, with `registerBuiltIns()`
already run:

| `name:` | on `event: PreToolUse` | on `event: PostToolUse` |
|---|---|---|
| `protect-files` | refused | accepted |
| `confirm-rm` | refused | accepted |
| `confirm-remove` | accepted | accepted |
| `audit` | **accepted** | refused |

`audit` is the row worth reading twice: `AuditHook` is a `PostToolUse` hook, so a
`PreToolUse` entry called `audit` does not displace it and is not refused.

---

## Events

`src/Hooks/HookEvent.php` defines eleven:

| Event | Fires |
|---|---|
| `PreToolUse` | before a tool runs — the one that can stop it |
| `PostToolUse` | after a tool ran, in provider order |
| `Stop` | the agent is about to stop |
| `SubagentStop` | a sub-agent is about to stop |
| `SessionStart` / `SessionEnd` | session lifecycle |
| `UserPromptSubmit` | you submitted a prompt |
| `PreCompact` | before history compaction |
| `TeammateIdle` | a teammate went idle |
| `TaskCreated` / `TaskCompleted` | task lifecycle |

What a **block** (exit 2) does depends on the event, because for some of them
the action has already happened:

- `PreToolUse`, `Stop`, `TaskCreated` — stops the action outright, and stderr
  goes back to the agent.
- `PostToolUse`, `SubagentStop`, `TaskCompleted` — too late to stop; surfaces
  through `continueOnBlock`.
- `PreCompact`, `SessionStart` — stderr reaches **you only**; there is no agent
  action to feed it to.
- `UserPromptSubmit` — discards the prompt entirely; nothing goes to the agent.

### One caveat on `PostToolUse` under concurrency

When `Runtime` dispatches a same-turn batch of parallel-safe tool calls, every
member of the group is **forked before any of them reaches `PostToolUse`**. So a
hook here that mutates shared state — writes a file, touches a database — no
longer has that mutation observable by a later sibling in the same group, the way
it would under sequential dispatch. The number of invocations and their order are
unchanged; only the interleave point moved.

The safety rule that keeps concurrency invisible in tool *results* covers tools.
It cannot cover a hook body you wrote. Set
`SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS=1` if your hooks depend on the
sequential interleave.

---

## The exit-code contract

`src/Hooks/ScriptHook.php`:

`ScriptHook::execute()` resolves the exit code with a four-arm `match`: `0`,
`3`, `4`, and `default`. (No line number here on purpose — the one that used to
be printed, `line 181`, had drifted by more than a hundred lines by the time
anybody checked it.)

| Exit | Verdict | stdout means |
|---|---|---|
| `0` | **allow** | the result message — *on `ScriptHook` only; see below* |
| `3` | **ask** | the question put to the user, clipped at 16 KiB |
| `4` | **modify** | a JSON **object** replacing the tool's arguments, **refused** over its ceiling rather than clipped |
| `1`, `2`, or **any** other non-zero | **deny** | — (stderr is the reason, clipped at 16 KiB; stdout is discarded) |

**"stdout becomes the result message" is true of `ScriptHook` and false of the
live path, for exit 0.** `HookRegistry::executeHooks()` ends
`return $modified ?? $inertRewrite ?? HookResult::allow();`, rebuilding a
permitting verdict with an **empty** message, and both live gates interpolate
`$hookResult->message` only into `"Hook denied: …"`. Measured: a hook printing
200,000 bytes and exiting 0 yields a 0-byte message at
`HookManager::preToolUse()`. An exit-0 hook's stdout is for *your* debugging, not
for the model — if you want the model to read something, deny or ask.

**How much a hook may say, per exit code.** Measured through
`HookManager::preToolUse()` with a 200,000-byte payload:

| Exit | What is bounded | Bound | Over the bound |
|---|---|---|---|
| `0` | nothing reaches the model | — | — |
| `3` | the question | 16,384 bytes | clipped, with a marker naming both figures |
| `4` | the rewrite (`modifiedInput`) | the larger of 16,384 bytes and the byte length of the arguments it replaces | **denied**, naming the size and the ceiling |
| `1`/`2`/other | the deny reason | 16,384 bytes | clipped, with a marker |

A rewrite is **refused, never truncated**, and the two are not
interchangeable: a truncated rewrite is invalid JSON, `rewrittenArgs()` reports
that as null, and every consumer then runs the **original** arguments — the one
outcome a rewriting hook definitely did not ask for. The ceiling tracks the call
because a sanitiser that edits the `file_path` of a 300 KB `Write` has to print
the body back; a flat cap would break that outright.

**Do not read `1` and `2` as two different denials.** They land on the same
`default =>` arm and produce the same `HookResult::deny()`. Measured, running one
`ScriptHook` per code: `1`, `2`, `5` and `7` all come back with
`permitsExecution() === false` and a message that differs only in the digit of
the `"Hook exited with code N"` fallback — and even that difference disappears
the moment the hook writes anything to stderr, since the arm is
`$errors ?: "Hook exited with code $exitCode"`.

`HookDispatcher` does carry a *notion* of a non-blocking deny, keyed off an
`[exit-1]` message prefix. It is not reachable from here. Its own docblock
(`HookDispatcher.php` lines 32-39) records that **no shipped `HookInterface`
implementation emits that prefix** — not `ScriptHook`, not any `BuiltIn/*Hook` —
so everything that fails to permit execution resolves to exit code 2. A
`hooks.yaml` hook cannot select the non-blocking path, and it should not try to
by printing the marker itself — see the first bullet below.

`0`/`3`/`4` are numbered the way they are because `0`/`1`/`2` were already
`HookDispatcher`'s tested contract; ask and modify took `3` and `4` rather than
`2` and `3`. Quietly redefining `2` would have turned every existing "exit 2 to
block" hook into a prompt.

Notes that matter in practice:

- **Do not try to reach the non-blocking path by printing `[exit-1]` yourself.**
  Only the dispatcher path strips that prefix. The two live gates
  (`Runtime::gate()` and `Chat::gateToolCall()`) quote the deny message verbatim
  into the model's tool result, so a hook that emitted the marker would leak it
  into the transcript rather than being treated as non-blocking.
- **Exit 3 with no stdout** falls back to the hook's `description`, then to a
  generic question. A prompt with an empty body is unanswerable, and defaulting
  to allow or deny would make silence mean something the hook never said.
- **Exit 4 whose stdout is not a JSON object is downgraded to a DENY**, never to
  an allow: a hook that meant to rewrite dangerous arguments and failed must not
  have the *original* arguments run in its place. A JSON list or scalar is
  rejected too. The test is on the opening brace of the text, not on
  `array_is_list()` of the decoded value, because PHP's decoder throws away
  exactly the distinction being made — `{}` (run with no arguments, a deliberate
  rewrite) and `[]` (a positional array the consumers cannot use) both decode to
  `[]`.
- **A hook that could not run denies.** `proc_open()` refuses a `cwd` that does
  not exist, so a mistyped `--root` used to turn a *denying* hook into an allow.
  The hook now inherits the process directory rather than not running.

### Environment handed to the script

`ScriptHook::execute()` builds the `$env` array it hands `proc_open()` from
**eight** `CRUSH_*` keys — six, on the one run where the temp directory will not
take a file, which is covered below — and it **replaces** the environment rather
than adding to it:

```
CRUSH_SESSION_ID       CRUSH_TOOL_NAME   CRUSH_TOOL_INPUT
CRUSH_TOOL_OUTPUT      CRUSH_MODEL       CRUSH_PROVIDER
CRUSH_TOOL_INPUT_FILE  CRUSH_TOOL_OUTPUT_FILE
```

#### The two `_FILE` variables, and why they exist

**Linux caps one environment entry at 131,072 bytes** (`MAX_ARG_STRLEN`, for
`NAME=VALUE\0` together) and fails the whole `execve()` with `E2BIG` past that.
While `CRUSH_TOOL_INPUT` was the only route the payload had, that kernel limit
was a limit on **the tool call**: with any script hook registered, a `Write`
whose JSON arguments exceeded ~128 KB could not run at all. Measured against a
hook whose entire script is `exit(0)`: 131,054 bytes of value allowed, 131,055
denied, 200,000 and 1,000,000 denied identically — with a refusal reading
`Hook <name> could not be executed`, which names neither the size nor the cause
and reads as *"your hook is broken"*. `CRUSH_TOOL_OUTPUT` behaved the same.

So the payload now travels **both** ways:

- `CRUSH_TOOL_INPUT_FILE` and `CRUSH_TOOL_OUTPUT_FILE` point at a `0600` temp
  file holding the **complete** bytes, and are set on every run in which such a
  file could be created — which is every run short of an unwritable temp
  directory, and in *that* case an oversize payload is a fail-closed deny rather
  than a hook that silently sees nothing. The files are deleted as soon as the
  hook exits: read them, do not stash the path.
- `CRUSH_TOOL_INPUT` / `CRUSH_TOOL_OUTPUT` are **unchanged whenever the value
  fits**, which is every call that works today. Nothing already written breaks.
- When the value does **not** fit, the variable carries a marker instead:

  ```
  @@CRUSH_PAYLOAD_IN_FILE@@ 200011 bytes; read $CRUSH_TOOL_INPUT_FILE
  ```

  Not a prefix of the JSON — truncated JSON is not smaller JSON, and a hook that
  decodes it leniently judges a call that does not exist. Not empty either, since
  an absent `CRUSH_*` already means "empty" here.

**If your hook inspects arguments, read the file, not the variable.** A hook that
reads only `CRUSH_TOOL_INPUT` will now *run* on an oversize call and see the
marker where it used to see arguments — whereas before, the call was denied
outright. For a guard that keys on the argument text, that is a change in the
permissive direction, over calls that previously could not happen at all.
`CRUSH_TOOL_NAME` and your `matcher:` are unaffected.

```sh
# portable, and correct at every size
input="$(cat "$CRUSH_TOOL_INPUT_FILE")"
```

One limit is **not** modelled: platforms that cap the whole environment rather
than one entry (macOS: 256 KiB for argv and environ together). A payload pair
that passes every per-entry check can still be refused there — but the refusal
now prints both payload sizes rather than saying only that the hook could not
be executed.

Nothing from your shell survives — no `HOME`, no `LANG`, no `VIRTUAL_ENV`, and
none of the `SUGARCRUSH_*` variables that configured the launch.

Those eight are what the hook *sets*. What a hook actually *sees* is a different
list, and counting it is the only way to find out — so, re-measured on this tree
with `command: 'env | sort'` and the project root as `cwd`, twice, varying only
`toolOutput`:

| `toolOutput` | `env` lines | Which |
|---|---|---|
| `''` (empty) | **8** | 7 × `CRUSH_*` + `PWD` |
| `'RESULT-TEXT'` | **9** | 8 × `CRUSH_*` + `PWD` |

```
CRUSH_MODEL=…
CRUSH_PROVIDER=…
CRUSH_SESSION_ID=…
CRUSH_TOOL_INPUT=…
CRUSH_TOOL_INPUT_FILE=/tmp/crush-hook-payload-…
CRUSH_TOOL_NAME=…
CRUSH_TOOL_OUTPUT=…          ← only in the second run
CRUSH_TOOL_OUTPUT_FILE=/tmp/crush-hook-payload-…
PWD=/…/sugar-crush
```

Note that `CRUSH_TOOL_OUTPUT_FILE` appears in **both** runs while
`CRUSH_TOOL_OUTPUT` appears in only one: the file is written even for an empty
payload, and an empty file is still a file, whereas `env` does not print an
empty value.

Two things fall out of that, and neither is the count above.

`CRUSH_TOOL_OUTPUT` vanishes from the listing when it is empty — the variable is
implemented and always passed, but an empty value is not something `env` prints.
**Read an absent `CRUSH_*` variable as empty, never as a missing feature.** (Which
also means the empty-`toolOutput` run coincidentally shows six lines for a
completely different reason than the six keys above. Do not read the two sixes as
the same fact.)

And `PWD` is the one line the hook did not put there: the command is passed to
`proc_open()` as a **string**, so it runs under `/bin/sh -c`, and `sh` exports
`PWD` itself.

**`PATH` is a separate story, and the distinction matters.** `sh` does synthesise
a default `PATH` when it inherits none, so `echo "PATH=[$PATH]"` inside a hook
prints `/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin` and a bare
`ls` in a hook resolves fine. But that value is a **shell variable that is never
exported**, which is exactly why it is missing from the `env` listing above.
Measured, on this tree:

| Probe | Result |
|---|---|
| `env \| grep '^PATH='` | no match |
| `export -p \| grep PATH` | no match |
| `env env \| grep -c '^PATH='` | `0` |
| `php -r 'var_dump(getenv("PATH"));'` | `bool(false)` |
| `command -v ls` | `/usr/bin/ls` |

So the consequence is one step worse than "set `PATH` or use absolute paths".
The hook's own shell can find things; **anything that shell execs cannot.** A
one-liner works and the script it calls breaks, which is the more confusing half
of the failure. If your hook shells out to a program that itself resolves
commands by `PATH`, `export PATH=…` explicitly — do not rely on the default
being inherited, because it is not inherited at all.

One trap to avoid when checking this yourself: probing with `sh -c 'echo $PATH'`
proves nothing, because the grandchild is *also* a shell and synthesises the same
default independently. Use a non-shell grandchild — the `php -r` row above — or
`env env`.

`cwd` is the project root when it is a real directory.

Both pipes are drained with `stream_select()` rather than sequential
`stream_get_contents()`. Sequential reads deadlock the moment a hook writes more
than one pipe buffer (64 KiB on Linux) to stderr — which is exactly the case a
security gate is most likely to be verbose in. A `false` from the select is
retried up to 128 consecutive times rather than treated as end-of-output,
because `pcntl_async_signals()` is on for the whole TUI and a terminal resize
during a hook returns EINTR: breaking there truncated deny reasons, half of an
`exit 3` question, and — worst — a partial `exit 4` rewrite, which is invalid
JSON and therefore a deny of a call the hook meant to permit differently.

### The timeout

**A hook run is bounded, drain and reap together.** 60 seconds by default;
`timeout:` on the entry overrides it, and anything that is not a **positive
finite** number is refused at load rather than read as "no timeout".

That last word is the whole guard, and it was one word short. `0`, `-1` and
`'none'` were refused from the start; `.inf` was not — `is_float(INF)` is true
and `INF > 0` is true, so `timeout: .inf` set a deadline of
`microtime(true) + INF` and put back exactly the unbounded wait the key exists
to remove, behind an error message that promised it could not be asked for.
Measured at `fc597e81`: `timeout: .inf` parsed to `INF`, and a real
`ScriptHook` on `sleep 300` under an 8-second external clock returned exit 124.
Every literal that overflows to infinity (`1e400`) did the same, and `.nan`
fell past the `<= 0` test to be *silently* coerced to 60. All of them are
refused now, and `.nan` is refused rather than coerced because substituting a
number for one a user typed is what `disabled: 'no'` is refused for.

**A hook CHAIN is bounded too**, at the sum of its matching entries' timeouts,
armed once and shared across every re-scan pass. A per-hook bound alone was
"one hook cannot freeze the CLI" — the chain runs every matching hook and
re-scans up to `MAX_REWRITE_PASSES` times, so the real freeze was
hooks x passes x 60. The sum is used rather than a new constant because it is
the only figure derived from what you already wrote: on a single pass every
entry gets exactly the budget it asked for, and what is taken away is the
multiplication nobody asked for. A chain that runs out is a **DENY**, naming
the budget. Hand-written PHP hooks (`HookInterface` implementations that are
not `ScriptHook`) are a synchronous call in this process with no deadline to
honour: they neither contribute to that budget nor are charged against it, and
a chain made only of those is bounded by nothing here, as it always was.

**A deny reason is clipped at 16 KiB**, with the clip announcing itself. A deny
message is quoted verbatim into the model's tool result, so it is prompt text
paid for per token — written by a process this class has just decided it cannot
trust to finish. `EXIT_MODIFY` JSON, an `EXIT_ASK` question and an `EXIT_ALLOW`
message are not clipped: the first must round-trip or it becomes a deny of a
call the hook meant to permit, and the other two are the hook succeeding.

It used to be unbounded in two independent places, and either one alone was
enough to freeze the CLI — no spinner, no Escape. Measured at `4a4ecb98`, each
under a 5-second external clock and each returning exit 124:

| Hook command | Where it parked |
|---|---|
| `sleep 30` | the drain's `stream_select()`, called with a `null` timeout |
| `printf hi; exec 1>&- 2>&-; sleep 30` | `proc_close()`, which waits — the drain had already finished at EOF |

Past the deadline the child gets SIGTERM, half a second, then signal 9, and the
hook is reported as a **DENY**: an expired hook has answered nothing, and
letting the call through would silently skip the guard that was written to stop
it. On `PostToolUse` that verdict is discarded by both consumers, so the only
effect there is that the tool result stops waiting.

---

## The built-in hooks

`HookManager::registerBuiltIns()` registers three unconditionally, ahead of
anything from a file and ahead of the permission gate:

| Hook | Event | What it does |
|---|---|---|
| `ProtectFilesHook` | `PreToolUse` on `^(Bash\|Edit\|Write\|Read)$` | denies secret and policy files — see [`PERMISSIONS.md`](PERMISSIONS.md#the-hooks-that-outrank-the-gate) |
| `ConfirmRemoveHook` | `PreToolUse` | denies obvious destructive shell (`rm -rf`, `find … -delete`, …) |
| `AuditHook` | `PostToolUse`, matcher `.*` | appends every call to `sys_get_temp_dir()/sugar-crush-audit.log` |

Two more exist and are **not** registered by default:

- `PermissionGateHook` — registered by `Bootstrap::hooks()` when a gate exists,
  which is every CLI launch. It is what makes the six-mode gate reachable from
  the main loop at all.
- `BashEscapeDenyHook` — opt-in, constructed with a jail root, and an embedder
  has to register it explicitly.

`ConfirmRemoveHook` and `BashEscapeDenyHook` are both documented in their own
source as **heuristics, not security boundaries**. Regex cannot see through
shell indirection: `x=rf; rm -$x`, aliases, `$(echo rm) -rf`, `$HOME/../..`,
command substitution, symlinks and here-docs all evade them. They catch the
obvious footgun — a model literally emitting `rm -rf` — not a hostile command.
For real containment, run the process in a jail or container.

## Writing a hook in PHP

Implement `SugarCraft\Crush\Hooks\HookInterface` (`name()`, `event()`,
`matcher()`, `execute(HookContext): HookResult`) and register it on a
`HookManager`. `HookResult` has four constructors — `allow()`, `deny()`,
`ask()`, `modify()` — the same four the exit codes above map onto. This is the
route for anything the five-key YAML shape cannot express; it needs an embedder,
not a config file.

## See also

- [`PERMISSIONS.md`](PERMISSIONS.md) — the layer the chain ends in.
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — "sugarcrush exits 2 and will not
  start".
