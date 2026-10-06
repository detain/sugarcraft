# SugarCraft libraries used by sugar-crush — audit report

**State as of 2026-10-06, master `ce0c1931e`.** This report covers the 15 SugarCraft libraries that
sugar-crush declares under `require`. It lists findings that are open on current master.

**Verification status — read this before trusting a row.** This round was produced by one review
agent per library, and every agent ran under permission mode `plan` with `Bash` denied. **No
`php -r` probe, no `php -l`, and no `phpunit` run executed anywhere in this round.** These are
static source reads with `file:line` citations, not measured results. That is a downgrade from the
2026-10-03 edition of this file, whose header recorded grep plus throwaway probes.

Two rows were checked by hand against the source afterwards and are marked **LEAD-VERIFIED**; one
agent-reported MAJOR was disproved that way and is recorded under *Disproved* rather than as a
finding. Everything else is unverified. Before any of it is scheduled as work, re-run it with
execution allowed — the probes that would settle each one are named in the row.

## Which libraries sugar-crush uses

sugar-crush lists 15 `sugarcraft/*` packages under `require` and none under `require-dev`. 14 are
referenced from `src/`; `candy-kit` is declared but not wired, deliberately (see its section). The
transitive closure is 22 libraries: the 7 reachable only through siblings (`candy-async`,
`candy-buffer`, `candy-ansi`, `candy-input`, `candy-palette`, `candy-flip`, `honey-bounce`) were
**not audited** in this round.

Counts are from `prompt_kit/tools/crush-dep-surface.php` (output:
`prompt_kit/findings/crush-dep-surface.txt`), which scans `sugar-crush/**.php` excluding `vendor/`
and caches. "Files" is every PHP file naming the FQN prefix, split `src`/`tests`.

| Library | Declared | Files (src/tests) | What crush pulls |
|---|---|---:|---|
| candy-core | `require` | 373 (109/260) | `KeyType` (176), `Msg\KeyMsg` (176), `Util\Width` (76), `Msg` (56), `AsyncCmd` (50), `Util\Ansi` (42), `MouseButton`/`MouseAction`/`Msg\Mouse*Msg`, `BatchMsg` (22), `Msg\WindowSizeMsg` (20), `Util\Color`, `Util\Sanitize`, `I18n\T`, `InputReader`, `View`, `Util\AtomicJsonFile`, `Program`, `Cmd`, `Model` |
| candy-mouse | `require` | 32 (8/24) | `Zone` (19), `Mark` (9), `Sentinel` (9), `Scanner` (3), `MouseEvent`, `ZoneClickTracker`, `Selection`, `SelectionRange` |
| candy-sprinkles | `require` | 45 (29/16) | `Style` (35), `Border` (16), `Bar\Segment`, `Table\Table`, `Layout`, `Position` |
| candy-mosaic | `require` | 21 (5/16) | `Mosaic` (11), `ImageLayer` (4), `ImageSource`, `Capability`, `Renderer\HalfBlockRenderer`, `Renderer\SixelRenderer`, `TmuxPassthroughDecorator`, `Detect` |
| candy-layout | `require` | 20 (6/14) | `Dock\Side` (14), `Dock\DockLayout` (10), `Region` |
| sugar-mcp | `require` | 17 (7/10) | `RequestIdSequence`, `ArgumentShape`, `ExchangeLock` |
| candy-pty | `require` | 10 (3/7) | `Libc`, `Posix\PosixPtySystem`, `Posix\PosixTermios`, `Spawn`, `Pty` |
| candy-fuzzy | `require` | 8 (5/3) | `MatchResult`, `Matcher\SmithWatermanMatcher`, `Highlighter`, `Matcher\CharFold` |
| candy-forms | `require` | 11 (5/6) | `ItemList\{ItemList,Item,LoadMoreMsg}`, `TextArea\TextArea`, `Field\{Input,Confirm,Select}`, `TextInput\TextInput` |
| candy-shine | `require` | 6 (3/3) | `Render\SectionStream` |
| sugar-veil | `require` | 3 (3/0) | `Veil`, `Position` |
| sugar-toast | `require` | 2 (2/0) | inline FQNs only: `Toast::new()->withDuration()->withSymbolSet()->alert(...)`, `ToastType`, `SymbolSet` |
| candy-focus | `require` (`@dev`) | 2 (1/1) | `FocusRing` |
| sugar-diff | `require` | 1 (1/0) | `Diff`, `DiffOptions`, `LineKind` |
| candy-kit | `require` (`@dev`) | 1 (0/1) | **Nothing yet**: the `Cli\Help::screen()` restyle is deferred (E453) |

## Severity index

"Worst open" is the worst finding recorded for the library. "Crush-live" counts findings reachable
from sugar-crush as it is wired today, as distinct from library-level defects no current call site
hits.

| Library | Worst open | Open | Crush-live |
|---|---|---:|---:|
| candy-core | MAJOR | 4 | 1 |
| candy-mosaic | MAJOR | 2 | 1 |
| candy-mouse | MAJOR | 3 | 2 |
| candy-shine | MAJOR | 2 | 1 |
| candy-forms | MAJOR | 4 | 1 |
| sugar-mcp | HIGH (carried) | 8 | 4 |
| sugar-toast | MAJOR | 6 | 0 |
| candy-kit | MAJOR | 5 | 0 |
| candy-layout | MINOR | 3 | 2 |
| sugar-veil | MAJOR | 4 | 1 |
| candy-fuzzy | MINOR | 4 | 1 |
| candy-focus | MINOR | 2 | 0 |
| sugar-diff | MINOR | 3 | 0 |
| candy-pty | MINOR | 3 | 0 |
| candy-sprinkles | MINOR | 2 | 0 |

## Cross-library patterns

