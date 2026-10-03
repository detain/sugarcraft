# SugarCraft libraries used by sugar-crush — audit report

**State as of 2026-10-03, master `f5bf7dc4a`.** This report covers the 13 SugarCraft libraries that
sugar-crush depends on at runtime. It lists only findings that are still open on current master.
Every finding from the original audit was re-checked against the source, with `grep` and throwaway
`php -r` probes. Findings fixed by the repair round (`f96765c56..f5bf7dc4a`) have been deleted, and
so have findings that turned out to be wrong.

**Open:** 2 findings, both in sugar-mcp. The other 12 libraries have no open findings.

## Which libraries sugar-crush uses

sugar-crush lists 13 `sugarcraft/*` packages under `require` and none under `require-dev`. 12 of them
are referenced from `src/`. `candy-kit` is declared but not referenced yet: its wiring is deliberately
deferred (see the candy-kit line below). Reference counts below are matches of the
`SugarCraft\<Ns>\…` FQN prefix in `sugar-crush/src` + `bin`.

| Library | Declared | Refs | What crush pulls |
|---|---|---:|---|
| candy-core | `require` | 162 | `Msg` (30), `Util\Width` (20), `Util\Color`, `Program`, `Subscriptions`, `Util\Sanitize`, `View`, `Util\AtomicJsonFile`, `Util\Ansi`, `Msg\KeyMsg`, `KeyType` |
| candy-sprinkles | `require` | 38 | `Style` (18), `Border` (13), `Theme`, `Table\Table`, `Bar\StatusBar`, `Bar\Segment`, `Layout`, `Position` |
| candy-mouse | `require` | 28 | `Mark` (9), `Scan`, `ZoneClickTracker`, `Sentinel`, `Zone`, `Scanner`, `MouseEvent`, `Selection` + `SelectionRange` (`Tui\TextSelection` is now an adapter over them) |
| sugar-mcp | `require` | 24 | `StdioMcpServer` (14), `RequestIdSequence`, `ExchangeLock`, `ArgumentShape`, `McpTool` |
| candy-layout | `require` | 12 | `Dock\DockLayout` (5), `Dock\Side`, `Region` |
| candy-shine | `require` | 10 | `Renderer`, `Theme`, `SyntaxHighlighter`, `Render\SectionStream` |
| candy-mosaic | `require` | 8 | `Mosaic`, `ImageSource`, `ImageLayer` |
| candy-fuzzy | `require` | 8 | `MatchResult`, `Matcher\SmithWatermanMatcher`, `Highlighter`, `Matcher\CharFold` |
| candy-forms | `require` | 6 | `ItemList\{ItemList,Item,LoadMoreMsg}`, `TextArea\TextArea` |
| sugar-veil | `require` | 5 | `Veil` (3), `Position` (2) |
| candy-pty | `require` | 5 | `Pty::open()` (`Tools/Concerns/CapturesProcessOutput.php:398`), `Posix\{PosixTermios,SttyTermios}` |
| candy-focus | `require` (`@dev`) | 1 | `FocusRing` |
| candy-kit | `require` (`@dev`) | 0 | **Nothing yet**: the restyle of `Cli\Help::screen()` is deferred (E453) |

## Severity index

| Library | Worst open | Open |
|---|---|---:|
| sugar-mcp | HIGH | 2 |
| candy-core | — | 0 |
| candy-sprinkles | — | 0 |
| candy-mouse | — | 0 |
| candy-layout | — | 0 |
| candy-shine | — | 0 |
| candy-mosaic | — | 0 |
| candy-fuzzy | — | 0 |
| candy-forms | — | 0 |
| sugar-veil | — | 0 |
| candy-pty | — | 0 |
| candy-focus | — | 0 |
| candy-kit | — | 0 |

## Cross-library patterns

