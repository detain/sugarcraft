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
already-escaped `\/` and gets it wrong at the edges. The same wrapping is what
makes the match-all the **empty string**: `''` holds no delimiter either, so it
compiles to `//i` — the `i` is the case-insensitive modifier, and between the
two delimiters there is no pattern at all, which PCRE reads as matching every
subject. The glob instinct is the one spelling that does not survive the load:
`matcher: '*'` becomes `/*/i`, PCRE refuses it because the quantifier has
nothing to repeat, and the loud half of the rule above turns that into a refused
launch rather than a guard that fires on nothing. Omitting the key outright is
the third route, and it lands on `.*`, which matches everything the same way.

One definition serves the config's own validation **and** both matchers
(`HookRegistry::matcherMatches()` and `HookDispatcher::matcherMatches()`), since
a pattern validated under one delimiter and matched under another is a hook that
loads and never fires.

A pattern can also compile fine and still fail to *evaluate*: `preg_match()`
returns `false` — not `0` — when PCRE exhausts `pcre.backtrack_limit` or
`pcre.recursion_limit` on the subject, and `'(a+)+$'` against a long enough tool
name does exactly that. `HookRegistry::matcherMatches()` reads that as
**matching**: the hook runs and gets to deny, because treating an
unevaluable guard as satisfied is the one failure mode a guard must not have.
The pattern and the PCRE error are kept on `HookRegistry::matcherFailures()` —
recorded, not printed, because the match happens mid-frame where the renderer
owns the screen.

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

`src/Hooks/HookEvent.php` defines twelve:

| Event | Fires | Dispatched from |
|---|---|---|
| `PreToolUse` | before a tool runs — the one that can stop it | `Runtime::gate()`, `Chat::gateToolCall()` |
| `PostToolUse` | after a tool ran, in provider order | `Runtime::settle()`, `Chat::applyPostToolUse()` |
| `Stop` | the agent answered without calling a tool and is about to stop — the one that can keep it going | `EngineBackend::runTurn()` |
| `SubagentStop` | a sub-agent (a `Task` run, or a workflow stage's agent) is about to stop | `EngineBackend::runTurn()` |
| `SessionStart` | the first prompt submitted into an empty history | `Chat::dispatchTurnHooks()`, `SessionHost::fireTurnHooks()` |
| `SessionEnd` | the process is exiting: the TUI closed, or a `-p` run printed its answer | `NonInteractive::fireSessionEnd()` |
| `UserPromptSubmit` | you submitted a prompt | `Chat::dispatchTurnHooks()`, `SessionHost::fireTurnHooks()` |
| `PreCompact` | before a compaction condenses the history — the one that can skip it | `Chat::preCompactGate()`, `EngineBackend::runTurn()` |
| `PostCompact` | after a compaction was applied | `Chat::postCompactCmd()` |
| `TeammateIdle` | a teammate went idle | — |
| `TaskCreated` / `TaskCompleted` | task lifecycle | — |

The `—` rows are **dormant, not removed**: an entry naming one of them parses
from `hooks.yaml`, registers, and keeps the block semantics below — but nothing
in `src/` reaches them at this tip. `TaskCreated`, `TaskCompleted` and
`TeammateIdle` do have call sites, all three in `TaskList`, but each is guarded
on an injected `HookDispatcher`, and `src/` constructs that class nowhere —
`Team.php`, the only production `new TaskList(…)`, leaves the parameter at its
default. So the operative reason a script written against one will never run is
the dispatcher that is never built, not a call site that was left out. Wiring
them is open work. (`HookDispatcher` also carries a method for `Stop`,
`SubagentStop` and `SessionEnd`; nothing calls those either — the live chain
dispatches all three through `HookManager`, see *The stop events* below.)

What a **block** (exit 2) does depends on the event, because for some of them
the action has already happened:

- `PreToolUse`, `TaskCreated` — stops the action outright, and stderr goes
  back to the agent.
- `Stop`, `SubagentStop` — the action is the agent *stopping*, so a block
  **keeps the turn going**: the reason goes back to the agent as its next
  prompt, at most eight times a turn. See *The stop events* below.
- `PostToolUse` — too late to stop, so it **withholds the output** instead:
  on both tool paths (`Runtime::settle()`, and `Chat::applyPostToolUse()` on
  the dormant Chat path) the model and the UI get
  `[output withheld by PostToolUse hook "<name>": <stderr>] The call ran; its
  output is not shown.` in place of the tool's output. The image and diff are
  dropped with it, `isError` keeps whatever the tool reported, and a permitting
  hook's note from earlier in the same chain is discarded with the verdict. A
  PreToolUse note is kept, because it was written before the output existed.
  Every verdict that does not permit counts: exit 1 or 2, any other non-zero
  exit, a timeout, an exit-3 ask (there is nobody to ask after the call ran).
  A hook that *throws* counts too (audit R6): it has vetted nothing, and the
  hooks queued behind it never run, so its reason reads
  `hook failed: <exception class>: <message>`. Only `PostToolUse` contains a
  throw this way; a `PreToolUse` hook that throws still ends the turn before
  the call can run. On the Chat path a failed call's withheld text stays in its
  error slot, and the hook is shown the error text, as `Runtime` shows it
  `ToolResult::content()`.

  `<name>` is the hook that refused, as the registry recorded it while running
  the chain — never what the hook's own result claims (`HookResult::$refusedBy`,
  or the first asker for an ask). It is the entry's `name:`, which defaults to
  its whole `command`, so it is clipped to 60 characters with control
  characters blanked. A refusal no single hook owns — a chain that ran out of
  its time budget, or kept rewriting — reads `[output withheld by the
  PostToolUse hook chain: <reason>]` instead of guessing a name.
- `TaskCompleted` — too late to stop; surfaces through `continueOnBlock` on
  the `HookDispatcher`, which `src/` never builds (see above).
- `SessionEnd` — nothing is left to stop, so the reason is one line on stderr
  and the exit is unchanged.
- `PreCompact` — **skips the compaction**: the history is left as it was, and
  stderr reaches **you only**. See *The two compaction events* below.
- `PostCompact`, `SessionStart` — stderr reaches **you only**; there is no agent
  action to feed it to. (`SessionStart` is elaborated under *The two turn
  events* below, including where a shipped block reason actually surfaces.)
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

### The two turn events

In the TUI, `Chat::dispatchTurnHooks()` is the only production dispatcher for both, reached
from `submit()` on every prompt, or from `scheduleParkedCompaction()` instead
when the automatic 85% compaction tier parks the prompt behind a model-written
summary. A session driven without a screen (`Host\SessionHost`) fires the same
two events from `SessionHost::fireTurnHooks()`, through the same
`Host\TurnController` logic, so the order, the notes and the refusals below hold
there too. Each submission takes exactly one of the two, and a parked prompt is
judged when it is parked — so a block stops it before the summarization is paid
for, and a note is written immediately ahead of the echoed prompt, where it rides
the turn that goes out once the summary lands. Their verdicts behave differently
from the tool gates:

- **Fired gate-first.** `UserPromptSubmit` runs before `SessionStart`, so a
  SessionStart script never spawns just to learn its prompt was blocked. The
  notes are *inserted* in the opposite order — session note, then prompt note,
  both as `role: system` history messages immediately before the user's line.
- **A `UserPromptSubmit` block discards the prompt.** Nothing is sent, the
  draft stays in the input box, and the reason lands in the transcript as a
  `Message::system()` line. That is a documented divergence from the strict
  `HookEvent::stderrToUserOnly()` reading: `tests/Cli/StderrEmitterCensusTest`
  pins `Chat.php`'s emitter counts, so the transcript seam is the surface that
  answers "where does the user see it". And the history that refusal commits is
  the **pre-compaction** one — the notice is appended to `$this->history`, while
  a rewrite the automatic tier adopted for this same submit lives only in the
  local that `dispatchTurn()` is handed — so a compaction that had already bought
  space goes out with the prompt by design instead of being persisted, and the
  tier re-runs on the next submit.
- **A `SessionStart` block stops nothing.** Per `stderrToUserOnly()` the session
  continues, the hook's note is discarded outright, and only the reason appears
  in the transcript.
- **An `ASK` fails closed on this path.** The turn dispatch has no approver UI
  in front of it, so `Chat::turnHookRefusalReason()` treats an ask as a block —
  the prompt is not sent rather than silently permitted.
- **The SessionStart gate is `count($this->history) === 0` at the dispatch
  point** — a once-per-*empty-history* gate, not once-per-session: `/clear`
  re-opens it, while `resume` and the palette's new-session path never re-fire
  it, and `compact` is unreachable there. The method's own docblock records this
  as a known gap rather than papering over it.
- **Context is smuggled through the tool-shaped `HookContext`.** `toolName`
  carries the event name — the only slot `HookRegistry::findMatches()` tests —
  `toolInput` is JSON (`{"prompt": …, "source": "startup"}` for `SessionStart`,
  `{"prompt": …}` for `UserPromptSubmit`), and `model`/`provider` are empty. A
  `matcher:` written against tool names will not fire on these events unless it
  names the event.

Hook notes go onto **history**, never into the prompt assembler — `role: system`
is the non-spoofable operator channel — which is why wiring these two events
moves no prompt golden.

### The stop events

`EngineBackend::runTurn()` fires `Stop` when the model answers without calling a
tool — the step that would end the turn — and `SubagentStop` instead when the
turn is a delegated run: a `Task` sub-agent, or an agent a workflow stage runs.
It runs in the turn's own process (the forked turn child on the TUI path), only
when a hook for the event is wired, so an unhooked turn ends exactly as before.

