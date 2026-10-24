# SugarCraft glossary

Every term the root [README](../README.md) and the library docs assume you
already know, defined in two or three lines each with a pointer to the code
that makes it true. This page is fleet-wide; [candy-code's own
glossary](../candy-code/docs/GLOSSARY.md) covers the agent-app vocabulary
(ACP, refusal kinds, presets) separately.

## How a terminal app draws

| Term | Meaning |
|---|---|
| **TUI** | Terminal User Interface — an interactive app that runs in a text terminal, redrawing the whole screen (or parts of it) instead of scrolling output. |
| **ANSI** | The family of escape-sequence conventions a terminal understands — bytes starting `ESC [` / `ESC ]` that move the cursor, set colors, toggle modes. Everything a TUI "draws" is ANSI text. |
| **ECMA-48** | The 1976 ISO-parented standard that defines the control sequences ANSI terminals follow (C0/C1 controls, CSI structure). SugarCraft's parser models it in [`sugar-ansi-parser`](../sugar-ansi-parser/). |
| **VT500** | Digital Equipment's late terminal-model spec — the de-facto reference register of CSI/DEC Private modes that modern terminals emulate. `sugar-ansi-parser`'s state machine targets it. |
| **CSI** | Control Sequence Introducer — `ESC [` — the prefix of most commands (cursor movement, SGR, mode set/reset). |
| **SGR** | Select Graphic Rendition — the CSI `m` command: `ESC[1m` bold, `ESC[38;2;R;G;Bm` truecolor. Style rendering is a stream of SGR parameters; defined and emitted by [`sugar-style/src/Style.php`](../sugar-style/src/Style.php). |
| **OSC** | Operating System Command — `ESC ] … ESC \` (or BEL) — out-of-band messages: window titles, hyperlinks, iTerm2 image chunks. |
| **OSC 8** | The hyperlink extension: `ESC]8;;URL ESC\` text `ESC]8;; ESC\`. A click target without a mouse protocol; emitted by [`SugarCraft\Core\Util\Ansi::hyperlink()`](../sugar-core/src/Util/Ansi.php) and consumed by `sugar-markdown`. |
| **alternate screen** | The second full-screen buffer many terminals keep (`ESC[?1049h`) — enter it and the user's scrollback survives you. `sugar-core`'s `Program` owns this lifecycle. |
| **raw mode** | TTY setting that turns off line buffering and echo, so the app reads each keystroke immediately. Set up by `sugar-core`'s input stack; required for arrow keys to arrive as bytes at all. |
| **PTY** | Pseudo-Terminal — the kernel master/slave pair that gives a child process a fake tty. Used to run programs under a terminal (`sugar-pty`, candy-code's PtyRun tool). |
| **cell-diff** | The renderer keeps the previous frame as a grid of cells and writes only the characters that changed. `sugar-cellbuffer`'s `Buffer::diff()` is the shared engine. |
| **"cursed" cell-diff mode** | sugar-core's opt-in raw-line diffing mode — the historic lipgloss term for byte-level (not cell-level) frame differencing: cheaper, but only correct when every row is rewritten exactly. See the sugar-core README row ("opt-in cursed cell-diff mode"). |
| **rune** | One user-perceived character in one grid cell (may be several bytes / codepoints: an emoji, a combining sequence). [`sugar-cellbuffer/src/Cell.php`](../sugar-cellbuffer/src/Cell.php) stores `rune`/style/link/width. |
| **braille chart** | An 8-dot Braille cell (`⣀`-shaped glyphs, U+2800 block) used as a 2×4-pixel plotting cell — line graphs in text. Produced by [`sugar-charts/src/Sparkline/Sparkline.php`](../sugar-charts/src/Sparkline/Sparkline.php) and the charts [`Canvas`](../sugar-charts/src/Canvas/Canvas.php)/[`BrailleGrid`](../sugar-charts/src/Canvas/BrailleGrid.php); candy-top's CPU graphs are these, hence "braille graphs, btop-compatible". |
| **luminance ramp** | Grayscale block glyphs (`░▒▓█`) chosen by pixel brightness — the fallback way to show an image or GIF where truecolor or graphics protocols are unavailable (`sugar-animation`, `candy-gifview`, sugar-image's ANSI tier). |
| **half block** | The `▄`/`▀` glyphs; sugar-image's always-available ANSI tier paints one image row per two such half-cells. |

## Keyboard and mouse protocols

| Term | Meaning |
|---|---|
| **Kitty progressive keyboard protocol** | A graphics-protocol-era keyboard extension (`CSI >1u` flags) that reports every key + modifier combination unambiguously, and lets apps "disambiguate" chords. Decoded by [`sugar-input`](../sugar-input/); negotiated on startup by apps such as candy-code and candy-files. |
| **xterm modifyOtherKeys** | xterm's older alternative for reporting chords as `CSI 27;modifier;keysym ~`. Also decoded by `sugar-input`. |
| **X10 mouse** | The 1984 DEC VT52-era mouse report (`ESC [ M` + 3 bytes) — button press only, coordinates capped to 223 columns. `sugar-input`'s mouse decoders read it. |
| **urxvt mouse** | The rxvt-unicode extension adding drag/motion reports to the X10 encoding. |
| **SGR mouse** | The modern encoding (`CSI < button;x;y M/m`) — arbitrary coordinates, press and release distinguishable; what candy-top enables (`?1002h` + `?1006h`). |
| **marker / zone / sentinel** | The click plumbing trio: a **marker** is the pair of invisible codepoints wrapped around rendered content, a **zone** is the rectangle the renderer discovers from those markers when a frame is scanned, and the **sentinels** are the codepoints themselves — U+E000 (open) and U+E001 (close), constants `Sanitize::ZONE_SENTINEL_OPEN/CLOSE` in [`sugar-core/src/Util/Sanitize.php`](../sugar-core/src/Util/Sanitize.php). The marker builder is [`SugarCraft\Mouse\Mark::zone()`](../sugar-mouse/src/Mark.php). |
| **PUA** | Private Use Areas — Unicode blocks reserved for app-defined glyphs. SugarCraft reserves U+E000–U+F8FF: the two zone sentinels plus image-overlay markers live there, which is why untrusted text is swept of them (see **untrusted** below and [docs/TRUST-BOUNDARY.md](TRUST-BOUNDARY.md)). |

## Runtime vocabulary

SugarCraft's interactive stack is an MVC-style Model–Update–View runtime (the
architecture pioneered by The Elm Architecture and ported to Go as Bubble
Tea). The words, per [`sugar-core`](../sugar-core/src/Model.php):

| Term | Meaning |
|---|---|
| **Model** | An immutable value object holding your app's whole state, implementing `SugarCraft\Core\Model` — `init()`, `update()`, `view()`, `subscriptions()`. |
| **Msg** | Anything that happened: a key press, a tick, an async result. A `SugarCraft\Core\Msg` value handed to `update()`. |
| **Update** | `Model::update(Msg): [Model, ?Cmd]` — the single place state changes; returns the next model plus an optional effect. |
| **View** | `Model::view(): string|View` — renders the model to SGR text each frame; pure, no side effects. |
| **Cmd** | A deferred effect — a `Closure` returned by `update()` that the runtime runs and feeds its resulting Msg back in ([`sugar-core/src/Cmd.php`](../sugar-core/src/Cmd.php)). |
| **Subscriptions** | The model's declared live sources (timers, streams, key-repeat…) that `update()` never touches; the runtime reconciles them after each cycle ([`sugar-core/src/Subscriptions.php`](../sugar-core/src/Subscriptions.php)). |
| **Program** | The harness that owns the event loop, raw mode, alt-screen, and the Update→View tick ([`sugar-core/src/Program.php`](../sugar-core/src/Program.php)). Your app is just a Model. |
| **tick** | One loop iteration's repaint decision; frame-rate is a `ProgramOptions` setting. |
| **frame** | Two senses — (1) TEA: one complete `view()` output painted to screen; (2) candy-vcr/VHS: one recorded (or rendered) image in a session tape/GIF. Each lib names its sense locally. |

## Styling and color

| Term | Meaning |
|---|---|
| **Style** | An immutable set-and-render description — bold, colors, underline — as a `SugarCraft\Style\Style` value object ([`sugar-style/src/Style.php`](../sugar-style/src/Style.php)); its setters keep lipgloss's bare names (`bold()`, `fg()`) and still return a new instance via a private `with()` — sugar-style is the documented naming exception, so the `with*()` spelling belongs to the other value-object families. Never "the Sprinkles Style" — that package no longer exists. |
| **theme** | A *named, loadable collection* of style choices — a file on disk you select (candy-top ships btop-compatible theme files; sugar-style's [`Theme.php`](../sugar-style/src/Theme.php) is the programmatic kind: `Theme::ansi()`). |
| **palette** | The *in-memory set* of colors resolved for one render — [`sugar-style/src/Palette.php`](../sugar-style/src/Palette.php) (and `sugar-colorprofile`'s tiered palettes). A theme selects a palette; the palette is what styles index into. |
| **ColorProfile** | The terminal's color *depth* — Ansi16/Ansi256/TrueColor — detected once at startup from TERM/`COLORTERM`/`NO_COLOR`/CI env by [`sugar-core`'s `Util\ColorProfile::detect()`](../sugar-core/src/Util/ColorProfile.php) and dispatched as a [`ColorProfileMsg`](../sugar-core/src/Msg/ColorProfileMsg.php); apps may then pick different palettes per tier. |
| **NO_COLOR** | The community convention: set `NO_COLOR` to any non-empty value and programs drop color. Honored fleet-wide via core detection plus per-app config; see [KEYMAPS.md](KEYMAPS.md#color-and-reduced-motion-env) for the app-by-app caveat. |

## Layout words: pane vs panel vs box

| Term | Meaning |
|---|---|
| **pane** | A dockable, *switchable* region of a multi-view app window — candy-code's are the `Pane` enum cases (chat, files, agents…) in [`candy-code/src/Tui/Pane.php`](../candy-code/src/Tui/Pane.php); one active pane at a time, draggable dividers. |
| **panel** | A self-contained bordered widget rendered *inside* a screen — sugar-panels' box-drawing tree ([`sugar-panels/src/SugarPanels.php`](../sugar-panels/src/SugarPanels.php)): each panel has a border and title, they tile, you never "switch to" them. |
| **box** | The bordered rectangle a panel draws; also the unit of candy-top's screen (CPU box, mem box). |

## Recording words: cassette vs tape

| Term | Meaning |
|---|---|
| **cassette** | A *recorded interactive session* — the JSONL event tape candy-vcr writes and replays to drive tests and demos deterministically ([`candy-vcr/src/Cassette.php`](../candy-vcr/src/Cassette.php)). |
| **tape** (VHS) | A *declarative GIF script* — the `.vhs/*.tape` file that says "type this command, sleep, screenshot" so CI renders the demo GIF ([AGENTS.md convention](../AGENTS.md), workflow [`vhs.yml`](../.github/workflows/vhs.yml)). Named after the `charmbracelet/vhs` tool it mirrors. |

## Trust words

| Term | Meaning |
|---|---|
| **trusted** | Data your program authored — your Model's own strings, config you wrote. It may contain markers and SGR freely. |
| **untrusted** | Anything from outside: tool output, file contents, network, prompt injections possible. Must pass through [`Sanitize::untrusted()` / `untrustedForMarkedFrames()`](../sugar-core/src/Util/Sanitize.php) before reaching a presentation sink, which strips forged sentinels and escape payloads. The full fleet contract is [docs/TRUST-BOUNDARY.md](TRUST-BOUNDARY.md). |

## Port and package vocabulary

| Term | Meaning |
|---|---|
| **upstream / antecedent** | The original project a SugarCraft package models. "**Port of** charmbracelet/x" or "**Design antecedent:** treilik/bubbleboxer" means the API and semantics follow that project deliberately (see the `Mirrors …` docblock law in [AGENTS.md](../AGENTS.md)); a bare "inspired by" would overstate nothing but also promise nothing — the docs use port/antecedent consistently, and README phrases like "bubblezone-style" name a specific antecedent project the same way. |
| **façade** | A package that re-exports another's classes via `class_alias` (e.g. `sugar-widgets` → sugar-forms symbols). Ships only an alias smoke-test, never a copied suite ([AGENTS.md](../AGENTS.md) façade law). |
| **path repository** | A composer `repositories[]` entry pointing at a sibling checkout. Never committed to a published lib manifest (it is a fatal for split-repo clones); CI injects the closure at build time — [tools/check-path-repos.php](../tools/check-path-repos.php). |
| **phar** | PHP Archive — a single self-contained executable file (`php candy-top.phar`), built and signed for `candy-code/v*` and `candy-top/v*` tags ([SECURITY.md](../SECURITY.md)). |

## Legacy campaign IDs

You will occasionally see labels like **F-18** (README's candy-image row),
**E-nnn**, **ISS-nnn**, **wave-N**, or **tranche-N** inside prose. These are
**internal audit/campaign finding IDs** — each was the handle for one line of
a dated review ledger (F-nn: the chafa study findings; E-nnn: the candy-code
hardening backlog; ISS-nnn: the tracked issue list). They are history, not
product vocabulary: nothing in the API depends on them, and the ledgers that
resolve them are not shipped with the packages. **Read the sentence around
one and ignore the ID.** New docs and code are written without them.