One pattern is still live: **a parallel copy drifting from its twin.** sugar-crush keeps its own
`McpMessage`, `MCP\McpRouter` and `MCP\McpServer` next to the ones sugar-mcp ships. Hardening has
already been applied to crush's copy and not to the library's (sugar-mcp #2). The stdio transport
crush actually runs goes through the library's `McpMessage`, so the unhardened copy is the one
that matters there.

---

# candy-core

No open findings.

# candy-sprinkles

No open findings.

# candy-mouse

No open findings.

# candy-layout

No open findings.

# sugar-mcp

### 1. [HIGH] `sugarcraft/sugar-mcp` is not on Packagist, so the published sugar-crush cannot be installed
- **WHERE:** `sugar-crush/composer.json:49` (`"sugarcraft/sugar-mcp": "dev-master"`). Also `php tools/check-path-repos.php`, which exits 1 with `sugar-crush: missing path-repo for sugar-mcp (required transitively via sugar-crush -> sugar-mcp)`.
- **WHAT:** `https://repo.packagist.org/p2/sugarcraft/sugar-mcp~dev.json` returns 404. `sugarcraft/sugar-crush` dev-master *is* on Packagist (200), and its published `require` lists `sugarcraft/sugar-mcp: dev-master`. A `composer require sugarcraft/sugar-crush` therefore cannot resolve. The split repo is already there: `github.com/sugarcraft/sugar-mcp` has a `master` head, pushed by `sync-sugarcraft.yml`. The one missing step is registering it on Packagist. The plain closure gate stays red until that is done; inside the monorepo the root path-repo hides the problem.
- **FIX:** Register `sugarcraft/sugar-mcp` on Packagist. After that the gate passes without any manifest change. Optionally, add a `sugar-mcp` row to the `DESCRIPTIONS` map in `scripts/bootstrap-org-repos.sh`, which currently lists 26 libs and leaves sugar-mcp out. Neither step can be done from a working tree: both need org/Packagist access.
- **USED-BY-CRUSH:** yes. It decides whether a Packagist install of sugar-crush resolves at all.

### 2. [LOW] `McpMessage::errorCode()`/`errorMessage()` make up values from malformed wire errors; crush's own copy was fixed, the library's was not
- **WHERE:** `sugar-mcp/src/McpMessage.php:283` (`(int) $this->error['code']`) and `:292` (`(string) $this->error['message']`). Read by `describeError()` at `sugar-mcp/src/StdioMcpServer.php:1159-1172`. The hardened twin is at `sugar-crush/src/McpMessage.php:308` and `:326`.
- **WHAT:** A JSON-RPC error's `code` must be an integer and its `message` a string, but this is third-party wire data. Probes on the library: `{"code":"abc"}` comes back as `errorCode() === 0`, and `{"code":true}` as `1`, so the refusal reports a code the server never sent. `{"message":{"x":1}}` raises `Warning: Array to string conversion` and the refusal text becomes `"Array"`; `{"message":42}` becomes `"42"`. sugar-crush made exactly this fix in its own copy (`f5bf7dc4a`, `0e31d2212`: return null for a non-int code or a non-string message). But crush's stdio MCP path (`sugar-crush/src/MCP/StdioMcpServer.php:71,118`) wraps the *library's* `StdioMcpServer`, and that formats initialize and tools/list refusals through the library's `McpMessage`. So crush still reports made-up codes, and under an error handler that turns warnings into exceptions it throws `ErrorException` from `start()`.
- **FIX:** Port crush's `is_int($code) ? $code : null` and `is_string($message) ? $message : null` into the library's `errorCode()`/`errorMessage()`, with a regression test for each malformed shape. Longer term, have crush use the library's `McpMessage`/`McpRouter`/`McpServer` instead of keeping parallel copies (`sugar-crush/src/McpMessage.php`, `src/MCP/McpRouter.php`, `src/MCP/McpServer.php`), so a fix lands in one place.
- **USED-BY-CRUSH:** yes. Every stdio MCP server's handshake refusal is formatted by the library copy.

# candy-shine

No open findings.

# candy-mosaic

No open findings.

# candy-fuzzy

No open findings.

# candy-forms

No open findings.

# sugar-veil

No open findings.

# candy-pty

No open findings.

# candy-focus

No open findings.

# candy-kit

No open findings. sugar-crush still references candy-kit nowhere, and the E453 restyle of
`Cli\Help::screen()` is still deferred. Its blocker is no longer colour handling, which
`Theme::detect()` now takes care of. What remains is `tests/Cli/HelpTest.php`: four line-start-anchored
regex pins (`:162`, `:202`, `:281`, `:437`) that any SGR prefix breaks, and the `strpos` section
anchors at `:466` and `:470`.

---

# Repair priority

1. **sugar-mcp #1:** register `sugarcraft/sugar-mcp` on Packagist. This needs org access; until it
   is done the published sugar-crush cannot be installed.
2. **sugar-mcp #2:** port crush's `errorCode()`/`errorMessage()` type checks into the library's `McpMessage`.

## Backlog

- Fold sugar-crush's parallel `McpMessage`/`McpRouter`/`McpServer` onto sugar-mcp's (sugar-mcp #2,
  longer-term fix).
- Wire candy-kit into `Cli\Help::screen()` (E453) once HelpTest's anchored pins are reworked.

## Coverage gaps (not reported does not mean clean)

These areas were not read by the original audit or by this re-check:

- **candy-core:** `src/Util/Tty/*`, `src/Util/Proc/BoundedShutdown.php`, `Util\{Clipboard,Editor,Open,
  Executable/Locator,LruMap}`, most of `src/Msg/*`, `src/Syntax/*`, `src/Cmd/*`, `src/Undo/*`,
  `ProgramOptions`.
- **candy-forms:** the widgets crush does not use: MultiSelect, Text, FilePicker, Date, Color,
  Slider, Note, Validator/*, Vim/*, plus lang/ locale parity.
- **candy-pty:** `src/Output/{AnsiOutputParser,SgrHandler,SgrState}.php`,
  `src/Input/PtyInputDecoder.php`, most of `src/Exception/*` and `src/Contract/*`.