- **A block keeps the turn going.** Any verdict that does not permit — exit `1`
  or `2`, an ask (there is nobody to ask), a timeout, a hook that throws —
  appends the hook's reason as the next prompt, `Stop hook "<name>" did not let
  you finish yet: <reason>`, and the turn takes another step. The answer it
  refused stays in the history ahead of it.
- **Bounded three ways.** At most eight continuations a turn
  (`HookManager::MAX_STOP_CONTINUATIONS`), each one a step under the turn's
  step ceiling, and none once the spend cap is reached or a soft cancel is
  pending. Past any of them the turn ends on its last answer.
- **`"continue": false` ends it.** A JSON envelope with `"continue": false` (see
  *JSON on stdout*) ends the turn, as it was about to, and its `stopReason`
  closes the reply: `[turn stopped by hook "<name>": <stopReason>]`.
- **Context.** `toolName` carries the event name (write the `matcher:` against
  it), and `toolInput` is JSON `{"stop_hook_active": …, "last_assistant_message":
  "<the answer>"}` — `stop_hook_active` is `true` once a block has already kept
  this turn going, so a hook can let go. `SubagentStop` adds `agent_id` and
  `agent_type`.

`SessionEnd` fires once as the process exits, from
`NonInteractive::fireSessionEnd()`: after the TUI's program loop returns
(`bin/sugarcrush`, with the session's own chain, id and root; `toolInput`
`{"reason": "prompt_input_exit"}`) and after a `-p` run's answer is printed
(`{"reason": "other"}`) — never from the TUI's quit paths, which only leave the
loop. A `-p` caller that supplies its own backend owns its session's end, so
nothing fires there. The session server (`serve`) does not fire it yet.

### The two compaction events

`Chat::preCompactGate()` fires `PreCompact` for every compaction that would
condense the history through the TUI: `/compact` (`trigger: manual`), and the
automatic 85% tier when it parks a prompt behind a model-written summary
(`trigger: auto`). `Chat::postCompactCmd()` fires `PostCompact` once such a
compaction has been applied. Inside a turn, `EngineBackend::runTurn()` fires
`PreCompact` (`trigger: auto`) before the model's own `Prune` call changes what
it is sent: a block refuses the call, and the model reads why. The automatic
tier's heuristic route (no summary model configured) and the engine's
step-level compaction do not fire them yet.

- **Never on the render loop.** A wired `PreCompact` chain runs inside the
  compaction's own Cmd — before the summarization request is sent, so a block
  costs nothing — and a chain with a script hook in it runs in a forked child,
  the way the turn events do, so the screen keeps painting. With no
  `PreCompact` hook wired, `/compact` behaves exactly as before.
- **A `PreCompact` block skips the compaction.** Nothing is condensed and the
  transcript says `Compaction skipped: PreCompact hook blocked it (<reason>).`
  On the automatic tier your prompt still goes out, against the uncompacted
  history. An `ASK` fails closed, and a hook that throws — or a forked child
  that reports nothing — counts as a block.
- **A permitting `PreCompact` hook's note steers the summary.** Its stdout is
  sent to the summarising model beside `/compact <focus>`'s focus text; it is
  not written into history.
- **`PostCompact` is observe-only.** The rewrite already happened, so neither
  its exit code nor its stdout is read back — use it for side effects such as
  logging or archiving the summary.
- **Context.** `toolName` carries the event name (write the `matcher:` against
  it), and `toolInput` is JSON: `{"trigger": …, "custom_instructions": "<the
  /compact focus>"}` for `PreCompact`, `{"trigger": …, "compact_summary": "<the
  summary rows the model now reads>"}` for `PostCompact`.

---

## The exit-code contract

`src/Hooks/ScriptHook.php`:

`ScriptHook::execute()` resolves the exit code with a four-arm `match`: `0`,
`3`, `4`, and `default`. (No line number here on purpose — the one that used to
be printed, `line 181`, had drifted by more than a hundred lines by the time
anybody checked it.)

| Exit | Verdict | stdout means |
|---|---|---|
| `0` | **allow** | a model-visible **note**, capped at 10,000 bytes — *see below* |
| `3` | **ask** | the question put to the user, clipped at 16 KiB |
| `4` | **modify** | a JSON **object** replacing the tool's arguments, **refused** over its ceiling rather than clipped |
| `1`, `2`, or **any** other non-zero | **deny** | — (stderr is the reason, clipped at 16 KiB; stdout is discarded) |

**Where an exit-0 hook's stdout goes.** Unless it is a JSON envelope (see
[JSON on stdout](#json-on-stdout)), `ScriptHook::execute()`'s `EXIT_ALLOW` arm
hands it to `ScriptHook::allowOrEnvelope()`, which returns
`HookResult::allow('', HookContextFiles::bound($output,
HookResult::MAX_ADDITIONAL_CONTEXT_BYTES))` — the *message* stays empty, and the
stdout travels in the third payload channel, `additionalContext`. It is the
**model-visible** one, and `HookRegistry::executeHooks()` collects it across the
whole chain: every hook that ran contributes its note, notes are joined with a
blank line and re-bound through the same cap on every re-scan pass, and the
total is set onto the settled permitting verdict with
`HookResult::withContextSet()`. A settled ASK carries the collected notes
through the user's answer (`HookManager::resolveAsk()`); a hard DENY returns the
blocking verdict alone — the chain's notes die with the call they were collected
for.

Where the note then lands depends on the event:

- **`PostToolUse`** — both live tool paths append it to the model-visible tool
  result: `Runtime::settle()` through the existing `Runtime::annotate()` seam,
  `Chat::applyPostToolUse()` onto whichever half (result or error) carries the
  body. An empty note returns the result **byte-identical**, so a chain that
  printed nothing cannot alter any payload, wired or not. This applies only when
  the chain permits. A refusing chain withholds the output on the engine path
  instead (see what a **block** does, above).
- **`PreToolUse`** — WHAT THIS SAID: that neither live gate consumes a
  permitting verdict's note, that both read only `message` and `modifiedInput`,
  and that "a note meant for the model belongs on a `PostToolUse` hook". WHAT
  IS TRUE NOW (since `9d81a6823`): `Runtime::gate()` returns the collected note
  as a fourth slot and `Chat::gateToolCall()` as a fifth, and both live paths
  append it to the model-visible tool result exactly the way `PostToolUse`
  does — `Runtime::settle()` through the existing `Runtime::annotate()` seam,
  `Chat::applyPostToolUse()` through its Chat twin `Chat::withAppendedModelNote()`
  onto whichever half (result or error) carries the body. The model-visible
  bytes are `result\n\npre\n\npost`, and that order is deterministic by
  construction rather than by timing: each consumer CAPTURES the `PostToolUse`
  verdict before appending anything, so the post chain still observes the RAW
  tool output — a pre-note is model-visible context, never the tool's own
  stdout — and only then are pre and post appended, in that order. The
  byte-identical discipline is the same on both halves of the pair: on an empty
  note `Runtime::annotate()` is never called and
  `Chat::withAppendedModelNote()` returns the instance untouched, so a chain
  that printed nothing cannot alter any payload, on either event. What the old
  sentence still gets right: `deny` and `ask` speak through `message`,
  interpolated into `"Hook denied: …"` — which is why the deny reason and the
  ask question reach the model — and `modifiedInput` is the rewrite channel;
  and a hard DENY pins the note slot to `''` on every gate arm, so a blocked
  call carries no note at all, while a settled ASK carries the chain's notes
  through the user's answer. A `PreToolUse` hook's stdout therefore reaches the
  model today: pick the event by what the hook needs to OBSERVE, not on the
  assumption that only `PostToolUse` is wired.

**How much a hook may say, per exit code.** Measured through
`HookManager::preToolUse()` with a 200,000-byte payload:

| Exit | What is bounded | Bound | Over the bound |
|---|---|---|---|
| `0` | the note (`additionalContext`) | 10,000 bytes | UTF-8-safe head kept, plus a marker naming both figures and the file where the full output was retained |
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
`[exit-1]` message prefix. It is not reachable from here. Its own class
docblock records that **no shipped `HookInterface` implementation emits that
prefix** — not `ScriptHook`, not any `BuiltIn/*Hook` —
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
- **Your exit-3 question is asked every time.** sugar-crush remembers two kinds
  of approval: a Task spawn approved once in a turn is not re-asked for the same
  agent in that turn, and `Chat`'s "allow always" reply skips later prompts for
  the same call. Both stand in for the user only when the permission gate was
  the **only** hook that asked. `HookRegistry::executeHooks()` records the name
  of every hook that returned an ask on the settled ASK (`HookResult::$askedBy`),
  overwriting anything a hook's own result carried, and the gate's name is
  reserved. A question your hook asks is about this call's content, so no
  earlier approval answers it (audit F-P7, F-P9).
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

### JSON on stdout

An exit-`0` hook may print a **JSON envelope** instead of a note, in Claude
Code's shape, so a hook written for that tool carries over.
`ScriptHook::allowOrEnvelope()` reads it; every field is the JSON twin of an
exit code above, so it adds no verdict the exit codes could not reach — it lets
one hook say several things at once:

```json
{"decision": "ask", "reason": "Touches prod config — run it?", "updatedInput": {"command": "make deploy DRY_RUN=1"}, "additionalContext": "deploy target is staging"}
```

| Key | Twin of | Effect |
|---|---|---|
| `decision` | exit `2` / `3` | `deny` or `block` refuses the call (`reason` is the refusal), `ask` puts `reason` to the user, `allow` or `approve` (or no `decision`) permits |
| `reason` | stderr on exit `2`, stdout on exit `3` | the refusal or the question, bounded exactly as its exit-code twin is |
| `updatedInput` | exit `4` | a JSON object replacing the tool's arguments, refused over the same ceiling; with `ask` it rides the question as a proposal the chain re-scans |
| `additionalContext` | stdout on exit `0` | the model-visible note, capped at 10,000 bytes |
| `continue` + `stopReason` | — | `"continue": false` refuses the call and ends the turn (`HookResult::haltsTurn()`); `stopReason` is the operator-facing reason the reply closes with |

The same keys are accepted under `hookSpecificOutput`, where
`permissionDecision` and `permissionDecisionReason` spell `decision` and
`reason`. The nested spelling wins when both are present, as in Claude Code.

Rules worth knowing before you rely on it:

- **Only exit `0` is parsed.** A blocking exit takes its reason from stderr, as
  in Claude Code, and exit `4`'s stdout is already a JSON object meaning the
  arguments themselves.
- **The bar is a whole-stdout JSON object carrying at least one key from the
  table.** Anything else is a note, unchanged: a hook that prints
  `{"errors": […]}` as context, a JSON list, or JSON with other text around it
  reaches the model exactly as before.
- **An envelope with a known key that cannot be honoured denies**: a
  `"decision": "maybe"`, a `"continue": "no"`, an `updatedInput` that is not an
  object. The hook evidently meant to steer the call, and guessing which way is
  the fail-open the exit-`4` rule already refuses.
- **`allow` is one hook not objecting, not permission.** In Claude Code a
  `PreToolUse` `allow` skips that tool's permission prompt. Here the chain still
  reaches the permission gate after your hook, so a hook file cannot widen the
  session's permission mode by printing a word.
- **`continue: false` ends the turn.** On a `PreToolUse` or `PostToolUse` hook
  it refuses the call (the model reads the reason in the `"Hook denied: …"` or
  withheld-output line), the step's other calls settle, and the engine's turn
  ends at that step boundary with no further provider call; the reply closes
  with `[turn stopped by hook "<name>": <stopReason>]`. On `Stop` and
  `SubagentStop` it ends the turn the same way (see *The stop events*). The
  TUI's own tool path (`Chat::gateToolCall()`) still only refuses the call.
- `suppressOutput` and `systemMessage` are not read.

### Environment handed to the script

`ScriptHook::execute()` sets **eight** `CRUSH_*` keys — six, on the one run where the temp directory will not
take a file, which is covered below — and lays them **over** the environment
SugarCrush was launched with, which the hook inherits after three changes (see
[What else the hook inherits](#what-else-the-hook-inherits)):

```
CRUSH_SESSION_ID       CRUSH_TOOL_NAME   CRUSH_TOOL_INPUT
CRUSH_TOOL_OUTPUT      CRUSH_MODEL       CRUSH_PROVIDER
CRUSH_TOOL_INPUT_FILE  CRUSH_TOOL_OUTPUT_FILE
```

`CRUSH_TOOL_INPUT` (and the file behind `CRUSH_TOOL_INPUT_FILE`) is the tool's
arguments as JSON with **slashes and non-ASCII left unescaped** — a Read of
`/etc/passwd` arrives as `{"file_path":"/etc/passwd"}`, not
`{"file_path":"\/etc\/passwd"}` — so a plain `grep -qF /etc/passwd` or
`grep -q 'rm -rf /'` guard matches what the model asked for. An invalid UTF-8
byte is replaced with U+FFFD in the hook's copy. Arguments that cannot be
encoded at all are **refused** with a `Hook denied:` reason before any hook
runs; they are never handed to the chain as an empty `{}`, which a deny hook
would read as nothing to refuse. Both tool paths encode through the same
`Runtime::hookInput()`, and the `UserPromptSubmit`/`SessionStart` input
(`{"prompt":…}`) leaves slashes and non-ASCII unescaped the same way.

#### The two `_FILE` variables, and why they exist

**Linux caps one environment entry** at `MAX_ARG_STRLEN`, which is
`PAGE_SIZE * 32` for `NAME=VALUE\0` together, and fails the whole `execve()`
with `E2BIG` past that. On the usual 4 KiB pages that is **131,072 bytes**; it
is 2 MiB on a 64 KiB-page kernel (ppc64le, and the aarch64 kernels RHEL and
SLES ship), and macOS and the BSDs cap the whole environment instead of one
entry, so the figures below are this platform's and not a portable constant.
SugarCrush does not assume any of them — see the retry described below.

While `CRUSH_TOOL_INPUT` was the only route the payload had, that kernel limit
was a limit on **the tool call**: with any script hook registered, a `Write`
whose JSON arguments exceeded ~128 KB could not run at all. Measured on a
4 KiB-page host against a hook whose entire script is `exit(0)`: 131,054 bytes
of value allowed, 131,055 denied, 200,000 and 1,000,000 denied identically —
with a refusal reading `Hook <name> could not be executed`, which names neither
the size nor the cause and reads as *"your hook is broken"*.
`CRUSH_TOOL_OUTPUT` behaves the same way one byte lower, at 131,053/131,054,
because its name is one byte longer and the kernel measures `NAME=VALUE\0`.

So the payload now travels **both** ways:

- `CRUSH_TOOL_INPUT_FILE` and `CRUSH_TOOL_OUTPUT_FILE` point at a `0600` temp
  file holding the **complete** bytes, and are set on every run in which such a
  file could be created — which is every run short of a temp directory that will
  not take a file. The files are deleted as soon as the hook exits: read them,
  do not stash the path.
- **On a run where no temp file could be written, neither `_FILE` variable is
  set at all**, and the two payloads split: an *oversize* one is a fail-closed
  deny before your hook ever starts, while one that *fits* is handed over in
  `CRUSH_TOOL_INPUT` exactly as it always was and your hook runs normally.
  A guard that reads only the file therefore sees **nothing** on that second
  case — which is why the snippet below falls back to the variable rather than
  trusting the path to be there. (Refusing every tool call because `/tmp` is
  full would be the worse trade, so this case is handled and not designed out.)
- `CRUSH_TOOL_INPUT` / `CRUSH_TOOL_OUTPUT` carry the **real bytes** whenever the
  operating system will accept them, which is every call that works today.
  Nothing already written breaks.
- When the OS refuses to start your hook with the payload in its environment,
  SugarCrush retries with the file-backed payloads moved out of the environment,
  and the variable carries a marker instead:

  ```
  @@CRUSH_PAYLOAD_IN_FILE@@ 200011 bytes; read $CRUSH_TOOL_INPUT_FILE
  ```

  Not a prefix of the JSON — truncated JSON is not smaller JSON, and a hook that
  decodes it leniently judges a call that does not exist. Not empty either, since
  an absent `CRUSH_*` already means "empty" here.

**If your hook inspects arguments, prefer the file, fall back to the variable,
and refuse when you end up with neither.** A hook that reads only
`CRUSH_TOOL_INPUT` will now *run* on a call whose payload the environment could
not carry, and see the marker where it used to see arguments — whereas before,
the call was denied outright. A hook that reads only `CRUSH_TOOL_INPUT_FILE`
sees the empty string on a host whose temp directory will not take a file.
Either way, a guard that cannot read the arguments has not cleared them, so say
so with an `exit 2`:

```sh
if [ -n "${CRUSH_TOOL_INPUT_FILE:-}" ] && [ -r "$CRUSH_TOOL_INPUT_FILE" ]; then
    input="$(cat "$CRUSH_TOOL_INPUT_FILE")"
else
    input="$CRUSH_TOOL_INPUT"
fi

case "$input" in
    ''|'@@CRUSH_PAYLOAD_IN_FILE@@'*)
        echo "hook: the tool arguments could not be read" >&2
        exit 2
        ;;
esac
```

Both branches are exercised by `ScriptHookTest`, including on a host whose
`TMPDIR` is a regular file: without the fallback that guard was **measured
allowing `{"command":"rm -rf /"}`**, because `cat` on an unset path reads
nothing and an empty string matches no dangerous pattern.

`CRUSH_TOOL_NAME` and your `matcher:` are unaffected by any of this.

Platforms that cap the whole environment rather than one entry (macOS: 256 KiB
for argv and environ together) are not modelled as a *number* anywhere — but
they no longer need to be: the retry above is triggered by the exec the OS
actually refused, not by a size SugarCrush guessed at, so a payload pair that
passes every per-entry check and is still refused gets moved onto the files and
tried again. If even that is refused, the refusal names both payload sizes
rather than saying only that the hook could not be executed.

#### What else the hook inherits

Your launch environment reaches the hook — `PATH`, `HOME`, `LANG`,
`VIRTUAL_ENV`, the `SUGARCRUSH_*` variables that configured the launch — with
three changes, made by `ProcessContainment::scrubbedEnv()`:

1. **Credentials are removed** (audit F-E1). A variable whose name matches
   `*_API_KEY`, `*_TOKEN`, `*_SECRET` or `AWS_*` — case-insensitive — is
   dropped, and so is every provider key SugarCrush itself reads
   (`ANTHROPIC_API_KEY`, `ANTHROPIC_AUTH_TOKEN`, `OPENAI_API_KEY`,
   `SGLANG_API_KEY`, `CUSTOM_PROVIDER_API_KEY`). A hook's stdout can become
   `additionalContext` the model reads, so a key in its environment was one
   `env` away from the transcript. Bash and Grep get the same scrub. If a hook
   genuinely needs one — a notifier posting with `SLACK_TOKEN` — name it in
   `secretEnvAllowlist` in your own `~/.sugar-crush/settings.json` (see
   [`SETTINGS.md`](SETTINGS.md)); a glob there never releases a provider key,
   only its exact name does, and no project file can set the key at all.
2. **Two terminal handles are removed:** `SUDO_ASKPASS` and `GPG_TTY`, which
   would let a detached child reach a prompt or a tty nobody can see.
3. **Fail-fast values are forced** over whatever your shell had:
   `GIT_TERMINAL_PROMPT=0`, `GIT_ASKPASS=/bin/false`, `SSH_ASKPASS=echo`,
   `DEBIAN_FRONTEND=noninteractive`, `PAGER=cat`, `GIT_PAGER=cat` and
   `SYSTEMD_PAGER=/bin/cat`, so a command that would prompt refuses on stderr
   instead.

The `CRUSH_*` keys go on last, so no credential pattern can remove one.

This page used to say the opposite: that the hook's environment *replaces*
yours, that nothing from your shell survives, and that `PATH` is not inherited.
That described an earlier `ScriptHook`. Once every spawn moved onto the shared
containment block the hook inherited everything, credentials included, and the
page went on describing the old build until the F-E1 audit counted 129 lines
where it promised 8.

Those eight are what the hook *sets*. What a hook actually *sees* depends on the
shell you launched from, so the measurement fixes that shell: `command: 'env |
sort'`, the project root as `cwd`, and SugarCrush launched under `env -i` with
exactly `HOME`, `PATH`, `LANG`, `OPENAI_API_KEY`, `GITHUB_TOKEN`, `AWS_PROFILE`
and `SUDO_ASKPASS` set — twice, varying only `toolOutput`:

| `toolOutput` | `env` lines | Which |
|---|---|---|
| `''` (empty) | **18** | 7 × `CRUSH_*` + 7 forced + 3 inherited + `PWD` |
| `'RESULT-TEXT'` | **19** | 8 × `CRUSH_*` + 7 forced + 3 inherited + `PWD` |

```
CRUSH_MODEL=…
CRUSH_PROVIDER=…
CRUSH_SESSION_ID=…
CRUSH_TOOL_INPUT=…
CRUSH_TOOL_INPUT_FILE=/tmp/crush-hook-payload-…
CRUSH_TOOL_NAME=…
CRUSH_TOOL_OUTPUT=…          ← only in the second run
CRUSH_TOOL_OUTPUT_FILE=/tmp/crush-hook-payload-…
DEBIAN_FRONTEND=noninteractive
GIT_ASKPASS=/bin/false
GIT_PAGER=cat
GIT_TERMINAL_PROMPT=0
HOME=…
LANG=…
PAGER=cat
PATH=…
PWD=/…/project
SSH_ASKPASS=echo
SYSTEMD_PAGER=/bin/cat
```

The three inherited lines are `HOME`, `LANG` and `PATH`. The launch had four
more that the hook does not: `OPENAI_API_KEY`, `GITHUB_TOKEN` and `AWS_PROFILE`
are credential-shaped, and `SUDO_ASKPASS` is a terminal handle.
`HookEnvironmentDocumentationDriftTest` re-runs this exact measurement and
compares it with the table and the listing, so neither can drift from the code
again.

Note that `CRUSH_TOOL_OUTPUT_FILE` appears in **both** runs while
`CRUSH_TOOL_OUTPUT` appears in only one: the file is written even for an empty
payload, and an empty file is still a file, whereas `env` does not print an
empty value.

Two things fall out of that, and neither is the count above.

`CRUSH_TOOL_OUTPUT` vanishes from the listing when it is empty — the variable is
implemented and always passed, but an empty value is not something `env` prints.
**Read an absent `CRUSH_*` variable as empty, never as a missing feature.** (Which
also means the empty-`toolOutput` run prints one fewer `CRUSH_*` line than it
has keys — `env` hides empties — while the degraded run's six keys are six for
the temp-directory reason above. Do not conflate the two counts.)

And `PWD` is the one line that came from neither SugarCrush nor your launch
environment: the command is passed to `proc_open()` as a **string**, so it runs
under `/bin/sh -c`, and `sh` exports `PWD` itself.

**`PATH` is inherited, and exported**, so a program your hook execs resolves
commands by it exactly as your shell would. (Earlier revisions of this page
said `PATH` was never inherited and printed a probe table to prove it. That
table measured the eight-variable environment described above as withdrawn, and
went with it.)

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

### The cap on the note, and the retained overflow

`additionalContext` has exactly one bound, `HookResult::MAX_ADDITIONAL_CONTEXT_BYTES`
— **10,000 bytes**, counted as bytes and not characters, because the figure that
matters is what the provider bills. It is applied by the *producer*, never
trusted from the writer: `ScriptHook` runs every exit-0 stdout, and
`HookRegistry::executeHooks()` re-runs every chain total after joining, through
`HookContextFiles::bound()`.

At or under the cap the text passes through unchanged. Over it, what travels is
the UTF-8-safe head plus a marker naming both figures and the file where the
**complete** output was retained:

- retained files live in a `sc-hook-ctx-<euid>/` directory inside the system
  temp directory — one directory per user, so the first user on a shared box
  cannot lock everybody else out of retention — one file per overflow, created
  `0600`, filled and then renamed, so a reader never sees a partial file;
- they are **never auto-deleted**: a hook's output is treated as audit material
  and outlives the run that produced it;
- they are deliberately outside the tool-IPC sweep. `ToolIpcFiles::sweep()`
  matches only its three bare-temp prefixes (`sc_runtime_tool_*`,
  `sc_chat_tool_*`, `crush-hook-payload-*`), and a glob `*` does not cross the
  directory separator — so nothing under `sc-hook-ctx-<euid>/` can be swept however
  long the notes pile up;
- if the temp directory will not take the file, the marker says the output
  *could not be retained* instead, and the bounded head still travels.

Because the chain re-binds after joining, the 10,000-byte figure bounds what
**one tool call** puts in front of the model, not each hook: fifty hooks printing
a kilobyte apiece still arrive as one bounded note on the result.

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

**A deny reason is clipped at 16 KiB**, with the clip announcing itself, and so
is an `EXIT_ASK` question. Both are quoted verbatim into the model's tool
result, so both are prompt text paid for per token — the deny reason written by
a process this class has just decided it cannot trust to finish, and the
question by one whose answer nobody is necessarily there to give
(`Runtime::settleAsk()` on a run with no approver attached interpolates the
question whole and hands it to the model). The clip is what the MODEL sees; the
permission modal was never the unbounded half of that path, since it keeps 8
wrapped rows and appends its own `… N more lines` well before 16 KiB.

`EXIT_MODIFY` JSON is not clipped, and that half is permanent: a truncated
rewrite is invalid JSON, so clipping would become a deny of the very call the
hook meant to permit differently — hence refusal at the ceiling instead. An
`EXIT_ALLOW` message used to be unclipped because it reached the model nowhere;
its stdout now travels in `additionalContext`, and the cap that rides on it is
`HookResult::MAX_ADDITIONAL_CONTEXT_BYTES` — see above.

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
it. On `PostToolUse` the engine path (`Runtime::settle()`) treats it as any
other deny and **withholds the tool's output**. That is fail-closed, so a
secret scanner that runs out of time cannot let through what it never finished
reading. The dormant Chat path (`Chat::applyPostToolUse()`) does the same.

---

## The built-in hooks

`HookManager::registerBuiltIns()` registers three unconditionally, ahead of
anything from a file and ahead of the permission gate. Registration order is
the order a chain runs in, with one exception: `AuditHook` always runs **after
every other `PostToolUse` hook**, whatever order they were registered in
(`HookRegistry::findMatches()`, audit R7). It records what a call produced, so
it runs only once the rest of the chain has permitted that output — see
[below](#what-the-audit-log-records).

| Hook | Event | What it does |
|---|---|---|
| `ProtectFilesHook` | `PreToolUse` on `^(Bash\|Edit\|Write\|Read\|Grep\|Glob\|Lsp\|mcp__.*)$` | denies secret and policy files; asks for the rest — see [below](#what-protect-files-covers) and [`PERMISSIONS.md`](PERMISSIONS.md#the-hooks-that-outrank-the-gate) |
| `ConfirmRemoveHook` | `PreToolUse` | denies obvious destructive shell (`rm -rf`, `find … -delete`, …) |
| `AuditHook` | `PostToolUse`, matcher `.*` | appends every call — and every refused or withheld one, see [below](#what-the-audit-log-records) — to whatever `AuditHook::defaultLogFile()` answers — a fixed leaf inside a per-user directory the hook creates `0700` and refuses to use if it is not its own |

Eight more exist and are **not** registered by default:

- `PermissionGateHook` — registered by `Bootstrap::hooks()` when a gate exists,
  which is every CLI launch. It is what makes the six-mode gate reachable from
  the main loop at all.
- `BashEscapeDenyHook` — opt-in, constructed with a jail root, and an embedder
  has to register it explicitly.
- `RepeatCallGuardHook` and `RepeatCallCountHook` — the repeat-call loop guard,
  registered per turn by `EngineBackend::resolveHookManager()` around one
  shared `Backend\ToolCallLoopGuard` ledger, on a copy of the launch's chain
  and on a `withoutHooks()` turn too. Within one turn, the same tool called
  with the same arguments (key order ignored) that returns the same result
  gets a warning appended to its 3rd result (`PostToolUse`, as
  `additionalContext`), is denied from the 5th call (`PreToolUse`), and ends
  the turn on the 8th. A changed result resets the count. They are not in
  `registerBuiltIns()` because their ledger lives one turn and a hook manager
  lives for the launch.
- `SubAgentGrantHook` — the delegated run's own tool declaration, registered
  by `EngineBackend::resolveHookManager()` only on the copy of the chain a
  `Task` sub-agent runs on (`TaskTool` binds it), ahead of the permission gate
  and on a `withoutHooks()` turn too. It denies a call outside the preset's
  `tools` grant or matched by its `disallowedTools`, argument halves included,
  so `Bash(git *)` refuses `rm x`, and, when the preset's `permissionMode` is
  stricter than the session's, holds each admitted call to that mode as well
  (see [`PERMISSIONS.md`](PERMISSIONS.md#a-sub-agents-mode)). See
  [`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md#how-a-grant-is-enforced). Its
  name, `subagent-grant`, is reserved like the gate's
  (`HookRegistry::isReserved()`): no other hook may register under it, and a
  disable naming it is ignored.
- `PostEditLintHook` — the post-edit lint, registered by `Bootstrap::hooks()`
  on every launch, after the three above and ahead of the hook files, because
  it reads the user's `lintCommands`. See [Post-edit lint](#post-edit-lint).
- `PostEditDiagnosticsHook` — the post-edit diagnostics, registered by `Bootstrap::hooks()`
  when a language server is configured under `lsp` and started, right after the
  post-edit lint and ahead of the hook files. See
  [Post-edit diagnostics](#post-edit-diagnostics).
- `AutoCommitHook` — the auto-commit of each edit, registered by `Bootstrap::hooks()`
  when `autoCommit` is `edit`, after the post-edit diagnostics and ahead of the
  hook files. See [Auto-commit](#auto-commit).

`ConfirmRemoveHook` and `BashEscapeDenyHook` are both documented in their own
source as **heuristics, not security boundaries**. Neither can see through
shell indirection: `x=rf; rm -$x`, aliases, `$(echo rm) -rf`, a variable set
earlier (`d=/etc; cat $d/passwd`), symlinks and here-docs fed to an
interpreter all evade them. They catch the obvious footgun — a model literally
emitting `rm -rf` or `cat ~/.ssh/id_rsa` — not a hostile command. For real
containment, run the process in a jail or container.

`BashEscapeDenyHook` reads the command through the same quote-aware tokenizer
as the permission rules, so separators, pipes, subshells and glued
redirections (`cat</etc/shadow`, `cd ..;ls`, `echo x >/tmp/out`) do not hide a
path. It expands `~`, `$HOME` and `$PWD` (the root), denies `$OLDPWD`, `cd -`
and `~user`, and also judges the bodies of `$(…)`, backticks, `<(…)`,
`sh -c '…'` and `eval`. It allows `/dev/null`, `/dev/std*` and an absolute
path to an existing executable in command position (`/usr/bin/php -v`).

Quoting is **not** among those evasions for `ConfirmRemoveHook` any more: each
pattern runs against the raw command *and* its quote-removed words (the same
`Permissions\ShellWords` tokeniser the permission gate's step-0 breaker uses),
so `rm '-rf' x`, `rm "-rf" x`, `find . '-delete'` and `dd 'of=/dev/sda'` are
denied like their unquoted spellings. Before audit F-P1 the first three passed
the whole built-in chain under `bypass-permissions`, then the default mode on
every path (it still is for `-p` and background sessions; the TUI now starts in
`default`).

### Post-edit lint

After every `Write` or `Edit`, `PostEditLintHook` lints the file the call
changed and appends what the linter found to that call's result, so the model
reads it on the very result it looks at next. The format is Aider's: the
command that ran, its output, then the flagged lines marked `█` among
`│`-marked context, with `⋮...` where lines were skipped.

```text
# Fix any errors below, if possible.

## Running: flake8 --select=E9,F63,F7,F82 'app/main.py'

app/main.py:3:10: E999 SyntaxError: invalid syntax

## See relevant line below marked with █.

app/main.py:
1│import sys
2│
3█def main(:
4│    return 0
```

PHP files are checked with `php -l` (the interpreter running sugar-crush) out
of the box. Add, replace or switch off linters by file extension with
`lintCommands` in your own `~/.sugar-crush/config.json` or
`~/.sugar-crush/settings.json`:

```json
{"lintCommands": {"php": "vendor/bin/phpstan analyse --no-progress --error-format=raw", "py": "flake8 --select=E9,F63,F7,F82", "js": false}}
```

- The file goes last on the command line, shell-quoted, or wherever the command
  writes `{file}`, relative to the project root; the command runs in the
  project root.
- Only a file the edit could reach is linted: the path is resolved through the
  same workspace jail `Edit` uses. The chain also runs after an edit the tool
  refused, and a linter quotes the lines it flags, so a refused edit outside
  the workspace must not get those lines read.
- `false`, `null` or `""` switches an extension off, the built-in `php` entry
  included. A value of any other shape is ignored, not refused.
- **User tier only.** A lint command is shell, so no project settings file can
  name one, trusted or not.
- A clean lint adds nothing, so the result stays byte-identical. A linter that
  exits non-zero produces the report above. One that cannot start (exit 126 or
  127) or runs past its 30-second budget produces a one-line note saying the
  file was not checked.
- **It never refuses.** The edit has already happened, and withholding its
  output would hide what the model needs to fix the error. Every outcome is an
  allow with a note.
- It counts against the chain deadline like a script hook (it implements
  `BoundedHookInterface`), and it runs through the same bounded spawn as
  `Bash`, so a hung linter is stopped rather than freezing the turn.
- The note is capped at 10,000 bytes like any other hook note, with the
  linter's own output cut first so the marked lines survive.

### Post-edit diagnostics

When you list a language server under `lsp` in your own
`~/.sugar-crush/config.json` (or `settings.json`), every `Write` or `Edit` to a
file that server owns is followed by `PostEditDiagnosticsHook`: the server is
asked to re-check the file as it now is on disk, and the **errors** it reports
are appended to that call's result:

```text
LSP errors detected in this file, please fix:
<diagnostics file="/abs/path/src/Order.php">
ERROR [42:9] Undefined method 'totl'.
… and 2 more
</diagnostics>
```

```json
{"lsp": {"php": {"command": "intelephense", "args": ["--stdio"]},
         "typescript": {"command": "typescript-language-server", "args": ["--stdio"], "extensions": ["ts", "tsx"]}}}
```

- Each key is the LSP language identifier. Per server: `command` (required),
  `args`, `extensions` (default: the key itself), `env`,
  `initializationOptions`, `timeout` (seconds per request, default 10) and
  `disabled`. A malformed entry or a server that will not start is reported at
  launch and costs only that server.
- The servers start once, at launch, in the process that builds the tools, and
  every forked turn, tool call and sub-agent shares them. They are stopped at
  exit.
- Errors only (severity 1), at most 20 per file, positions 1-based. Warnings and
  hints are left out.
- It waits at most 5 seconds for the server's verdict, less when the chain has
  less time left (`BoundedHookInterface`). A server that says nothing in time,
  a file no server owns and a clean file all add nothing — silence is never
  reported as "no errors".
- Jailed like the post-edit lint: only a file the edit could reach is sent.
- **User tier only.** Starting a server is code execution, so no project
  settings file can name one.
- **It never refuses**, for the post-edit lint's reason.

The same servers answer the `Lsp` tool and give the `Read` tool its outline of
a file too long for one page.

### Auto-commit

`autoCommit` (user tier only, default `off`) commits what sugar-crush changes,
Aider's way:

- `turn` — when a turn settles in the TUI, every file changed since the
  checkpoint taken before its prompt is committed in one commit. The subject is
  a one-line Conventional-Commits message (`fix: …`, `feat: …`, at most 72
  characters) written by the cheap title model (`SUGARCRUSH_TITLE_MODEL` /
  `titleModel`) from the diff, or a plain `chore: update …` when there is no
  title model, the spend cap is reached, or its answer is unusable. One
  transcript line says what was committed or why not. A turn that a queued
  prompt follows at once is not committed on its own.
- `edit` — `AutoCommitHook` commits each `Write`/`Edit` as it lands, with the
  call's own `description` as the subject (`chore: rename the legacy config
  helper`), and tells the model the commit it made. It never refuses; a commit
  that fails leaves the change in the file, uncommitted, and says why.

Either way:

- **Your hooks run.** There is no `--no-verify`: a pre-commit hook that rejects
  the commit fails it.
- **Your work is never mixed in.** A file you had already changed before the
  turn is first committed as you had it, in its own commit (`chore: snapshot
  user changes before sugar-crush edit`), and the model's change goes on top.
  What you had is read from the turn's checkpoint; a tracked file with changes
  and no checkpoint to separate them is left uncommitted. Only the files
  sugar-crush changed are committed — the rest of your index is untouched.
- The author is you (`user.name`/`user.email`); the trailer is
  `attribution.commit`, or `Co-authored-by: sugar-crush
  <sugar-crush@noreply.invalid>` when that is unset (an empty string drops it).
- `/undo` reverts the last commit this session made — `git checkout HEAD~1 --
  <files>` then `git reset --soft HEAD~1` — and tells the model the change was
  undone. It refuses, changing nothing, when HEAD is not this session's commit,
  is a merge or the first commit, a file it changed has uncommitted changes now,
  a file it changed did not exist before it, or it is already on a remote. A
  session that never auto-committed gets the checkpoint `/undo` instead.

### What the audit log records

One line per record, in three shapes. The separator after the input says which
one it is:

```text
[2026-10-02 09:14:03] <session> Bash {"command":"ls"} => README.md\nsrc
[2026-10-02 09:14:05] <session> Read {"file_path":".env"} =! DENY hook: This hook prevents modification of files matching: …
[2026-10-02 09:14:09] <session> Bash {"command":"env"} =! WITHHELD "secret-scan": output contains AWS key, blocked
```

- `=>` is a call that ran. The excerpt is the first 200 bytes of its output,
  cut on a UTF-8 character boundary.
- `=! DENY <kind>:` is a call the engine refused before it ran. That covers a
  `PreToolUse` deny (including `protect-files` and a hook that timed out), a
  permission-gate refusal, an ASK that was refused or that nobody could answer,
  and arguments that cannot be encoded as JSON. `<kind>` is the denial kind's
  token: `hook`, `refused` or `unanswered`. A refused call never reaches
  `PostToolUse`, so the engine writes this line itself, through the registered
  `audit` hook, from the gate's refusal arm. Both the sequential path and the
  concurrent path gate there. Before audit F-H2, none of these left a record.
- `=! WITHHELD "<hook>":` is a call that ran but whose output a `PostToolUse`
  hook refused, or a hook in that chain threw (see the block rules above). The
  line keeps the hook's name and reason; it reads `=! WITHHELD:` when no single
  hook owns the refusal. It is the call's **only** record: the audit hook runs
  last in the chain, and a refusal returns before reaching it, so no `=>` line
  holds an excerpt of the output that was withheld. Before audit R7 the audit
  hook ran first — `registerBuiltIns()` is called ahead of every hook file — and
  wrote 200 bytes of the very output a secret scanner then refused.

Every field written into a line is escaped. That means the session, the tool
name, the input, the excerpt and the reason. C0 controls, DEL, NEL, U+2028 and
U+2029 are written as `\n`, `\r`, `\t`, `\xNN` or `\u{NNNN}`. Invalid UTF-8
becomes U+FFFD. A newline in a web page or a command's output therefore cannot
write a forged second record, and neither can a terminal escape. Backslashes
are left as they are, so the JSON input stays readable. The escaping is
therefore not reversible: it promises one record per line, not a decodable
field.

The input is capped at 4 KiB (`AuditHook::INPUT_CAP_BYTES`). Anything longer
ends in ` [truncated: <n> bytes]`, so a `Write` no longer copies the whole file
it wrote into a log that is never rotated.

### What `protect-files` covers

Before audit F-J2 the matcher was `^(Bash|Edit|Write|Read)$` and the `.env`
pattern wanted whitespace on both sides of the name, so `cat .env;true`,
`cat ".env"`, `.env.local` and — the easiest route — `Grep pattern="=" path="."
include_ignored=true` all read the secret under `bypass-permissions`, then
the default on every path. Now:

| Tool | Arguments judged |
|---|---|
| `Bash` | the raw `command` **and** its quote-removed words and redirection targets (`Permissions\ShellWords`), so `cat '.env'`, `cat .e''nv` and `cat < .git/"config"` are denied like their plain spellings |
| `Read`, `Edit`, `Write` | `file_path`, as given and canonicalised (symlinks resolved) |
| `Grep` | `path` (canonicalised) and `include` — not `pattern`, which is the text searched for |
| `Glob` | `path` (canonicalised) and `pattern` — `**/.env*` is refused; a `**/*` listing that happens to include `.env` is not, because naming a file is not reading it |
| `Lsp` | `path`, canonicalised |
| `mcp__*` | every string leaf of the decoded arguments, at any depth (not the raw JSON text) — so an MCP call whose free text merely mentions `.env` is refused too |

`Read`, `Grep`, `Glob` and `Lsp` cannot write, so they skip the write-only
policy patterns (`.sugar-crush/hooks.yaml`, `config.json` and `agents/`, plus
`.git/hooks/` and `.git/info/`, which are denied; and the always-asked rest —
`settings.json`, `settings.local.json`, the `skills/`, `commands/`, `rules/`
and `workflows/` directories, `.mcp.json`, and the `.claude/` / `.opencode/`
skill, agent and command trees); `Bash` and MCP tools get the full list — so
`cat .git/hooks/pre-commit` in `Bash` is refused, and `cat .mcp.json` asks,
where `Read` of either file is not. The asked rows are a hook's `ask()`, put to
you in every permission mode; a deny-class match in the same call wins.

`.git/hooks/` and `.git/info/` are on that list since audit F-J4: a file
written to `.git/hooks/pre-commit` runs on the user's next `git commit`,
outside any sugar-crush session, and `cp ./payload.sh ./.git/hooks/pre-commit`
was allowed under `bypass-permissions` and auto-run under `accept-edits`. The
known limit is `git config`: `git config core.hooksPath ./evil` (or
`core.fsmonitor`, a filter driver, …) rewrites `.git/config` without naming it,
so no path pattern sees it — the permission mode is the boundary there.

The secret-file family is a deliberate list: `.env`, `.env.<anything>`
(`.env.local`, `.env.production`, `.env.bak`) and direnv's `.envrc` are
denied; the committed templates `.env.example`, `.env.sample`, `.env.dist`,
`.env.template`, `.env.tpl` (and `.env.<stage>.example`) are allowed; and a
name where `.env` is glued to more name (`process.env.KEY`, `foo.env.example`,
`.environment`) or a `.env/` virtualenv directory is not a `.env` at all.

Key material is read-denied the same way: `*.pem` and `*.key` (any case, with a
stem or a glob stem, so `server.key` and `Glob **/*.key` are refused but `jq
.key` is not) and the SSH identities `id_rsa`, `id_dsa`, `id_ecdsa`,
`id_ed25519` with any suffix (`id_rsa_work`, `id_ed25519_sk`) — except the
`.pub` half, which exists to be shared. A public `.pem` certificate is refused
too: the extension cannot tell a chain from a key.

A hook cannot screen a *directory* search — `Grep path="."` contains `.env` —
so `Grep` itself never opens one: it always passes `--exclude=.env
--exclude=.envrc --exclude=.env.*` and `--exclude-dir=.git` (plus
`--exclude=config` when searching inside a `.git` directory), even with
`include_ignored: true`. That also skips the templates in a `Grep` walk; `Read`
them directly.

The `Bash` half stays best-effort: `$HOME` expansion, globs (`cat .en?`),
variables and `cd` are outside what any text match sees. It is defence in depth,
not a jail.

## Writing a hook in PHP

Implement `SugarCraft\Crush\Hooks\HookInterface` (`name()`, `event()`,
`matcher()`, `execute(HookContext): HookResult`) and register it on a
`HookManager`. `HookResult` has four constructors — `allow()`, `deny()`,
`ask()`, `modify()` — the same four the exit codes above map onto, each taking
an optional trailing `$additionalContext`: that is where a PHP hook puts the
model-visible note `ScriptHook` derives from stdout, and the chain collects it
the same way. This is the route for anything the six-key YAML shape cannot
express; it needs an embedder, not a config file.

## See also

- [`PERMISSIONS.md`](PERMISSIONS.md) — the layer the chain ends in.
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — "sugarcrush exits 2 and will not
  start".
