# Environment Variables

Every environment variable SugarCrush reads, in one place. Until this page
existed the surface was documented piecemeal — three variables in
`./bin/sugarcrush --help`, one more in the README's "Mouse" section, two more
only in PHP docblocks, and the rest discoverable only by `grep`.

Two groups are listed separately because they behave differently:

- **App variables** (`SUGARCRUSH_*`) configure SugarCrush itself. Every one is
  optional; each row gives the behaviour when it is unset.
- **Provider credential variables** are read on your behalf when
  `ProviderFactory` builds a provider's *default* config. They are the
  upstream vendors' own variable names, not ours, so they are spelled exactly
  as the vendor SDKs spell them.
- **Claude Code variables** are Claude Code's own relocation switches, read
  only by `/memory import claude` so it finds the memory Claude Code wrote.

Environment variables are the highest-precedence configuration tier: they win
over a choice persisted to `~/.sugar-crush/config.json` — written by the Ctrl+P
palette's Switch Model row, by `/model <name>`, by the palette's Switch Theme
row, or by `/theme <name>`, all four of which reach the identical write — which
is what makes them the right override for scripting and CI.

---

## App variables

All are named `SUGARCRUSH_*` — no underscore between `SUGAR` and `CRUSH`. See
[Deprecated aliases](#deprecated-aliases) for the two that briefly differed.

| Variable | Default when unset | Description |
|----------|--------------------|-------------|
| `SUGARCRUSH_PROVIDER` | offline `EchoProvider`, or a provider persisted to `~/.sugar-crush/config.json` | Which LLM provider to use: `openai`, `anthropic`, `claude-code`, `sglang`, `bedrock`, `vertex`, `custom`, or any provider name declared in a project `.sugar-crush/config.dev.json` (the repo ships `dev-sglang`). That block points at `https://skynet2.interserver.net/v1` serving `Qwen/Qwen3.8-Flash-Next` with no auth. Its `reasoningEffort` is sanitized per request into the template's exact vocabulary — `xhigh` (default), `medium`, `low`, nothing else (an out-of-vocabulary effort answers with a clean 400). The optional `templateKwargs` key merges UNDER any per-request template kwargs and ships verbatim as the top-level `chat_template_kwargs` field the chat template reads; the repo's shipped policy is `{"preserve_thinking": false}`, which makes a history re-render drop the assistant turns' stored reasoning instead of paying those replayed tokens back every turn — the template's default (`true`) was measured to re-render them, so flip the value only if cross-turn reasoning continuity is worth the token cost. |
| `SUGARCRUSH_MODEL` | the selected provider's default model | Pins the conversation model, overriding the provider default (e.g. `gpt-4o` for `openai`). |
| `SUGARCRUSH_TITLE_MODEL` | the `titleModel` key in `~/.sugar-crush/config.json`, else the provider's default model | The cheap small model used to auto-name a session after its first exchange. Kept separate from `SUGARCRUSH_MODEL` so naming never costs a full tool-capable agent turn. A run with no title backend (no provider — e.g. the `SUGARCRUSH_BACKEND_CMD` tier) is never auto-titled: the title call never falls back to the conversation backend, which may be an agentic command or carry tools. `/rename` still names the session. |
| `SUGARCRUSH_SUMMARY_MODEL` | the `summaryModel` key in `~/.sugar-crush/config.json`, else the provider's default model | The model that writes `/compact`'s exchange summaries. Runs on its own tool-less backend, so a compaction cannot call a tool or raise a permission prompt. Deliberately defaults to the provider's default rather than to `SUGARCRUSH_TITLE_MODEL`'s cheap titling model: a compaction summary is what the model will be shown of the earlier conversation from then on, and a bad one is permanent context loss. Unset it and a run with no provider at all still compacts, using the local heuristic. |
| `SUGARCRUSH_MAX_COST` | unset — no cap | A spend ceiling for this launch, in US dollars (fractional allowed, e.g. `2.50`). A leading `$` and surrounding whitespace are accepted, so `$2.50` works. `/budget` sets, shows and clears the same ceiling at runtime. A **present but unusable** value stops the launch with exit 2 rather than being ignored — `5USD`, `five dollars`, `0`, `-5` and `1e309` (which is infinity, and would install a cap that never triggers) all refuse to start. Empty or unset is absence and means no cap. The asymmetry with `/budget 0`, which merely answers in the transcript, is deliberate: that refusal is *visible*, whereas this variable is read once at launch, so discarding it silently would hand the user an uncapped session they believed was capped. Enforcement refuses the **next** turn once the reported spend has reached the cap; it does not abort one in flight, so the final total can overshoot by that one turn's cost. It also gates `/compact`'s model-written summaries — the compaction still happens, on the local heuristic, and says so. It only ever acts on figures a provider actually reported, and a streamed turn commonly reports none — so this is a budget guard, not a spending control. |
| `SUGARCRUSH_BACKEND_CMD` | unset — the provider is used directly | The **prose** shell-out: a command that reads JSON history on stdin and writes the reply to stdout, which is used **verbatim** — newlines, blank lines, lists and code fences all survive (a `trim()` at the two ends is the only transformation). Set it to avoid PHP provider SDKs entirely. Takes priority over a persisted provider choice, and over `SUGARCRUSH_BACKEND_CMD_STREAM`. There is no completion deadline on this path; a completion can legitimately run for many minutes. See [The two shell-out variables](#the-two-shell-out-variables). |
| `SUGARCRUSH_BACKEND_CMD_STREAM` | unset | The same shell-out under the **other** stdout contract, a **token stream** rather than prose: the command writes **one token per line**, the newline *between* two tokens is framing and is dropped, and a **blank line is a literal newline** in the answer (an unterminated empty remainder at EOF is nothing at all). So the protocol can express any string — but only through that blank-line rule. **The two variables are not interchangeable in either direction:** a wrapper written for `SUGARCRUSH_BACKEND_CMD` (e.g. `curl … \| jq -r '.content[0].text'`, which emits the model's prose) comes back through this one with every newline it emitted gone and each blank line collapsed to a single newline — so a paragraph break, a list and a code fence do not survive. Use this only for a wrapper that really does emit one token per line, such as `curl -sN … \| jq -r '.message.content'` against Ollama's streaming endpoint. Ranked below `SUGARCRUSH_BACKEND_CMD` (which wins when both are set) and above a persisted provider choice. No completion deadline either. See [The two shell-out variables](#the-two-shell-out-variables). |
| `SUGARCRUSH_SEARCH_ENDPOINT` | unset — **no default**; `WebSearch` refuses every call, naming this variable, until it is set | Search URL of the SearXNG instance the built-in `WebSearch` tool (and `/websearch`) queries, e.g. `https://searx.example.org/search`. There is deliberately no built-in default (audit F-W3): the one that used to ship was a private host reached over cleartext `http://`, so every model-composed query — which routinely quotes code, file names and error text from your repository — crossed the network unencrypted to a third party, unprompted under the default `bypass-permissions`. Prefer an `https://` URL. An empty value counts as unset. A redirect from the endpoint is refused, never followed, so give the final URL. |
| `SUGARCRUSH_PERMISSION_MODE` | the `permissionMode` key in `~/.sugar-crush/config.json`, else `bypass-permissions` | The launch's permission mode: `default`, `accept-edits`, `plan`, `auto`, `dont-ask` or `bypass-permissions`. Same vocabulary an agent preset's `permissionMode:` frontmatter uses, so `plan` means the same thing in both places. An **unrecognised value stops the launch** with exit 2 rather than being ignored — every fallback in the chain ends somewhere more permissive, so silently discarding a mode the user set on purpose is a fail-open. An empty value counts as unset and falls through to the config. The permissive default is a **stopgap**: the main loop had no gate at all before it existed, and every Ask-answering mode failed closed on the engine path because nothing anywhere attached an approver, so a stricter default would have refused edits rather than prompting. Half of that is now closed — the one-shot `-p` path and the background-session daemon attach a console approver, which asks on stderr at a terminal and refuses with a reason (naming the tool, the mode and the remedies) without one — but the interactive TUI's engine path still fails closed, and that is the path a default mode would be judged on, so the default has not moved with it. See [`PERMISSIONS.md`](PERMISSIONS.md). With no `permissionRules` configured, `bypass-permissions` is *identical* to having no gate — the `rm -rf /` circuit breaker refuses nothing that `ConfirmRemoveHook` does not already refuse more broadly and earlier. What it buys is a gate that is reachable and configurable. |
| `SUGARCRUSH_SESSION_RETENTION_DAYS` | `0` — retention is **off**, nothing is ever pruned | A positive whole number of days. Each launch drops sessions untouched for at least that long and reports what it removed. **This row used to say the report goes "on stderr"; since round 42 it goes to both channels, split.** The one-line summary (`retention removed 3 unnamed sessions untouched for 30+ days (ids on stderr)`) is seeded into the transcript as well, because an interactive launch paints the alternate screen over stderr before you can read it and a deleted conversation is not something to learn about after the session ends; the per-session ids stay on stderr alone, because one transcript row per deleted session is a per-entry fan-out that buries the rows around it (launch notices are display-only — they never reach the model — so the cost is the user's scrollback, not tokens). See [`SETTINGS.md`](SETTINGS.md) for the routing rule. A session you have named is never pruned whatever its age, and neither is the session the launch is about to resume. Non-numeric, empty and negative values read as `0`; values are capped at `36500` (100 years). |
| `SUGARCRUSH_CONNECT_TIMEOUT` | `15.0` seconds | Connect-phase bound (in seconds, fractional allowed) for provider HTTP transports. This bounds establishing the connection only — it is **not** a total-request timeout, because a completion can legitimately run for many minutes. Non-numeric values, and anything below `0.001`, fall back to the default rather than disabling the bound. |
| `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS` | unset — a turn's read-only tool calls run concurrently | Set to any value other than empty or `0` to force every tool call in a turn to run sequentially. Also settable as `parallelToolCalls: false` in `~/.sugar-crush/config.json`; the environment variable wins. |
| `SUGARCRUSH_PARALLEL_TOOL_DEADLINE` | `90` seconds | Wall-clock ceiling for one batch of concurrently dispatched tool calls. Also settable as `parallelToolDeadlineSeconds` in `~/.sugar-crush/config.json`; the environment variable wins. |
| `SUGARCRUSH_DISABLE_MOUSE` | unset — mouse tracking is on | Set to any value other than empty or `0` to turn mouse tracking off entirely: no wheel scrolling, no clickable tool calls, session tabs, palette rows or menu bar. The escape hatch for terminals whose own selection behaviour you would rather keep. |
| `SUGARCRUSH_DISABLE_MOUSE_CLICKS` | unset — clicks are handled | Set to any value other than empty or `0` to ignore click gestures while keeping wheel scrolling. Narrower than `SUGARCRUSH_DISABLE_MOUSE`. |
| `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS` | unset — suggestions are on | Set to any value other than empty or `0` to stop the grayed next-message suggestion the empty input box shows after each turn (`→` accepts it). Each suggestion is one extra call on the `SUGARCRUSH_TITLE_MODEL` backend — tool-less, so it can never run a tool or raise a permission prompt — shown the last 12 messages of the transcript; it counts towards the spend total and stops once `SUGARCRUSH_MAX_COST` is reached. A run with no title backend (no provider) never makes the call. |
| `SUGARCRUSH_DISABLE_PROMPT_CACHE` | unset — breakpoints are enabled | Set to any value other than empty or `0` to stop the `vertex` and `bedrock` providers marking prompt-cache breakpoints: no `cache_control` on Vertex's Claude requests, no `cachePoint` blocks on Bedrock's. Read once, when the provider is built (`ProviderFactory::promptCacheEnabled()`, through `CacheBreakpoints::disabledFromEnvironment()`), and it outranks the `promptCache` setting in [`SETTINGS.md`](SETTINGS.md), which is the persistent way to say the same thing. Cache tokens a provider still reports (Gemini 2.5 caches on its own) are priced either way. Turn it on when a model rejects the marks; expect every request to bill its whole prompt at the full input rate while it is on. |
| `SUGARCRUSH_BACKGROUND` | unset — the terminal is asked directly over OSC 11, falling back to `COLORFGBG`, and to dark when neither says anything | Forces what the `adaptive` theme believes about the terminal's background: `light` or `dark`, case-insensitive and surrounding whitespace ignored. **Any other value is ignored** rather than treated as an error, so a typo falls through to detection instead of pinning the wrong palette. This is a statement, not a measurement, so it outranks *both* detection sources — including the terminal's own OSC 11 answer, which is otherwise authoritative. That ordering is what keeps this variable useful at all: most terminals do answer, so ranking the measurement first would leave it with nothing to override. Only consulted by the `adaptive` theme — a theme picked by name (`/theme light`) does not detect anything. |
| `SUGARCRUSH_WORKTREES_DIR` | `WorktreeConfig`'s `basePath`, itself defaulting to `.sugar-crush/worktrees/` | Base directory under which per-teammate git worktrees are created. Replaces the configured path outright. A `~/` prefix is expanded; a value containing `..` is rejected rather than resolved. |
| `SUGARCRUSH_SHARE_UPLOAD_URL` | unset — `/share` only writes a local file | Opt-in upload host for `/share`. `/share` always writes the export locally (`~/.sugar-crush/exports/`, or a path inside the project); only when this names a host does it also try `ShareUploader`, which has no backend yet, so the reply says the upload did not happen beside the local path. There is no public default host. |
| `SUGARCRUSH_DEBUG_SKILLS` | unset — skill-load failures are silent on stderr | Set to any value other than empty or `0` to put `SkillLoader`'s per-skip and per-refused-directory lines back on stderr. Off by default because the TUI renders to stdout under an alt screen and a skill scan also runs mid-session on the Ctrl+P provider switch, so a stray stderr line lands inside a frame the renderer believes it owns; the diagnostic is not lost when it is off — every skip is readable from `SkillManager::skipped()` and the launch prints one bounded summary line. Added to this table late: it was the one variable `src/` reads that this page did not list, which made the page's own "every environment variable" claim false. |
| `SUGARCRUSH_DEBUG_COMMANDS` | unset — command-discovery refusals are silent on stderr | Set to any value other than empty or `0` to put `CommandLoader`'s five refusal lines back on stderr: the two tier-directory refusals, the per-file containment skip, the per-file parse failure, and the control-plane name refusal. Off by default for two reasons rather than one. Three of the five are paired with a collector that `Bootstrap::chat()` drains onto `warnPermissionConfigInTranscript()`, which already writes a `sugarcrush: `-prefixed line to stderr AND seeds a transcript row — so the raw copy was the same sentence twice on one channel. The other two are per-file and are collected on `CommandLoader::skippedFiles()`, which was added alongside this flag precisely so gating them would not be a deletion. Turn it on when a command file is missing from the popup: the two per-file refusals are collected on `CommandLoader::skippedFiles()`, which nothing drains yet, so this flag is currently their only reader. For the other three it adds a raw unprefixed copy rather than a fuller one — the transcript seam clips and caps its TRANSCRIPT ROW, not its stderr line, which reaches you whole either way. |
| `SUGARCRUSH_DEBUG_RULES` | unset — rule-discovery refusals are silent on stderr | Set to any value other than empty or `0` to put `RuleLoader`'s refusal lines back on stderr: a tier directory that escapes its anchor, a rules file that escapes the directory it lives in, a file past the per-tier count cap, and a file whose frontmatter fails to parse. Off by default because the TUI renders under an alt screen where a stray stderr line lands inside a frame the renderer owns. Unlike the command tier's refusals, the rules loader's are deliberately loader-local — nothing drains them at launch, so `RuleLoader::refusedPaths()` and `RuleLoader::skippedFiles()` are readable by an embedder but are not yet surfaced on the transcript; this flag is currently the only way a refusal reaches a terminal. Turn it on when a rules file does not appear in the assembled prompt. |
| `SUGARCRUSH_MCP_DISABLE` | unset — project MCP is enabled and `.mcp.json` decides | Set to `1`, `true` or `yes` (case-insensitive) to silence project MCP entirely: `Bootstrap::mcpConfigDecision()` answers the same `absent` decision a config-less checkout answers, so nothing launches, no server child is ever spawned, and no trust opt-in notice is recorded. Unset, `0`, empty or ANY other value keeps MCP enabled — this is the one variable whose off-switch is an exact three-word vocabulary rather than "any value other than empty or 0"; the deliberate narrowness means a launcher (or a test) that cannot scrub its inherited environment still gets the documented default-on behavior unless one of the three words was explicitly set. `scripts/parallel-tests.sh` sets it to `1` on the shard launch (E737): K=8 shards run at the checkout root, where an operator's own trusted `.mcp.json` used to turn any suite sibling that walks the MCP launch funnel into a spawner of arbitrary servers. |
| `SUGARCRUSH_DEBUG_STREAM` | unset — a throwing `onToken` embedder sink is detached silently | Set to any value other than empty or `0` to keep `Chat`'s "onToken observer threw, detaching it for this turn" line on stderr (E175). Off by default because the line fires MID-TURN — the alternate screen has been up since launch, the turn completes normally either way, and the audience is the embedder who owns the throwing sink rather than the person at the terminal. The gate covers the REPORT only, never the DETACH: whatever this flag says, a sink that throws is dropped for the remainder of the turn so one bad delta cannot strand the reply or cost the rest of the turn. Turn it on when an embedder's `onToken` callback appears to simply stop receiving chunks. |

Every flag-style variable above (`SUGARCRUSH_DISABLE_MOUSE`,
`SUGARCRUSH_DISABLE_MOUSE_CLICKS`, `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS`,
`SUGARCRUSH_DISABLE_PROMPT_CACHE`, `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS`)
treats unset, empty **and the literal string `0`** as "not set", so
`SUGARCRUSH_DISABLE_MOUSE=0` reads as "leave the mouse on" rather than as
any-value-means-true.

### The two shell-out variables

They are two **different protocols**, which is the whole reason there are two
variables rather than one. `SUGARCRUSH_BACKEND_CMD` is prose: stdout *is* the
answer. `SUGARCRUSH_BACKEND_CMD_STREAM` is a token stream: one token per line,
the newline between tokens is framing, and a terminated blank line is a literal
newline in the answer. Neither can stand in for the other, and nothing tries to
guess which one it was handed. Measured on this tree against a wrapper printing
`"Para one line one.\nPara one line two.\n\nPara two.\n"`:

| variable | what SugarCrush received |
|---|---|
| `SUGARCRUSH_BACKEND_CMD` | `"Para one line one.\nPara one line two.\n\nPara two."` |
| `SUGARCRUSH_BACKEND_CMD_STREAM` | `"Para one line one.Para one line two.\nPara two."` |

**Streaming is a live screen.** The streaming backend calls its callback once
per token, at the moment that token's newline lands on the pipe, and the TUI
repaints between them. That second half used to be false and is worth recording
because it was measured both ways: the read loop was synchronous and
`completeAsync()` ran the whole of it inside one ReactPHP `futureTick`, so the
event loop was blocked for the duration and the render tick could not run until
the answer had already resolved. Measured on this tree — `completeAsync()` under
a live `Loop::run()`, a 50ms periodic timer standing in for the render tick, and
a wrapper emitting six tokens 300ms apart:

| | before | after |
|---|---|---|
| callback invocations | 6, at 0.005s / 0.304s / 0.608s / 0.907s / 1.210s / 1.514s | 6, at 0.010s / 0.309s / 0.609s / 0.914s / 1.214s / 1.515s |
| loop ticks during the stream | **0** | **36** |

The same is true of `SUGARCRUSH_BACKEND_CMD`, which had the same defect in a
worse form — its promise executor ran the blocking call immediately, so the
freeze started before the promise was even returned. Both are now drained from a
periodic timer on the event loop rather than read to completion in one go. (The
one-shot `-p` path passes no callback at all and blocks deliberately.)

**A long conversation no longer hangs a shell-out wrapper.** Both variables used
to hand the whole history to the command in one blocking `fwrite()` and only
then read its output. Past the kernel's ~64K pipe buffer that deadlocks against
any command that *echoes* its input — the shape of every streaming wrapper, and
of `cat` — because the parent is blocked writing a full stdin pipe while the
child is blocked writing a full stdout pipe, and neither path has a completion
deadline that would ever end it. Measured with `cat` as the command: a 64 KB
history returned, 130 KB returned, 200 KB hung until it was killed. Both paths
now interleave the write with the reads, `-p` included, which keeps parking in
the kernel at 0% CPU (0.03% before, 0.04% after, against a wrapper that thinks
for two seconds) and returns at 200 KB, 1 MB and 8 MB.

**Absence means unset, empty *or* whitespace-only,** for both variables. One
helper (`Bootstrap::backendCommandEnv()`) defines that, and every site that
either selects the tier or labels the run asks it, so the tier a launch selects
and the tier it reports can never disagree. This matters because
`export SUGARCRUSH_BACKEND_CMD='   '` previously selected the shell-out tier,
ran `sh -c '   '`, exited 0 and returned an empty assistant message while
nothing warned — a run with no model, no answer and no complaint. There is no
command a caller could mean by a string of spaces, and reading it as absence is
the only reading that leaves the next tier reachable. The value is passed on
**untrimmed**, so a command with leading whitespace still runs as written.

**Windows: `bypass_shell` is no longer passed.** Both shell-out backends call
`proc_open()` with no options array. They previously passed
`['bypass_shell' => true]` — a Windows-only option — for both command shapes,
behind a branch whose two arms were identical, so relative to earlier releases a
Windows user loses that option on both shapes. What is known: the option is
Windows-only; it was **measured inert on Linux/PHP 8.3** (with it set, the
string `"printf a; printf b"` still went through `/bin/sh -c`, and the list
`["printf", "a;b"]` still exec'd directly — byte-identical either way); and
passing the command as an **array** does not need it in the first place, because
PHP then opens the process directly, without a shell, and escapes the arguments
itself (PHP 7.4 UPGRADING: "the process will be opened directly … PHP will take
care of any necessary argument escaping"). Nothing is claimed here about what
Windows does with a **string** command — that is not something this project can
run. If you are on Windows, pass your command as a list.

---

## Deprecated aliases

Two app variables originally carried an underscore after `SUGAR`, unlike every
other variable SugarCrush reads. They were renamed to the canonical spelling.
The old names keep working for one release, so an existing export does not
silently change behaviour the day the rename lands. **When both are set the
canonical name wins** — which is what lets you add the new export to a shared
profile before removing the old one.

| Deprecated | Canonical |
|------------|-----------|
| `SUGAR_CRUSH_WORKTREES_DIR` | `SUGARCRUSH_WORKTREES_DIR` |
| `SUGAR_CRUSH_SHARE_UPLOAD_URL` | `SUGARCRUSH_SHARE_UPLOAD_URL` |

No deprecation warning is printed. The only reader of each runs while the
interactive TUI owns the terminal, where a stray stderr line corrupts the frame
rather than informing anyone.

---

## Provider credential variables

These are read by `ProviderFactory` when it builds a provider's *default*
config — that is, when you select a provider by name and do not pass an
explicit config array. Supplying the credential in a config array instead
takes priority; nothing here is consulted in that case.

| Variable | Provider | Description |
|----------|----------|-------------|
| `OPENAI_API_KEY` | `openai` | API key. Defaults to the empty string, which the provider will reject. |
| `OPENAI_ORG_ID` | `openai` | Optional organization ID. Defaults to `null`. |
| `ANTHROPIC_API_KEY` | `anthropic`, `claude-code` | API key, sent as `x-api-key`. |
| `ANTHROPIC_AUTH_TOKEN` | `claude-code` | Alternative bearer credential, forwarded into the `claude` binary's environment. |
| `ANTHROPIC_BASE_URL` | `anthropic`, `claude-code` | API base URL, the root without `/v1`. Defaults to `https://api.anthropic.com`. The `anthropic` type sends to `<base>/v1/chat/completions`; a base that already ends in `/v1` is used as it is. |
| `SGLANG_API_KEY` | `sglang` | Optional key for a self-hosted OpenAI-compatible endpoint. Defaults to `null`, which is correct for an unauthenticated local server. |
| `GCP_PROJECT_ID` | `vertex` | Google Cloud project ID. Defaults to the empty string. |

`bedrock` reads no variable of its own here — it relies on the AWS SDK's
ambient credential chain (`AWS_*` variables, `~/.aws/credentials`, instance
roles), resolved by `aws/aws-sdk-php` rather than by SugarCrush.

`CUSTOM_PROVIDER_API_KEY` is the *default* variable name for
`CustomProvider::openAiCompatibleFromEnv()`, but that is a caller-supplied
parameter — pass a different name and that variable is read instead. It is
not consulted by the `custom` provider type's default config.

---

## Claude Code variables

Read by `/memory import claude` (`ForeignMemoryImporter`) so the import looks
where Claude Code itself keeps a project's memory. They are Claude Code's own
names and follow Claude Code's rules (audit R11):

| Variable | Default when unset | Description |
|----------|--------------------|-------------|
| `CLAUDE_CONFIG_DIR` | `~/.claude` | Claude Code's relocated config directory; the import reads `<dir>/projects/<slug>/memory/` instead of `~/.claude/projects/<slug>/memory/`. Blank counts as unset. A **relative** value is refused (it would resolve inside the checkout the session runs in, a tree the repository chooses), and so is a directory that is world-writable or owned by another account — the same gate the derived `~/.claude` faces; the refusal is named in the command's reply and nothing is imported. |
| `CLAUDE_CODE_PROJECT_DIR_NAME` | the project slug (the absolute path with every non-alphanumeric character turned into `-`) | Replaces the slug outright — `<config dir>/projects/<name>/memory/`, with no fallback to the slug directory. As in Claude Code, it is honoured **only while `CLAUDE_CONFIG_DIR` is set**, and only for a name of 1–64 letters, digits, `_` and `-` that is not a reserved Windows device name (`CON`, `PRN`, `AUX`, `NUL`, `COM0`–`COM9`, `LPT0`–`LPT9`); otherwise it is ignored and the slug applies, which is where Claude Code wrote. |

---

## Variables read from any config file

`ProviderFactory` expands `${VAR}` and `${VAR:-default}` placeholders in
every string value of a provider block, so **any** environment variable can be
referenced from one. Today exactly one file supplies provider blocks: the
`.sugar-crush/config.dev.json` that ships **inside the sugar-crush package**,
next to `src/` (the repo's copy declares `dev-sglang`). It is not looked up in
the project you run sugar-crush in, and not under your home directory.
`~/.sugar-crush/config.json` and a project `.sugar-crush/` are never read for a
`providers` map, so a block written in either is ignored. User-defined
providers in those files are planned, not current behaviour. Until then, a built-in type is configured only through the
credential variables above (`ANTHROPIC_BASE_URL` is the one endpoint override;
the `sglang` type always targets `http://localhost:30000`). Any other endpoint
needs an edit to the package's own `config.dev.json` in a checkout you
control. A block there looks like this:

```json
{
  "providers": {
    "my-endpoint": {
      "type": "sglang",
      "baseUrl": "${MY_SGLANG_URL:-http://localhost:30000}",
      "apiKey": "${MY_SGLANG_KEY}",
      "toolCallParser": "${SUGARCRUSH_TOOL_CALL_PARSER}"
    }
  }
}
```

An unset variable with no `:-default` resolves to the empty string.

`SUGARCRUSH_TOOL_CALL_PARSER` is worth calling out because it appears in
discussions of SugarCrush's environment surface but is **not** read by any
direct `getenv()` call. It works only through the placeholder mechanism above,
and only if you write that placeholder into the package's `config.dev.json`
yourself — the shipped copy does not contain it. Its three valid values are `openai`,
`minimax-xml-fallback` and `dsml`; an unset variable resolves to the empty
string, which is treated as "key absent" rather than as a typo.

"Key absent" is not the same as `openai`. With no value the parser is derived
from the configured model — `dsml` for the DeepSeek-V4 family, `openai` for
everything else — so leaving the placeholder unset is the recommended state,
not a fallback to the OpenAI-only strategy.

---

## OS variables

Read for their standard meanings, not as SugarCrush settings:

| Variable | Used for |
|----------|----------|
| `HOME` (`USERPROFILE` on Windows) | Locating `~/.sugar-crush/`, expanding `~` in `@import` paths, and the environment handed to forked git commands. Falls back to `/tmp`. |
| `PATH` | Resolving MCP server executables and the git binary. |
| `TMUX`, `TERM_PROGRAM` | Multiplexer detection (tmux vs iTerm2) for split-pane support. |
| `COLORFGBG` | Background detection for the `adaptive` theme — the *last* resort, consulted when `SUGARCRUSH_BACKGROUND` has not settled it **and** the terminal has not answered the OSC 11 background query the TUI sends at startup (it has not answered *yet* during the first frames after launch; it never will on a terminal that does not implement the query, over a pipe, or on the one-shot `-p`/`run` path, which starts no TUI and so never asks). The **last** `;`-separated field is the background — xterm/rxvt emit a three-field `fg;faint;bg` form as well as the usual two — and it is read as an xterm-256 palette index put through a luminance test, not matched against a list of "light" indices. Anything that is not a palette index at all (the literal `default`, an empty or malformed value, a number above 255) reads as dark. |