**Parallel copies drifting from their twin** — still live, and now in two places. sugar-crush keeps
its own `McpMessage`, `MCP\McpRouter` and `MCP\McpServer` beside sugar-mcp's, and hardening has
again landed on only one side (sugar-mcp #2, #7). Separately, sugar-crush's `LSP\LspExchangeLock` is
a hand-rolled twin of sugar-mcp's `ExchangeLock`: the library accumulated fail-closed gates
(`store()`/`markPhase()` returning `false` must abort the exchange, `sweepStale()` on every `new()`)
and nothing tests parity, so each gate has to be noticed and re-landed by hand.

**A forked copy of a canonical helper going stale.** `sugar-toast` carries its own private cluster
walker forked from `candy-core`'s `Width::nextCluster()` and never re-synced the invalid-UTF-8
guards that fix was about (sugar-toast #3). `CALIBER_LEARNINGS.md` for that library says to delegate
to `Width` as the oracle; the code does not.

**The prior per-library audit files are actively costing time.** Six of fifteen agents spent part of
their budget discovering that `findings/<slug>.md` describes code that no longer exists. See
*Stale source-of-truth docs* below — that is the cheapest thing in this report to fix.

---

# candy-core

### 1. [MAJOR] `AsyncCmd` dispatches into a torn-down runtime; every other deferred path is generation-guarded and this one is not
- **WHERE:** `candy-core/src/Program.php:694-717` — the promise `then()`/`otherwise()` callbacks call `$this->dispatch()` directly. Compare the guard on the tick path at `Program.php:1198-1207` (`deferTick`, runtime-generation checked), and `releaseRuntime()` at `:1170-1181`, which cancels ticks, timers, sequences and pending sends but never cancels or detaches an outstanding promise.
- **WHAT:** An `AsyncCmd` whose promise settles after the program has released its runtime still calls `dispatch()`. `ProgramRuntimeTeardownTest` pins ticks, timers, sequences and send — it has no `AsyncCmd` case, which is why the gap has stayed invisible. sugar-crush drives `Cmd::promise()` from 20+ sites in `src/Chat.php`, so any provider response landing after a quit/restart takes this path.
- **FIX:** Route the `then()`/`otherwise()` bodies through the same generation check `deferTick` uses, or have `releaseRuntime()` detach/cancel pending async handles. Add the missing `AsyncCmd` case to `ProgramRuntimeTeardownTest` — the test is the reason this is still open.
- **USED-BY-CRUSH:** yes, on the live path. Needs a runtime probe (settle a promise after `releaseRuntime()` and observe `dispatch()`) to confirm the consequence is corruption rather than a benign late no-op.

### 2. [MINOR] `AtomicJsonFile`'s `flock` is dead code, and the docblock claims the protection it cannot provide
- **WHERE:** `candy-core/src/Util/AtomicJsonFile.php:167-220`. The claim is repeated downstream at `sugar-crush/src/Session.php:98`.
- **WHAT:** The exclusive lock is taken on a per-write uniquely-named temp file. Two writers never share that inode, so the lock cannot block anyone — concurrent writes to the same target are unordered. There is also no `fsync` before the rename, so a crash can leave the file present but not durable.
- **FIX:** Lock a stable sidecar path (e.g. `<target>.lock`), or drop the lock and correct the docblock and the `Session.php:98` comment. Do not leave a comment promising mutual exclusion that the code does not implement.
- **USED-BY-CRUSH:** partially — sugar-crush writes session and config state through it. Single-process today, so the missing exclusion is latent; the false documentation is the live cost.

### 3. [MINOR] `Alt` + a non-ASCII character decodes as Escape + plain character
- **WHERE:** `candy-core/src/InputReader.php:233-241` — the alt-prefixed branch excludes bytes `>= 0x80`.
- **WHAT:** In UTF-8 mode `Alt+é` arrives as `ESC 0xC3 0xA9`; the decoder yields Escape then the character rather than an alt-chord. Existing tests cover only ASCII alt cases.
- **FIX:** Include the multi-byte lead in the alt-prefixed set and decode the following cluster as the chord. Add a non-ASCII alt case to `InputReaderTest`.
- **USED-BY-CRUSH:** yes for any user whose keybindings use Alt with a non-ASCII key; sugar-crush's own defaults are ASCII.

### 4. [MINOR] `Program::withRecorder()` mutates `$this` and returns `$this`, unlike its siblings
- **WHERE:** `candy-core/src/Program.php:178-183`. `withLogger()` and `withExceptionHandler()` at `:205-223` clone.
- **WHAT:** Breaks the repo rule that every `with*()` returns a new instance, and is inconsistent with the two adjacent setters in the same class, so a caller has to know which one lies.
- **FIX:** Make it clone like its siblings, or rename to `setRecorder()` to stop advertising fluent semantics.
- **USED-BY-CRUSH:** no — sugar-crush does not call it.

# candy-mosaic

### 1. [MAJOR] Half-block transparency is inverted, and fully-transparent cells paint default-foreground stripes — **LEAD-VERIFIED**
- **WHERE:** `candy-mosaic/src/Renderer/HalfBlockRenderer.php:62-71`. Class docblock `:23-29` states the mapping backwards from the code.
- **WHAT:** Verified by reading `:60-84`. `▀` (U+2580) paints its **upper** half with the foreground and `▄` (U+2584) its **lower** half with the foreground.
  - `:62-65` both-transparent emits a bare `▀` with no SGR, so the upper half renders in the terminal's *default foreground* — a visible stripe over whatever the transcript already drew. It should emit a space.
  - `:66-71` top-transparent uses `Ansi::bgRgb($botR,$botG,$botB)` with `▄`. `▄`'s lower half takes the *foreground*, so the image colour lands in the wrong half and the upper half renders default white. It should be `fgRgb`.
  - `:72-77` bottom-transparent (`fgRgb` + `▀`) is correct.
- **PROOF GAP:** the test that supposedly pins this, `candy-mosaic/tests/Renderer/HalfBlockTransparentTest.php:29-63`, only asserts `assertStringContainsString("▀")` — it cannot detect either defect. Confirm with a `bin2hex()` dump of a 2-row image with a transparent top pixel.
- **FIX:** `:65` emit `' '`; `:69` use `Ansi::fgRgb(...)`; correct the docblock at `:23-29`; strengthen the test to assert the exact SGR+glyph bytes for all four branches.
- **USED-BY-CRUSH:** yes. This is the fallback renderer on every non-graphics terminal and `sugar-crush/src/Renderer.php:4855` inlines its output into the frame.

### 2. [MINOR] Kitty graphics: compression is declared with the format key, and both in-repo sides agree on the wrong one
- **WHERE:** `candy-mosaic/src/Renderer/KittyRenderer.php:101-110` with `KittyOptions.php:99-115`; the counterpart is `candy-testing/.../KittyStream.php:331-341`.
- **WHAT:** A zlib-deflated payload is sent as `f=1`. Per the Kitty spec `f` is the data *format* (1 = raw RGBA) and compression is `o=z`. candy-testing's own decoder inflates when it sees `f=1`, so the encoder and the in-repo decoder are mutually consistent and the test suite passes, while a real Kitty terminal would read compressed bytes as raw RGBA and render garbage.
- **FIX:** Send `o=z` for compression and keep `f` as the format. The candy-testing decoder must be corrected in the same change or the pair will keep passing against each other.
- **USED-BY-CRUSH:** no today — `withCompression()` has no sugar-crush caller, so this is a public-API landmine rather than a live defect.

**Coverage note:** `ImageLayer`, `MosaicBuilder`, `DiskCache`, `AdaptiveImage`, `PrecomputedImage`,
`Animation/AnimationDriver`, `ApngDecoder`, `Scale`, `CellSize` and `Deadline` were not reached
before the agent's budget ran out. `ImageLayer` is on sugar-crush's hot path and is the priority gap.

# candy-mouse

### 1. [MAJOR] `ZoneClickTracker` resolves a release against the press's stored zone box, so a re-render between press and release can fire a different control
- **WHERE:** `candy-mouse/src/ZoneClickTracker.php:83` (pairs the release against the press's recorded zone/box). Consumer: `sugar-crush/src/Chat.php:7869-7957`.
- **WHAT:** sugar-crush dispatches on `$click->zone->id`, and its ids are positional per frame (`session-row:<n>`, `picker-item:<n>`). If the transcript or picker re-renders between button-down and button-up — which it does, since ticks and streamed tokens repaint — the id now names a different row, and the action fires for a control that is no longer at that position. In an app whose clickable set includes permission grants this is the dangerous class of bug.
- **STATUS: not traced to a confirmed mis-fire.** Settling it needs the probe the agent could not run: press on zone A, re-scan with A moved, release on A's old box, inspect the returned `Zone`.
- **FIX:** Carry a per-frame epoch in `ClickResult` and have the consumer drop a release whose epoch is not the current frame's; or resolve by identity rather than by positional id at dispatch.
- **USED-BY-CRUSH:** yes, if reproducible — this is the highest-value thing to probe in this report.

### 2. [MAJOR] The zone sentinel is a fixed, guessable literal; neutralising it is left entirely to the consumer
- **WHERE:** `candy-mouse/src/Sentinel.php:31-34`; consumer-side stripping at `sugar-crush/src/Renderer.php:1543,4175,4215` via `Sanitize::stripZoneSentinels` (`candy-core/src/Util/Sanitize.php:80,83`).
- **WHAT:** Because the sentinel is a constant string with no per-process nonce, text that happens to contain it — including model-authored or file-sourced text rendered into a frame — is parsed as a zone marker. sugar-crush defends against this by stripping at three call sites; candy-mouse ships no first-party neutralisation and no test asserting that a forged sentinel inside content is inert. A fourth render path that forgets the strip silently reintroduces it.
- **FIX:** Give the sentinel a per-process random component so untrusted content cannot reproduce it by accident, and add a candy-mouse test that scans content containing the sentinel shape.
- **USED-BY-CRUSH:** yes — sugar-crush renders untrusted model output, and its safety currently rests on three hand-maintained strip calls rather than on the library.

### 3. [MINOR] `SelectionRange::extract()` is not clamped to the current frame height
- **WHERE:** `candy-mouse/src/SelectionRange.php` (`$lines[$row - 1] ?? ''`), with `Selection`'s region frozen at construction.
- **WHAT:** A selection that survives a resize copies blanks instead of being clamped to the new frame.
- **FIX:** Clamp on extract, or have `Selection` re-derive its region per frame.
- **USED-BY-CRUSH:** possible via `Tui/TextSelection` across a terminal resize; the agent did not finish reading whether the adapter re-clamps per render.

# candy-shine

### 1. [MAJOR] Streaming markdown repaints the open tail in full every frame, and sections only ever close at a column-0 heading
- **WHERE:** `candy-shine/src/Render/SectionScanner.php:148-169` (boundaries emitted only for ATX headings at column 0), `candy-shine/src/Render/SectionStream.php:120-146`, consumer `sugar-crush/src/Renderer.php:4279-4330` with the re-render at `:4329`.
- **WHAT:** `Renderer::streamingMarkdown()` keeps one `SectionStream` alive in a static memo, pushes each delta one line at a time, and re-renders the still-open tail on every frame via `(clone $stream)->finish()`. Because the scanner only closes a section at a column-0 heading, a long reply that is one fenced code block, or plain prose with no headings, never closes — so cost grows quadratically in tokens on the hottest path in the app. `sugar-crush/src/Renderer.php:4275-4277` documents this as accepted.
- **FIX:** Either close sections on other block boundaries (fence end, blank-line paragraph break) so the tail stays small, or make the tail render incremental. `defersStreaming()` (`candy-shine/src/Renderer.php:355`) is the existing escape hatch and is worth checking before inventing a new one.
- **USED-BY-CRUSH:** yes — every streamed assistant message. Needs a timing probe (render a 5k-token heading-free reply and plot per-frame cost) to size it.

### 2. [MINOR] `DiffGutter` justifies a setting with a `Width` fact that is no longer true
- **WHERE:** `sugar-crush/src/Tui/DiffGutter.php:35` claims `Width::string("\t")` is 0. `candy-core/src/Util/Width.php:52-76` records that as pre-E69 behaviour; a tab now costs `TAB_WIDTH = 4`.
- **WHAT:** The conclusion (`lineNumbers: false`) is still right, because `candy-shine/src/SyntaxHighlighter.php:65` joins with a literal `"\t"` whose real width is column-dependent — but the stated reason is stale, and a future reader will re-derive from it and get the wrong answer.
- **FIX:** Restate the comment against current `Width` behaviour. sugar-crush-side edit, not a candy-shine one.
- **USED-BY-CRUSH:** documentation only.

# candy-forms

### 1. [MAJOR] `TextArea` never wraps, and measures codepoints rather than display cells
- **WHERE:** `candy-forms/src/TextArea/TextArea.php` — `$width` is stored and `view()` never uses it to wrap.
- **WHAT:** Two separate defects on the same widget: long content does not wrap at the configured width, and caret column arithmetic counts codepoints, so the caret sits at the wrong x for any text containing wide or emoji characters. sugar-crush's session-title and rename editors are the reachable surfaces.
- **FIX:** Wrap in `view()` against `$width` via `Width::wrapAnsi`, and derive caret column from `Width::string()` of the text before the caret.
- **USED-BY-CRUSH:** yes — `SettingsEditor`, `SessionPicker::startRename`, `Chat` title editor.
- **NOTE:** because `sugar-bits` and `sugar-prompt` alias these classes, both fixes propagate to every façade consumer.

### 2. [MINOR] `TextInput::paste()` bypasses the restrict pattern
- **WHERE:** `candy-forms/src/TextInput/TextInput.php:939-943` (inserts the whole payload) with the check at `:1002` (`preg_match` over the entire insert).
- **WHAT:** Restrict is evaluated as an any-substring match against the pasted blob, so `4<script>` satisfies a `[0-9]` restrict.
- **FIX:** Match per-character, or anchor with `^...$` over the full candidate.
- **USED-BY-CRUSH:** no — grep of `sugar-crush/src` for `withRestrict|withValidator|withEnum` returns zero hits; crush uses only `withPrompt`/`withCharLimit`/`setValue`.

### 3. [MINOR] `Confirm`'s docblock advertises `Tab` toggling that `update()` does not implement
- **WHERE:** `candy-forms/src/Field/Confirm.php:19-21` versus the `match` at `:127-139`, which has no `Tab` arm. **LEAD-VERIFIED.**
- **FIX:** Add the `Tab` arm or delete the claim.
- **USED-BY-CRUSH:** documentation.

### 4. [MINOR] `get*()` accessors on the field classes
- **WHERE:** `Field/Confirm.php:171-173`, `TextArea/TextArea.php:793,796,803`, `TextInput/TextInput.php:591,622`.
- **WHAT:** The repo rule is bare accessors. Renaming is a breaking change for every façade consumer, so it needs a deprecation pass rather than a sweep.
- **USED-BY-CRUSH:** no (crush calls `value()`/`key()`).

**Also checked here:** the three inconsistent validator-attach semantics (Confirm revalidates on
change, `Field\Input` validates on attach, `Field` does not) are real but unreachable from
sugar-crush, which attaches no validators. `ItemList` `LoadMoreMsg` was probed by reading for the
infinite-loop/no-advance risk and is **clean**: `Chat::handleSessionLoadMore`
(`sugar-crush/src/Chat.php:14994-15008`) widens the fetch limit and short-page handling closes the
edge, so a zero-row fetch cannot re-fire.

# sugar-mcp

Rows 1 and 2 are carried over from the 2026-10-03 edition; both were re-checked against source this
round. Rows 3-8 are new and unprobed.

### 1. [HIGH] `sugarcraft/sugar-mcp` is not on Packagist, so the published sugar-crush cannot be installed
- **WHERE:** `sugar-crush/composer.json:49`. Also `php tools/check-path-repos.php`, which exits 1 with `sugar-crush: missing path-repo for sugar-mcp (required transitively via sugar-crush -> sugar-mcp)`.
- **WHAT:** `https://repo.packagist.org/p2/sugarcraft/sugar-mcp~dev.json` returns 404 while `sugarcraft/sugar-crush` dev-master resolves and requires it. `composer require sugarcraft/sugar-crush` therefore cannot install. The split repo exists (`github.com/sugarcraft/sugar-mcp`, pushed by `sync-sugarcraft.yml`); only the Packagist registration is missing. Inside the monorepo the root path-repo hides this. *Carried from 2026-10-03; not re-probed this round — re-verify the Packagist 404 before acting.*
- **FIX:** Register `sugarcraft/sugar-mcp` on Packagist; the gate then passes with no manifest change. Optionally add a `sugar-mcp` row to `DESCRIPTIONS` in `scripts/bootstrap-org-repos.sh`. Neither is doable from a working tree — both need org/Packagist access.
- **USED-BY-CRUSH:** yes; it decides whether a Packagist install resolves at all.

### 2. [LOW] `McpMessage::errorCode()`/`errorMessage()` invent values from malformed wire errors — **re-confirmed still open at `ce0c1931e`**
- **WHERE:** `sugar-mcp/src/McpMessage.php:289` (`(int) $this->error['code']`) and `:298` (`(string) $this->error['message']`), read by `describeError()` at `sugar-mcp/src/StdioMcpServer.php:1159-1172`. The hardened twin is `sugar-crush/src/McpMessage.php:308,326`.
- **WHAT:** Third-party wire data. `{"code":"abc"}` yields `0` and `{"code":true}` yields `1`, so a refusal reports a code the server never sent; `{"message":{"x":1}}` raises `Warning: Array to string conversion` and the text becomes `"Array"`. Under an error handler that promotes warnings, `start()` throws `ErrorException`.
- **FIX:** Port `is_int($code) ? $code : null` / `is_string($message) ? $message : null` into the library with a regression test per malformed shape. Longer term, fold crush's parallel copies onto the library's.
- **USED-BY-CRUSH:** yes — crush's stdio path (`sugar-crush/src/MCP/StdioMcpServer.php:71,118`) wraps the library's server and formats refusals through the library's `McpMessage`.

### 3. [MAJOR] One deadline-less hung `callTool` wedges every process sharing the connection
- **WHERE:** `sugar-mcp/src/ExchangeLock.php:248-276` (`acquire()`), `sugar-mcp/src/StdioMcpServer.php:664-667` (deadline null unless `toolTimeoutSeconds` is opted in) and `:832-847` (`exchange()` blocks in `acquire()` before doing anything).
- **WHAT:** The whole exchange runs under one `flock`. Waiters honour *their own* deadline and the server's liveness, but the **holder** is deliberately unbounded (E646: a tool call is somebody's real work). So a live-but-silent server — a stuck tool, not a crash — leaves the holder holding forever: bounded siblings time out, unbounded siblings hang. To the user the server looks dead while its process is up. `flock` waiters are also not FIFO, so even without a hang a waiter can starve. The liveness probe only rescues the *dead* server case.
- **FIX:** (a) sugar-crush-side: give forked MCP workers a default `toolTimeoutSeconds` unless a tool declares itself long-running — the library already supports it per call. (b) Document the holder-wedges-the-queue consequence in the README's "Fork safety" section; it currently documents serialisation but not this. (c) Optionally have `exchange()` surface *why* `acquire()` returned null (deadline vs dead server vs starvation) so the payload can name the hung request id.
- **USED-BY-CRUSH:** yes — the MCP worker pool and `ClaudeCodeMcpClient`. The LSP twin has the same shape (see #7).

### 4. [MINOR] `callTool()` can throw where the `McpServer` contract promises an `{"error": …}` payload
- **WHERE:** contract at `sugar-mcp/src/McpServer.php:57-61`; the `try` at `sugar-mcp/src/StdioMcpServer.php:670-686` catches only `\InvalidArgumentException`; escapes come from the 64 MiB frame cap at `:1335` and from a caller-supplied `onWait` closure invoked at `:1241,1253`.
- **WHAT:** An oversized or pathological server reply, or a throwing `onWait` beat, propagates a `RuntimeException` through `callTool()` into the model-facing transcript path — exactly the consumer the interface says is protected from throws.
- **FIX:** Guard the framing-cap throw separately (after a cap trip the buffer is poisoned: reset it or mark the connection dead), wrap the `onWait` invocation so a throwing beat degrades to a failed call, or amend the interface doc to name both throwing paths.
- **USED-BY-CRUSH:** yes, tool-result rendering.

### 5. [MINOR] `claim()` has no two-owner guard, so the fork-safety promise holds only while exactly one process ever claims
- **WHERE:** `sugar-mcp/src/RequestIdSequence.php:68-71` (`claim()` sets `ownerPid` unconditionally) and `:98-110` (the plain-int branch keys solely off `$pid === $this->ownerPid`). Callers: `StdioMcpServer.php:340`, `sugar-crush/src/LSP/LspConnection.php:290`, `sugar-crush/src/MCP/HttpMcpServer.php:111`.
- **WHAT:** A process that forks a *started* connection and re-runs its connect/`claim()` in the child produces two owners each holding a copy of the parent's counter, both emitting plain decimal ids. Sharing one connection (one HTTP session id, one LSP pipe) then collides ids and a reply can be matched to the wrong call. The library's own stdio flow self-protects — a child's inherited copy reports "already running" (`StdioMcpServer.php:328`) — but the downstream twins carry no such protection.
- **FIX:** Make the handover explicit: `claim()` returns `false` or throws when the previous `ownerPid` is alive and not the current pid, leaving `claimAfterOwnerDeath()` for the legitimate restart case. Or document that consumers sharing one connection across forks must never re-claim.
- **USED-BY-CRUSH:** reachable in principle through `HttpMcpServer`/`LspConnection`; the agent could not run the `pcntl_fork` probe that would settle it.

### 6. [MINOR] Null-id and batch replies are skipped silently, so a deadline-less call waits forever on a non-conforming server
- **WHERE:** `sugar-mcp/src/McpMessage.php:54-92` (`{"id":null}` parses to `id = null`; a top-level JSON array fails the `jsonrpc` check at `:61` and returns `null`), `:268` (`isResponse()` requires `id !== null`), and the skip-by-design reader policy at `sugar-mcp/src/StdioMcpServer.php:1054-1096`.
- **WHAT:** The malformed-reply gate `isMalformedReplyTo()` (`:1096`) can only fire for a frame carrying *our* id, so a null-id or array frame never reaches it. For a bounded call the deadline saves the caller; for the default unbounded `callTool()` it waits on a reply that will never be attributed — indistinguishable from finding #3.
- **FIX:** A conformance tripwire: while an exchange is outstanding, count frames that parse to `null` or carry `id === null` with a result/error shape and fail the exchange with an `{"error": …}` after a small threshold.
- **USED-BY-CRUSH:** only against misbehaving servers; conforming SDK servers never send these shapes.

### 7. [MINOR] `LspExchangeLock` duplicates `ExchangeLock`, so library hardening must be re-landed by hand
- **WHERE:** `sugar-crush/src/LSP/LspExchangeLock.php` (wired `LspConnection.php:72,313`) versus `sugar-mcp/src/ExchangeLock.php`.
- **WHAT:** The library's fail-closed gates (`store()`/`markPhase()` returning `false` aborts the exchange — `ExchangeLock.php:314,340`; `sweepStale()` on every `new()` — `:89-95`) have no parity test, so each must be noticed and re-applied in the twin.
- **FIX:** Parameterise `ExchangeLock` with the note/append API the LSP side needs so it can use the canonical class, or add a parity test asserting the twin behaves identically.
- **USED-BY-CRUSH:** yes — LSP crash recovery.

### 8. [MINOR] Test gaps
- **WHERE:** `sugar-mcp/tests/`.
- **WHAT:** no test exercises `acquire()` expiring **while the holder is alive** — the exact #3 scenario, so the mitigation surface is unpinned; `RequestIdSequence` is pinned only through a pid seam (real-fork coverage lives one level up in `StdioMcpServerForkSafetyTest`); `McpMessageTest` (177 lines) was not opened, so null-id/error-shape tolerance is unverified.
- **FIX:** Add the deadline-expiry-while-alive test first, then a fork test for `claim()`.

**Clean bill (sugar-mcp):** NDJSON framing is solid on every hostile input the agent could construct
statically — `json_encode` escapes control characters so a payload cannot forge a frame boundary,
CRLF and blank lines are trimmed, split and coalesced reads are handled by the floor-offset scan
(`StdioMcpServer.php:1118-1125`, O(n) via `$scannedFrom`), the 64 MiB cap drops rather than
truncates, stderr is drained on both wait sets so a full pipe cannot deadlock the child. Correlation
happy path, `ArgumentShape` (bounded by `MAX_DEPTH 64` / `VISIT_BUDGET 10000`, 16-hop `$ref` cap, no
injection path found from untrusted `inputSchema`), `McpRouter` deny-before-allow ordering, and
live-process child teardown (`BoundedShutdown` TERM→KILL→reap, group-aware) all checked out.
`tools/check-child-lifetimes.php` was **not run** (Bash denied) — treat as blocked, not clean.

# sugar-toast

sugar-crush imports nothing from this library and names every symbol by inline FQN. The complete
reached surface: `Toast::new(56)->withDuration(null)->withSymbolSet(SymbolSet::Unicode)->alert(...)`
at `sugar-crush/src/Chat.php:9226-9229`, `ToastType::Warning/Success/Info` at `:9117-9119,9158`, and
`->view('', $width, 0)` at `sugar-crush/src/Renderer.php:6495`. **Every FQN resolves** — no contract
divergence. The inline spelling is house style in those two very large files, not drift; for
`Position` there is additionally a genuine clash with `SugarCraft\Veil\Position` imported at
`Renderer.php:28`.

**Correction to this round's tasking**, recorded because the premise was wrong in the prompt:
sugar-toast *is* integration-tested — `sugar-crush/tests/Chat/ApplySettingsTest.php:177-199` asserts
the toast text renders, that per-row width never exceeds terminal columns, and the generation
semantics; `CompactionLiveSettingsTest.php:284` also exercises it. So there is no coverage gap on
the reached surface.

### 1. [MAJOR] `dismiss()` is a one-way trap; `clear()` does not undo it
- **WHERE:** `sugar-toast/src/Toast.php:315` (flag set), `:471` (`view()` short-circuits on it), `:334-339` (`clear()` empties the queue but not the flag); `README.md:131`.
- **WHAT:** After one `dismiss()` call that instance can never render again — subsequent `alert()`s queue invisibly forever. There is no `withDismissed(false)`. The README explicitly tells hosts to retire persistent alerts via `dismiss()`, `clear()` or `pruneExpired()`, and `dismiss()` is the only one that records history *and* the only one that bricks the object.
- **PROOF GAP:** `ToastEscCloseTest.php:75-81` documents the split brain in a comment, but no test renders, re-alerts or clears *after* a dismiss.
- **FIX:** Have `clear()` reset `dismissed`, or add `withDismissed(bool)`; add a `dismiss → clear → alert → view` regression test.
- **USED-BY-CRUSH:** no — `applySettings()` replaces the whole toast (`Chat.php:9226`) and never calls `dismiss()`. Latent.

### 2. [MAJOR] Unbounded accumulation by default; `view()` never frees expired alerts
- **WHERE:** `sugar-toast/src/Toast.php:49` (`maxConcurrent = null`), `:251-267` (`appendBounded` caps nothing when null), `:475-477` (expiry filter), `:304-317` (`dismiss`), `HistoryLog.php:25-28`.
- **WHAT:** Three defaults compose badly: no concurrency cap; `view()` filters expired alerts into a **local** `$active` and, being immutable, never writes the filtered set back, so expired alerts stay in `$queue` forever and nothing inside the library calls `pruneExpired()`; and `HistoryLog::push` is uncapped while `dismiss()` copies live alerts into it *and* leaves them in the queue, double-counting until pruned.
- **FIX:** Return a drained instance alongside `view()` (or make the rendered set authoritative), default `maxConcurrent` to a finite number, add `withHistoryLimit(int|null)`.
- **USED-BY-CRUSH:** no — crush toasts only on settings save, one persistent alert replaced wholesale, so the tight-loop-of-failures premise has no crush path. Latent for any long-lived host.

### 3. [MINOR] Forked `nextCluster()` lacks the invalid-UTF-8 guards `candy-core`'s canonical version has
- **WHERE:** `sugar-toast/src/Toast.php:774-792` versus `candy-core/src/Util/Width.php:847-884`.
- **WHAT:** Toast's private cluster walker accepts `grapheme_extract()`'s return unconditionally. ICU, on malformed input, returns the *next* cluster (skipping the stray byte) or a substituted U+FFFD; `Width::nextCluster` learned this and now rejects clusters not positioned at the cursor plus validates the lead byte's continuation bytes. `candy-core/tests/Util/WidthInvalidUtf8Test.php:18-23` records the bug that fix closed ("every cluster walk duplicated one cluster and dropped the bad byte"). Toast forked the walker before that fix and never re-synced — which also breaks its own `CALIBER_LEARNINGS.md` instruction to delegate to `Width`.
- **FIX:** Delete the fork and call `Width::nextCluster()`, or port both guards verbatim; mirror `WidthInvalidUtf8Test::malformed()` into the toast suite.
- **USED-BY-CRUSH:** low — crush's alert texts are fixed strings plus settings paths.

### 4. [MINOR] README shows a call chain that fatals
- **WHERE:** `sugar-toast/README.md:253-255`.
- **WHAT:** `$toast->alert(...)->withActions([$action])` throws "Call to undefined method Toast::withActions()" — `withActions()` exists only on `Alert` (`Alert.php:90`). The prior plan's Phase 4.4 asked for exactly this fix; the code grew an `actions:` parameter on `alert()`/`progressToast()` (`Toast.php:175,199`) and the example was never corrected.
- **FIX:** `$toast->alert(ToastType::Error, 'Connection lost', actions: [$action])`.
- **USED-BY-CRUSH:** documentation.

### 5. [MINOR] `Action::make()` violates the `::new()`-only factory rule
- **WHERE:** `sugar-toast/src/Action.php:30`, with no `new()` twin.
- **FIX:** Rename to `Action::new()`, or alias `new()` and deprecate `make()`.
- **USED-BY-CRUSH:** no — crush never touches `Action`.

### 6. [INFO] `withOverflow()` docblock contradicts the property default
- **WHERE:** `sugar-toast/src/Toast.php:246` says "Enqueue (the default)"; `:52` is `Overflow::DropOldest` (matching README and `CALIBER_LEARNINGS.md`). One-word doc fix.

**Clean bill (sugar-toast):** the brief's top-risk hypothesis — armed timers left on the shared
ReactPHP loop — has **no surface here**: greps over `src/` and `lang/` for
`Loop|React|timer|proc_open|getenv|POSIX` return zero hits. Expiry is pure wall-clock
(`Alert::isExpired()`, `Alert.php:35-39`); crush's single `Cmd::tick(6.0)` is host-side and
generation-guarded (`Chat.php:1764-1767`), which is the right pattern. No POSIX calls, so nothing to
audit on portability. All 9 src files have `declare(strict_types=1)`, public classes `final`, bare
accessors, enums for `Position`/`SymbolSet`/`ToastType`/`Overflow`; every `with*()` returns a fresh
clone (`ToastEscCloseTest.php:31-38`, `AlertTest.php:160`). `view('', $w, 0)` with height 0 is safe —
canvas height is `max(max(bg,0), stackHeight)` (`Toast.php:505`). `nextExpiry()` vs
`secondsUntilNextExpiry()` are correctly distinguished. 23 test files cover essentially every public
method; the only real gaps are the two dismiss cases in #1.

# candy-kit

sugar-crush declares this library and reaches it from **zero** `src/` files. The deferral is
deliberate and documented, and this round confirms the record is accurate.

**Deferral status: holds.** `git show --stat ddd9560d0` is "require candy-focus and candy-kit, which
two plan items need and neither had", touching only `sugar-crush/composer.json`. The row is at
`sugar-crush/composer.json:59` (`@dev`) with the E453 `deferred-wiring` note at `:90-92`.
`tools/check-path-repos.php` keys `$deferredWiring` off that row (`:620`) and prints `DEFERRED_WIRING`
then `continue`s **before** `$unusedFindings++` (`:720-730`), so `--unused` exits 0 for it — this is
code-path evidence; the command itself was not run (Bash denied). The single test naming the
namespace, `sugar-crush/tests/Config/DocFigureProseDriftTest.php:2774`
`testCandyKitDeferredWiringRowMatchesWhatItRecords()`, is a drift guard on the deferral *record*: it
pins the row's prose against candy-kit source and finally asserts `src/` reaches candy-kit in zero
files. It is not integration and does not make the deferral a wiring. E453 is `[CLOSED]` as a CI
defect at `docs/plans/crush_code_hardening_backlog.md:15101` (resolved in the keep direction by E487,
`:16012`), while the restyle remains open — the row uses "E453" for both.

### 1. [MAJOR] candy-kit's presenters cannot express the current help page, so E453 is a content-model rewrite
- **WHERE:** `candy-kit/src/Internal/SafeText.php:37`, used by `HelpText.php:65,68,73,107,108`, `Section.php:41,108`, `Banner.php:29-30`.
- **WHAT:** `SafeText::line()` strips `\x00-\x1f`, i.e. every newline, so a multi-line usage synopsis or any description containing a line break is silently flattened to one line. The single-line contract is deliberate — it protects the frame-diff renderer — but `sugar-crush/lang/en.php:152-533` is a 380-line page whose meaning lives in its line breaks and continuation indents (`serve`'s option block, `session pin|unpin|…`). `HelpText::render()` cannot reproduce it.
- **FIX:** For whoever picks up E453: split the catalogue into per-row keys (`sections[title][key] => description`), or add a multi-line-preserving variant. Note the collision first: `sugar-crush/src/Cli/Help.php:37-41` records the *opposite* decision (audit 15b-14 — translated as a page, column layout included, deliberately not split per-row). Adopting `HelpText` reverses an i18n contract, and that, not the test pins, is the blocker.
- **USED-BY-CRUSH:** no today; this is what makes the deferred work larger than a restyle.

### 2. [MINOR] `Banner::title()` takes no width
- **WHERE:** `candy-kit/src/Banner.php:24-43` sizes to content (`Style::new()->border()->padding(0,2)->render()`); `Section` and `HelpText` both accept `?int $width`.
- **WHAT:** A title wider than the terminal wraps and breaks the border box, and no caller can cap it.
- **USED-BY-CRUSH:** no.

### 3. [MINOR] No presenter self-resolves width, and the current help page already exceeds the default
- **WHERE:** every presenter takes an explicit `?int $width` defaulting to 80 and never queries the terminal (`Section.php:96-97` says so outright); `Cli/Help.php:43` is `screen(): string` with no width to pass.
- **WHAT:** Measured: the longest current help line is 81 cells, so rendering at the 80 default already changes output. Wiring must widen the signature or resolve width at the `ArgvParser` call site.
- **USED-BY-CRUSH:** no today.

### 4. [MINOR] Sibling presenters disagree on a bad width
- **WHERE:** `HelpText::assertWidth()` throws `InvalidArgumentException` for `<1` (`HelpText.php:169-176`, correct per "no silent failures"); `Section::header()` clamps negatives to empty output (`Section.php:139`, pinned by `SectionTest.php:193-194`).
- **WHAT:** One screen using both explodes in one place and blanks in the other.
- **USED-BY-CRUSH:** no.

### 5. [MINOR] `SafeText.php:37` swallows a PCRE failure into an empty string
- **WHERE:** `preg_replace(...) ?? ''`.
- **WHAT:** Caller text can vanish silently where the repo requires a throw. Reachability is low (fixed character-class pattern), hence MINOR. **FIX:** `?? throw new \RuntimeException(...)`.
- **USED-BY-CRUSH:** no.

**SUSPECTED, unrun:** `Stage::subStepWithProgress()` picks its spinner frame from
`(int)(microtime(true)*10) % 10` (`Stage.php:117-118`) while `tests/fixtures/stage-substep-progress.golden`
is a 101-byte golden — confirm by running `--filter 'Progress|Banner'`.

**Clean bill (candy-kit):** all 10 classes `final`, `declare(strict_types=1)` first, no
`::create()/::make()/::default()`, no `get*()` accessors, `Frame::new()` is the root; every value-object
`with*()` returns `new self(...)` (`Frame.php:64,75,81,87`, `Logo.php:80`, `Theme.php:182`); reset
discipline sound (`Style::render` appends `Ansi::reset()`, `candy-sprinkles/src/Style.php:1050`, and
`Frame.php:213` adds one after truncation); width math is cell-aware and the old `mb_strlen` alignment
bug is pinned away (`HelpTextTest.php:71-86`); zero POSIX calls in `src/`, tty-ness delegated to
`ColorProfile::detect()` with `\defined('STDOUT')` guards (`Theme.php:123-139`). Prior audit items #1,
#2, #13, #14 and #20-#24 are all fixed in tree. `ThemeBuilder` mutating `$this` (`:25-43`) is an
`@internal` builder, not a value-object violation.

# candy-layout

The headline risk from the brief — silent divergence between the Cassowary simplex and the greedy
fallback — is **structurally impossible now**: the simplex was deleted and
`candy-layout/src/CassowarySolver.php:68-76` delegates wholly to `GreedySolver`. One solver, so no
divergent geometry. sugar-crush also never touches `GreedySolver`, `CassowarySolver` or `Constraint`
directly; it uses only `Dock\Side` and `DockLayout` (`slots`, `resolve`, `regionFor`,
`toArray`/`fromArray`, `withSlotAdded/Removed/MovedTo`, `columnShare`, `centerPaneId`, `sideMinCols`,
`centerMinCols`, `dividerCols`) plus `Region`. No contract divergence found and the Dock classes are
`final readonly` / immutable-fluent as required.

### 1. [MINOR] No test asserts the dock's columns sum to the frame width
- **WHERE:** `candy-layout/tests/Dock/DockLayoutTest.php:650` sweeps **heights** 1..400; no equivalent width sweep exists.
- **WHAT:** Rounding that loses a cell per region is exactly the failure that leaves a growing gutter or clips the last pane, and it is the one invariant sugar-crush's dock depends on that nothing pins.
- **FIX:** Add the width sweep: 3-region dock, widths 1..200, assert region widths sum exactly to the frame. This is the single most valuable probe for this library and it was never run.
- **USED-BY-CRUSH:** yes — every pane split.

### 2. [MINOR] Per-frame re-resolve cost on the drag path
- **WHERE:** sugar-crush calls `resolve()` from `sideWidth`, `stackHeights` and both drag previews; `isUntouchedDefaultDock()` rebuilds `toArray()` twice per call at `sugar-crush/src/App/App.php:1403`.
- **WHAT:** Layout is recomputed continuously during a drag and on every `WindowSizeMsg` during a terminal resize.
- **FIX:** Measure first (unbounded agent budget; no timing was taken). Memoize `toArray()` in `isUntouchedDefaultDock()` if the sweep shows it mattering.
- **USED-BY-CRUSH:** yes, during drag/resize.

### 3. [MINOR] The only machine-sensitive arithmetic is the opt-in rounding path
- **WHERE:** `candy-layout/src/GreedySolver.php:253-261` (`round()`/float `floor` percentage split, opt-in `roundSplit` only).
- **WHAT:** Deterministic given identical input, but it is the one place a float-to-int policy could differ across builds. `DockGeometry` also exposes `dividerColumns` as both a property and a method.
- **USED-BY-CRUSH:** not on crush's path (crush does not opt into `roundSplit`).

# sugar-veil

sugar-crush touches exactly two call sites, both the same shape:
`Veil::new()->withBackdrop(50)->composite($overlay, $backdrop, CENTER, CENTER[, $shift])` at
`sugar-crush/src/Renderer.php:1977` and `src/Tui/Components/AgentDashboardPane.php:262`; it also
re-implements `Position::CENTER->xOffset()/yOffset()` arithmetic itself at
`Renderer.php:6586,6627,6739`.

**The brief's premise that this path is untested is not established.** `Renderer.php:1911-1915` names
`KeyHelpTest::testTheOverlayChainPaintsInRoutingOrderRightDownTheChain()` as driving all four
overlays through this chain; the agent did not open that file, so the claim was correctly withheld.
Check it before treating veil coverage as a gap.

### 1. [MAJOR] A wide glyph straddling the overlay's clip boundary leaves half-glyph residue
- **WHERE:** `sugar-veil/src/Veil.php` clip path, via `candy-core/src/Util/Width::dropAnsi()` (`candy-core/src/Util/Width.php:714-721`), which consumes the whole straddling cluster.
- **WHAT:** The background cell under the split half is dropped rather than blanked, so half a glyph persists on screen. `DiffCellModelTest.php:27` covers wide glyphs in the diff model, not at the clip edge.
- **FIX:** Blank the straddling cell explicitly when the cluster is consumed by a clip. Confirm by composing a CJK-bearing backdrop under a known overlay and dumping `bin2hex()`.
- **USED-BY-CRUSH:** yes whenever an overlay edge lands on a wide character — CJK session titles and emoji in the transcript both qualify.

### 2. [MINOR] An overlay taller than the backdrop silently drops its own top rows
- **WHERE:** `sugar-veil/src/Position.php:39` (`yOffset()` goes negative) with the row loop starting at `fy = row - $y` in `Veil.php:520`.
- **WHAT:** The overlay's top rows — its border and title — are never painted. Latent for sugar-crush, which guards this itself (`Renderer.php:1345`, "never taller than `rows - 2`").
- **FIX:** Clamp and clip the overlay rather than skipping rows.
- **USED-BY-CRUSH:** no, guarded consumer-side.

### 3. [MINOR] `RenderSession` is shared by reference across every `with*()` clone
- **WHERE:** `sugar-veil/src/Veil.php:709`.
- **WHAT:** Two clones of one veil diff against each other's frames. Harmless for sugar-crush, which builds a fresh `Veil` per render (`Renderer.php:1951-1954`).
- **USED-BY-CRUSH:** no.

### 4. [INFO] `withBackdrop()` cannot dim SGR-styled rows — deliberate, and worth stating in the README
- **WHERE:** `sugar-veil/src/Veil.php:619` returns any ESC-leading line untouched; **LEAD-VERIFIED** and documented in-code at `:604-608` ("wrapping an escape-led line in color SGR would corrupt the payload it carries") and `README.md:84`.
- **WHAT:** An agent filed this as a probable MAJOR ("the dim the product asks for may be a near-no-op" against crush's themed, SGR-prefixed frame rows). It is not a bug — skipping escape-introducing lines is the correct guard. The residual truth is only that a backdrop dim over a fully themed frame does much less than `withBackdrop(50)` suggests, which is a documentation matter.
- **FIX:** If the dim is wanted over styled content, it needs per-cell SGR rewriting, not a line-level factor. Otherwise note the limitation next to the option.
- **USED-BY-CRUSH:** cosmetic.

# candy-fuzzy

**Index correctness is clean, and that was the main thing to fear.** The unit is code points end to
end and self-consistent: `MatchResult.php:18-24` states the contract, `CharFold.php:46-64` folds
per-code-point 1:1 by construction (İ→i+U+0307 stays one element),
`SmithWatermanMatcher.php:331-390` plus traceback `:667-695`, and `Highlighter.php:36-42,106-110` use
`mb_strlen`/`mb_substr` with explicit `'UTF-8'`. This is the fixed MAJOR-1 from the 2026-09-30 audit,
pinned by `CodePointExpansionTest.php:63-146`. Cost is bounded (caps 128/1000,
`SmithWatermanMatcher.php:60-63`; memory pinned under 8 MB at 1000×1000 by `SmithWatermanCapsTest.php:74-87`),
and `requireFullQuery` — the mode all four crush pickers use, e.g. `Chat.php:16659` — is
brute-force-verified against every in-order placement (`RequireFullQueryTest.php:198-247`). Ordering
is deterministic, so there is no reorder-under-the-fingers bug.

### 1. [MINOR] No test anywhere covers 4-byte (SMP/emoji) code points through matcher → highlighter
- **WHERE:** `candy-fuzzy/tests/` — a grep for `u{1` returns only U+1E9E (`CodePointExpansionTest.php:51,157`).
- **WHAT:** SMP characters are the one class of non-ASCII input never exercised against the path that produces the highlight offsets sugar-crush paints.
- **FIX:** Add emoji and CJK candidates to the round-trip test. Probe: match a query against a candidate containing U+1F600 and assert highlighter offsets.
- **USED-BY-CRUSH:** yes if a session title or command name contains an emoji.

### 2. [MINOR] Malformed UTF-8 is unhandled and can desync indices
- **WHERE:** `candy-fuzzy/src/Matcher/CharFold.php:54` — the `preg_match('/[\x80-\xFF]/')` fast path plus `mb_str_split`.
- **WHAT:** On invalid bytes the split and the fold can disagree, shifting every subsequent index. Session titles read off disk are the plausible source. **SUSPECTED**: confirm by matching a query against `"\xFF" . 'ab'`.
- **USED-BY-CRUSH:** only for corrupt on-disk state.

### 3. [MINOR] No test for duplicate candidates or input-order stability
- **WHERE:** `candy-fuzzy/src/MatchResultSorter.php:26-28` relies on PHP 8's stable `usort` without saying so in a comment.
- **FIX:** State the stability dependency; a sort that stops being stable silently reorders the palette.

### 4. [INFO] The haystack tiebreak compares fully-numeric strings numerically
- **WHERE:** `candy-fuzzy/src/MatchResultSorter.php:27` uses `<=>`, so `"10" <=> "9"` is numeric, not byte order. Deterministic, just not lexicographic.

# candy-focus

One-file library, and the audit read all of it. sugar-crush uses 6 of 25 public methods
(`ofStrict()` `:72`, `has()`, `focus()`, `next()`, `previous()`, `current()` at
`sugar-crush/src/Tui/Pane.php:152-162`) and rebuilds the ring fresh per call from 7 constant ids
(`:70-75`). All 25 public methods have at least one test; `FocusRingTest.php` is 969 lines / 89
methods including a white-box `assertCacheConsistent()` reflection guard (`:623`) and an
incremental-cache parity sequence (`:566`). Conventions clean.

**No BLOCKER and no MAJOR here.** The desync scenarios the brief ranked first (remove the focused
item, remove before the cursor, `reorder()`, hidden-region-holds-focus) are unreachable from
sugar-crush as wired, because the ring is rebuilt rather than mutated.

### 1. [MINOR] `focus()` accepts a disabled id, while `next()`/`previous()` refuse one
- **WHERE:** `candy-focus/src/FocusRing.php:239-247` checks only registration, never `$disabled`. No test covers `focus()` on a disabled region; `README.md:116` is silent on it.
- **WHAT:** Exactly the brief's "can a hidden region still hold focus?" — yes, by explicit `focus()`. A focused-but-hidden control means keystrokes go somewhere the user cannot see.
- **FIX:** Refuse or document; add the missing test.
- **USED-BY-CRUSH:** no (fresh ring, nothing disabled).

### 2. [MINOR] README's restore snippet indexes `[-1]` on an empty snapshot
- **WHERE:** `candy-focus/README.md:98-104` does `->focus($s['ids'][$s['index']])`; for an empty snapshot `index` is `-1`, an undefined offset. Guarded only by the prose "a non-empty snapshot", and `testJsonSnapshotRoundTripsThroughPublicApi` (`:938`) uses a non-empty ring.

**Also worth naming:** the live Tab/Shift-Tab keystroke path does **not** use this library at all.
`App::cyclePaneFocus()` (`sugar-crush/src/App/App.php:1682-1695`) hand-rolls
`(($index + $step) % $count + $count) % $count` over the dock-scoped order from `paneCycleOrder()`,
and `Pane.php:110-116` states this as an erratum. Two independent orderings (full strip vs dock
slots) pinned only by `PaneReverseCycleTest` is the real divergence risk in this area — larger than
anything inside `FocusRing`.

# sugar-diff

Small surface: `Diff`, `DiffOptions`, `LineKind`, all from
`sugar-crush/src/Tui/Settings/SettingsSavePreview.php:13-15,123-126,190-205` — the preview shown
before a settings write.

**The "preview is a lie" scenario the brief asked about is NOT reachable today**: both sides are
re-serialised with identical flags (`json()` at `:255-261` matches the writer at
`Bootstrap.php:4525`), always newline-terminated, same key order. Clean bill otherwise: LCS tie-break
and hunk-merge threshold agree with GNU at the probed boundary (gap 6 merges, both), no phantom EOF
line, empty/identical/single-line inputs correct, `with*()` immutability correct and tested, no
`::create/::make/::default`, no `get*()`, all classes `final`, no missing methods in the consumer
contract, and `UnifiedScan`'s reset/oversized-header/`--- content` handling is solid.

### 1. [MINOR] Zero-context mid-file insertion headers mis-anchor relative to GNU
- **WHERE:** `sugar-diff/src/Diff.php:406-414` (`assemble()` forces `oldStart = 0` whenever `oldLen === 0`).
- **WHAT:** For `withContextLines(0)` plus a pure insertion after line 1, the engine emits `@@ -0,0 +2,1 @@` where GNU emits `@@ -1,0 +2 @@`. An applier reading `-0,0` inserts at the wrong position. Documented as a faithful-port choice (`Hunk.php:12-17`, `README.md:71`), and `DiffTest.php:305-313` pins only zero-context *replacement*, which does agree with GNU.
- **FIX:** Match GNU's `-<lastline>,0` form for pure insertions, or restrict the documented choice to replacement and say so. Confirm with a `diff -U0` oracle run.
- **USED-BY-CRUSH:** no — preview-only consumer. Matters for sugar-stash, whose `DiffViewer::fromRawDiff()` consumes this text verbatim (`DiffTest.php:26-27`).

### 2. [INFO] `"x\n"` versus `"x"` diffs empty, so any future raw-disk preview can hide a trailing-newline change
- **WHERE:** documented rule, pinned by `DiffTest.php:168-176`.
- **WHAT:** Today crush normalises both sides so it cannot bite. A future caller that previews raw disk bytes against normalised bytes would silently omit a trailing-newline mutation from the diff it shows.
- **FIX:** Keep the invariant by asserting it at the consumer, or make the engine surface newline-only changes.

### 3. [INFO] Two diff engines coexist inside sugar-crush
- **WHERE:** sugar-crush still ships its original 509-line `BuildsUnifiedDiff` trait, used by the Edit/Write/ApplyPatch tools; only `SettingsSavePreview` uses the library.
- **FIX:** Fold the trait onto sugar-diff so the fix in #1 lands in one place.

Also untested on this side: CRLF and lone-`\r` splitting (`Diff.php:134` leaves CR embedded — same as
GNU, so not a bug), combined `ignoreWhitespace+ignoreCase`, the zero-context insertion header shape,
and no invariant test that every hunk header's counts equal its body. Memory **is** bounded — the
`maxLcsCells = 250_000` cap at `Diff.php:194` collapses the middle to delete-all/insert-all rather
than OOMing, though timing was not measured (php execution denied).

# candy-pty

Lowest-level dependency and the best-hardened one in the round. The agent's conclusion is that
sugar-crush's path is clean: EINTR retry, `FD_CLOEXEC` with abort-on-failure, a measured fd-leak fix
in `close()`, fail-closed Darwin `stty` fallbacks, a loud Windows throw, `waitpid(WNOHANG)` fast
path, non-blocking destructor reaping. The pump/wait family (`PosixPump::pump`, `MultiPump::run`,
`ChildPollTrait::wait`) carries **no internal deadline by design** (E717, documented at
`PosixPump.php:22-43`), and sugar-crush correctly bounds every loop itself — 0.05 s reads, idle plus
wall ceilings, `ProcessReaper::escalate`, group-first `terminatePid`, `$pty->close()` in `finally`.
The test gates are right too: `requirePtySyscalls()` skips **loudly** (Windows / no ffi / no pcntl /
no `/dev/ptmx`), and `HangWatchdog` + `LoopPin` are installed in bootstrap in the documented order.

Note `src/Workflows/WorkflowEngine.php` references `PosixTermios` only in a doc-comment — it is not a
runtime pty user, so the real surface is `ProcessContainment` and `CapturesProcessOutput`.

### 1. [MINOR] `PosixMasterPty::read()` with `$timeout === null` inherits whatever blocking mode was last set
- **WHERE:** `candy-pty/src/Posix/PosixMasterPty.php` — a bare `fread` whose behaviour depends on whoever last called `stream_set_blocking`.
- **WHAT:** Can block forever on a quiet child. Unreachable from sugar-crush, which always passes a timeout, but any other consumer can wedge the UI from an async callback.
- **FIX:** Force non-blocking + select when a timeout is absent, or reject `null`.
- **USED-BY-CRUSH:** no.

### 2. [INFO] No `register_shutdown_function` termios-restore net anywhere; restore is caller-owned
- **WHAT:** Fine for sugar-crush — the child gets the pty slave and the user's real tty is never raw-moded — but it is a contract gap for a consumer that raw-modes the controlling terminal. If it ever does, a missed restore leaves the user's shell broken after exit, which is the worst outcome available in this dependency set.
- **FIX:** Either document "caller owns restoration" on the API or provide the net.

### 3. [INFO] `PosixChild::kill()` signals the process leader only
- **WHERE:** `candy-pty/src/Posix/PosixChild.php`.
- **WHAT:** The `setsid`-in-shim guarantee that sugar-crush's group-kill relies on lives in sugar-crush, not in the library. Any other consumer doing a bare `kill()` leaks the group.

**Blocked, not clean:** `php tools/check-child-lifetimes.php` was never run (Bash denied). Run it, plus
`cd candy-pty && timeout 120 vendor/bin/phpunit --filter PosixMasterPtyTest` — single class only, per
the repo's own warning that pump-loop tests "can only hang, never fail".

# candy-sprinkles

`Style.php` is 1831 lines and was read in full, plus `Border`, `Border/BorderTitle`, `Table\Table`,
`Position`, `Layout`, `Bar/Segment` and the `Theme` surface. **The two things the brief ranked
hardest are genuinely clean**: immutability and sentinels (every setter routes `with()` → `new`, all
`$XSet` pairs present, all classes `final`, `::new()` only) and reset discipline (content SGR always
closed with `Ansi::reset()`; `NoTty` runs `Ansi::strip()` over the whole render — no terminal left
dirty). Contract divergence was closed by checking every `->open(`/`->close(`/`->getId(`/`->column(`
hit in sugar-crush: they are LSP/HTTP/WebSocket and sugar-bits objects, **not** Sprinkles.
`Theme::dark/light/dracula/tokyoNight/ansi` and the
`StatusBar::new()->separator()->caps()->left()->render()` / `Segment::of()` chains crush uses all
exist; crush never calls `transform`, `patch`, `inherit`, `hyperlink`, `tabWidth`, `paddingChar` or
`marginChar`.

### 1. [MINOR] `transform()` is applied after the border but before the margin, contradicting both its docblock and lipgloss
- **WHERE:** `candy-sprinkles/src/Style.php:1139-1143`; docblock says "just before its border / margin layer", lipgloss applies it last.
- **USED-BY-CRUSH:** no — crush never calls `transform()`.

### 2. [MINOR] Border titles are coloured from `borderFg` only
- **WHERE:** `candy-sprinkles/src/Style.php:1566`.
- **WHAT:** A style using per-side colours or a blended border foreground renders its titles uncoloured — `borderForegroundBlend()` writes `borderSideFg`, not `borderFg`.
- **USED-BY-CRUSH:** yes wherever a blended border carries a title; cosmetic.

---

## Disproved this round

Recorded so nobody re-files them.

- **candy-forms `Confirm` left/right inversion — FALSE.** An agent reported a MAJOR: "← meaning No
  saves Yes to config", claiming Left→true contradicts the pill layout. **LEAD-VERIFIED wrong.**
  `update()` maps Left/`h`→`true` (`candy-forms/src/Field/Confirm.php:127-133`) and `view()` renders
  `$yes . '   ' . $no` (`:162-166`) — Yes *is* the left pill. The mapping is correct and
  self-consistent. Do not spend time here. (Its real defect is only the docblock's `Tab` claim,
  filed above as candy-forms #3.)
- **sugar-veil `dimLine()` "near-no-op" — not a bug.** The ESC-skip is a deliberate, documented
  guard; downgraded to sugar-veil #4.
- **candy-layout two-solver divergence — cannot occur.** The simplex was deleted;
  `CassowarySolver::solve()` delegates wholly to `GreedySolver`.
- **candy-mouse "Scan accumulates / dead zone stays hittable" — not a bug.** `Scanner::scan()`
  *replaces* the registry (`Scanner.php:59`) and sugar-crush's `scanRoot()` clears on marker-free
  frames and on throw. Staleness is a press/release-pairing problem (candy-mouse #1), not a registry
  problem.
- **sugar-toast "no integration test" — wrong premise.** `ApplySettingsTest.php:177-199` and
  `CompactionLiveSettingsTest.php:284` both exercise it.
- **candy-core prior-audit Critical #1 (`$len_of_buf`, `InputReader.php:195`) — confirmed fixed.** Its
  items 2-10 remain pending in `findings/candy-core.md`.

## Stale source-of-truth docs

Six of fifteen agents spent budget discovering that `findings/<slug>.md` describes code that no
longer exists. This is the cheapest item in the report and it should be fixed before any repair work,
because otherwise the next pass redoes finished work:

- **candy-focus** — `findings/candy-focus.md` reviews a 373-line file and lists 10 open items
  (no `disabledIds()`, no `enabledCount()`/`disabledCount()`, no `IteratorAggregate`, no
  `JsonSerializable`, `ids()` leaking the internal array, `next()`/`previous()` duplicating 30 lines,
  O(n²) dedup). **All ten are implemented** in the current 521-line `FocusRing.php` (`:25` declares
  `Countable, IteratorAggregate, JsonSerializable`; `disabledIds()` `:439`, `enabledCount()` `:448`,
  `disabledCount()` `:454`, `ids()` returns `array_values(...)` `:508`, one shared `step()` `:321`,
  `unique()` `:99`). `plan_candy-focus.md` is still `status: not-started` with every phase PENDING.
- **candy-layout** — the old Cassowary cycling/tableau/BIG-M findings are premise-dead (above).
- **sugar-veil** — prior findings already fixed in tree: single accurate `dimLine()` docblock
  (`:593-608`), `isClickOutside()` now throws instead of returning false (`:327-331`, matching
  `README.md:239`), the `Manager` BC shim is gone, `RenderSession::release()` exists
  (`RenderSession.php:163-166`), `Fade::apply()` is a real gray-pen implementation (`Fade.php:49-75`),
  `compositeAll()`'s docblock rewritten (`VeilStack.php:101-112`).
- **candy-mouse** — Critical #1 (different-zone release leaks pending state) fixed at
  `ZoneClickTracker.php:83-86` and pinned by `ZoneClickTrackerTest.php:253`; High #3 (O(n) `hit()`)
  superseded by the grid index (`Scanner.php:116-163`); High #4 (`Scan` not reentrant) gone —
  `parse()` keeps state in locals (`Scan.php:130-140`); Low #10 — `composer.json` has no
  `repositories[]` block at all. `plan_candy-mouse.md` says `status: not-started` but the work is done.
- **candy-fuzzy** — **actively misleading**: §1.1 asks to delete the `if ($indices === [])` guard in
  `Highlighter.php:37-46`, which is now *load-bearing* after the out-of-range filter (pinned at
  `:143-150`). §2.1/plan 2.3 document a full-matrix memory limit the one-byte-traceback rework
  removed; §6.3/§7.1/§7.4/§8.1 are all implemented. Only the deprecated `ScoringProfile::default()`
  (`:73`) and `FuzzyMatcherFactory::create()` (`:59`) remain open, deliberately, pending candy-lister's
  `FuzzyMatch` migration (`CALIBER_LEARNINGS.md:79-81`).
- **candy-sprinkles** — `findings/candy-sprinkles.md` cites files that do not exist in this library.
- **sugar-toast** — `plan_sugar-toast.md` is `status: not-started` while its Phases 1-4 are
  demonstrably shipped: viewport clamping via `resolveWidth`/`capWidth` (`Toast.php:1014-1028` +
  `:500-508`), `cancelAlert`/`extendAlert`/`extendAll` (`:345-385`), nullable message
  (`Alert.php:26`, coalesced at `Toast.php:559,581`) and the `actions:` parameter (`:175,:199`).
  Findings 1, 2, 3, 8 of `findings/sugar-toast.md` are fixed; 9 and 10 verified. Its Phase 4.4 README
  item is the one still-open MINOR (sugar-toast #4).

**Also a convention non-finding, recorded so it stops being reported:** `candy-focus` and `candy-kit`
are required at `@dev` while 13 siblings are at `dev-master`. `tools/check-path-repos.php:33`
documents `@dev` as a bare alias for `dev-{default-branch}` and `$isDevConstraint` (`:320-324`) treats
it identically, so CI injects the same path-repo. Cosmetic; 7 such constraints exist repo-wide
(`candy-testing`, `sugar-dash`, `sugar-readline`, `sugar-stickers`, `docs/cookbook`).

---

# Repair priority

1. **candy-mosaic #1** — the only correctness defect verified by hand, live on every non-graphics
   terminal, and the fix is three lines plus a real byte assertion.
2. **candy-mouse #1 and #2** — positional zone ids dispatched after a re-render, and a guessable
   sentinel neutralised only by three hand-maintained consumer calls. Both concern an app whose
   clickables include permission grants. Probe #1 before fixing it.
3. **candy-core #1** — `AsyncCmd` dispatching into a torn-down runtime. Add the missing
   `ProgramRuntimeTeardownTest` case first; the absent test is why this survived.
4. **candy-shine #1** — quadratic streaming repaint. Measure, then decide between more section
   boundaries and incremental tail rendering.
5. **sugar-mcp #3** — hung-holder wedging. The library fix is a documentation change plus a default
   `toolTimeoutSeconds` on sugar-crush's forked workers; #1 (Packagist) still gates installability
   and needs org access.
6. **candy-forms #1** — `TextArea` wrap and wide-character caret, in the settings/rename editors.
7. **sugar-toast #1 and #2** — both latent for crush today but each is a one-to-ten-line fix, and
   the README currently advises the `dismiss()` call that bricks the instance.
8. **candy-kit** — nothing to fix in the library; record in the E453 row that `SafeText::line()`
   strips newlines and that adopting it reverses `Help.php:37-41`, so the item is not costed as a
   restyle any more.
9. **Stale `findings/*.md`** — retrack or delete the seven files above.
10. Everything marked MINOR/INFO, plus sugar-mcp #2 (carried, still open, small).

## Backlog

- Fold sugar-crush's parallel `McpMessage`/`McpRouter`/`McpServer` onto sugar-mcp's, and
  `LspExchangeLock` onto `ExchangeLock` (sugar-mcp #7), so hardening lands once.
- Fold sugar-crush's `BuildsUnifiedDiff` trait onto sugar-diff (sugar-diff #3).
- Wire candy-kit into `Cli/Help::screen()` (E453) — but only after the content-model question in
  candy-kit #1 is answered; the old blocker (HelpTest's anchored pins) is not the binding one.
- Audit the 7 transitive-only libraries: `candy-async`, `candy-buffer`, `candy-ansi`, `candy-input`,
  `candy-palette`, `candy-flip`, `honey-bounce`.
- Re-run this whole report with execution permitted. Nothing in it except the three LEAD-VERIFIED
  rows has been measured, and several rows explicitly say which probe would settle them.

## Coverage gaps (not reported does not mean clean)

- **Method:** no probe, phpunit or `php -l` ran in this round. `tools/check-child-lifetimes.php` and
  `tools/check-path-repos.php --unused` were both **blocked**, not passing.
- **Unaudited libraries:** the 7 transitive-only deps listed above.
- **candy-mosaic:** `ImageLayer`, `MosaicBuilder`, `DiskCache`, `AdaptiveImage`, `PrecomputedImage`,
  `Animation/AnimationDriver`, `ApngDecoder`, `Scale`, `CellSize`, `Deadline` — `ImageLayer` is on
  crush's hot path.
- **candy-core:** the agent read the sugar-crush-reachable surface only; `src/Util/Tty/*`,
  `src/Util/Proc/*`, `Util\{Clipboard,Editor,Open, Executable/Locator,LruMap}`, most of `src/Msg/*`,
  `src/Syntax/*`, `src/Cmd/*`, `src/Undo/*`, `ProgramOptions` remain unread from the previous round
  too.
- **candy-forms:** the widgets crush does not use: MultiSelect, Text, FilePicker, Date, Color,
  Slider, Note, Validator/*, plus lang/ locale parity.
- **candy-fuzzy:** `SahilmMatcher` has no caps (`:141-156`), though it is linear; not on crush's path.
- **candy-kit:** `Stage`'s spinner/golden interaction (SUSPECTED, above).
- **sugar-veil:** `Animation/AnimationKind.php` unread (15-line enum); `KeyHelpTest` not opened, so
  the existing overlay-chain coverage claim is unverified.
- **sugar-mcp:** `McpMessageTest.php` (177 lines) not opened.
- **candy-pty:** `src/Output/{AnsiOutputParser,SgrHandler,SgrState}.php`,
  `src/Input/PtyInputDecoder.php`, most of `src/Exception/*` and `src/Contract/*`.
