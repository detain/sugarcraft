# Settings

Four files can contribute a setting. Two of them belong to you, two of them
arrive with a `git clone` — and that split is what every rule on this page is
about.

Everything here is implemented by `SugarCraft\Crush\Config\LayeredSettings`
and read through `Bootstrap::readUserConfig()`.

## The four layers

Lowest precedence first. On a key present in more than one file, the **highest
listed** file wins.

| # | File | Whose | Read when |
|---|---|---|---|
| 1 | `<root>/.sugar-crush/settings.json` | the project's, meant to be committed | the root is listed in `trustedProjectSettings` **and** an entry point named it (see below) |
| 2 | `<root>/.sugar-crush/settings.local.json` | the project's, meant to be `.gitignore`d | same gate as #1 |
| 3 | `~/.sugar-crush/settings.json` | yours, hand-authored | whenever `$HOME` resolves and passes the ownership check |
| 4 | `~/.sugar-crush/config.json` | yours, partly **written by the CLI** | always — but see the two qualifications below |

**Layers 1 and 2 need a project root to have been *named*, not just trusted.**
`readUserConfig()` takes no `$root` argument and deliberately does not fall
back to `getcwd()` — the root is remembered by whichever entry point resolved
it (`chat()`, `app()`, `backend()`, `backendFor()`, via
`Bootstrap::useProjectRootForSettings()`). A subcommand that never resolves a
root — `sugarcrush models`, say — is **user-tier only even inside a trusted
checkout**. That is deliberate: deriving the root from the working directory
would take settings from wherever you were standing rather than from the
repository `--root` named.

**`<root>` is the repository, not the subdirectory you launched from** (audit
15d-13 (b)). `cd repo/src && sugarcrush` used to look for every `.sugar-crush/*`
file under `repo/src/.sugar-crush`, so a trusted repository's settings were
silently ignored one directory down. The `.sugar-crush/*` lookups — settings,
skills, rules (and `RULES.md`), commands, workflows, agent presets, hooks,
memory — and `.mcp.json` now walk up (`Support\ProjectRoot`):

1. the launch directory itself, when it holds `.sugar-crush/`, `.mcp.json` or
   `.git` — every launch that worked before resolves where it did;
2. otherwise, inside a git work tree (`git rev-parse --show-toplevel`, bounded
   at 2 s), the nearest directory up to the work-tree root that holds
   `.sugar-crush/` or `.mcp.json` — so a monorepo package keeps its own — and
   the work-tree root when none does;
3. outside any work tree, the launch directory. There is nothing to bound the
   walk with, and an unbounded one reaches `~/.sugar-crush`, which is *your*
   tier, not a project's. For the same reason a work tree rooted at or above
   your home directory (a dotfiles repository) is never walked.

The trust keys (`trustedProjectSettings` and the other three) are matched
against that same root, so the entry to write is the repository's path. The
**working directory does not move**: Bash, Read, Edit and the other tools,
hooks and spawned sessions still run in the directory you launched from.

**Layer 4 is not always `~/.sugar-crush/config.json`.** Two things move it:

- `--config <path>` repoints it (`Bootstrap::userConfigPath()`), and it moves
  **that file only** — layer 3 stays in `~/.sugar-crush`, deliberately, so a
  repository shipping a `crush.json` and a README saying
  `sugarcrush --config ./crush.json` cannot hand itself the user tier.
- when the home directory cannot be established at all, `HomeDirectory::path()`
  falls back to the system temp directory, so layer 4 silently becomes
  `<tmpdir>/.sugar-crush/config.json`. A real launch refuses before reaching
  that (`trustedConfigDirPath()` throws), so it only bites direct
  `readUserConfig()` callers such as the per-turn read.

Layer 4 is also only *partly* CLI-written: exactly two keys are ever written
through the chat's config-change door (`provider` and `theme` — see below),
and a third, `layout`, is written by the shell itself each time a pane is
docked, moved, undocked or reset — its value is the versioned `DockLayout`
manifest, and the door is `App`'s own `onLayoutChange` hook rather than
Chat's. The settings view's save is a door of its own too — `SettingsWriter`,
see [Saving from the settings view](#saving-from-the-settings-view) — and it
writes the keys the view changed, never `provider` or `theme`; `/model
<provider> <model>` writes `models` through the same writer. Everything else
in it, including `trustedProjectSettings`, you hand-author.

Two orderings on that table are deliberate and both cost something:

**Your files outrank the project's** — the reverse of the convention most
editors use. Layer 1 arrived with a clone. If it outranked you, a checkout
could change a setting you had already made for yourself, and your own file
would look broken. A project can only fill in what you left unsaid.

**`config.json` outranks `settings.json`.** `config.json` is the *older* of
the two names — nothing in `src/` marks it deprecated and it is still the only
file the CLI writes back to. It has to be the file read back. The other way
round, a `settings.json` naming `theme` would outrank what `/theme` just
wrote: the theme would repaint immediately (`/theme` mutates the live `Chat`)
and then silently revert on the next launch, with no error and nothing
pointing at the file responsible. What breaks is *persistence*, not the
visible command.

Exactly two keys reach it through `Chat`'s config-change door, and **each has
two producers — a palette row and a slash command**: `provider` (the Ctrl+P palette's "Switch
Model" row, and `/model <name>`) and `theme` (the palette's "Switch Theme" row,
and `/theme <name>`). Those are the only two values `Chat`'s `onConfigChange`
callback is invoked with, and `Bootstrap` wires that callback to
`writeUserConfig()` at one site.

The `/model` half is easy to miss and this page missed it: `Chat`'s
`handleModelCommand()` ends in `selectPaletteProvider()`, which is the *sole*
site that invokes `onConfigChange('provider', …)`. The palette row and the
command are not two writers — they are one writer with two doors, which is also
why a `/model` choice persists exactly as a palette choice does.

**`settings.local.json` gets the same gate as its tracked sibling.** The name
says "local" and `.gitignore` says it is not committed, but neither is a
property of a repository *someone else* wrote: `.gitignore` is advice to
whoever commits, `git add -f` overrides it, and a hostile checkout ships a
`settings.local.json` as readily as a `settings.json`. The two differ in
precedence only.

## Opting a project in

Layers 1 and 2 are ignored entirely until you list the project root in your
own layer-4 config:

```json
{ "trustedProjectSettings": ["/home/you/src/that-project"] }
```

**Put it in the file `--config` actually names.** The trust list is read
through `Bootstrap::permissionConfigLayers()`, which honours
`$configPathOverride` — so under `--config alt.json` a
`trustedProjectSettings` sitting in `~/.sugar-crush/config.json` is never
consulted, and the project layer contributes nothing. Measured both ways.

It will **not** work from `~/.sugar-crush/settings.json` either:
`permissionSettingsLayer()` filters that file down to `permissionMode` and
`permissionRules` before the merge, so a trust key there is dropped. (The one
exception is `--config` pointed *at* your own `settings.json`, which collapses
the two layers and reads the whole file.)

This is the fourth of the four `trustedProject*` grants; the shared
properties — absolute paths only, read once per process and frozen, refused
loudly — are documented once, in
[`PERMISSIONS.md`](PERMISSIONS.md#the-four-trustedproject-keys), along with the
one behaviour this key does **not** share with the other three.

## Which keys are layered at all

A key not in this list is answered by layer 4 alone **through this stack**,
exactly as it was before layering existed. This is a security boundary, not a
scoping shortcut: it is what stops a project file from writing
`permissionMode`, `permissionRules`, any `trustedProject*` key — or
`trustedProjectSettings` itself, so a project cannot add itself to the list of
trusted projects.

"Through this stack" is load-bearing rather than hedging. `permissionMode` and
`permissionRules` are answered by layers 3 **and** 4 — just not by
`LayeredSettings`. They travel `Bootstrap::permissionConfigLayers()`, which
stacks `~/.sugar-crush/settings.json` beneath `config.json` with the same
later-wins ordering. Measured: `{"permissionMode":"plan"}` in
`~/.sugar-crush/settings.json` with **no `config.json` at all** resolves the
gate to `plan`. What no lower layer can reach is the *project* tier, which is
the property this section is really about.

**An empty value does not override.** `""` and `null` are how a key is spelled
when nothing is being set there, so `permissionConfigLayers()` drops such a
value out of the later layer *before* the merge rather than letting it displace
what an earlier layer set. Measured: `{"permissionMode":"plan"}` in
`~/.sugar-crush/settings.json` with `{"permissionMode":""}` in `config.json`
resolves the gate to `plan`, and the ignored key is reported on stderr naming
both files. Before that filter existed the same pair resolved to the built-in
default, silently — and the same shape dropped a `permissionRules` `deny` that
`settings.json` had configured.

Only those two spellings count as empty, and the narrowness is the point:
`"permissionMode": "  "` names no mode and still refuses the launch by name,
and `"permissionRules": []` is a well-formed empty list that still outranks
`settings.json` under the later-wins rule above.

<!-- settings:layered:begin -->
| Key | Read by | Project may set |
|---|---|---|
| `provider` | `Bootstrap::selectedProviderName()`, `backend()` | **no** |
| `models` | `Bootstrap::selectedModelName()`, `backendFor()`, `selectedProviderLabel()` | **no** |
| `titleModel` | `Bootstrap::titleBackend()` | **no** |
| `summaryModel` | `Bootstrap::summaryModel()`, `summaryBackend()` | **no** |
| `maxOutputTokens` | `EngineBackend::complete()` | **no** |
| `modelPrices` | `ProviderFactory::createOpenAI()`, `createAnthropic()`, `createVertex()`, `createBedrock()`, `createCustom()` → `userTierModelPrices()` | **no** |
| `extraBody` | `ProviderFactory::createCustom()` → `CustomProvider` | **no** |
| `thinkingBudget` | `ProviderFactory::createVertex()` → `VertexProvider` | **no** |
| `promptCache` | `ProviderFactory::createVertex()`, `createBedrock()` → `promptCacheEnabled()` | **no** |
| `parallelToolCalls` | `EngineBackend::complete()` | yes |
| `parallelToolDeadlineSeconds` | `EngineBackend::complete()` | yes |
| `maxToolSteps` | `Bootstrap::backend()`, `Chat::applySettings()` → `resolvedMaxToolSteps()` | **no** |
| `contextWindow` | `ProviderFactory::createOpenAI()`, `createAnthropic()`, `createCustom()` → each provider's `contextWindow()` | **no** |
| `secretEnvAllowlist` | `Bootstrap::tools()` → `installSecretEnvAllowlist()` | **no** |
| `allowedTools` | `Bootstrap::tools()` → `filterToolSet()` | **no** |
| `disabledTools` | `Bootstrap::tools()` → `filterToolSet()` | yes |
| `bashSandbox` | `Bash::fromCatalog()` → `Bubblewrap::fromSetting()` | **no** |
| `testCommand` | `Bootstrap::hooks()` → `TestRunner::withCommand()` | **no** |
| `autoTest` | `Bootstrap::hooks()` → `AutoTestHook` | **no** |
| `instructions` | `Bootstrap::forcedInstructions()` | **no** |
| `disabledRules` | `Bootstrap::chat()` → `RulesState::new()` | **no** |
| `embeddingModel` | `EngineBackend::completeAsync()` | **no** |
| `disabledSkills` | `Bootstrap::chat()` → `skillRegistry()` | yes |
| `enabledSkills` | `Bootstrap::backend()`, `backendFor()` → `promptEnabledSkills()` | **no** |
| `subagentModel` | `Bootstrap::agentManager()` | **no** |
| `includeGitInstructions` | `Bootstrap::tools()` → `Bash::withGitGuidance()` | yes |
| `attribution` | `Bootstrap::tools()` → `Bash::withGitGuidance()` | **no** |
| `lsp` | `Bootstrap::lspClient()` → `LspLauncher::fromConfig()` | **no** |
| `autoCommit` | `Bootstrap::hooks()` → `AutoCommitHook`; `Chat` (turn mode) | **no** |
| `theme` | `Bootstrap::chat()` | yes |
| `statusLine` | `Bootstrap::chat()`, `Chat::applySettings()` → `StatusLineCommand::fromSettings()` | **no** |
| `layout` | `Bootstrap::app()` → `App::$dock` via `DockLayout::fromArray()` | **no** |
| `lintCommands` | `Bootstrap::hooks()` → `LintRunner::withCommands()` | **no** |
| `connectTimeoutSeconds` | `HttpClientDefaults::connectTimeoutSeconds()`, when a provider client is built | yes |
| `providerRetryAttempts` | `Runtime::runStreaming()`, `runBatch()`, `AgentManager::executeSubAgent()` → `TransientFailure::maxAttempts()` | yes |
| `providerRetryBaseBackoffMs` | `TransientFailure::backoff()` → `baseBackoffMicroseconds()` | yes |
<!-- settings:layered:end -->

Every key in that table has a real reader named beside it, and the table is
COMPLETE — `LayeredSettings::LAYERED_KEYS` is exactly these thirty-six, and the
"Project may set" column is exactly `PROJECT_TIER_KEYS`. Both halves are
asserted by `TrustKeyDocumentationDriftTest`, so a key added to either constant
without a row here reds rather than drifting. The table and that count are
generated from `SettingsSchema` (`php tools/gen-settings-doc.php --write`);
edit the schema, never the rows. A key nothing reads is worse than
a missing one, because it looks configurable.

`includeGitInstructions` and `attribution` shape the Bash tool's generic
`<git_commits>` guidance in the system prompt (inspect, stage by name, commit,
and the never-do rules: no `--no-verify`, no force-push to the default branch,
no `git add -A`). `"includeGitInstructions": false` drops the whole block, for
a repository whose own `AGENTS.md` states its commit process; a project may set
it, because it only ever removes prompt text. `attribution` is
`{"commit": "…", "pr": "…"}`: a non-empty `commit` is the trailer every commit
message is told to end with, a non-empty `pr` the closing line of every
pull-request description, and an empty or missing string adds nothing (the
default). It is user-tier only, because it is text stamped on every commit
made under your identity. A non-boolean `includeGitInstructions` or a
non-string entry is ignored as if unset.

`maxOutputTokens` is the exception that has no exception: it is E707's opt-in
output ceiling, and **unset is not zero** — an absent key sends no `max_tokens`
override, and every provider keeps the documented default it already ships
(4096 on the Anthropic-shaped wires; Gemini on `vertex` sends no ceiling at
all, so the model's own maximum applies — see `thinkingBudget` below). The
`sglang` provider's default is not a
constant: it is `min(262144, window − estimated prompt − margin)`, where the
window is what the server itself reports from `/server_info` (read once per
session), else a per-family table (DeepSeek-V4 and Qwen3.8), else 4096 for a
model nobody measured — so a long reasoning turn is not cut off at 4096
tokens, and a prompt near the end of the window still leaves room to answer.
A set `maxOutputTokens` replaces that derivation outright, unclamped.
Setting it raises the per-request paid ceiling on the operator's own
credential, which is why the tier column says **no**: no key whose meaningful
direction is UP belongs to a checked-out repository (the full argument lives
on `LayeredSettings::LAYERED_KEYS`). When a reply actually hits whatever
ceiling is in force, the provider's stop verdict now reaches the transcript as
a system notice instead of a silently truncated turn.

Its value is a positive token count; a fraction truncates toward zero (`2047.9`
asks for 2047), and zero, negatives or non-numbers mean unset — and say so: the
launch raises one notice naming the value it ignored (audit R12). There is no
upper bound — the model's own limit is the provider's to enforce, and its
rejection is the honest answer — except that a value too large to be an integer
at all (`1e19`, or an over-long numeric string) also means unset, because no
request could carry it — cast anyway, it wraps to a negative `max_tokens` that
fails every request.

`modelPrices` is that argument mirrored on the price axis rather than the size
axis. It declares rates — **USD per 1M tokens**, `{"<model>": {"input": 7.5,
"output": 30}}` — for models the `openai`, `vertex` or `bedrock` provider has
no built-in price for, overriding or extending its table. (Vertex and Bedrock
only started receiving it in audit A15; before that the unpriced-model notice
told you to set a key those two never read.) On Vertex and Bedrock a key may
be the raw model id or its normalised family — `claude-sonnet-4-6` covers
`us.anthropic.claude-sonnet-4-6-v1:0` too. An optional `"cached"` rate prices
cache-hit prompt tokens (`prompt_tokens_details.cached_tokens`) on the OpenAI
wire; an entry without one bills them at its own `input` rate, because a named
model's entry replaces the built-in row and its cached discount together. On
Vertex and Bedrock the same `"cached"` rate prices cache reads
(`cache_read_input_tokens`, `cacheReadInputTokens`, Gemini's
`cachedContentTokenCount`) and a `"cacheWrite"` rate prices cache writes
(`cache_creation_input_tokens`, `cacheWriteInputTokens`); either one left out
bills at the entry's own `input` rate, for the same reason. Without an entry,
the built-in tables carry the published cache rates (Claude: reads at 0.1× and
writes at 1.25× the input rate; Gemini 2.5: reads at 0.1×). Unset is not zero
either: a model with no rate anywhere bills $0.00 as a disclosed **lower
bound** — the turn earns a system notice naming it, and `/budget` marks the
session total as under-counted — rather than the fabricated cent-per-thousand
the old fallback invented. It is
user-tier only because a checked-out repository that could supply this map
could zero a rate and blind the spend cap on the operator's credential, which
is the same money decision `maxOutputTokens` refuses to delegate.

`titleModel` and `summaryModel` are user-tier only for the same reason (audit
15d-24). They pick the model for every session title, every per-turn prompt
suggestion and every `/compact` summary, all on your credential. They used to
be project-settable on the argument that they only name a model *within* the
provider you chose — but within one provider the price spread is over 100×,
and a model with no price on file bills as the $0 lower bound, so a project
naming one would blind the spend cap for every one of those calls. Set them in
your own `~/.sugar-crush/settings.json` or `config.json`, or with
`SUGARCRUSH_TITLE_MODEL` / `SUGARCRUSH_SUMMARY_MODEL`.

`maxToolSteps` is the same axis counted in CALLS rather than tokens: how many
provider round-trips ONE agentic turn may take before the harness stops it
(F2). Unset keeps the shipped ceiling of 1000 (sub-agents spawned by `Task`
default to 200 unless their preset declares `maxTurns`, see
[AGENTS_AUTHORING.md](AGENTS_AUTHORING.md)). A turn that exhausts
the ceiling without the model finishing gets one more request with tools
disabled, asking the model to summarise what is done, what remains and what
comes next, so the reply is an answer rather than a half-finished step; it
still names itself in the transcript as stopped, and the notice points back at
this key. A ceiling that high is safe because of the **repeat-call loop
guard** that ships with it: within one turn, the same tool called with the same
arguments (key order ignored) that returns the same result gets a warning
appended to its 3rd result, is refused from the 5th call, and ends the turn on
the 8th, again with the no-tools summary. A changed result resets the count, so
polling something that is still moving is never caught. The guard matters
because the spend cap cannot catch a loop on a provider that reports $0, such
as a self-hosted SGLang server. It is user-tier only for the
`maxOutputTokens` reason squared: the key multiplies billed calls, so raising it
from a checked-out repository would spend the operator's credential on the
project's behalf. Nonsense values (non-integer, zero, negative) resolve to the
default, never clamp upward, and the launch says so in one notice naming the
value (audit R12); an absent key, `null` or `""` is unset, not nonsense. An integral float such as `8.0` is a whole number
and counts; `1.5` does not. There is no upper bound, but the bound stops at
what an integer can hold: a value too large to be one (`1e19`, or a numeric
string such as `"99999999999999999999"`) resolves to the default as well,
rather than being clamped to a ceiling the key deliberately does not have.

`secretEnvAllowlist` is the one exception to the credential scrub (audit
F-E1). Bash, Grep and script hooks inherit your environment **minus** every
variable whose name matches `*_API_KEY`, `*_TOKEN`, `*_SECRET` or `AWS_*`
(case-insensitive), because what those processes print is handed to the model,
and `env | grep KEY` used to hand it your provider key. The value is a list of
names or `fnmatch()` globs that are let through anyway — `["GITHUB_TOKEN"]`
for a `gh` workflow, `["NPM_*"]` for a publish script. A glob never releases a
key SugarCrush itself authenticates with (`ANTHROPIC_API_KEY`,
`ANTHROPIC_AUTH_TOKEN`, `OPENAI_API_KEY`, `SGLANG_API_KEY`,
`CUSTOM_PROVIDER_API_KEY`); only that exact name does. Anything but a list of
strings scrubs everything. MCP `stdio` servers get the same scrub, plus the
credentials their own `.mcp.json` `env` map declares — a repository chose
that command, so it gets only what it names (see
[`MCP.md`](MCP.md#var-interpolation--exactly-where-it-works)). LSP servers, the
`claude-code` provider and the command backends are not scrubbed — they are
processes you configured to authenticate as you, and their output is a
protocol, not text the model reads.
The key is read when the tool set and the hook chain are built
(`Bootstrap::tools()` and `Bootstrap::hooks()`), and it is user-tier only for
the plainest reason on this page: a project-tier `["*"]` would let a cloned
repository read every credential in your shell back through one `env` call.
See [`HOOKS.md`](HOOKS.md#environment-handed-to-the-script) for what a hook
sees.

Four keys shape a provider rather than a session, and all four are read
once, when the provider is built. A provider's own block in
`.sugar-crush/config.dev.json` may carry the same key, and there it wins,
because it is the narrower statement. All four are user-tier only, for the
same money reason as `maxOutputTokens`.

- **`contextWindow`** sizes the context window of the `openai`, `anthropic`
  and `custom` providers, the number
  every context tier (the 70% reminder, 85% auto-compaction and 95% refusal) is
  a percentage of (audit A13). Give a token count for whatever model the
  provider runs (`"contextWindow": 400000`), or an object to size models one
  by one (`{"gpt-5": 400000}`), which leaves an unnamed model on the built-in
  table. A model the table does not know otherwise reports "unknown" and gets
  the generic fallback. Zero, negatives, fractions and non-numbers are ignored.
  A project may not set it: an inflated window switches compaction and the
  refusal off, so requests grow until the server rejects them.
- **`extraBody`** adds top-level request fields to the `custom`
  (OpenAI-compatible) provider (audit A10), for example
  `{"separate_reasoning": true}` for an SGLang server reached through
  `custom`. `extra_body` itself (an OpenAI Python SDK convention a strict server
  rejects) and any field the provider writes itself (`model`, `messages`,
  `stream`, …) are refused when the provider is built, naming the key. A
  project may not set it: a field such as `n` multiplies what every request
  bills.
- **`thinkingBudget`** sets Gemini's `thinkingConfig.thinkingBudget` on the
  `vertex` provider (audit A21): `-1` for dynamic thinking, `0` to turn it off
  (Flash models only; Pro refuses `0`), or a token cap. Unset sends no
  `thinkingConfig`, so the model's own default applies. Thinking tokens bill as
  output, which is why a project may not set it. Separately, Vertex no longer
  sends a 4096 `maxOutputTokens` to Gemini when you set none: Gemini 2.5 thinks
  by default and its thinking spends from that same budget, so a hard prompt
  could end at the ceiling with almost no answer. With no `maxOutputTokens` the
  model's own maximum applies (65,535 on Gemini 2.5). Neither change has been
  checked against a live Gemini endpoint yet.
- **`promptCache`** turns prompt-cache breakpoints on the `vertex` and
  `bedrock` providers on or off (audit A15). It is on unless set to `false`.
  On Vertex's Claude models the request marks `cache_control` on the system
  prompt, the last tool and the end of the conversation, re-derived on every
  step of a turn and never more than the API's limit of four. On Bedrock,
  Converse `cachePoint` blocks close the system prompt and the conversation
  (no tools are sent there). Models that never offered caching (Claude 3
  Sonnet on Vertex, the Claude 3 family and 3.5 Sonnet on Bedrock, and any
  Bedrock model outside the Claude and Amazon Nova text families) are sent no
  marks, because Bedrock fails the whole request on an unsupported one. Gemini
  needs none: Gemini 2.5 caches on its own, and its cached tokens are priced
  either way. `SUGARCRUSH_DISABLE_PROMPT_CACHE` turns the marks off whatever
  this key says. A project may not set it, because caching off makes every
  request bill its whole prompt at the full input rate. Reads and writes are
  priced at the cache rates described under `modelPrices` above. While the
  marks are on, three responses in a row that report reading and writing no
  cache at all raise one notice for the session: the prompt is probably below
  the model's minimum cacheable length, or the marks are not reaching the
  wire. None of this has been checked against a live Vertex or Bedrock
  endpoint.

One more key shapes a provider but lives **only** in its block, never in a
settings file: `fallbackModels`, the other model ids of the same provider to
try, in order, when a request fails transiently or overflows the context window
(`ProviderFactory::create()` → `FallbackProvider`). No tier merges it, so a
project cannot set it. The [README](../README.md#providers) says when a switch
happens and what it reports.

Where a row names two methods, the first is the public entry point and the
second is the method that does the read — cited because that is the one to
grep for. The second name is a private method on `Bootstrap` in every row but
`provider`, `statusLine` and `disabledRules`: `backend()` is public static
because callers outside `chat()` build a backend through it rather than only
through it, and for the other two the second name lives in another class —
`StatusLineCommand::fromSettings()`, public because the runner is testable
without a launch, and `RulesState::new()`, which *consumes* the value that
`chat()` reads and filters through the private
`Bootstrap::rulePacksToDisable()` on the way in. The previous revision of this row named `StatusLineCommand::fromSettings()` first and
`Renderer::renderStatusBar()` second, and neither half fitted the convention:
nothing calls `fromSettings()` on a launch except `Bootstrap::chat()`, and
`renderStatusBar()` does not read the settings key at all — it reads the
already-cached process line.

**`disabledRules` is a LIST of pack names, and a name is a path.** Spell it like
this:

```json
{"disabledRules": ["focus", "style/terse"]}
```

`"focus"` is a pack sitting at `~/.sugar-crush/rulebooks/focus.md`: flat in its
tier directory, so the basename minus the extension is the whole name.
`"style/terse"` is one directory deeper, at
`~/.sugar-crush/rulebooks/style/terse.md` — the key is the path RELATIVE TO THE
TIER DIRECTORY, so a bare `"terse"` there selects nothing and a line that looks
like a no-op is really a name that matches no pack. A name pointing at the
repository's own tier — anything under `<repo>/.sugar-crush/rules`, or the root
`RULES.md`, which is keyed with its extension and outside the toggleable tier —
is inert by design: a session may silence the operator's packs and never a
checkout's, which is the same reason this key is one a project may not set.

LIST, not map. `{"disabledRules": {"terraform": true}}` is the shape the skill
registry keeps in memory for its own disable set, and it is a natural thing to
copy by analogy; as config it decodes to `true` values rather than strings, every
entry is dropped by `Bootstrap::rulePacksToDisable()`, and the file disables
nothing while looking completely serious about it. That is one of the ways this
key fails with no message attached, not the only one: so does the wrong-depth
name two paragraphs above, because a seeded name that matches no pack is simply
never consulted again — nothing in `src/` reads the disable list back out to
compare it with what loaded (`/rules <that name>` will tell you it is unknown, but
only once you think to type it); and so does a value that is not a list at all,
like `"disabledRules": "focus"`, which the filter's non-array guard drops whole.
The launch cannot tell a considered empty list from a typo in a shape, and none of
the three say anything on their own.

**A padded name is the fourth silent spelling, and the one with no shape error to
learn from.** `Bootstrap::rulePacksToDisable()` keeps every string whose `trim()`
is non-empty, so `"focus "` — one stray space from a hand edit — survives the
filter, is stored verbatim by `RulesState::new()` (whose parse step rejects only a
name blank as a whole, never a padded one), and is then compared to each pack's
key with strict identity. A padded name equals no key, so it silences nothing, and
it does so exactly as quietly as the wrong-depth name above.

### The live half of the list: `/rules` is session-scoped, by pinned contract

The same names are a conversation command. `/rules` lists every pack the loader
found — both user directories, `~/.sugar-crush/rules` and
`~/.sugar-crush/rulebooks`, the second holding the named packs of the same tier
(`RuleLoader` walks four directories across three tiers: user rules, user
rulebooks, project rules, root `RULES.md`) — and it lists them even when they are
currently OFF, because a list that hides what is silenced cannot help you un-silence
it. `/rules <name>` flips one pack out of, or back into, the session's
`RulesState`; a pack whose stem sits in both user directories is two packs sharing
one handle, and one toggle silences both
(`RuleLoaderTest::testTheSameStemInBothUserDirectoriesStaysTwoPacksToggledByOneName()`).
The command's own header states the scope — *"session only — nothing here is
written to config"* — and the scope is pinned at the file level rather than in
prose: `RulesCommandTest::testTogglingAPackLeavesTheConfigFileByteIdentical()`
compares `config.json`'s bytes across a toggle. A flip reports `ON` or `OFF` for
this session and takes effect on the prompt from the next turn onward; a pack
whose frontmatter already says disabled stays off whichever way you toggle, because
the session set only subtracts from the file's intent and never overrules it.

**Persisting a toggle is a deferred step, not an omission.** The ruling in
`prompt_plan.md` (P6.S4) kept the byte-identical contract standing and named the
cost of breaking it: a third key through `Chat`'s config-change door would
collide with the two-key invariant above and with the census that guards that
invariant, so the toggle waits for a guarded door of its own. That door now
exists — the settings view's `SettingsWriter` — but `/rules` does not use it, so
a `/rules` decision still dies with the session, and `disabledRules` in one of
your two files is the only way to make one permanent.

And that file value arrives through the **merged** read, which is what makes the
key live rather than decorative: `Bootstrap::chat()` seeds the session's
`RulesState` from `Bootstrap::rulePacksToDisable()` applied to `disabledRules` as
`readUserConfig()` returns it — so both `config.json` and your own
`~/.sugar-crush/settings.json` can carry it, and no project file can, because the
key sits outside `PROJECT_TIER_KEYS` (the user-tier-only half is derived from the
full list by `LayeredSettings::userTierOnlyKeys()`, never hand-maintained in a
second place). The rationale is the one `instructions` rests on: a rule pack is
the operator's own prompt text — prose you wrote to steer the model — and a
hand-edited list of which of your own prose to withhold at launch is a user-tier
decision by construction. The wiring is pinned end to end: the seed comes from the
user's own config
(`RulesStateWiringTest::testTheLaunchSeedsTheDisableListFromTheUsersOwnConfig()`),
from the merged read and not from either single user file
(`RulesStateWiringTest::testTheSeedListIsBuiltFromTheMergedReadRatherThanFromEitherSingleUserFile()`),
a toggle typed into the launched shell moves the pack out of the prompt that same
session builds
(`RulesStateWiringTest::testAToggleTypedIntoTheLaunchedShellMovesThePackOutOfThePromptItBuilds()`),
and the backend consults the live set per turn rather than freezing it at launch
(`RulesStateWiringTest::testTheBackendReadsItsToggleSetPerTurnRatherThanFreezingItAtLaunch()`).

**`claudeMcpBinary` names a spawn, which is why it is user-tier only.** A
trusted repository's `.mcp.json` may declare a `claude-mcp` entry — but the
entry carries the type and nothing else, and the spawn it requests comes
from this file: `claudeMcpBinary`, an absolute path to an existing,
executable program, plus optional `claudeMcpArgs` (default `--mcp`) and
`claudeMcpEnv` (literal strings, no `${VAR}` interpolation). A project's
`.sugar-crush/settings.json` naming them changes nothing: the reader
consults the user files only. The grant is frozen for the process at the
first MCP launch — mid-session edits land next relaunch, the same posture
as `trustedProjectMcp` — and the path itself never appears in the `/mcp`
panel or any transcript row. See the transport section of `docs/MCP.md`
for what a claude-mcp server is and why its own spawn is the execution the
tiers gate.

**`statusLine` runs a command, which is why it is user-tier only.** The shape
is Claude Code's, so a settings file written for that tool carries over:

```json
{"statusLine": {"type": "command", "command": "git branch --show-current"}}
```

The command's stdout becomes one extra segment of the status bar. A project's
`.sugar-crush/settings.json` may **not** set it, at any trust level — that
would be arbitrary code execution on clone-and-launch, on a timer, with no tool
call and no permission gate in the path. It is the strongest case of the
argument `provider` and `instructions` already rest on.

What it costs, stated because the command runs on the TUI's own thread:
`StatusLineCommand::REFRESH_SECONDS` (2.0) between runs, and a hard
`StatusLineCommand::TIMEOUT_SECONDS` budget per run — derived as half the
refresh period, so two runs can never overlap — after which the child is
SIGTERMed and then SIGKILLed. A run that times out, exits non-zero, prints
nothing, or writes more than `StatusLineCommand::MAX_OUTPUT_BYTES` (16 KiB)
blanks the segment rather than leaving stale text up — note that the byte cap
BLANKS, it does not paint the first 16 KiB. (This paragraph previously said
output was "capped at `MAX_OUTPUT_BYTES`" and then clipped, which described a
pipeline that does not exist: `exec printf "%020000d" 0` exits 0 and paints
nothing, while the same command at 16000 bytes paints all 16000. Measured at
db20c568 on PHP 8.3.6.)

What a run that does finish gets: its stdout stripped of ANSI and control
bytes, made valid UTF-8 (malformed bytes become U+FFFD), collapsed to one line
(a newline would make the bar wrap, and the bar is the one row that must not),
and clipped to the terminal width by a grapheme-aware measure. stderr is
drained so it cannot deadlock the read, and discarded.

**There is no top-level `model` key**, and it is the one name people look for.
Nothing reads one. The model-shaped keys that exist are `models`, `titleModel`
and `summaryModel`.

**The session's model persists per provider, in `models`** —
`{"<provider>": "<model id>"}`, user tier only (a model is a price). It is
keyed by provider because a model id means nothing to any other provider: one
flat key would send the last provider's model to the next one a `/model`
switch picked. `Bootstrap::selectedModelName()` resolves `--model`, then
`$SUGARCRUSH_MODEL`, then the entry for the provider being built, then the
provider's own default — at launch, on every `/model` switch, and for the
status-bar caption alike. The Ctrl+P action called "Switch Model" still writes
`provider`, not a model; `models` is written by `/model <provider> <model>`,
by the settings view's save (see
[Saving from the settings view](#saving-from-the-settings-view)) — both through
the same `SettingsWriter` — or by hand. `/model` saves the entry before it
builds the switched backend, so the provider is constructed on the chosen id
rather than relabelled, and writes the whole map this launch resolves: a
`models` in `config.json` masks one in `settings.json` key-wise, so the
entries a `settings.json` map supplies are carried into `config.json` rather
than masked by it.

**`permissionMode` and `permissionRules` are readable from
`~/.sugar-crush/settings.json`**, but not through this stack. They go through
`Bootstrap::permissionSettingsLayer()`, which uses the STRICT reader — the one
that refuses to start on a policy file it cannot parse — while this class's
reader is tolerant by contract, treating a malformed file as an absent layer.
Routing the permission keys through the tolerant reader would have handed a
stray comma the power to silently downgrade a session to the permissive
default.

### Why `allowedTools` is user-tier only when `disabledTools` is not

On capability alone the two look equally safe: both only ever shrink the tool
set, neither can add anything. The difference is shape.

A whitelist is defined by what it OMITS, so it is the one form in which a
small, innocuous-looking value deletes almost everything.
`allowedTools: ["Bash"]` removes `Read`, `Edit`, `Write`, `Grep`, `Glob`,
`WebFetch`, `WebSearch`, `doctor`, `Skill` and `Lsp` in one line — and what the
model does next is not less work, it is the *same* work through `Bash`, which
reaches the permission gate as opaque shell text instead of as a reviewable
path. Strictly fewer tools, strictly coarser review.

**A previous version of this page claimed `disabledTools` can only express that
attack "by naming every tool it removes — a value you can see when you read the
file". That is false.** `Bootstrap::filterToolSet()` matches names with
`PermissionRule::matchesToolName()`, which is bare `fnmatch()`, and `fnmatch()`
honours negated character classes. Measured end-to-end **on PHP 8.3.6,
2026-08-22** — and **PHP 8.4 was not exercised**, because this box has only
8.3.6 while CI runs both. The version and the date matter here only as
provenance: `fnmatch()`'s handling of `[!…]` is not version-sensitive, negated
classes are not a recent addition, and no ICU is involved. They are recorded
anyway because an undated figure is how a PHP-8.3-only defect once got written
down as unconditional. Both claims on this page also have live generators
rather than only a date, and they are **two different generators** because they
are two different claims: `Tests\Config\ReadmeSettingsTierClaimTest` re-derives
the *tool set* the glob leaves, and `Tests\Config\GlobFigureDriftTest` re-derives
every *character count* below — including the ones inside the retraction — from
`strlen()` of the glob this page quotes. (That sentence used to name the first
test alone as the generator for both. It is not: it holds the glob as a class
constant and never measures its length, so the count it was credited with
keeping fresh was in fact unpinned on this page and in
`src/Config/LayeredSettings.php` at the same time. The retraction is kept
because "there is a generator" is exactly the claim a reader stops checking.)
In a project you have listed
under `trustedProjectSettings` (an untrusted project's `disabledTools` never
reaches the merge at all, and all seventeen tools survive):

```json
{ "disabledTools": ["[!B]*"] }
```

Five characters of glob, in a key a project **is** allowed to set, leaving
exactly `Bash` and removing everything else — the same tool set
`allowedTools: ["Bash"]` produces, and the same degradation to opaque shell
text. (This line said "eight characters" until the count was re-derived:
`[!B]*` is five, `"[!B]*"` seven, `["[!B]*"]` nine, and nothing here is eight.
The point the figure was making — that the value names none of the sixteen tools it
removes — is what survives, so the sentence stays and the number is corrected.
This line once continued
"`src/Config/LayeredSettings.php` and `Bootstrap::reportProjectTierToolRemovals()`
still carry the old figure in their doc-blocks", and neither half is true any
more: `LayeredSettings` was corrected in round 43 and
`Bootstrap::reportProjectTierToolRemovals()` in round 44 — its clause about
refusing negated character classes now names the glob instead of counting it —
so both carry the retraction rather than the figure. **Across `src/` and
`docs/` the census finds zero remaining sites.** The naming was DROPPED, and
the sentence that used to defend it is corrected here rather than deleted. It
said: "the naming is kept rather than dropped because a list of known-stale
copies is the only thing that makes the next one findable". What is true now:
there is nothing left to name, and a list would be the wrong instrument even
if there were — a list is what went stale in round 43, when a copy was fixed
and its entry was not. Findability was the right goal; a number derived from
the census is the instrument that reaches it, because it cannot be right about
a tree it no longer describes. And it is asserted rather than believed:
`Tests\Config\GlobFigureDriftTest` censuses `src/` AND `docs/` for paragraphs
that spell the old count without retracting it — `docs/` is in scope because
round 43's shipped defect was a stale sentence on this very page, not in
source — and reds both if a new one appears and if this sentence stops
agreeing with what the census finds. An empty census is a weaker claim than a
census of one, because it also passes in a tree where the scanner has quietly
stopped working, so the same scanner is run against a known-stale fixture in
the same test and must still find it. A THIRD
occurrence was in this file, in the paragraph beginning "The report is the
*effect*, not the pattern" — located by its words rather than by "sixty lines
below", because an offset in prose is the same rot one level up — and is
corrected; a page that re-derives a number and then contradicts itself further
down is worse than one that never re-derived it.)

**Two things narrow this, and both are measured.** An *untrusted* project's
`disabledTools` never reaches the merge — all seventeen tools survive — so this
needs a `trustedProjectSettings` grant you made yourself. And the layers merge
**key by key, not as a union**: if *you* name any `disabledTools` at all, yours
replaces the project's entirely. Measured: your `["Read"]` against a trusted
project's `["[!B]*"]` removes exactly `Read` and leaves everything the
project's glob named. The gap is open only for an operator who trusted a
repository and set no `disabledTools` of their own.

So the *shape* argument does not hold on its own; the ceiling argument below is
what the split actually rests on. **A trusted project's `disabledTools` can
choose your tool set, and that has not changed** — do not trust
`trustedProjectSettings` on a repository you would not trust with
`allowedTools`.

**What has changed is that it can no longer do so unnoticed.** A trusted
project's tool removals are reported at launch, naming the file, the tools it
took and the tools it left:

```
sugarcrush: /repo/.sugar-crush/settings.json (disabledTools) disabled 16 of the
17 tools your own settings left — Read, Edit, Glob, Grep, Write, WebFetch,
WebSearch, doctor, Skill, Lsp, Memory, RepoMap, Prune, Todo, Compress, Workflow
— leaving: Bash.
```

That is the stderr form, byte for byte. The `sugarcrush: ` prefix and the
trailing full stop are added by `Bootstrap::warnPermissionConfig()`, not by
`Bootstrap::reportProjectTierToolRemovals()`, so the **transcript** row two
paragraphs down carries the same sentence WITHOUT either of them — the message
the reporter builds ends at `leaving: Bash`. (This block used to be printed
here without the full stop, which made it neither form.)

**In two places, because one of them you cannot read.** The line above goes to
stderr, which is the right channel for `-p` and for the scrollback you get back
after quitting — and which an interactive session cannot show you. Measured on
a real launch under a pty: it printed **0.47 s** before the terminal entered the
alternate screen, and replaying the captured byte stream through a virtual
terminal found no trace of it on the visible screen. The alternate buffer had
painted over it, and the primary buffer does not come back until the session
ENDS — so the warning that your tools had been cut to `Bash` arrived after the
`Bash`-only session was over.

So the same sentence is also seeded into the **transcript**, as a system row,
before the first frame paints. That is the copy an interactive operator reads;
the stderr copy is unchanged and is not going away. Note that the transcript
copy is a UI-only row: since audit 15b-03 a launch notice is never sent to the
model, so a long list costs the transcript space, not tokens on every turn.

The three warnings this paragraph used to name as still-stderr-only — an
unusable provider, a skipped hook file, a rejected permission pattern — have
since migrated through the same seam, along with the agent-preset degradations,
the refused project directories, the skipped skill files, the skipped command
files (E172), the unreadable memory notes, the instruction files and enabled
skill bodies the system prompt leaves out for budget (audit R1), a nonsense
`maxToolSteps` or `maxOutputTokens` (audit R12) and the empty tool set — the
reports that can raise several rows share one call site:
**twenty-four** call sites in total (`grep -c 'self::warnPermissionConfigInTranscript('
src/Cli/Bootstrap.php`, which agrees with the token scan in
`BootstrapTranscriptSeamCallSiteCensusTest` today; `grep` for the bare
identifier does **not** — it reports roughly double, because most occurrences in
that file are prose). The rule that decided the split is on
`Bootstrap::warnPermissionConfigInTranscript()` — a warning earns a transcript
row iff it names something **the session can no longer do**. Warnings that
report a malformed config entry without the session being diminished
(`trustedProjectHooks[2] is not a project path`, `permissionMode in config.json
is empty so it was ignored`) stay on stderr, because a transcript row per bad
config entry is how a useful notice becomes a wall you scroll past.

**What this paragraph used to say, and what changed.** The count above has read
*fourteen*, then *fifteen*, and both were left behind by the seam it counts:
round 42 (E78) routed `reportPrunedSessions()`'s retention summary on as the
fifteenth, round 43 (E86) routed `mcpClient()`'s start-then-throw catch on as
the sixteenth, and neither round came back to this page. Round 44 corrected it
and then wired it: this sentence is now one of the rows in
`BootstrapTranscriptSeamCallSiteCensusTest::PROSE_SITES`, so the next round that
adds a call site fails a test here instead of leaving a reader to notice. The
stderr-only list above also used to name a third example: `retention
removed 3 sessions`. Both are now wrong, and the second one was the more
interesting mistake — it was the one entry on that list whose subject was data
the launch had **destroyed** rather than a setting it had declined to honour.
Read against the rule the list is decided by, a prune does name something the
session can no longer do: those conversations cannot be resumed, branched,
renamed or rewound, and `/resume` and `sugarcrush session list` come back
shorter than the user left them. So the **summary** migrated in round 42 and is
now the fifteenth call site. The reason the example still earns a mention is the
*half* of it that did not move: the **per-session ids** stay on stderr alone,
because one row per deleted session is exactly the per-entry fan-out the cap
below exists to refuse. (These rows used to be re-sent to the model every turn
as well; launch notices are display-only now, so the cap bounds what the user
scrolls past, not what the model is billed for.) The
transcript gets `retention removed 3 unnamed sessions untouched for 30+ days
(ids on stderr)`; stderr gets that line and the ids.

The transcript copy is capped — 36 rows and 400 characters per row, see
`Bootstrap::LAUNCH_NOTICE_LIMIT`. The stderr copy is never clipped and never
capped, so an overflowed launch says so in the transcript and points at the
channel that has the rest.

The report is the *effect*, not the pattern, and that is deliberate. Refusing
negated classes at the project tier would close the five-character version and
nothing else: `["[C-Z]*", "[a-z]*"]` uses no negation, is barely longer, and
also leaves only `Bash` — measured. Restricting the tier to literal names would
close it, at the cost of the use the key was admitted for (a checkout saying
"there is no git server here, stop offering `mcp__git__*`"), and at the cost of
a capability the *operator* granted rather than one an attacker took.

**The server segment of an `mcp__` name is the sanitised spelling, not the key
as typed in `.mcp.json`.** Every byte of the key outside `[A-Za-z0-9-]` is
rewritten to `_` plus its two uppercase hex digits, so the key `github.com/foo`
appears on the wire as `mcp__github_2Ecom_2Ffoo__*` — a `disabledTools` or
permission pattern written against `mcp__github.com/foo__*` matches nothing and
fails silently. `sugarcrush mcp list` prints this mapping for every server whose
key is rewritten, and the permission prompt always names the tool in exactly the
spelling that will match.

Only the removals a project **actually made** are reported, which follows from
the key-by-key merge above: if your own `disabledTools` displaced the project's
list, the project removed nothing and nothing is said. Re-matching the
project's patterns instead would announce removals that never happened, in
exactly the case where you had already protected yourself.

**There is no floor either, and that is also unchanged.**
`disabledTools: ["*"]` leaves zero tools. The direction is fail-safe, and
`Bootstrap::filterToolSet()`'s doc-block names that spelling as the supported
way to ask for a toolless agent (it is the stated alternative to reading
`allowedTools: []` that way), so it is reported rather than refused — but it is
reported, not handed over in silence.

**That sentence is written about YOU, and the code applies it to a trusted
project as well.** `disabledTools` is a project-tier key, so `["*"]` in a
checkout's `settings.json` reaches the same branch and yields the same empty
tool set — a "supported way to ask for a toolless agent" that the repository,
not the operator, asked for. It is deliberately left that way: both warnings
fire (the removal report names the file, and the empty-set report follows), and
reaching the branch at all needs your own `trustedProjectSettings` grant. But
the justification's authority comes from it being *your* choice, so do not read
it as covering the project tier by itself — the trust grant is what covers that.

And a whitelist is what you reach for when you want a *ceiling*; a ceiling a
checkout can rewrite is not one. That holds by conjunction rather than by
ordering: `Bootstrap::filterToolSet()` keeps a tool if and only if the
allow-list admits it AND the deny-list does not name it, in one expression, so
there is no later stage at which a project's `disabledTools` could re-admit
what your `allowedTools` excluded.

## Every key

Every key any settings file can carry, layered or not, generated from
`SettingsSchema` by `php tools/gen-settings-doc.php --write` — do not edit the
table by hand; `SettingsSchemaDocDriftTest` reds when it is stale. **Tiers**:
`P` the two project files (a trusted project only), `U`
`~/.sugar-crush/settings.json`, `C` `~/.sugar-crush/config.json` (or the
`--config` file). **Env / flag** outranks every file. **Applies** is when a
saved change takes effect: `live`, `next turn`, `restart`, or `next launch` for
the keys frozen for the life of the process. **Risk** is what a hostile value
could cost; only `cosmetic`, `narrowing` and `tuning` keys may ever be
project-settable.

<!-- settings:begin -->
| Key | Category | Type | Default | Tiers | Env / flag | Applies | Risk |
|---|---|---|---|---|---|---|---|
| `provider` | Model & Provider | string | unset | U C | `SUGARCRUSH_PROVIDER` | live | egress |
| `models` | Model & Provider | object | `{}` | U C | `SUGARCRUSH_MODEL`, `--model` | restart | spend |
| `titleModel` | Model & Provider | string | unset | U C | `SUGARCRUSH_TITLE_MODEL` | restart | spend |
| `summaryModel` | Model & Provider | string | unset | U C | `SUGARCRUSH_SUMMARY_MODEL` | restart | spend |
| `maxOutputTokens` | Model & Provider | int | unset | U C | — | next turn | spend |
| `modelPrices` | Model & Provider | object | `{}` | U C | — | restart | spend |
| `extraBody` | Model & Provider | object | unset | U C | — | restart | egress |
| `thinkingBudget` | Model & Provider | int | unset | U C | — | restart | spend |
| `promptCache` | Model & Provider | bool | `true` | U C | `SUGARCRUSH_DISABLE_PROMPT_CACHE` | restart | spend |
| `parallelToolCalls` | Agent loop | bool | `true` | P U C | `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS` | next turn | tuning |
| `parallelToolDeadlineSeconds` | Agent loop | int | `90` | P U C | `SUGARCRUSH_PARALLEL_TOOL_DEADLINE` | next turn | tuning |
| `maxToolSteps` | Agent loop | int | unset | U C | — | live | spend |
| `contextWindow` | Context & Compaction | JSON | unset | U C | — | restart | tuning |
| `contextPruning.mode` | Context & Compaction | enum | `auto` | C | `SUGARCRUSH_CONTEXT_PRUNING` | next turn | tuning |
| `permissionMode` | Permissions | enum | `default` (TUI); `bypass-permissions` (`-p`, daemon) | U C | `SUGARCRUSH_PERMISSION_MODE`, `--permission-mode` | restart | security |
| `permissionRules` | Permissions | JSON | `[]` | U C | — | restart | security |
| `secretEnvAllowlist` | Permissions | list | `[]` | U C | — | restart | security |
| `trustedProjectHooks` | Permissions | list | `[]` | C | — | next launch | security |
| `trustedProjectMcp` | Permissions | list | `[]` | C | — | next launch | security |
| `trustedProjectCommands` | Permissions | list | `[]` | C | — | next launch | security |
| `trustedProjectSettings` | Permissions | list | `[]` | C | — | next launch | security |
| `allowedTools` | Tools | list | unset | U C | — | restart | security |
| `disabledTools` | Tools | list | `[]` | P U C | — | restart | narrowing |
| `bashSandbox` | Tools | enum | `off` | U C | — | restart | security |
| `testCommand` | Tools | string | unset | U C | — | restart | exec |
| `autoTest` | Tools | bool | `false` | U C | — | restart | exec |
| `instructions` | Memory & Rules | list | `[]` | U C | — | restart | prompt |
| `disabledRules` | Memory & Rules | list | `[]` | U C | — | restart | prompt |
| `embeddingModel` | Memory & Rules | string | unset | U C | — | next turn | spend |
| `disabledSkills` | Skills | list | `[]` | P U C | — | restart | narrowing |
| `enabledSkills` | Skills | list | `[]` | U C | — | restart | prompt |
| `subagentModel` | Sub-agents | string | unset | U C | — | restart | spend |
| `includeGitInstructions` | Git & Automation | bool | `true` | P U C | — | restart | narrowing |
| `attribution` | Git & Automation | object | unset | U C | — | restart | prompt |
| `lsp` | Git & Automation | object | unset | U C | — | restart | exec |
| `autoCommit` | Git & Automation | enum | `off` | U C | — | restart | exec |
| `theme` | Interface | enum | `dark` | P U C | — | live | cosmetic |
| `statusLine` | Interface | object | unset | U C | — | live | exec |
| `layout` | Interface | JSON | unset | U C | — | live | cosmetic |
| `lintCommands` | Hooks & MCP | object | `{}` | U C | — | restart | exec |
| `claudeMcpBinary` | Hooks & MCP | path | unset | C | — | next launch | exec |
| `claudeMcpArgs` | Hooks & MCP | list | unset | C | — | next launch | exec |
| `claudeMcpEnv` | Hooks & MCP | object | unset | C | — | next launch | security |
| `server.host` | Server | string | `127.0.0.1` | C | `SUGARCRUSH_SERVER_HOST` | restart | security |
| `server.port` | Server | int | `7420` | C | `SUGARCRUSH_SERVER_PORT` | restart | security |
| `server.allowedOrigins` | Server | list | `[]` | C | `SUGARCRUSH_SERVER_ALLOWED_ORIGINS` | restart | security |
| `server.allowedHosts` | Server | list | `[]` | C | — | restart | security |
| `server.trustedProxies` | Server | list | `[]` | C | — | restart | security |
| `server.maxOpenSessions` | Server | int | `32` | C | — | restart | security |
| `server.maxConcurrentTurns` | Server | int | `4` | C | — | restart | security |
| `server.askTimeoutSeconds` | Server | number | `0` | C | — | restart | security |
| `server.drainSeconds` | Server | number | `10` | C | — | restart | security |
| `server.allowBypass` | Server | bool | `false` | C | — | restart | security |
| `turnIdleTimeoutSeconds` | Advanced | int | `120` | C | — | next turn | tuning |
| `connectTimeoutSeconds` | Advanced | number | `15` | P U C | `SUGARCRUSH_CONNECT_TIMEOUT` | restart | tuning |
| `streamIdleTimeoutSeconds` | Advanced | int | `3600` | C | — | next turn | tuning |
| `providerRetryAttempts` | Advanced | int | `3` | P U C | — | next turn | tuning |
| `providerRetryBaseBackoffMs` | Advanced | int | `500` | P U C | — | next turn | tuning |
| `temperature` | Advanced | number | unset (`0.7`) | C | — | next turn | tuning |
<!-- settings:end -->

## Saving from the settings view

The settings view (`/settings`) holds an edit set — values staged against a
tier — and saves it through `Config\Settings\SettingsWriter`, a write door of
its own. It is **not** `Chat`'s config-change door, so that door still carries
exactly `provider` and `theme`; `SettingsWriterCensusTest` pins who may write
`config.json` at all.

| Tier | File | Keys |
|---|---|---|
| **You** (default) | `config.json` — `Bootstrap::userConfigPath()`, so `--config` moves it — through `Bootstrap::writeUserConfig()`, the one writer that file already has | every editable key except `provider` and `theme`, whose live commands (`/model`, `/theme`) are their writers |
| **This project (local)** | `<root>/.sugar-crush/settings.local.json` | the project-settable keys only, and only for a project you already trust |
| **This session only** | nothing — the values live in memory, above every file, until the process exits | the keys that apply without a restart (listed under the next section's table); never `provider`, which `/model` switches |

`settings.json` is never written. A save to **You** outranks it, so the value
sticks; the preview says when your `settings.json` is the value it overrides,
and — for a project-tier save — when one of your own files, the environment or
a flag still outranks the file being written.

Before anything is written the view shows a preview: the target file, a
unified diff of its JSON (sugar-diff), and when each change applies. A save is
refused, with the reason, for a key the schema does not define or marks
read-only, for a value of the wrong type or outside its range, for a
`permissionMode` that is not a mode (the launch would refuse it), for a
project-tier key a project may not set, and for the trust lists — those change
only through the confirmed trust action, on your `config.json` alone, and like
every trust grant they apply from the next launch. **A reset deletes the key**
rather than writing its default, so a later change to the default still reaches
you; an explicit `null` is refused, because in a settings file `null` is a value
that masks every lower layer. A `config.json` that exists but is not a JSON
object is never overwritten.

The keys, all plain letters (no `Ctrl+S`/`Ctrl+R`: both are already taken):

| Key | Does |
|---|---|
| `Enter` | Edit the highlighted setting in its field; `Enter` again stages the value, `Esc` drops it |
| `r` | Stage a reset of the highlighted setting (the save deletes the key) |
| `t` | Switch the tier the save writes to |
| `s` | Show the save preview of everything staged |
| `y` / `Enter` | In the preview: write it. `n` / `Esc` goes back with everything still staged |
| `Enter` on a `trustedProject*` list | Ask whether to trust this project for it; `y` runs the confirmed trust action, `n` cancels |
| `Esc` with changes staged | Ask before closing: `d` discards them, `k` keeps editing, `s` previews the save |

The confirmed trust action adds this launch's project root to that list in your
`config.json` (`SettingsWriter::grantTrust()`), and like every trust grant it
applies from the next launch.

Saved is not applied: see the next section for when each key takes effect.

## When a change takes effect

**A save from the settings view** applies each key by its apply mode — the
**Applies** column of the table above, generated from `SettingsSchema`:

<!-- settings:apply:begin -->
| Applies | When a saved change takes effect | Keys |
|---|---|---|
| live | At once, in the running session (`Chat::applySettings()`); a key that rebuilds the engine waits for a running turn to end | `provider`, `maxToolSteps`, `theme`, `statusLine`, `layout` |
| next turn | From the next turn: the engine re-reads the merged settings at every turn start | `maxOutputTokens`, `parallelToolCalls`, `parallelToolDeadlineSeconds`, `contextPruning.mode`, `embeddingModel`, `turnIdleTimeoutSeconds`, `streamIdleTimeoutSeconds`, `providerRetryAttempts`, `providerRetryBaseBackoffMs`, `temperature` |
| restart | At the next launch: read once while the session is built | `models`, `titleModel`, `summaryModel`, `modelPrices`, `extraBody`, `thinkingBudget`, `promptCache`, `contextWindow`, `permissionMode`, `permissionRules`, `secretEnvAllowlist`, `allowedTools`, `disabledTools`, `bashSandbox`, `testCommand`, `autoTest`, `instructions`, `disabledRules`, `disabledSkills`, `enabledSkills`, `subagentModel`, `includeGitInstructions`, `attribution`, `lsp`, `autoCommit`, `lintCommands`, `server.host`, `server.port`, `server.allowedOrigins`, `server.allowedHosts`, `server.trustedProxies`, `server.maxOpenSessions`, `server.maxConcurrentTurns`, `server.askTimeoutSeconds`, `server.drainSeconds`, `server.allowBypass`, `connectTimeoutSeconds` |
| next launch | At the next launch, and only then: frozen for the life of the process | `trustedProjectHooks`, `trustedProjectMcp`, `trustedProjectCommands`, `trustedProjectSettings`, `claudeMcpBinary`, `claudeMcpArgs`, `claudeMcpEnv` |

**This session only** accepts `maxOutputTokens`, `parallelToolCalls`,
`parallelToolDeadlineSeconds`, `maxToolSteps`, `embeddingModel`, `theme`,
`statusLine`, `providerRetryAttempts` and `providerRetryBaseBackoffMs`.
<!-- settings:apply:end -->

`provider` and `layout` are live through their own doors — `/model` and the
pane shell apply them as they write them — and the view saves neither. A live
key that rebuilds the engine (`maxToolSteps`) is held while a turn runs and
applied once it has ended; the running turn itself never changes, because it
read its settings when it started. What the save did shows as a toast in the
top-right corner of the chat, never as a transcript row — a row would be sent
to the model with every later turn.

**The session tier** keeps its values in memory, above every file and below
the environment and flags, until the process exits. A turn's forked child and
the Task sub-agents it runs inherit them; a `/bg` daemon is a separate process
and starts from the files alone.

**A hand edit to a file** is another matter. The settings files are
**re-read every turn** — `EngineBackend::runTurn()`, the loop behind
`complete()`, calls `readUserConfig()` once per turn, so all four are opened
again each time.

**Re-read is not the same as re-applied**, and only three keys actually change
behaviour mid-session. That per-turn read feeds exactly three settings —
`parallelToolCalls`, `parallelToolDeadlineSeconds` and `maxOutputTokens` — so
editing your own `~/.sugar-crush/config.json` or `~/.sugar-crush/settings.json`
mid-session changes those on the next turn and nothing else. A trusted project's
`.sugar-crush/settings.local.json` reaches only the first two, because
`maxOutputTokens` is a key no project file may set. Every other key is
consumed once, while `Bootstrap` builds the session: `disabledSkills` is read
by `Bootstrap::skillRegistry()` at launch (and again on a Ctrl+P skill
switch), `theme` and `provider` when the `Chat` is constructed, `models` whenever a backend is built for a provider (at launch and on a `/model` switch), `disabledRules` when the launch seeds the session's `RulesState`
— a mid-session edit to that file waits for a restart like the rest, though a
`/rules` toggle flips the seeded set live, from the next turn onward — and
`allowedTools`/`disabledTools` when the tool set is assembled.
Changing any of those means restarting.

The **trust list is not**: it is frozen for the life of the process, so a
project cannot become trusted mid-session. The asymmetry is deliberate — you
opted the repository in, and the project tier cannot reach `provider`,
`instructions` or any permission key — but "trusted, frozen" would otherwise
read as covering both halves.

## When a file is ignored

Every one of these is silent **as a settings read**, because a settings file is
not a permission policy and the tolerant reader is what lets a half-written
file cost you a setting rather than your session. Your OWN two files — layers
3 and 4 — are the exception, and it is a big one: see "the loud half" below.

- the file does not exist, cannot be read, is not valid JSON, or is valid JSON
  that is not an object;
- the project root is not listed in `trustedProjectSettings`;
- `$HOME` cannot be resolved, or the home directory fails the ownership check —
  and this drops **layers 1, 2 and 3**, not layer 3 alone. `readUserConfig()`
  loses layer 3 because `userSettingsDirOrNull()` returns `null`, and it loses
  the project layers too because `projectSettingsTrusted()` reaches
  `trustedConfigDirPath()` to find the trust list, catches the same throw, and
  returns `false` — which makes `projectLayer()` return `[]`. Only layer 4
  survives, since `--config` may have pointed it somewhere else entirely;
- the project's `.sugar-crush` directory is not actually inside the project
  root (a symlink pointing out of the checkout, say) — containment is checked
  on the directory and again on each file;
- for a **project** file: the key is not in the layered list, or not in the
  project-tier subset. (For layer 4 an unlisted key is *not* ignored —
  `merge()` passes your own config through unfiltered, which is exactly what
  "answered by layer 4 alone" above means.)

**A project's own `.sugar-crush/config.json` is not a settings layer**, so it
is not on that list: it is never read as settings, trusted or not, whatever it
holds. From a project only `settings.json` and `settings.local.json` (layers 1
and 2) are read; the name `config.json` is a layer only under your home (layer
4). In a project that name belongs to the worktree configuration
(`worktreeCleanupPeriodDays`, `worktreeIncludeFile`), which only
`SugarCraft\Crush\Agents\WorktreeConfig` reads — and nothing constructs that
class until worktree support is wired, so the file is inert today. A
`trustedProjectMcp` written there grants nothing: MCP trust is read from
`~/.sugar-crush/config.json` only (see [`MCP.md`](MCP.md)). The settings view's
**Files** tab lists the file as "not a layer". SugarCraft's own repository root
carries one.

### The loud half

Both of your own files are read a **second** time, by a second reader.
`Bootstrap::permissionConfigLayers()` builds the permission stack from
`~/.sugar-crush/config.json` *and* `~/.sugar-crush/settings.json`, routing both
through `readPolicyFile()` — the STRICT reader. So the bullets above describe
layers 1 and 2 completely, and layers 3 and 4 only as far as the *settings*
read goes.

Every one of these **refuses the launch** with `PermissionConfigException`,
for either of your two files:

- it is not valid JSON, or is valid JSON that is not an object (a top-level
  list refuses);
- it is world-writable, or owned by another uid — and the same check runs on
  `~/.sugar-crush` itself, so a world-writable config directory refuses too;
- it exists but cannot be read; it is a directory or a dangling symlink; an
  ancestor directory is unsearchable.

One file, two readers, and they disagree on purpose: a stray comma in
`~/.sugar-crush/settings.json` costs you a `theme` **silently** and refuses the
session **loudly**, because the second reader is the one carrying
`permissionMode` and `permissionRules` — and a permission mode that silently
falls back to the permissive default is the one failure this project will not
take quietly. `readUserConfig()` itself stays tolerant throughout; it is the
launch that refuses. See [`PERMISSIONS.md`](PERMISSIONS.md) and
[`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

## See also

- [`PERMISSIONS.md`](PERMISSIONS.md) — permission modes, rule patterns, and
  all four `trustedProject*` grants.
- [`MEMORY.md`](MEMORY.md) — the rest of the `~/.sugar-crush/` layout.
- [`ENVIRONMENT.md`](ENVIRONMENT.md) — the environment variables that sit above
  this stack.
  <!-- settings:env-split:begin -->
  They do not cover it: only eight of the thirty-six layered keys have an
  env override (`provider`, `models`, `titleModel`, `summaryModel`, `promptCache`,
  `parallelToolCalls`, `parallelToolDeadlineSeconds`, `connectTimeoutSeconds`).
  `maxOutputTokens`, `modelPrices`, `extraBody`, `thinkingBudget`, `maxToolSteps`,
  `contextWindow`, `secretEnvAllowlist`, `allowedTools`, `disabledTools`,
  `bashSandbox`, `testCommand`, `autoTest`, `instructions`, `disabledRules`,
  `embeddingModel`, `disabledSkills`, `enabledSkills`, `subagentModel`,
  `includeGitInstructions`, `attribution`, `lsp`, `autoCommit`, `theme`,
  `statusLine`, `layout`, `lintCommands`, `providerRetryAttempts` and
  `providerRetryBaseBackoffMs` have none.
  <!-- settings:env-split:end -->
  (`statusLine` was missing from this list when it joined the stack — P6.S4
  counted the keys rather than copying the sentence, which is what found it.
  The sentence is now generated from `SettingsSchema`, so a key can no longer
  join the stack without landing in it.)
