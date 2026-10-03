# SugarCraft libraries used by sugar-crush — audit report

**Date:** 2026-10-03 · **Method:** one read-only reviewer agent per library. Each agent ran the
library's own PHPUnit suite once, the repo CI gates (`tools/check-one-type-per-file.php`,
`tools/check-child-lifetimes.php`, path-repo checks where relevant), read `findings/<slug>.md` +
`plan_findings/<slug>.md` to separate NEW findings from already-tracked ones, and probed live
defects with throwaway `php -r` scripts. Nothing was written to any library's tree.

**Every suite is green.** None of the defects below are caught by tests.

## Which libraries sugar-crush uses

sugar-crush declares 12 `sugarcraft/*` runtime deps and actually imports 12 namespaces.
`candy-pty` is used from production `src/` but sits in `require-dev` only; `candy-kit` is
declared with zero references (deliberately deferred — see its section).

| Library | Declared | Import refs in `sugar-crush/` | What crush pulls |
|---|---|---:|---|
| candy-core | `require` | 165 | `Msg`, `Util\Width` (~20 sites), `Program`, `Util\Color`, `Subscriptions`, `Util\Sanitize`, `View`, `Util\AtomicJsonFile`, `Util\Ansi`, `KeyMsg`, `KeyType` |
| candy-sprinkles | `require` | 38 | `Style` (18), `Border` (13), `Theme`, `Table\Table`, `Bar\StatusBar` |
| candy-mouse | `require` | 23 | `Mark` (8), `ZoneClickTracker`, `Sentinel`, `Scan`, `Zone`, `Scanner`, `MouseEvent` |
| candy-layout | `require` | 12 | `Dock\DockLayout` (5), `Dock\Side`, `Region` |
| candy-shine | `require` | 10 | `Renderer`, `Theme`, `SyntaxHighlighter`, `Render\SectionStream` |
| candy-mosaic | `require` | 8 | `Mosaic`, `ImageSource`, `ImageLayer` |
| candy-fuzzy | `require` | 7 | `MatchResult`, `SmithWatermanMatcher`, `Highlighter` |
| candy-forms | `require` | 6 | `ItemList\{ItemList,Item,LoadMoreMsg}`, `TextArea\TextArea` |
| sugar-veil | `require` | 5 | `Veil` (3), `Position` (2) |
| sugar-mcp | `require` | 23 | `StdioMcpServer` (14), `RequestIdSequence`, `ExchangeLock`, `ArgumentShape`, `McpTool` |
| candy-focus | `require` (`@dev`) | 1 | `FocusRing` (via `Tui/Pane.php:7`) |
| candy-kit | `require` (`@dev`) | 0 | **Nothing yet** — deferred restyle of `Cli\Help::screen()` (E453) |
| candy-pty | `require-dev` only | 5 | `Pty::open()` (`CapturesProcessOutput.php:398`), `Posix\{PosixTermios,SttyTermios}` |

## Severity index

| Library | Worst | Issues | Suite |
|---|---|---:|---|
| candy-pty | CRITICAL | 11 | 670 tests, OK (14 skips, not FFI-gated) |
| candy-mosaic | CRITICAL | 9 | 621 tests, OK (5 platform skips) |
| candy-core | HIGH | 6 | 1169 tests, OK (25 skips) |
| candy-sprinkles | HIGH | 12 | 762 tests, OK |
| candy-mouse | HIGH | 8 | 152 tests, OK |
| candy-layout | HIGH | 8 | 265 tests, OK |
| candy-shine | HIGH | 6 | 777 tests, OK |
| sugar-mcp | HIGH | 9 | 141 tests, OK |
| candy-forms | HIGH | 6 | 2201 tests, OK |
| sugar-veil | HIGH | 9 | 217 tests, OK |
| candy-fuzzy | MEDIUM | 7 | 846 tests, OK |
| candy-focus | HIGH | 7 | 79 tests, OK |
| candy-kit | HIGH | 7 | 150 tests, OK |

## Cross-library patterns

Four failure shapes repeat across libraries; fixing the shape beats fixing the instance.

1. **A copy that drifted from its canonical source.** candy-mosaic's hand-rolled clone of flip's
   GIF walk (CRITICAL), candy-pty's deprecated `Pty` facade vs `PosixPtySystem` (CRITICAL), and
   `LspExchangeLock` copying `ExchangeLock::create()`. In both criticals the *canonical* path
   already has the correct code and even documents why.
2. **The `with*()`/sentinel idiom leaking state.** sprinkles' `patch()` and `unsetBorder()` leave
   `propsSet` entries; veil's `mutate()` drops the scanner. This is the repo's canonical
   immutability pattern — `Style.php` is cited in the playbook as the exemplar everything copies —
   so defects here propagate by imitation.
3. **A docblock or interface promising something the code doesn't do.** forms' `isHidden()`,
   layout's "no floating-point drift", kit's width and rule() minimums, mouse's downstream
   contract in crush's own comments, fuzzy's cap note omitting the semantic flip. Tests stay
   green because they assert the predicate, not the effect.
4. **Silent swallowing where the project demands a throw.** core's `Width::wrap` returning `''`,
   mcp's `toJson()` returning `''`, mouse's orphan sentinels, veil's empty background, layout's
   un-warned overflow, pty's swallowed close rc.

---

# candy-core

**MODE:** vendor symlinked (`vendor/sugarcraft/{candy-ansi,candy-input,candy-pty}` are symlinks
into the monorepo — suite results reflect local wiring, not Packagist copies)
**TESTS:** `OK, but some tests were skipped! Tests: 1169, Assertions: 9773, Skipped: 25.`
(Time 00:20.101, Memory 62.00 MB; the `RejectedPromise` backtrace printed mid-run is
SemaphoreTest's deliberate unhandled-rejection path, not a failure)
**VERDICT:** The runtime is healthy and its previously-audited defects are genuinely fixed — the
live issues are one unreachable-promise leak in WorkerPool, one silent data-loss path in Width,
and a set of teardown gaps (timers, signal handlers, paste buffer) that only bite a REPL staying
up for hours.

### 1. [HIGH] `WorkerPool::sendToWorker()` catches the wrong throwable — a closure task leaks a never-settling promise
- **WHERE:** `src/WorkerPool.php:279`
- **WHAT:** `catch (\Error $e)` guards `serialize($payload)`, but `serialize()` of a `Closure` throws `\Exception` ("Serialization of 'Closure' is not allowed"), verified on this PHP 8.3.6. So `dispatch(fn() => …)` throws out of `dispatch()` synchronously after `$this->pending[$jobId]` was already populated (WorkerPool.php:99-101): the caller gets an exception instead of a promise, and the `Deferred` stays in `$this->pending` forever — the class docblock's own "fail loud, never hang" contract (E716) is broken on its most-documented misuse path.
- **FIX:** Widen the catch to `\Throwable` (or pre-check `!$task instanceof \Closure` and reject via `handleWorkerDeath`), so the job's Deferred is rejected rather than orphaned.
- **USED-BY-CRUSH:** no — sugar-crush's `WorkerPool` hits (`src/Cli/Bootstrap.php:1385,1751`) are its own `SugarCraft\Crush\Agents\AgentWorkerPool`; `Core\WorkerPool` itself has no crush call site.
- **STATUS:** NEW

### 2. [HIGH] `Width::wrap()` silently returns `''` for any invalid-UTF-8 input
- **WHERE:** `src/Util/Width.php:447` (via `wrap()` at :325)
- **WHAT:** `preg_split('/(\s+)/u', …) ?: []` — the `/u` modifier fails on malformed bytes, and `?: []` converts that failure into "this paragraph has no words", so the text is dropped. Measured: `Width::wrap("\xff\xfe garbage here", 20) === ''` and `Width::wrap("hello \xff world", 20) === ''`. This is the exact fail-open shape the sibling `Sanitize` class documents against (a failed `/u` regex must never hand back or swallow hostile/valuable bytes); the project convention is throw, not silence. `Width::truncate()` on the same input returns `aaab` (byte path), so the two disagree.
- **FIX:** In `wrapParagraph()`, on `preg_split()` returning `false`, fall back to a byte-level split (`preg_split('/(\s+)/')` without `/u`) or throw — never `?: []`.
- **USED-BY-CRUSH:** partially — sugar-crush calls `Width::wrapAnsi()` (`sugar-crush/src/Renderer.php:3272`), whose byte scanner does not use `preg` and is unaffected; no confirmed crush call to `Width::wrap()` itself, but the class is crush's 2nd-most-referenced core symbol (20 refs).
- **STATUS:** NEW

### 3. [MEDIUM] `Program` tears down the periodic render timer and subscriptions but not its one-shot timers, and never restores the signal handlers it replaced
- **WHERE:** `src/Program.php:613` (`TickRequest` arm), `:1008` (escape-flush arm), `:434` (only `$tickTimer` cancelled), `:1320-1362` (`installSignalHandlers`)
- **WHAT:** Two ways a long-lived process outlives its `Program`. (a) `run()`'s epilogue cancels `$tickTimer` and calls `cancelAllSubscriptions()`, but a one-shot `addTimer` armed from a `TickRequest` is untracked, so a tick due after `QuitMsg` fires into `dispatch()` → `view()`/`writeOutput()` on a terminal already restored to cooked mode. (b) `installSignalHandlers()` calls `pcntl_async_signals(true)` and overwrites the process-wide SIGINT/SIGWINCH/SIGTSTP/SIGCONT handlers with closures bound to `$this`, with no counterpart restore — after `run()` returns, those closures keep the whole `Program` graph reachable and a second `Program` in the same process inherits the first one's handlers. Signal-subscription handlers do get restored (`cancelSignalSubscription()`, :1506), which shows the teardown was intended here too.
- **FIX:** Track one-shot timers in a property and cancel them in the `run()` epilogue, and save/restore the four `pcntl_signal` handlers (the `prevSignalHandlers` pattern already built for subscriptions) on teardown.
- **USED-BY-CRUSH:** yes — sugar-crush holds `Core\Program` at 10 sites and is the multi-hour REPL the leak shape describes.
- **STATUS:** NEW

### 4. [MEDIUM] `Renderer::tokenByteLength()` charges a BEL-terminated string token two bytes, desyncing cell-diff repaints
- **WHERE:** `src/Renderer.php:343` (`default => 2 + strlen($t->data) + 2`)
- **WHAT:** The `default` arm (OSC/DCS/APC/SOS/PM) assumes an ST terminator, but `Parser::consumeStTerminated()` (src/Util/Parser.php:~250) accepts BEL and consumes **one** byte there — confirmed by measurement (`"\x1b]0;title\x07hello"` yields an OSC token whose consumed span is 10 bytes, not 11). In cell-diff mode `repaintLine()` sums these lengths into `$byteOffset`, so a row containing a BEL-terminated OSC makes the repaint `substr($curr, $byteOffset)` one byte early and the `cursorTo()` column wrong, painting a shifted tail.
- **FIX:** Have `Token` carry its consumed byte length (or count the terminator: `strlen($t->data) + 3` for ST, `+ 2` for BEL) instead of assuming ST in `tokenByteLength()`.
- **USED-BY-CRUSH:** indirectly — crush renders through `Core\Renderer`; only reachable with `cellDiff` enabled.
- **STATUS:** NEW

### 5. [MEDIUM] `InputReader` paste buffer grows unbounded on a paste envelope that never closes
- **WHERE:** `src/InputReader.php:81-88` (`$this->pasteBuf .= substr($this->buf, $i)` with no ceiling)
- **WHAT:** A stream that emits `ESC[200~` without a matching `ESC[201~` (a terminal that drops the tail, or hostile input) parks the reader in `$this->pasting` permanently; every subsequent byte is appended to `pasteBuf` and never surfaced as a `Msg`, so memory climbs for the life of the process and the keyboard goes dead — both failure modes the brief ranks highest.
- **FIX:** Cap `pasteBuf` (e.g. a `MAX_PASTE_BYTES` constant) and on overflow flush what was collected as a `PasteMsg` and clear `$this->pasting`.
- **USED-BY-CRUSH:** yes — crush enables bracketed paste and consumes `PasteMsg`.
- **STATUS:** NEW

### 6. [LOW] `Program::getModel()` — `get*` accessor against the bare-accessor rule
- **WHERE:** `src/Program.php:208`
- **WHAT:** Violates the project's "bare accessors (no `get`)" convention; it is explicitly `@deprecated` with `model()` (:200) as the replacement and a documented candy-testing back-compat reason, so this is a tracked transitional alias, not a live defect.
- **FIX:** Remove once `candy-testing`'s `ProgramSimulator` is migrated to `model()`.
- **USED-BY-CRUSH:** no (candy-testing only).
- **STATUS:** NEW (style nit; intentionally staged)

### Checked and clean
Every `src/*.php` carries `declare(strict_types=1)`; `tools/check-one-type-per-file.php` passes
for this lib; no `::create()`/`::make()`/`::default()` factories; no TODO/FIXME/XXX/HACK markers
(the only `XXX` hits are `<U+XXXX>` placeholder text in `Sanitize` docblocks); no commented-out
code. All four `findings/plan_candy-core.md` action items are already fixed in the tree — that
file's `status: not-started` header is stale and should be flipped.

### Unaudited (so "not reported" isn't read as "clean")
`src/Util/Tty/*` (PosixBackend, WindowsBackend, Kernel32, Backend, EnvDetect, InterruptFlags),
`src/Util/Proc/BoundedShutdown.php`, `src/Util/{LruMap,Clipboard/Clipboard,Editor,Open,Executable/Locator,TtyDetect,ColorProfile,ColorUtil,Palettes,Clamp,Json,NullLogger,RawMode,Validation}.php`,
`src/I18n/*`, `src/Lang.php`, `src/Syntax/*`, `src/{ProgramOptions,ProgramOptions/ProgramOptionsBuilder,Pane,Panes,ScreenStackCapable}.php`,
`src/Model.php` (only to :30), `src/Progress*`, `src/Rect.php`, `src/Undo/*`, `src/Cmd/*`,
`src/Msg/*` except KeyMsg/WindowSizeMsg, `src/MouseMsg` subclasses, `src/Modifiers.php`,
`src/{Kind,ModeState,WorkerState,BatchMsg,SequenceMsg,RawMsg,PrintMsg,ExecRequest,TickRequest,AsyncCmd}.php`,
`src/{Component,AddComponentMsg,RemoveComponentMsg,ComponentAddressedMsg}.php`, `src/Exception/*`,
`src/{SubscriptionCapable,Concerns/Mutable}.php`, tests/ coverage map for the public symbols above.

---

# candy-sprinkles

**MODE:** symlinked (`vendor/sugarcraft/*` are symlinks into the monorepo — verified with `ls -l`;
suite figures are interpretable against local siblings)
**TESTS:** `OK (762 tests, 2712 assertions)`, PHPUnit 10.5.64, PHP 8.3.6, 1.9s — green
**VERDICT:** The lib is well-tested and its core Style/Border render pipeline is sound for the
common paths, but it carries two reproducible crash/mis-layout defects in width math (Table
width-cap divide-by-zero, border center-title overflow), a background-bleed bug in the documented
`colorWhitespace(false)` path, and an inverted `Theme::adaptive()` that sugar-crush has had to
route around — plus a set of sentinel/convention breaks that matter because this file is the
repo's canonical immutability exemplar.

### 1. [HIGH] Table width-cap divide-by-zero on empty cells
- **WHERE:** `src/Table/Table.php:277`
- **WHAT:** `$scale = $available / array_sum($widths)` — when a width cap is set, the natural total exceeds it, and every cell measures 0 (all-empty rows), `array_sum($widths)` is 0 and `render()` dies with `DivisionByZeroError`. Measured twice: `Table::new()->row('','')->width(3)->border(Border::normal())->render()` and `->width(0)` on empty-cell rows both throw uncaught. A long-running TUI rendering a table of streaming/live data hits this the moment all visible cells are blank.
- **FIX:** In `render()`, guard before the division: `if (array_sum($widths) > 0) { … }` — when the sum is 0 every column is already at the floor of 1, so skip the shrink loop entirely.
- **USED-BY-CRUSH:** yes — `sugar-crush/src/Commands/TranscriptTable.php:234` calls `->width(self::maxCells($columns))`; that command's own docblock (TranscriptTable.php:45-53) documents it avoids `Table::width()` as a layout mechanism precisely because of its proportional-shrink behavior, so the crash sits one caller-mistake away.
- **STATUS:** NEW (the findings file's ProgressBar/Spinner items were all misreferenced and closed; nothing here was tracked)

### 2. [HIGH] Border center title overflows the box when left/right titles exhaust `available`
- **WHERE:** `src/Style.php:1323-1332` (top) and `src/Style.php:1396-1405` (bottom), `buildTopBorderLine()` / `buildBottomBorderLine()`
- **WHAT:** The center-title branch is gated on `$available > 0`; when the left+right titles consume all of `contentWidth`, `$available` is ≤ 0 and the *untruncated* `$centerTitle2` is concatenated anyway, so the top border line is longer than the box and the closing corner is pushed right, misaligning every row below it. Measured: `Border::normal()->withTitles(['TopLeft'=>'ABCD','TopRight'=>'XY','TopCenter'=>'CENTERED'])` on a `width(4)` style renders a 14-cell top border (`┌ABCDCENTERED┐`) for a 6-cell box.
- **FIX:** Truncate the center title unconditionally before the `if ($available > 0 …)` — e.g. `$centerTitle2 = Width::truncateAnsi($centerTitle2, max(0, $available));` — so the `$available <= 0` case yields `''` instead of the full string.
- **USED-BY-CRUSH:** yes for border titles generally (`sugar-crush/src/Renderer.php:5051, 5373, 5493`; `src/Tui/SessionPicker.php:377`, all `->withTitle(...)` TopLeft); the overflow needs a wide left/right title combo crush does not currently emit — latent.
- **STATUS:** NEW

### 3. [MEDIUM] `colorWhitespace(false)` still bleeds background across alignment fill
- **WHERE:** `src/Style.php:1027-1036` (halign call, step 3) vs `src/Style.php:1041-1060` (padding, step 4)
- **WHAT:** `halign()` pads each line out to `innerWidth` *before* the styled/unstyled split, and `render()` wraps the whole aligned line in `$sgr … $reset` at step 4 (Style.php:1056-1058). So with `colorWhitespace(false)` + `width()` + a background, the alignment spaces are inside the SGR run and take the background — the exact thing the flag exists to prevent. Measured: `Style::new()->bg('#0000ff')->width(8)->colorWhitespace(false)->render('x')` emits `\x1b[48;2;0;0;255mx       \x1b[0m` — 7 styled blanks.
- **FIX:** In step 4, when `colorWhitespace` is false, split each aligned line's trailing fill out of the styled run (or have `halign()` return the pad count and emit the styled run around content only in the unstyled branch).
- **USED-BY-CRUSH:** no — no `colorWhitespace` callers in sugar-crush/src; this is the canonical-Style path other libs imitate.
- **STATUS:** NEW

### 4. [MEDIUM] `Theme::adaptive()` inverted and misparses 3-field COLORFGBG
- **WHERE:** `src/Theme.php:264` (`return $bg >= 8 ? self::light() : self::dark();`), docblock inverted at Theme.php:246
- **WHAT:** Per xterm convention the code's own docblock says "if the background index is >= 8 the terminal uses a dark palette" and then returns `light()` for ≥ 8 — the mapping is backwards for the common cases: plain white bg (7) resolves dark, bright-black (8, #7f7f7f, a dark grey) resolves light. Additionally Theme.php:255-257 bails to `dark()` unless COLORFGBG splits into exactly 2 fields, so rxvt's 3-field form is unreadable. sugar-crush independently measured this and documented it (`sugar-crush/src/Tui/TerminalBackground.php:261-269`: "which calls any index >= 8 light: that misreads plain white (7) as dark and bright-black (8) as light… It is unusable here").
- **FIX:** Flip the comparison to match the luminance of the ANSI16 slot (or delegate to `Color::ansi($bg)->isDark()` from candy-core, which crush already re-derives correctly), and accept the 3-field form by taking the last field. Update the Theme.php:246 docblock to match.
- **USED-BY-CRUSH:** no — `sugar-crush/src/Theme.php:187` explicitly states `SprinklesTheme::adaptive()` is NOT used; the bug is quarantined by avoidance, which is itself evidence it is real.
- **STATUS:** NEW

### 5. [MEDIUM] Border-title truncation strips the title's SGR styling
- **WHERE:** `src/Style.php:1307, 1316, 1326, 1380, 1389, 1399` (`Width::truncate(...)` on title strings)
- **WHAT:** `renderTitlesAtAnchor()` (Style.php:1434-1460) deliberately keeps `$titleSgr`/`$titleReset` inside the returned string and the callers measure with ANSI-aware `Width::string()`, but on overflow the truncation uses `Width::truncate()`, which candy-core's implementation (candy-core/src/Util/Width.php:160-177) strips *all* ANSI first. A truncated styled title renders in default colours while a non-truncated one is coloured — an invisible-until-narrow-pane inconsistency in exactly the dialog-title paths crush uses.
- **FIX:** Swap the six `Width::truncate($title, $available)` calls to `Width::truncateAnsi(...)` in `buildTopBorderLine()` / `buildBottomBorderLine()`.
- **USED-BY-CRUSH:** yes — Renderer.php:5051/5373/5493 and SessionPicker.php:377 attach titles to boxes sized by `width()`; triggers when a title exceeds the box width (narrow terminal).
- **STATUS:** NEW

### 6. [MEDIUM] `patch()` leaks propsSet sentinels for values it skips
- **WHERE:** `src/Style.php:840-845` (docblock) and the `$newProps` merge loop at ~Style.php:905-910
- **WHAT:** `patch()` merges `$other->propsSet` wholesale into the result, but the value merge skips nulls (via `$applied`). Measured: `$base->patch(Style::new()->foreground(null))` returns a style whose `getForeground()` kept the base colour but `isSet('fg') === true` — a style that claims fg was explicitly set when the patch refused to apply it. Downstream, a subsequent `inherit()` on that result will now refuse a parent's fg, so the phantom sentinel silently changes inheritance behaviour. The docblock at Style.php:840 also states "null and zero values in $other are skipped" while `patch(Style::new()->height(0))` measured `height 0` merged (the `isSet`-based probes do not skip zero, only the `$applied` probes skip null).
- **FIX:** In `patch()`, build `$newProps` from only those `$other->propsSet` keys whose merged value is non-null (record the sentinel when — and only when — the value was actually applied), and align the docblock with the real null-vs-zero semantics.
- **USED-BY-CRUSH:** no — no `->patch(` call sites in sugar-crush/src.
- **STATUS:** NEW

### 7. [MEDIUM] StyleParser throws on unknown colour names beginning with a hex digit
- **WHERE:** `src/StyleParser.php:207` (`ctype_xdigit(substr($value, 0, 1))`), contract at :211
- **WHAT:** `parseColor()` documents "return null to signal it should be ignored" (StyleParser.php:167, 211), but any name whose first letter is a-f is routed to `Color::hex()`, which throws. Measured: `StyleParser::parse('[hi](fg:beige)', Style::new())` → uncaught `InvalidArgumentException: invalid hex color: beige`. Same class of names: `coffee`, `dead-salmon`, `cabaret`. A parse of user-authored markup strings kills the render path instead of ignoring the bad attribute like every other unknown name does.
- **FIX:** Only take the hex branch when the value matches a real hex shape (`preg_match('/^#?[0-9a-fA-F]{3,6}$/', $value)`), otherwise return null; or wrap the `Color::hex()` call and return null on `InvalidArgumentException`.
- **USED-BY-CRUSH:** no — no StyleParser/Markup consumers outside the lib.
- **STATUS:** NEW

### 8. [MEDIUM] Table `wrap()` callback cannot receive the column width, and extra lines are discarded
- **WHERE:** `src/Table/Table.php:216-223` (setter docblock), `:388-391` (call site: `$lines = ($this->wrap)($cell); $cell = $lines[0] ?? '';`)
- **WHAT:** The docblock instructs callers to supply a closure that calls `Width::wrap($cell, $col_width)` — but the signature is `Closure(string $cell): list<string>`; no width is passed, so the recommended usage is impossible. Worse, only `$lines[0]` is used, so any actual wrapping silently drops lines 2+ of the cell. Measured: a 40-char cell with `wrap(fn($c)=>str_split($c,5))` under a width cap renders `xxxxx` and loses the remaining 35 characters with no error. That is silent data loss in a transcript/scrollback table.
- **FIX:** Either change the contract to `Closure(string $cell, int $colWidth): list<string>` and render the returned lines as multiple physical rows, or — if multi-line rows are out of scope — rename to truncate-style semantics and delete the misleading width guidance from the docblock.
- **USED-BY-CRUSH:** no — TranscriptTable.php clips cells itself and never calls `->wrap(`.
- **STATUS:** NEW

### 9. [LOW] `unsetBorder()` leaves `borderSides` recorded in propsSet
- **WHERE:** `src/Style.php:673`
- **WHAT:** `border()` sets both `border` and `borderSides` (Style.php:470), but `unsetBorder()` scrubs only `'border'`. Measured: `Style::new()->border(Border::normal(), true,true,false,false)->unsetBorder()->isSet('borderSides')` is still `true` — the next `inherit()` will keep dead side flags instead of re-inheriting the parent's.
- **FIX:** Have `unsetBorder()` also remove `'borderSides'` (and, for symmetry, reset it to `[true,true,true,true]`).
- **USED-BY-CRUSH:** no.
- **STATUS:** NEW

### 10. [LOW] `get*` accessors throughout, breaking the repo's bare-accessor rule
- **WHERE:** `src/Style.php` (22: e.g. :144 `getUnderlineColor`, :158, :171, and the Getters block ~:470-510), `src/Bar/StatusBar.php` (5), `src/Bar/HelpBar.php` (5), `src/Border.php:213` `getTitles()`
- **WHAT:** The contributor playbook mandates "Bare accessors (no `get`)". Style is the canonical exemplar every fluent class copies, so 33 `get*()` methods here propagate the break by imitation. (No CI gate enforces it, so it is convention debt, not a build failure.)
- **FIX:** Add bare aliases (`underlineColor()`, `titles()`, …) and deprecate the `get*` forms in a follow-up; since sugar-crush compiles against some of these today, alias-first is the non-breaking order.
- **USED-BY-CRUSH:** yes — crush reads Style via `getWidth()`/`getBorder()`-family accessors in its renderer paths; removal would break compilation, hence aliases.
- **STATUS:** NEW

### 11. [NIT] `::default()` factories instead of `::new()`
- **WHERE:** `src/Layout/SolverFactory.php:39`, `src/Tree/Enumerator.php:26`
- **WHAT:** Playbook: "never `::create()`/`::make()`/`::default()`". `Tree/Enumerator::default()` mirrors lipgloss's `DefaultEnumerator` name, so it is arguably an upstream-mirror exception; `SolverFactory::default()` has no upstream contract and should be `::new()` or `::fromEnvironment()` (it already sniffs `SUGARCRAFT_LAYOUT_SOLVER`, which the name would say).
- **FIX:** Rename `SolverFactory::default()`; keep `Tree\Enumerator::default()` with a `Mirrors` doc-comment if the upstream-name exception is accepted.
- **USED-BY-CRUSH:** no.
- **STATUS:** NEW

### 12. [NIT] Docblock example is invalid code
- **WHERE:** `src/Style.php:193` ("you can write `$s->fg('#ff5')->on('navy')`")
- **WHAT:** `on('navy')` routes through `Color::hex()` and throws — measured `InvalidArgumentException: invalid hex color: white` for the analogous `fg('white')`. The fg/bg/on aliases accept *hex strings or Color objects only*; the example teaches a call that fatals.
- **FIX:** Change the example to `->on('#000080')` or note that named colours need `Palette::`/`Color::` objects.
- **USED-BY-CRUSH:** no.
- **STATUS:** NEW

### Checked and clean
- **CI gates:** `tools/check-one-type-per-file.php` — 0 problems for this lib (the orphan docblock above `Table::HEADER_ROW` at src/Table/Table.php:20-26 is a stray comment, not a second type); `php tools/check-child-lifetimes.php` — 0 problems; no `proc_open`/streams/popen anywhere in src/, so no lifecycle-leak surface. `Style::println/print/fprint` and `Output::*` write to caller-supplied streams without closing them — correct, the caller owns them.
- **Immutability core:** no `with*()` mutates `$this`; every one returns `new self(...)`; the `contentSgrMemo`/`borderSgrMemo` lazy writes are safe (content-derived, memoized per instance, `clone` carries valid memos as documented at Style.php:96-118); `padding`/`margin`/`borderSides` arrays are value-copied on every write path.
- **Width math elsewhere:** `Style::render()` truncation paths use ANSI-aware `Width::truncateAnsi` (Style.php:1000, 1013); negative padding/margin/width/height/maxWidth/maxHeight/tabWidth all throw `InvalidArgumentException`; `vfit`/`halign` cannot go negative; `StatusBar::render()` clamps caps overflow (StatusBar.php:229-233); `Border` narrower-than-border degrades to corner-only lines without crashing (issue #2 is the misalignment, not a crash); `Canvas::pasteRow` resets SGR at splice boundaries.
- **Markers:** zero TODO/FIXME/XXX/HACK in src/. The `SUGARCRAFT_LAYOUT_SOLVER=cassowary` warning in SolverFactory.php is documented intentional behaviour, not a defect.
- **strict_types:** present in all 47 src files; all public classes `final` (`abstract class Constraint` at src/Layout/Constraint.php:13 is an extension contract — compliant); no `::create()`/`::make()`.
- **findings/:** every item in `candy-sprinkles.md` was verified by the plan to reference files that do not exist in this lib (AnsiParser, Width, Spinner, ProgressBar, Lipstick, BoxDrawing, Text, Background) and is closed N/A; none of the 12 issues above appears in it — all are NEW.

---

# candy-mouse

**MODE:** symlinked (`vendor/sugarcraft/{candy-ansi,candy-core,candy-input,candy-pty}` are all `../../../` symlinks)
**TESTS:** `OK (152 tests, 400 assertions)` in 00:00.133, PHP 8.3.6, PHPUnit 10.5.64 — all green
**VERDICT:** The library is clean on lifecycle and conventions (no streams/timers/children, no
mouse-mode enable sequences to leak, one type per file, `final` + `declare(strict_types=1)`
everywhere, `::new()` factories, path-repos removed from the manifest), and the four big findings
from `findings/candy-mouse.md` (tracker phantom click, OSC bounds guard, spatial index, button
constants) are verifiably fixed — but `Scan::parse()` has two confirmed coordinate-desync defects
(stray/malformed sentinel pairs and CRLF line endings) that silently register zones at the wrong
cell, which is worse than failing: clicks fire the wrong zone.

### 1. [HIGH] `Scan::parse()` mis-tracks columns/rows across stray or malformed sentinel pairs, and two of them throw away the whole frame's zones
- **WHERE:** `src/Scan.php:85-96` (id field extracted between any EE-80-80 and the next EE-80-81 with no charset/length validation), `:127` and `:147` (`$i = $idEnd + 3` skips the swallowed span with zero column/row advance)
- **WHAT:** Confirmed by probe: a bare U+E000 in text followed by a U+E001 further along makes the parser treat everything between as the "id" and skip it — a real zone after that is reported at cols 9-12 when it painted at col 40, and if the skipped span contains newlines the row counter desyncs too (reported row 4 for content on row 6). Two consecutive bare open+close pairs (exactly what a U+E000-based image marker pair emits) produce two empty-id opens and trip the duplicate-id throw at Scan.php:140, which downstream sugar-crush's `scanRoot()` answers by clearing the registry — every zone in the frame (session tabs, tool rows, status bar) stops responding. sugar-crush had to build `maskImageMarkers()` (`sugar-crush/src/Renderer.php:1349-1361`) to work around precisely this; the lib itself still trusts the decode side while `Mark` rigorously validates the encode side (Mark.php ID_PATTERN comment calls this exact scenario "a zone-marker injection").
- **FIX:** In `Scan::parse()`, validate the extracted `$id` against the same charset/length Mark enforces (letters/digits/`._:-`, ≤ `Mark::MAX_ID_BYTES`) before acting on the sentinel; on failure, skip only the 3 sentinel bytes (advance normally, no id-skip) so column/row tracking stays exact, or throw — but never emit a bbox from a desynced cursor.
- **USED-BY-CRUSH:** yes — `Scanner::scan()` via `scanRoot()` (`sugar-crush/src/Renderer.php:1283-1308`), `Tui/Renderer.php` chrome scanner, `MenuBar.php` marks
- **STATUS:** NEW (not in findings/candy-mouse.md; downstream masks around it, the lib is still broken standalone)

### 2. [HIGH] CRLF never advances the row — every zone below a `\r\n` line is registered one row too high
- **WHERE:** `src/Scan.php:158-166` (the `\n` branch) and `:228-244` (`nextGrapheme`)
- **WHAT:** `grapheme_extract()` returns `"\r\n"` as a single cluster (verified: returns 2-byte `"\r\n"`, `$next` advanced by 2), and `Width::string()` of it is 0, so the `\n` branch never fires and neither row nor column advances. Probe: `parse("AAAA\r\n" . marked zone)` reports the zone at cols 5-8 row 1; the same input with bare `\n` correctly reports cols 1-4 row 2. Any frame carrying CRLF (Windows-file tool output, `git clone`/`npm` progress — the exact cases sugar-crush's own `CarriageReturnFrameTest` documents) shifts every zone beneath it up a row, so a click on row N+1 fires whatever zone the parser thinks is on row N. Lone `\r` (width 0) likewise leaves the column unchanged even though it returns the real cursor to column 0.
- **FIX:** In `Scan::parse()`'s main loop, before the grapheme path, handle `$b === "\r" && ($rendered[$i + 1] ?? '') === "\n"` by running the existing newline branch and `$i += 2` (and decide documented behavior for lone `\r`, e.g. reset `$col = 1` without row advance).
- **USED-BY-CRUSH:** yes — same `scanRoot()` path; crush currently survives only because it strips CR from rows upstream, not because the scanner handles it
- **STATUS:** NEW

### 3. [MEDIUM] A zone wrapping empty content claims the adjacent character's cell and steals its clicks
- **WHERE:** `src/Scan.php:101-104` (`$endCol = max($startCol, $col - 1)` back-off)
- **WHAT:** Probe: `parse("AAAA" . Mark::zone("e","") . "B")` yields zone `e` at cols 5-5 — column 5 is where `B` actually paints, since the empty content advanced nothing and the back-off floors at `$startCol`. A conditionally-rendered widget that wraps an empty string becomes a phantom 1-cell zone that captures clicks meant for whatever sits to its right, with no error anywhere.
- **FIX:** In the close branch of `Scan::parse()`, track whether any visible cell was consumed since the open and drop the zone instead of emitting a bbox when the content had zero display width.
- **USED-BY-CRUSH:** yes — every `Mark::zone()` call site can hit it when a label renders empty (e.g. `recordToolCallZone` rows with empty labels)
- **STATUS:** NEW

### 4. [MEDIUM] Malformed input is silently swallowed (orphan close, unmatched open), contradicting the repo's "no silent failures" convention — and downstream's documented contract about it is already wrong
- **WHERE:** `src/Scan.php:98-129` (`isset($this->open[$id])` guard makes an orphan close a no-op), `:143-147` (unmatched open just left in `$this->open`, zones built only from closed pairs)
- **WHAT:** Probes: an orphan close (`EE8080 / EE8081` with no matching open) parses with no throw and no zone; an open never closed is silently dropped. Meanwhile sugar-crush's docblock at `sugar-crush/src/Renderer.php:3050` asserts "a unmatched close makes Scan::parse() throw, and scanRoot() answers a throw by clearing the registry" — it does not throw (verified against the clipped multi-row frame case: 0 zones, no exception). The lenient behavior is probably right for clipped frames, but the throw-on-duplicate vs ignore-on-orphan asymmetry is undocumented in `Scan`'s own contract, and a consumer has already written a false claim about it.
- **FIX:** Document the malformed-sentinel matrix explicitly in `Scan::parse()`'s docblock (duplicate open → throws; orphan close / unclosed open → ignored, and why clipping makes that the safe choice), and reconcile sugar-crush's Renderer.php:2973/3050 comments with the actual behavior.
- **USED-BY-CRUSH:** yes — `scanRoot()`'s try/catch policy is built on this contract
- **STATUS:** NEW (the findings file never covered orphan-sentinel behavior)

### 5. [LOW] `Scan` still holds mutable instance state — not reentrant
- **WHERE:** `src/Scan.php:28-31`
- **WHAT:** `$open`/`$zones` are instance properties reset at the top of `parse()` (Scan.php:47-48), so a reused `Scan` instance nested/reentered mid-parse corrupts state. `Scanner::scan()` (Scanner.php:60-66) constructs a fresh `Scan` per call, so today's callers are safe; the hazard is only for anyone holding one `Scan`.
- **FIX:** Make `parse()` a `public static function` returning the zones array (locals instead of properties), or keep as-is and document "one instance per parse, never reused" on the class.
- **USED-BY-CRUSH:** no — crush only touches it through `Scanner::scan()`
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT (findings/candy-mouse.md #4; plan leaves it as a design alternative — carry forward the plan's "pure static parse" option)

### 6. [LOW] `Lang` façade and `lang/en.php` are dead weight
- **WHERE:** `src/Lang.php:20-23`, `lang/en.php:5-7`
- **WHAT:** `Lang::t()` is called from nowhere in `src/` (verified by grep — zero call sites), and `en.php` returns an empty array with only a commented example. Every error string in the lib is a hardcoded English literal (e.g. Scan.php:141, Mark.php:106). No defect, but it claims i18n the lib doesn't do, and a future `add-locale` pass would translate nothing.
- **FIX:** Either route the three user-facing `InvalidArgumentException` messages through `Lang::t()` or drop the façade + lang dir until there are strings to translate.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW

### 7. [LOW] `Selection` and `SelectionRange` have no consumer anywhere in the monorepo yet
- **WHERE:** `src/Selection.php:34`, `src/SelectionRange.php:41`
- **WHAT:** Their docblocks say they were upstreamed from sugar-crush's `Tui\TextSelection` "the rewire drops its `[$col - 1, $row - 1]` step", but sugar-crush still uses its own `Tui/TextSelection.php` (verified: zero `use SugarCraft\Mouse\Selection*` imports repo-wide outside the lib). They are tested (SelectionTest, SelectionRangeTest) and internally consistent — no defect found in them — but the rewire they were ported for hasn't landed, so they're shipped-but-unwired.
- **FIX:** No code change in candy-mouse; the follow-up is landing the sugar-crush rewire (or noting it pending in the lib README).
- **USED-BY-CRUSH:** no (pending rewire)
- **STATUS:** NEW (informational)

### 8. [NIT] `Mark` exposes a public constructor with `::disabled()`/`::zone()` but no `::new()` root
- **WHERE:** `src/Mark.php:60-63`
- **WHAT:** Repo convention is `::new()` as the default root with a private constructor. `Scanner`, `Selection`, `SelectionRange` all follow it; `Mark` uses a public constructor with a defaulted bool instead. Harmless — no gate fails on it — just the odd class out in the lib.
- **FIX:** Add `public static function new(): self` to `Mark` and (optionally) make the constructor private.
- **USED-BY-CRUSH:** yes — `new Mark(...)` call sites would keep working unchanged
- **STATUS:** NEW

### Checked and clean
No `proc_open`/streams/timers anywhere in `src/` (nothing to leak on teardown); the lib emits no
mouse-tracking enable sequences (grep for `1000`/`1003`/`1006`/`1015`/`ESC[` in `src/` returns
nothing) so there is no enable-without-disable hazard — mode setup/teardown lives in candy-core,
outside this target; `Scanner::scan()` replaces the zone map wholesale and
`ZoneClickTracker::$pending` is bounded by button count and cleared on release, so no
long-session growth; 12 src files, 12 top-level types; findings items 1, 2, 3, 5, 9, 10 and the
Sentinel-duplication refactor are all verifiably fixed (tracker phantom-click probe returns null;
OSC guard present at Scan.php:112; grid index shipped; button constants present; path-repos gone;
`Sentinel` class anchors both sides to `Sanitize::ZONE_SENTINEL_*`).

---

# candy-layout

**MODE:** published (dependency-free leaf — `vendor/sugarcraft/` does not exist; composer.json
requires only `php ^8.3` + dev phpunit, so there are no siblings to symlink and the suite result
is mode-independent)
**TESTS:** `OK (265 tests, 1135 assertions)` — `vendor/bin/phpunit --colors=never`, PHP 8.3.6,
0.224s, no timeout, no warnings
**VERDICT:** The library is healthy overall — the retired simplex is properly deprecated, all
CI-gated conventions pass (strict_types in all 17 src files, all classes final,
`tools/check-one-type-per-file.php` reports 0 problems for this lib, no `get*`/`::create`/`::make`/
`::default`, no streams/processes/timers so no lifecycle leaks, and `DockLayout`/`GreedySolver`
are stateless per call so resize re-solves cannot accumulate symbols or constraints) — but the
Dock stack split contradicts its own "no floating-point drift" claim, and three GreedySolver
rounding paths silently fail the interface's "sizes sum to the region" contract.

### 1. [HIGH] `DockLayout::stackHeights()` float math drifts off the exact rational floor the class docblock promises
- **WHERE:** `src/Dock/DockLayout.php:530, 543` (claim at :15-16 and :355 "heights are the floor of each rational weight share")
- **WHAT:** Weights are stored as rationals to be drift-free, but the share is computed as `floor($rows * ($weightNum/$weightDenom / $totalWeight))` in binary floating point. Reproduced: two left slots with weights 3/5 and 1/1 in a 9-row frame → slot a gets height 2, while the exact rational floor is `floor(8 * 3/8) = 3`; the boundary row silently migrates to the last slot. A sweep over heights 1-4000 × weights n/1..n/7 found 2,859 drift cases, so common stack weights mis-split at regular height intervals during terminal resizes. The frame still tiles (residual-to-last), so this is a wrong pane boundary, not a hole.
- **FIX:** Compute the share in integer arithmetic — e.g. `intdiv($rows * $slot->weightNum * $commonDenomProduct, $totalWeightScaled)` over a common denominator (cross-multiplication generalized), keeping the existing floor + residual-to-last contract; add a DockLayoutTest pinning weights 3/5 + 1/1 at height 9 → [3, 5].
- **USED-BY-CRUSH:** yes — `sugar-crush/src/App/App.php:1487,1566` and `sugar-crush/src/Tui/Renderer.php` call `DockLayout::resolve()` on every dock render/resize; multi-slot side stacks (e.g. `defaultDock()` plus a second docked pane via `withStackWeight`) hit this path.
- **STATUS:** NEW (findings/candy-layout.md predates the Dock module; not in plan_candy-layout.md)

### 2. [MEDIUM] Floor-rounding reclaim is capped at a fixed 2 cells, so ≥4 Percentage/Ratio segments can leave an unreclaimed gap
- **WHERE:** `src/GreedySolver.php:73` (`MAX_FLOOR_RECLAIM = 2`), gate at :358, Percentage/Ratio-only reclaim at :368
- **WHAT:** Reproduced: four `Percentage(25)` in width 99 → [24,24,24,24], sum 96 — a 3-cell hole because the pure floor loss (N−1 = 3) exceeds the hard cap of 2 and the reclaim block is skipped entirely. The comment's own rationale ("loses at most one cell per segment") scales with segment count, but the cap does not, so a quartile split in an odd-width terminal violates the `LayoutSolver` sum-to-total contract (src/LayoutSolver.php:11-12) with no Fill/Max to absorb it. Existing tests only pin the 3×33%-in-100 (diff 1) case.
- **FIX:** In `solveHorizontal`, gate the reclaim on `$diff <= max(0, $percentageOrRatioCount - 1)` instead of the fixed `MAX_FLOOR_RECLAIM`, and add the 4×Pct(25)@99 case to GreedySolverTest.
- **USED-BY-CRUSH:** no (indirectly only — sugar-crush reaches GreedySolver via candy-sprinkles `Layout`, which uses Length/Fill-heavy layouts; no crush call site constructs ≥4 Percentage/Ratio siblings today)
- **STATUS:** NEW

### 3. [MEDIUM] Proportional Min-slack distribution loses a cell and never reclaims it (test-pinned gap)
- **WHERE:** `src/GreedySolver.php:341` (`floor(($c->n / $reservedMinSum) * $slack) + $c->n`), reclaim at :368 covers only Percentage/Ratio
- **WHAT:** Reproduced: `[Min(30), Min(40)]` in width 100 → [42,57], sum 99; `[Min(10)×3]` → sum 99. The 1-cell floor loss has no Percentage/Ratio recipient to reclaim it, so the layout leaves a trailing column and violates the interface's "sum to the region's total dimension" contract. `tests/GreedySolverTest.php:74-88` pins the lossy result ("= 99 + 1 rounding"), so this is known behavior — but the zero-weight sibling path (GreedySolver.php:314-335) deliberately preserves the sum invariant, making the two Min branches inconsistent with each other. For a TUI, a Min-only row split silently uncovers one column of background at the right edge.
- **FIX:** Hand the residual to the first Min recipient in the `$reservedMinSum > 0` branch, mirroring the equal-shares fallback's remainder handling at :330-334; update the pinned expectation in testPureMinHorizontal.
- **USED-BY-CRUSH:** no (direct DockLayout/Region use only; sprinkles-side Min usage would hit it)
- **STATUS:** NEW

### 4. [MEDIUM] Overflow truncation neither warns nor preserves Length semantics, contradicting the algorithm doc
- **WHERE:** `src/GreedySolver.php:29` (step 7: "truncate proportionally and warn"), overflow branch :281-299
- **WHAT:** Reproduced: four `Length(3)` in width 10 → [2,2,2,2] (sum 8, two fixed panels clipped below request) with zero diagnostics captured through `set_error_handler` — the "warn" half of the documented contract is unimplemented. An over-constrained system is therefore silently degraded to a garbage-tiled layout instead of reported, and the floor in the scale pass loses additional cells with no reclaim path (the Step-3 branch never runs in overflow).
- **FIX:** Emit a `trigger_error(...)` in the `truncateOverflow === true` overflow branch of `solveHorizontal`, and give the last truncated entry the scale-pass residual so sizes sum to width; add a test asserting the warning fires.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW

### 5. [LOW] `@trigger_error` suppression makes the CassowarySolver deprecation invisible in production
- **WHERE:** `src/CassowarySolver.php:67`
- **WHAT:** The `@` operator suppresses the notice for the default PHP error handler, so the deprecation the class docblock promises ("solve() now emits E_USER_DEPRECATED") only surfaces to hosts with a custom `set_error_handler` (which the tests install, :35/:65 — masking the gap). Violates the project's php-best-practices `error-never-suppress` rule.
- **FIX:** Drop the `@` and let `error_reporting`/host handlers govern visibility, as candy-sprinkles' `SolverFactory` already does for its own warning (candy-sprinkles/src/Layout/SolverFactory.php:47).
- **USED-BY-CRUSH:** no (crush never selects the cassowary solver; the env gate lives in sprinkles)
- **STATUS:** NEW

### 6. [LOW] Stale cross-lib deprecation text: sprinkles still warns of the Ratio-returns-0 bug the delegation fixed
- **WHERE:** consumer-side, `candy-sprinkles/src/Layout/SolverFactory.php:28-33, 48-52` vs `candy-layout src/CassowarySolver.php:12-24`
- **WHAT:** `SolverFactory::default()` tells `SUGARCRAFT_LAYOUT_SOLVER=cassowary` users that Ratio returns 0, but `CassowarySolver::solve()` now delegates wholly to GreedySolver, which the candy-layout docblock itself says "FIXES the long-standing Ratio-returns-0 bug". The warning is now misinformation. Outside the target lib's tree, so flagged for the sprinkles lane.
- **FIX:** Update the SolverFactory warning text to name the deprecation + delegation instead of the fixed Ratio bug.
- **USED-BY-CRUSH:** no (crush uses sprinkles `Layout` with the default GreedySolver path; `sugar-crush/src/Tui/Renderer.php:12`)
- **STATUS:** NEW

### 7. [LOW] LayoutSolver interface still carries concrete-type static factories (Interface Segregation)
- **WHERE:** `src/LayoutSolver.php:31, 36`
- **WHAT:** `greedy(): GreedySolver` / `cassowary(): CassowarySolver` on the interface force every implementer to know both concrete classes.
- **FIX:** As ruled in the plan — remove from the interface, keep on concrete classes or a separate `LayoutSolverFactory`; the plan names this a STOP-class public-API change across 4 consumers.
- **USED-BY-CRUSH:** no
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT (findings/candy-layout.md #2; plan_candy-layout.md row 1.2, "ruled STOP-class, orchestrator decision r86-v2")

### 8. [LOW] Dead code: `Expression` has no `src/` consumer
- **WHERE:** `src/Expression.php` (whole file); retention note at `src/CassowarySolver.php:75-81`
- **WHAT:** The simplex that consumed it was deleted; grep shows zero uses of `SugarCraft\Layout\Expression` in candy-layout/src and in candy-sprinkles, candy-forms, sugar-bits, sugar-crush src trees — only tests/ExpressionTest.php keeps it alive. 13 tests exercise a helper nothing ships.
- **FIX:** Either mark `@internal`/deprecate alongside CassowarySolver, or delete Expression.php + ExpressionTest.php in a coordinated PR.
- **USED-BY-CRUSH:** no
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT (plan rows 2.2/3.2 note the simplex premise is dead but keep the class; deletion itself is not scheduled)

### Checked and clean
No `proc_open`/streams/timers anywhere in src (lifecycle class N/A); `DockLayout`/`GreedySolver`
hold no mutable state across `resolve()`/`solve()` calls, so resize re-solving cannot accumulate
constraints; all divide sites are zero-guarded (GreedySolver.php:314, :593-604, solveMinShare
totalWeight===0; PHP_INT_MAX weight-sum float overflow is pinned by SolverEdgeCaseTest.php:130-142);
Region and every constraint reject negatives at construction; the Dock degradation ladder
(`planColumns`/`dropOneSide`, DockLayout.php:400-465) exactly tiles the frame including the
documented 0-column-active-side case; no TODO/FIXME/XXX/HACK markers remain in src (the old
commented-out convergence guard was deleted with the simplex, CassowarySolver.php:75-81).

---

# sugar-mcp

**MODE:** linked (`vendor/sugarcraft/*` are symlinks into the monorepo: candy-ansi, candy-core,
candy-input, candy-pty)
**TESTS:** `OK (141 tests, 471 assertions)` in 16.05s, PHP 8.3.6 — no timeouts, no failures
**GATES:** `php tools/check-child-lifetimes.php` → exit 0, "26 proc_open sites, 7 findings, 7
accounted rows, 0 problems"; this lib's child IS accounted for with a roster entry at
`tools/check-child-lifetimes.php:152` (`sugar-mcp/src/StdioMcpServer.php::start`) — pipes closed
before signalling, `BoundedShutdown::terminateBounded` TERM→KILL→`proc_close` ladder, `__destruct`
→ `stop()`. `php tools/check-one-type-per-file.php` → exit 0.
**VERDICT:** A carefully hardened transport whose framing, fork-safety and child-lifetime handling
are genuinely solid, but it has one hang vector and one silent-degradation path in exactly the
places its own initialize-leg discipline says they shouldn't exist: an unencodable request payload
is written as a blank frame with no error, and a tools/list error reply is accepted as a
successful start with zero tools.

### 1. [HIGH] `McpMessage::toJson()` silently emits an empty string when `json_encode` fails, and `callTool` then hangs forever
- **WHERE:** `src/McpMessage.php:216`, consumed at `src/StdioMcpServer.php:562` and `:684`
- **WHAT:** `json_encode` returns `false` for INF/NAN (trivially reachable: a model emitting `1e999` in tool arguments survives `json_decode` as `float(INF)` — confirmed by direct execution) and for invalid-UTF-8 strings; `(string) false` is `""`. `request()` then writes `"" . "\n"` — a blank line every server ignores — and `readResponse()` with the deliberately no-deadline `callTool` policy (StdioMcpServer.php:496, E646) loops until EOF, i.e. forever, because the child is alive. Reproduced the empty wire string end-to-end: `McpMessage::request("7","tools/call",["n"=>INF])->toJson()` returned `""`. In sugar-crush this wedges a forked agent process mid-turn with no exception anywhere.
- **FIX:** In `toJson()`, use `json_encode($payload, JSON_THROW_ON_ERROR)` (or check for `false`) and throw `\InvalidArgumentException` naming the method/id; `callTool`'s caller in sugar-crush already catches `\Throwable` (`sugar-crush/src/Tools/McpToolBridge.php:413`), so it degrades to a reported tool failure instead of a hang.
- **USED-BY-CRUSH:** yes — every request leg of `SugarCraft\Crush\MCP\StdioMcpServer` (`sugar-crush/src/MCP/StdioMcpServer.php:71` wraps the lib transport), plus `HttpMcpServer`/`ClaudeCodeMcpServer` import `ArgumentShape`/`RequestIdSequence` from this lib
- **STATUS:** NEW (no findings/sugar-mcp.md, no plan file — both confirmed absent; this lib had never been audited)

### 2. [HIGH] `start()` treats a `tools/list` error response as success and reports "up, 0 tools"
- **WHERE:** `src/StdioMcpServer.php:361-362`
- **WHAT:** The initialize leg (line 344) explicitly rejects `$response->error !== null || !$response->resultSet` with the comment that "an ERROR reply is a refusal, not a start" — but the tools/list leg checks only `=== null`. A server that answers `tools/list` with `{"error": {"code": -32601, ...}}` (session-gated or capability-gated servers do this) flows into `parseTools()`, whose `$response['result']['tools'] ?? []` (line 1046) resolves to `[]` — start() returns successfully with an empty tool table, the server's error code and message are discarded, and the child stays spawned. On a long-running TUI this is the worst shape of failure: the server looks connected, exposes nothing, and there is no diagnostic. No test covers an error reply on this leg (the echo-command test at StdioMcpServerTest.php:215 passes only because initialize itself fails first).
- **FIX:** Mirror the initialize gate at line 361: `if ($listResponse === null || $listResponse->error !== null || !$listResponse->resultSet) { /* same refusal path: capture stderrTail, describeError, stop(), throw */ }`.
- **USED-BY-CRUSH:** yes — `start()` is the crush wrapper's only handshake path (`sugar-crush/src/MCP/StdioMcpServer.php:118ff`)
- **STATUS:** NEW

### 3. [MEDIUM] The "bounded by child liveness" guarantee for deadline-less `callTool` breaks when a grandchild inherits the stdout write end
- **WHERE:** `src/StdioMcpServer.php:104-110` (E646 docblock), readLine loop at `:861-933`
- **WHAT:** The class docblock states a tool call is "bounded by child liveness (a dead child fails the write or the read)". The read side never checks liveness — it only ends on pipe EOF — and EOF requires *every* holder of the write end to close it. A server that forked helpers (language servers spawning compilers, node workers) or a wrapper process (crush's own `setsid -w` front, `sugar-crush/src/Support/ProcessContainment.php:215`) that dies while the real server grandchild lives leaves the pipe open and the parent reading forever on a `callTool` with no deadline. The write side would fail on stdin EOF, but a request already in flight or a pure read wait never notices.
- **FIX:** In `readLine()`'s `$ready === 0` poll branch (line :903), when the caller passed `$deadline === null`, periodically (e.g. every poll second) consult `serverIsRunning()` and return null when the direct child is gone — turning the documented liveness bound into an enforced one without adding a wall-clock cap on legitimate long work.
- **USED-BY-CRUSH:** yes — `callTool` is the model-facing path (`sugar-crush/src/Tools/McpToolBridge.php:408`)
- **STATUS:** NEW

### 4. [LOW] `notify()` swallows a failed write with no signal to the caller
- **WHERE:** `src/StdioMcpServer.php:576-581`
- **WHAT:** `notify()` returns void and discards the exchange result; a dropped `notifications/initialized` (dead pipe, deadline) is invisible. During `start()` the subsequent tools/list deadline catches it, but an embedder using the public `notify()` for `notifications/cancelled`/`progress` gets no failure channel at all.
- **FIX:** Return `bool` from `notify()` (it already computes one inside the closure at line 580), or throw on failure.
- **USED-BY-CRUSH:** no (crush's transports call `request()` through the wrapper)
- **STATUS:** NEW

### 5. [LOW] `spawnPlan()` validation accepts a string command from the planner, re-opening the shell-grandchild teardown hazard the class itself documents as measured
- **WHERE:** `src/StdioMcpServer.php:261`
- **WHAT:** The docblock at lines 28-32 explains that a shell-string spawn orphans the real server on stop() ("measured in the product"), yet the planner's return is validated as `is_string($plan[0]) || is_array($plan[0])` — a containment wrapper returning `"setsid -w -- $argv"` passes validation and silently reproduces the orphan. The current crush planner returns arrays (`sugar-crush/src/MCP/StdioMcpServer.php:100-110`), so this is latent, but the seam is exactly where a future embedder will step on it.
- **FIX:** Either require `is_array($plan[0])` outright, or keep string support but document the orphan consequence at the validation site.
- **USED-BY-CRUSH:** no (crush's planner always returns an array)
- **STATUS:** NEW

### 6. [LOW] `ExchangeLock::create()` violates the repo's factory-naming rule (`::new()`, never `::create()`)
- **WHERE:** `src/ExchangeLock.php:83`
- **WHAT:** AGENTS.md is explicit: "Factories mirror upstream — `::new()` default … never `::create()`/`::make()`/`::default()`." `ExchangeLock::create($label, $dir)` is the one violation in src/. Note the downstream twin `sugar-crush/src/LSP/LspExchangeLock.php` copied the name, so a rename is a two-repo change, not a one-line fix — flagging, not demanding.
- **FIX:** Rename to `ExchangeLock::open()` or `::new()` in this lib and bump the crush twin in a coordinated PR.
- **USED-BY-CRUSH:** yes — `sugar-crush/src/ClaudeCodeMcpClient.php:481` calls `ExchangeLock::create('claude-mcp')`
- **STATUS:** NEW

### 7. [LOW] `ExchangeLock::store()`/`markPhase()` ignore write failures, so a full/unwritable temp filesystem reads back as "clean"
- **WHERE:** `src/ExchangeLock.php:290-320` (`store`, `markPhase`)
- **WHAT:** `fwrite` return is unchecked after `ftruncate($handle, 0)`. If the store fails on a full tmpfs, the file is left empty, and `load()` (line 262) reads an empty file as `PHASE_CLEAN` — the next holder then trusts a stream that may hold half a line, exactly the state the phase byte exists to detect. Best-effort is defensible for the sweep, but this silently defeats the class's own corruption-recovery contract. Low probability, contained blast radius (worst case is one failed exchange, since readResponse skips unparseable fragments).
- **FIX:** Have `store()`/`markPhase()` return the fwrite byte count and have `exchange()` treat a failed store as a failed exchange (keep the marker dirty), or at minimum document the degraded-on-write-failure reading.
- **USED-BY-CRUSH:** yes (same lock object via the wrapper and ClaudeCodeMcpClient)
- **STATUS:** NEW

### 8. [NIT] Docblock types reference `\ProcOpen`, a PHP 8.5 class, on a `^8.3` library
- **WHERE:** `src/StdioMcpServer.php:133, :669`
- **WHAT:** `@var resource|\ProcOpen|null` — on PHP 8.3 `proc_open` returns `resource|false` and the class does not exist. Harmless (docblock-only, `is_resource()` is the real check) but it's a type no analyzer on the declared floor can resolve.
- **FIX:** Drop the union to `resource|null` or gate the mention in prose.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW

### 9. [INFO] Dead/unused-by-flagship-consumer
sugar-crush imports only `StdioMcpServer`, `RequestIdSequence`, `ExchangeLock`, `ArgumentShape`,
`McpTool`; it keeps its own parallel copies of `McpMessage`, `McpRouter`, and the `McpServer`
interface (`sugar-crush/src/McpMessage.php`, `src/MCP/McpRouter.php`, `src/MCP/McpServer.php`), so
the router's fnmatch deny-before-allow logic is not the code the flagship actually runs.
Acceptable for a published standalone lib, but worth knowing.

### Positives
The NDJSON framing is done right — multi-read accumulation with a per-call `strpos` floor
(readLine, StdioMcpServer.php:861), leftover bytes carried through the lock file so the next
exchange starts at a line boundary, oversized frames refused rather than buffered, and stderr
absorbed in every poll set on both the read and write paths (the 64KiB-pipe deadlock is closed).
The fork-safety design (pid+nonce ids, phase markers, owner-only teardown, per-pid lock handles)
is the most thorough in this monorepo, and the strict-id-match + malformed-reply-to-our-id
handling in `readResponse` (:792, :832) correctly distinguishes skippable noise from a broken
answer to us. All 8 src files carry `declare(strict_types=1)`; all public classes `final`; no
`get*` accessors; no shell-string spawn on the default path (argv array to `proc_open`,
StdioMcpServer.php:292). Findings #1 and #2 are both fixable in a handful of lines in files that
already contain the corrected pattern elsewhere.

---

# candy-shine

**MODE:** symlinked (`vendor/sugarcraft/*` are relative symlinks into the monorepo — local wiring)
**TESTS:** `OK (777 tests, 1534 assertions)`, 3.62s, no hang
**VERDICT:** The lib is well-hardened against injected controls and malformed UTF-8 and its suite
is green, but its streaming path has one live performance defect (any literal `]` in model output
stalls SectionStream so sugar-crush re-renders the whole reply every frame), plus a heading-case
transform that corrupts embedded SGR and a list-wrap width accounting bug.

### 1. [HIGH] SectionStream stalls on any literal `]` — every later section collapses into `finish()`
- **WHERE:** `src/Render/SectionStream.php:154` (`confirm()` sets `body => null`), `:159-168` (`retryHeld`), `:204-211` (`hasUnresolvedBracket`)
- **WHAT:** `hasUnresolvedBracket()` holds back a confirmed section whenever any Text node contains `]`, so ordinary model output — `array[0]`, `[31m`, footnote-style `[1]` — permanently blocks release (retryHeld only fires when a *new definition* appears, which model output rarely emits). Measured: a clean 3-section stream releases 1 body per push; the same stream with `array[0]` in section 1 releases 0 bodies across all three pushes. In sugar-crush's per-frame `clone + finish()` path the stalled section plus everything queued behind it re-renders from source every frame; measured the held branch at ~2× the clean branch's per-frame cost on a 2-section doc, and the gap grows with document length — the exact long-running-TUI cost this streaming memo exists to avoid.
- **FIX:** In `SectionStream::confirm()`/`hasUnresolvedBracket()`, only hold a section when the document contains an *unresolved link-reference candidate* — e.g. require a `[...]:` definition or a `[label]` shortcut-shaped run (text after `]` is not `(`/`[`) — rather than any `]`; or cap the hold (release after N pending sections).
- **USED-BY-CRUSH:** yes — `sugar-crush/src/Renderer.php:3792` (`new SectionStream($md)`), `:3810-3820` (per-frame `push`/`clone`/`finish` in `streamingMarkdown`)
- **STATUS:** NEW (findings/candy-shine.md predates the r89 streaming lane; audit 15b-30 tracked definition carrying, not the stall)

### 2. [MEDIUM] `headingCase` upper/lower/title mutates embedded SGR bytes
- **WHERE:** `src/Renderer.php:1156` (call site), `:1170-1177` (`applyCase`)
- **WHAT:** `applyCase()` runs `mb_strtoupper`/`strtolower`/`mb_convert_case` over the *already-rendered* heading body, so inline styling inside a heading is corrupted: measured `# a **b** c` with `headingCase: 'upper'` emits `\x1b[1M` (capitalized `m` terminator) instead of `\x1b[1m`, and with `'lower'` the code-span color SGR `38;2;...;135m` survives only because it has no letters to flip — the terminator case is luck, not design. The existing tests (RendererTest.php:574-623) assert on plain text only, so the suite is green over a broken byte stream.
- **FIX:** Apply the case transform per Text node before styling (move `applyCase` into the inline path, or split on the SGR regex and transform only non-escape segments) in `Renderer::renderHeading`/`applyCase`.
- **USED-BY-CRUSH:** no — crush themes come from `Theme::byName()`/stock constructors, which never set `headingCase` (default `'none'`); only custom `new Theme(...)` callers hit it
- **STATUS:** NEW

### 3. [MEDIUM] List continuation indent is added after wrapping, so list lines exceed `wrapWidth`
- **WHERE:** `src/Renderer.php:1068-1080` (`renderParagraph` wraps at `blockStack->availableWidth()`), `src/Renderer.php:1231+` (`renderList` prefixes the continuation `$indent` onto already-wrapped lines)
- **WHAT:** `renderList`/`renderListItem` push contexts with `accumulatedIndent` copied unchanged from the parent, and `renderList` prefixes `max(bullet+1, listLevelIndent)` spaces to continuation lines *after* the inner paragraph was already wrapped to the parent's available width. Measured with `withWordWrap(40)`: a third-level nested list line renders 44 cells — 4 over the configured width — so nested lists overflow the crush pane by the accumulated indent. (Exact wrap/indent statement lines not re-verified before the step cap; the push anchors are confirmed.)
- **FIX:** Add the list's continuation indent to `accumulatedIndent` when pushing the List/ListItem contexts (or pass it into `availableWidth`) so `renderParagraph` wraps at `wrapWidth − indent`.
- **USED-BY-CRUSH:** yes — `sugar-crush/src/Renderer.php:3576, 3710` (`new Markdown($theme->markdown, wrapWidth: $width)` on model output)
- **STATUS:** NEW

### 4. [LOW] Reused `Renderer` grows its block stack one Document context per `render()`
- **WHERE:** `src/Renderer.php:702-712`
- **WHAT:** The lazy `if ($this->blockStack === null)` init (the landed half of plan item 1.1) means each `render()` call on a reused instance pushes another Document context and never pops it — measured depth 2→5 after 5 renders. Output stays byte-identical (Document contexts carry indent 0), but a long-lived renderer leaks stack entries and inflates `depth()`-keyed `StyleSheet::for()` lookups.
- **FIX:** Reset unconditionally at the top of `render()`: `$this->blockStack = new BlockStack(); $this->styleSheet = StyleSheet::base();` — matching what `renderParsedSection()` already does via its throwaway copy at :435.
- **USED-BY-CRUSH:** no — crush builds a fresh `Markdown` per frame
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT (plan_candy-shine.md Phase 1.1; the fix landed as lazy-init only, the per-render reset is the leftover)

### 5. [LOW] `preservedNewLines` drops the first preserved blank line (off-by-one)
- **WHERE:** `src/Renderer.php:772-799` (`extractBlankRuns` / `reapplyBlankRuns`)
- **WHAT:** `extractBlankRuns` records `strlen($run) - 1` newlines and `reapplyBlankRuns` emits exactly `str_repeat("\n", $blanks)` — but CommonMark already collapsed the run to `\n\n`, so one blank line survives on its own and the replacement *subtracts* one. Measured with the flag on: `"one\n\ntwo"` and `"one\n\n\ntwo"` both render `"one\n\ntwo"`; only `\n{4,}` grows the output. The existing test (RendererTest.php:859-866) asserts only `substr_count > 2`, which the 3-newline case passes vacuously.
- **FIX:** Return `strlen($run)` (or `str_repeat("\n", $blanks + 1)`) in `extractBlankRuns`, and tighten the test to assert the exact run.
- **USED-BY-CRUSH:** no — crush never enables `withPreservedNewLines`; enabling it would also flip `defersStreaming()` to buffering
- **STATUS:** NEW

### 6. [NIT] Redundant same-namespace import
- **WHERE:** `src/Renderer.php:5` (`use SugarCraft\Shine\Lang;` inside namespace `SugarCraft\Shine`), same pattern in `src/Theme.php:6`
- **WHAT:** Dead import; `Lang` already resolves unqualified. Cosmetic only.
- **FIX:** Delete the two `use` lines.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW (style nit)

### Checked and clean (probed, not asserted)
- **Hostile input:** unclosed `*emphasis`/`**strong` and unclosed fence render as literal text without throwing; unknown fence language falls through to the plain `codeBlock` style; table alignment with wide chars (`你好`) measures correctly inside the Sprinkles border.
- **SGR hygiene:** no escape leaks past styled runs and no mid-line reset clobbering observed.
- **Control injection:** lone-8-bit and UTF-8 C1s are stripped from text (`stripLoneC1`/`stripControls`, Renderer.php:853-925); the URL channel cannot smuggle raw C1 because CommonMark percent-encodes it — measured `\xC2\x9B` arriving inside an OSC-8 parameter as literal `%C2\x9B`, inert. `withSanitize(false)` + malformed UTF-8 throws `UnexpectedEncodingException` out of `parse()` (Renderer.php:706); crush already wraps this in try/catch (`sugar-crush/src/Renderer.php:3705-3714`), so it's a documented tradeoff, not a defect.
- **Repo gates:** `tools/check-one-type-per-file.php` exits 0; every src file has `declare(strict_types=1)`; all public classes `final`; factories are `::new()`/`::ansi()`/`::base()`-style; bare accessors; zero TODO/FIXME markers (the one `XXXX` hit at Renderer.php:853 is the `<U+XXXX>` marker documentation); all 15 `Lang::t` keys resolve in lang/en.php.
- **Resource lifecycle:** `StreamSink` closes owned handles and never touches adopted ones, `close()` idempotent, `feed()`-after-`close()` throws `LogicException`; `Writer`/`SectionStream` hold no timers, streams, or child processes.
- **Audit items already fixed since the findings file:** syntax-lexer regex caching now lives in candy-core `RegexHighlighter` with `$patternCache` (candy-core/src/Syntax/RegexHighlighter.php:105-106, 187-191), the `&$line` reference loop is gone (SyntaxHighlighter.php:64-73 uses `array_map`), `renderChildren` uses array+`implode` (Renderer.php:1084-1091), and the emoji pattern is a class constant (Renderer.php:66) — all ALREADY-TRACKED-FIXED.

---

# candy-mosaic

**MODE:** linked (`vendor/sugarcraft/*` are symlinks into the monorepo — candy-ansi, candy-buffer,
candy-core, candy-flip, candy-input, candy-palette, candy-pty, candy-testing)
**EXTENSIONS:** gd, imagick, ffi all loaded; this lib exercises only GD (no imagick/ffi code paths
exist in src/), so no backend was untested due to a missing extension. The 5 skipped tests are
platform-gated (`/proc` census and `php -S` startup in SsrfServerLeakTest/ImageSourceSsrfTest),
not extension-gated.
**TESTS:** `OK, but some tests were skipped! Tests: 621, Assertions: 8174, Skipped: 5.` Time
00:57.081, Memory 28.00 MB.
**VERDICT:** A mature, heavily-audited lib with a green suite and strong decoder hardening
(CRC-verified chunk walks, bounded inflate, bounded file reads, offset reconciliation), but its
animated-GIF entry point is currently broken for real-world encoder output: the hand-written clone
of candy-flip's header walk went stale when flip fixed its own desync in `2d5117e1e`, so the
safety gate that was meant to reject hostile GIFs now rejects valid ones.

### 1. [CRITICAL] Stale clone of candy-flip's GIF walk makes `fromAnimatedFile()` reject valid GIFs — FULLY VERIFIED (measured)
- **WHERE:** `src/ImageSource.php:~865` (`gifFlipWalkDescriptorOffsets()`, the `$i = self::flipSkipSubBlocks($bytes, $i + 10, $len)` line; the honest walk's throw verified live at ImageSource.php:800)
- **WHAT:** The clone mirrors flip's *old* image-data skip (start at descriptor+10, i.e. reading the LZW minimum-code-size byte as a sub-block length, no LCT skip). candy-flip's `Decoder::parseHeader()` was fixed in commit `2d5117e1e` ("walk resync") to `$j = $i + 11 + $lctBytes`. Measured on a valid 2-frame 4×4 GIF with 255-byte LZW sub-blocks (what real encoders emit): honest `[789,1065]`, clone `[789]`, real flip `[789,1065]` — the clone disagrees with both, so the offset-equality gate throws "frame decoder disagrees with the container on 2 frame positions" and `fromAnimatedFile()` refuses a legitimate animation. The lib's own fixtures pass only because GD's tiny sub-blocks happen to re-sync the old walk. The doc-comments asserting flip "fails to skip the min-code byte" are now false.
- **FIX:** Update `gifFlipWalkDescriptorOffsets()` to mirror the current flip walk (start at `$i + 11`, skip the LCT sized from the descriptor's packed byte), and re-pin with a fixture carrying ≥255-byte sub-blocks and one carrying an LCT; better, expose flip's offsets and drop the clone.
- **USED-BY-CRUSH:** no (sugar-crush never calls `fromAnimatedFile`; grep of sugar-crush/src shows only Mosaic/ImageSource/ImageLayer)
- **STATUS:** NEW (findings/plan r82 predate flip's resync; the round-4 comments claim byte-exactness that no longer holds)

### 2. [HIGH] `detectImageFormat()` fallback mislabels non-PNG formats as `image/png`, poisoning the format field — verified by code reading (call sites confirmed; not run end-to-end)
- **WHERE:** `src/ImageSource.php:~258` (`return 'image/png'; // dummy` in `detectImageFormat()`)
- **WHAT:** Any format GD's `imagecreatefromstring()` accepts but the magic-byte list misses (BMP, TGA, WBMP) decodes successfully and is stored with `format === 'image/png'` while `bytes` are not PNG. `KittyRenderer::ensurePng()` (KittyRenderer.php:212) and `Iterm2Renderer::render()` (Iterm2Renderer.php:28) both short-circuit on `format === 'image/png'` and pass the raw non-PNG bytes straight into the terminal, kitty declaring `f=100` (PNG) — a silently broken/blank image rather than a throw.
- **FIX:** Return the real detected MIME (or `'application/octet-stream'`) from the fallback, and have `ensurePng()`/iterm2 verify the PNG signature (`\x89PNG`) before passing bytes through instead of trusting the format field.
- **USED-BY-CRUSH:** yes — `Renderer.php:4282` `ImageSource::fromString($bytes)` on model/tool-supplied image bytes, rendered through `Mosaic::render()` on a kitty/iterm2 terminal
- **STATUS:** NEW

### 3. [MEDIUM] Unguarded destination allocation in `resize()` / `Scale::None` render path — verified by code reading (not run end-to-end)
- **WHERE:** `src/ImageSource.php:1595-1600` (`resize()`: `imagecreatetruecolor($w, $h)` before any `guardPixelCount`); `src/Mosaic.php:404-408` (`Scale::None` with null height sets `$w`/`$h` to native *pixel* dims), consumed by `src/Renderer/SixelRenderer.php:124-125` (multiplies cells by 10×20 px each)
- **WHAT:** `resize()` allocates the target canvas before validating it against the pixel ceiling — `fromGd()`'s guard fires only after the allocation, so the check bounds the result, not the peak. `Mosaic::render()` with `Scale::None` and null height feeds native pixel dimensions in as *cell* counts: a 5000×5000 source (inside the 50 MP budget) attempts a 50000×100000 truecolor canvas (~20 GB); GD returns false (loud `gd_resize_failed`) but only after a suppressed-warning-sized allocation attempt, and with a raised memory_limit an OOM-kill is plausible.
- **FIX:** Call `self::guardPixelCount($w, $h, $this->maxPixels)` at the top of `resize()`, and clamp/reject cell dimensions in `Mosaic::render()` before the renderer's pixel-canvas multiplication.
- **USED-BY-CRUSH:** no (crush renders with default scale; `resize()` is not on its call path)
- **STATUS:** NEW

### 4. [MEDIUM] Silent encode failure in `fromGd()` — the `$ok` result is discarded — verified by code reading
- **WHERE:** `src/ImageSource.php:~295` (`$ok = match ($format) { 'image/png' => imagepng(...) ... }` — `$ok` never checked)
- **WHAT:** If `imagepng()`/`imagejpeg()`/`imagegif()` fails (write error, unsupported format on the GD build), the stream is read back empty and an `ImageSource` with zero-length bytes is returned instead of a throw — the "returns a blank image instead of throwing" class of defect, and it feeds every downstream renderer.
- **FIX:** `if ($ok === false) { throw new \RuntimeException(Lang::t('image_source.gd_load_failed', ...)); }` after the match.
- **USED-BY-CRUSH:** indirectly yes — `fromGd` is the back half of `crop()`/`resize()`/`fromRgb()`, which crush's `renderToolImage` path can reach via `Mosaic::render()` scaling
- **STATUS:** NEW

### 5. [MEDIUM] Sixel env-var hints gated behind `XTERM_VERSION` that mlterm/foot never set — verified by code reading
- **WHERE:** `src/Detect.php:497-503` (`hasSixelEnvHints()`: `($xtermVersion !== '') && preg_match('/^(mlterm|foot|xterm(-256color)?)$/i', $TERM)`)
- **WHAT:** The AND requires a non-empty `XTERM_VERSION` even for the mlterm/foot branches, so those terminals only get Sixel via the DA1 round-trip; when stdin isn't an interactive TTY (pipe, daemon, harness) the probe returns null and they silently fall back to half-block. Under-detection is safe (no screen corruption) but costs the pixel path on exactly the terminals the table was written for.
- **FIX:** Split the condition: `mlterm`/`foot` match on `$TERM` alone; keep the `XTERM_VERSION` requirement only for the `xterm*` branches.
- **USED-BY-CRUSH:** yes — `ToolResult.php:337` `Mosaic::auto()` probe feeds `imageProtocol` and the render path
- **STATUS:** NEW

### 6. [LOW → flagged-for-investigation, ~70% confidence] `mintty` mapped to iTerm2 protocol — NOT verified against a live mintty
- **WHERE:** `src/Detect.php:470-477` (`$termProgram === 'mintty'` → `Capability::iterm2(...)`)
- **WHAT:** mintty (Git-Bash default on Windows) supports Sixel, not OSC 1337 inline images. If true, a mintty user gets OSC 1337 sequences that the terminal ignores — images silently absent rather than garbling (unknown OSCs are consumed whole), but the Sixel path it does support is skipped. Confirming needs a Windows/mintty check or upstream mintty docs.
- **FIX:** Move `mintty` from the iterm2 branch to `Capability::sixel(...)` once confirmed.
- **USED-BY-CRUSH:** yes (same `Mosaic::auto()` probe)
- **STATUS:** NEW

### 7. [LOW] Dead private helpers: `countGifFrames()` and `countGifFramesAsFlipWalks()` have no src/ callers — verified by grep
- **WHERE:** `src/ImageSource.php:~700` and `~838`
- **FIX:** Fold the reflection probes in ImageSourceAnimatedSecurityTest onto the offset-list methods directly and delete both wrappers.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW

### 8. [LOW] `Mosaic::$forcedWidth`/`$forcedHeight` are written by the builder but never read — verified by grep
- **WHERE:** `src/Mosaic.php:65-66`
- **WHAT:** `MosaicBuilder::withResize()` threads dimensions into `Mosaic` that `render()` ignores — a caller-visible API that silently does nothing.
- **FIX:** Either apply them in `render()` as the default cell box or drop the fields and the `withResize()` plumbing.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW

### 9. [NIT] `DiskCache::getOrCompute()` `get*` name (src/DiskCache.php:156) — this is a cache read-through, not a bare accessor, so the repo's no-`get` rule arguably doesn't bite; noted for completeness. `Animation`'s class docblock has two mis-indented continuation lines (src/Animation.php:28-33 region). Both optional.

### Positives and gate results
- **CI gates pass:** `tools/check-one-type-per-file.php` exits 0 for this lib; every src/ file has `declare(strict_types=1)`; every public class is `final`; no `::create()/::make()/::default()`; no missing `Lang` keys.
- **Decoder hardening is genuinely strong where it isn't stale:** APNG walk verifies CRCs, rejects non-ASCII chunk types before they reach exception strings, bounds `gzuncompress` to the exact expected scanline length (bomb and truncation both refused), reconciles `acTL` against real frames, and hard-caps dimensions at 16384 to dodge the uint32-multiply overflow. Every GIF/PNG walk traced terminates (sub-block skips always advance ≥1 or return; unknown block types throw or step).
- **Resource handling:** GD images destroyed on all paths (finally or immediate), Sixel/Kitty encode through `php://temp` with guaranteed `fclose`, ChafaRenderer's scratch file is hash-reused and unlinked at shutdown, DiskCache writes are atomic with stale-temp sweeping, and the Sixel encoder guarantees the ST terminator so a mid-stream consumer failure never strands the terminal inside a DCS.
- **Capability detection fails safe:** `Mosaic::auto()` never throws and lands on half-block; crush memoizes it once (ToolResult.php:337), so the probe's TTY round-trips cost one startup.
- **SSRF/scheme/redirect machinery** on the URL paths is thorough (per-hop re-validation, all-resolved-IP judging, IPv4-mapped unwrap, fail-closed on unresolvable hosts, CRLF header rejection) and the residual TOCTOU is honestly documented rather than hidden.

**Fix priority: #1 first** — it's a live functional regression on the animated-GIF path caused by
a cross-lib drift, and it's the one finding with a measured repro.

---

# candy-fuzzy

**MODE:** published — `vendor/sugarcraft/` does not exist; the lib declares zero sibling runtime
deps (composer.json requires only `php ^8.3` + phpunit-dev), so neither symlinked nor published
applies; the run is not subject to the Packagist-vs-symlink ambiguity.
**TESTS:** `OK (846 tests, 22235 assertions)` in 00:01.198, Memory 14.00 MB (PHPUnit 10.5.64, PHP
8.3.6) — no hangs.
**VERDICT:** Healthy lib — the classic byte-vs-codepoint index defect is fixed and verified
(CharFold keeps folded arrays 1:1 with original code points; measured `İstanbul大阪abc` → indices
[10,11,12], Highlighter output correct; dirty/out-of-range indices are normalized safely), all CI
convention gates pass, and the remaining issues are a documented-too-gently DoS-cap semantic
cliff, per-keystroke cost on the crush palette path, and minor API traps.

### 1. [MEDIUM] Cap-boundary delegates not just the score scale but the match semantics (match → null flip)
- **WHERE:** `src/Matcher/SmithWatermanMatcher.php:255-257` (compute) and `:122-124` (score), fallback at `:476-479`
- **WHAT:** At 991 chars a candidate is scored by Smith-Waterman local alignment (a partial query alignment scores and is returned); at 1001 chars the same content is delegated to SahilmMatcher, which requires every query char in order and returns null. Measured: query `bq` vs candidate `"x"×990+"b"` → score 3; vs `"x"×1000+"b"` → null. The class docblock (:26-33) documents only that "scores … are on a different scale … can rank inconsistently" — it does not state that the fallback is a different matcher contract (full-subsequence vs local alignment), so a candidate can flip between present and absent from a filtered list by growing one character.
- **FIX:** In the class DoS note, state the match→null flip explicitly; longer term, make the fallback degrade within the Smith-Waterman contract (e.g. truncate the candidate to `maxCandidateLength` and run the two-row `score()` path with empty indices) instead of swapping in SahilmMatcher.
- **USED-BY-CRUSH:** yes — `sugar-crush/src/Chat.php:16495` (`paletteMatchResults`) and `sugar-crush/src/Commands/CommandRegistry.php:421`; reachable via a >1000-char palette label, e.g. a user-named long session (`/rename` stores free text).
- **STATUS:** NEW (the scale caveat is documented; the semantic flip is not)

### 2. [MEDIUM] Per-keystroke quadratic path has no time bound and no caching on the crush picker hot path
- **WHERE:** `src/Matcher/SmithWatermanMatcher.php:246-334` (compute), caps default 1000 at `:52-54`
- **WHAT:** The DoS cap bounds memory per call to ~1000×1000 but not time: measured one at-cap `match()` call = 1012 ms / +40 MB (two full PHP nested-array matrices, ~2×10⁶ cells). `matchAll` has no aggregate budget, so a pasted ~1000-char query in the palette re-runs up to that per candidate. Realistic figure: 2000 candidates × 60 chars, 4-char query = 312 ms per `matchAll` call, and crush constructs a fresh matcher and re-runs it on every keystroke (`Chat.php:16495`, `CommandRegistry.php:421`) — sub-second but visible input lag on a large label set, and a hard freeze on the long-query path.
- **FIX:** Lower the default `maxQueryLength`/`maxCandidateLength` (a 300×300 matrix is ~90k cells vs 1e6), or add a row-count budget to `matchAll` that delegates once cumulative quadratic work passes a threshold; crush-side, memoize per (query, label) since labels are stable across keystrokes.
- **USED-BY-CRUSH:** yes — `Chat::paletteMatchResults()` and `CommandRegistry::filterMatchResults()`, both per keystroke in the interactive picker
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT (findings/candy-fuzzy.md §2.1 tracked the matrix memory; the cap + two-row `score()` landed since, but the time bound and per-keystroke re-run it names as "consider" remain open — carry forward its recommendation: bound/optimize the quadratic path)

### 3. [MEDIUM] `matchAll` reports partial alignments as matches with no coverage mode; the two crush call sites compensate differently
- **WHERE:** `src/Matcher/SmithWatermanMatcher.php:186-207` (matchAll), behavior at `:246-334`
- **WHAT:** Any query sharing one character with a candidate produces a hit: measured `matchAll("bxz", ["abcdef"])` → 1 result, score 3, indices [1] (only "b" aligned). This is correct local alignment and `compute()` says so, but the lib offers no "all query chars must align" mode, so each consumer re-implements the guard: `CommandRegistry.php:423` filters on `count($matchedIndices) !== $length || indices[0] !== 0`, while `Chat::paletteMatchResults()` (`Chat.php:16495`) applies nothing — so the Ctrl+P palette lists any label containing the query's first-ish char.
- **FIX:** Add a `requireFullQuery(): bool` option (or a `matchAllStrict()` variant) to `SmithWatermanMatcher` that drops results whose traceback indices are shorter than the query, and have both crush call sites use it instead of hand-rolled filters.
- **USED-BY-CRUSH:** yes — `CommandRegistry::filterMatchResults` (guarded), `Chat::paletteMatchResults` (unguarded, the leaky one)
- **STATUS:** NEW (crush's own comment at CommandRegistry.php:417-420 documents the behavior as a workaround; the lib-side gap is untracked)

### 4. [LOW] `FuzzyMatcherFactory::create` silently discards the injected profile for `'sahilm'`
- **WHERE:** `src/Matcher/FuzzyMatcherFactory.php:29-34`
- **WHAT:** `create('sahilm', $strictProfile)` returns a default-scored SahilmMatcher with no signal; the omission is documented in the docblock (:20-23) but a caller tuning scoring through the factory gets silently un-tuned results. The unknown-name case correctly throws, so the "silent null" concern is clean here.
- **FIX:** Throw `InvalidArgumentException` when a non-null `$profile` is passed with `'sahilm'`, rather than ignoring it.
- **USED-BY-CRUSH:** no — crush constructs `new SmithWatermanMatcher()` directly
- **STATUS:** NEW

### 5. [LOW] `ScoringProfile::default()` conflicts with the repo factory-naming rule
- **WHERE:** `src/ScoringProfile.php:59-62`; also `FuzzyMatcherFactory::create` at `src/Matcher/FuzzyMatcherFactory.php:29`
- **WHAT:** AGENTS.md bans `::create()`/`::make()`/`::default()` as root factories. `ScoringProfile::default()` is a named preset (alongside `strict()`/`lenient()`) rather than a root constructor — `new()` exists and forwards — so this reads as intentional, but it is the literal banned spelling and is the ecosystem scoring SSOT (aliased by `candy-lister/src/ScoringProfile.php:19`), so any rename propagates to candy-lister and the `SugarCraft\Forms\Fuzzy` shim. Flagging rather than demanding: no CI gate enforces this, and `Theme::ansi()` sets the named-preset precedent.
- **FIX:** If renamed, use a non-banned spelling (`ScoringProfile::canonical()`), update the class_alias in candy-lister and all `::default()` callers in one PR.
- **USED-BY-CRUSH:** yes — `ScoringProfile::default()` is the implicit profile behind `Chat.php:16495` / `CommandRegistry.php:421` via `SmithWatermanMatcher`'s constructor
- **STATUS:** NEW

### 6. [LOW] `SmithWatermanMatcher::new()` cannot set the DoS caps the constructor exposes
- **WHERE:** `src/Matcher/SmithWatermanMatcher.php:62-65` vs `:48-60`
- **WHAT:** `new(?ScoringProfile)` forwards only the profile; callers following the repo's named-constructor convention cannot reach `maxQueryLength`/`maxCandidateLength` without dropping to `new self(...)`, which nudges crush-style callers toward the default 1000-char cap even when issue #2 argues for a lower one.
- **FIX:** Promote the two cap parameters onto the static `new()` factory with the same defaults.
- **USED-BY-CRUSH:** no (crush uses the constructor defaults)
- **STATUS:** NEW

### 7. [LOW] SahilmMatcher scoring constants remain hardcoded (enhancement, tracked)
- **WHERE:** `src/Matcher/SahilmMatcher.php:25-31`; CALIBER_LEARNINGS.md "Future Enhancements"
- **WHAT:** MATCH_SCORE/CONSECUTIVE_BONUS/SEPARATOR_BONUS/CAMEL_BONUS/FIRST_CHAR_BONUS/LOWER_CASE_BONUS are private constants with no tuning surface; the Smith-Waterman side got `ScoringProfile`, the Sahilm side did not. Not a defect — the case-sensitive-bonus behavior the old finding 1.2 flagged is now documented at SahilmMatcher.php:38-44, and the `$prevCharLower` rename (finding 1.3) is done.
- **FIX:** Per CALIBER_LEARNINGS: a constructor config object with defaults preserving current output.
- **USED-BY-CRUSH:** no (crush uses SmithWatermanMatcher; Sahilm runs only as the over-cap fallback)
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT

### Checked and clean
No TODO/FIXME/XXX/HACK in `src/` or `tests/`; no commented-out code; `declare(strict_types=1)` in
all 9 src files; all public classes `final` (`FuzzyMatcher` is the extension contract);
`tools/check-one-type-per-file.php` exits 0 across the repo; no `get*` accessors; the old findings
§1.1 "dead code" at Highlighter.php is now a live, necessary guard after the out-of-range-index
normalization added at Highlighter.php:34-49, and findings §3.1/3.2/3.3/4.1/4.2 (generator,
factory, sorter, Closure type, memory doc) are all ALREADY-TRACKED-FIXED. Façade note:
`candy-lister`'s `ScoringProfile` is a `class_alias` into this namespace
(candy-lister/src/ScoringProfile.php:19), so issues #5/#7 propagate there by design — the alias
itself resolves correctly.

---

# candy-forms

**MODE:** vendor symlinked (candy-forms/vendor/sugarcraft/* are symlinks into the monorepo siblings)
**TESTS:** `OK (2201 tests, 4030 assertions)`, 3.4s — green
**VERDICT:** The lib is in good shape overall — strict_types, finality, one-type-per-file and
factory-naming gates all pass and most round-90 findings are fixed — but the charLimit paste-DoS
guard is bypassable in both text fields and the Field-level `isHidden()` contract is unenforced by
Form, so two advertised guarantees do not hold.

### 1. [HIGH] charLimit overflowed by multi-character insert / paste
- **WHERE:** `src/TextInput/TextInput.php:988` (guard) vs `:986` insert(), reached from paste() at `:939`; `src/TextArea/TextArea.php:776` (guard) vs `:774` insert(), reached from insertString() at `:711`
- **WHAT:** Both insert paths check `length() >= charLimit` *before* appending, then concatenate the whole incoming string. A paste of N characters arriving at length limit−1 yields limit−1+N characters, so one clipboard action blows arbitrarily far past the cap. This defeats the paste-DoS guard the `new()` docblocks claim (TextInput.php:118-124, TextArea.php:112-118: default 4096 / 65536); `setValue()` clamps correctly via `mb_substr`, so the bug is exclusive to the interactive insert path. Tests pin the default limit value (tests/TextInput/TextInputTest.php:130-131) but nothing pins overflow behavior — no test would catch this.
- **FIX:** In `TextInput::insert()` and `TextArea::insert()`, when `charLimit > 0` truncate the incoming string to the remaining budget (`mb_substr($rune, 0, $charLimit - $currentLen, 'UTF-8')`) and return `$this` only when the remaining budget is zero, instead of rejecting the whole multi-char payload on a pre-check.
- **USED-BY-CRUSH:** yes — Chat.php drives TextArea through `insertString()`/`PasteMsg` (Chat.php:2392-2423), though that host sets `withCharLimit(0)` (Chat.php:15770) so it is self-immunized; sugar-bits and candy-shell TextInput/TextArea consumers keep the default caps and are exposed.
- **STATUS:** NEW

### 2. [HIGH] Field-level `isHidden()` is a contract lie — Form never honors it
- **WHERE:** `src/Field.php:77-86` (contract text) vs `src/Form.php` (no `$f->isHidden()` call anywhere; only `skippable()` gates at Form.php:565, 931, 981, 1026)
- **WHAT:** The `Field` interface promises "the form skips fields whose `isHidden(values)` returns true; both navigation and the values collector treat them as if they didn't exist." Form only checks `Group::isHidden()` (Form.php:538, 925, 975, 1020, 1195) — a field attached via `withHideFunc()` (HasHideFunc.php) still receives keystrokes, still appears in `values()`/`validateAll()`/the submit gate, and its stale value is committed. tests/FormTest.php:307-308 asserts the predicate evaluates but never asserts the Form acts on it, so the suite is green on an unenforced contract.
- **FIX:** In `Form::valueWalk()`, `errors()`, `validateAll()`, `validateAsync()`, and `firstNonSkippable()` (Form.php:565, 931, 981, 1026, 1213), skip fields whose `isHidden($accumulated)` returns true, mirroring the group-level progressive convention; add a FormTest pinning that a hidden field's value is excluded from `values()`.
- **USED-BY-CRUSH:** no — sugar-crush consumes only ItemList/TextArea/Item/LoadMoreMsg, which do not route through Form; the blast radius is sugar-bits/candy-shell/sugar-prompt form hosts.
- **STATUS:** NEW

### 3. [MEDIUM] TextArea cursor row never scrolls into view
- **WHERE:** `src/TextArea/TextArea.php:71` (rowOffset), `:266-268` (view slice), `:1044-1048` moveCursor — no navigation or edit path writes rowOffset except `reset()` (`:390`)
- **WHAT:** `rowOffset` is only ever set to 0; arrow keys, Enter-splitting and paste all move `row` but never pan the window, so once `row >= rowOffset + height` the caret row falls outside the `array_slice` window and the cursor renders off-screen while the user keeps typing blind. Hosts that call `TextArea::view()` in a bounded-height box hit this; sugar-crush is immune because Chat paints its own renderer and never calls `view()` (Chat.php:15762-15768).
- **FIX:** Add a private `panToCursor(): self` using the same `ViewportPan::offsetFor($row, $rowOffset, $height)` math ItemList already uses (src/Util/ViewportPan.php), called from `moveCursor`, `insertNewline`, `backspace` (line-merge) and `insertString`.
- **USED-BY-CRUSH:** no (Chat bypasses `view()`); yes for sugar-bits/candy-shell hosts that render TextArea directly.
- **STATUS:** NEW

### 4. [MEDIUM] `Form::accessibleView()` array-to-string on MultiSelect
- **WHERE:** `src/Form.php:467`
- **WHAT:** `(string) $field->value()` — a focused MultiSelect returns `array<string>`, so accessible mode emits an "Array to string conversion" warning and renders the literal "Array" for screen-reader users exactly on the field types accessible mode exists to serve. No test covers accessible view with an array-valued field.
- **FIX:** Reuse the `getString()` coercion (Form.php:723-749, which already implodes arrays with `, `) instead of the raw cast.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW

### 5. [LOW] `scheduleAsyncSuggestions()` mutates `$this` inside `update()`
- **WHERE:** `src/Field/Input.php:582`, `src/Field/Select.php:386`
- **WHAT:** `++$this->pendingAsyncSeq` writes to the *old* field instance from within the TEA `update()` path — an in-place mutation of an object the immutable-model contract says is frozen. Harmless today only because the counter has no reader (both docblocks disclose this), but any future seq-gating that reads it off the rebuilt instance will see a value that diverges depending on which snapshot `update()` was called from.
- **FIX:** Thread the increment through the same `carryNonCtorState(new self(...))` rebuild that already carries `pendingAsyncCancellation` (Input.php:560, Select.php:356), or delete the field until Phase-6 seq gating actually consumes it.
- **USED-BY-CRUSH:** no (Form/Input/Select field wrappers are unconsumed by crush)
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT (findings/candy-forms.md Critical #1, plan Phase 1.1 — the plan's original fix was superseded by documentation in round-90; the mutation itself remains)

### 6. [LOW] `Viewport::update()` wheel match has no default arm
- **WHERE:** `src/Viewport/Viewport.php:93-99`
- **WHAT:** `match ($msg->button)` lists only WheelUp/WheelDown. Unreachable via candy-core's `InputReader` (only those two buttons ever construct `MouseWheelMsg`, InputReader.php:701-703), but a host or test that builds a wheel message with any other button gets an `UnhandledMatchError` fatal inside update() instead of a dropped event.
- **FIX:** Add `default => [$this, null]` to the match.
- **USED-BY-CRUSH:** no (crush does not embed Forms\Viewport; sugar-bits/candy-shell do)
- **STATUS:** NEW

### Checked and clean
The round-90 audit trail is real — the duplicate `Field/Field.php` is gone, `hasErrors()` now
filters empty strings (Form.php:940), `Confirm::withValidator` routes through `mutate()`
(Confirm.php:85), the `@scandir` silence and per-keystroke matcher allocation are fixed, and
`RenderSafe::clean` at display sites is consistently applied. The `get*` accessor family
(`getTitle`, `getWidth`, `getFocusedField`) deviates from the repo's bare-accessor rule but is
pinned by the `Field` interface contract itself, so it reads as a deliberate, documented deviation
rather than drift.
**Unaudited:** Viewport (lines 300-583), MultiSelect, Text, FilePicker (both widgets), Date,
Color, Slider, Note, Confirm (tail), KeyMap, Group, Theme, Validator/*, Vim/*, Scrollbar/*,
Spinner/*, lang/ locale parity.

---

# sugar-veil

**MODE:** symlinked (`vendor/sugarcraft/*` are symlinks into the monorepo siblings)
**TESTS:** `OK (217 tests, 454 assertions)`, PHPUnit 10.5.64 on PHP 8.3.6, 0.153s
**VERDICT:** The renderer core (compositing, clipping, diff session, animations) is sound and
well-tested, and the prior audit's findings are almost all fixed — but the click-outside hit-test
state is silently destroyed by any `with*()` call made after `scan()`, which is a real
trust-boundary defect for a modal library, and an empty background makes an overlay vanish without
touching session state.

### 1. [HIGH] `mutate()` discards the scanned scanner — any `with*()` after `scan()` silently invalidates hit-testing while the "unscanned" guard stays muted
- **WHERE:** `src/Veil.php:667` (`scanner: $scanner` in `mutate()`), constructor fallback at `src/Veil.php:118`, guard at `src/Veil.php:322-333`
- **WHAT:** `scan()` (src/Veil.php:350-354) attaches a fresh scanned `Scanner` to the returned clone, but every other `with*()` routes through `mutate()`, which passes `scanner: null` (no `scannerSet` sentinel), and the constructor then substitutes a virgin `Scanner::new()`. `lastRendered` *is* carried forward, so `isClickOutside()`'s RuntimeException guard for "forgot to scan" does not fire — the call instead answers with an empty zone set. Reproduced live: `$scanned->isClickOutside($click)` → `false` (inside), `$scanned->withZIndex(5)->isClickOutside($click)` → `true` (outside) for the identical click. For a veil with `clickOutsideDismiss` on, a model change between render and click (e.g. `withContent()` when the dialog body updates) makes a click *on* the modal read as a click *outside* it — the overlay is dismissed, or an outside click is absorbed, depending on direction.
- **FIX:** In `mutate()`, carry the current scanner forward unless `scan()` supplies one: `scanner: $scanner ?? $this->scanner` (the constructor's `?? Scanner::new()` then only serves fresh instances), and add a regression test that a `with*()` after `scan()` preserves hit results.
- **USED-BY-CRUSH:** no — sugar-crush never calls `Veil::scan()`/`isClickOutside()`; its permission prompt routes keys through `Chat::update()` and its zone hit-testing uses its own frame-level `Renderer::scanner()` (sugar-crush/src/Renderer.php:1299, sugar-crush/src/Chat.php:6518). The bug is latent for crush but live for any consumer using the lib's advertised click-outside-dismiss primitive.
- **STATUS:** NEW (findings/sugar-veil.md Finding 5 concerned the old in-place-mutation scanner, since replaced; this reset-on-mutate path is not covered there)

### 2. [MEDIUM] Empty/zero-width background makes the overlay vanish and leaves the diff session stale
- **WHERE:** `src/Veil.php:476-477` (`if ($bgHeight === 0 || $bgWidth === 0) return $background;`)
- **WHAT:** `composite()` returns the background untouched when it is empty — the foreground overlay (a dialog, a permission prompt) is not painted at all, not even as a full-screen dim. Worse for a session-reusing consumer: the early return skips `shouldEmitFull`/`rememberFull`/`diff` entirely, so if the terminal painted the empty frame, the next non-empty composite diffs against the stale `previousOutput` from two frames ago and emits a delta that no longer matches the screen. sugar-crush dodges this only because it builds a fresh Veil per render (sugar-crush/src/Renderer.php:1713-1717) and pads the backdrop to ≥1 (`padForOverlay`); a consumer that reuses one Veil for frame diffing is exposed.
- **FIX:** When the background is empty, still run the session bookkeeping (treat it as a full-frame emit of the overlay at 0,0, or at minimum call `$this->session->reset()` before the early return) so the next composite cannot diff against a frame the terminal never saw.
- **USED-BY-CRUSH:** indirectly yes — `Veil::composite()` at sugar-crush/src/Renderer.php:1739 and sugar-crush/src/Tui/Components/AgentDashboardPane.php:252; neither can hit it today because the backdrop is always non-empty, but both rely on that guard holding.
- **STATUS:** NEW

### 3. [LOW] `Position` offset docblocks claim "pixel" space; the space is terminal cells
- **WHERE:** `src/Position.php:27-29` and `:47-49` ("Resolve the vertical/horizontal pixel offset"); composer.json description repeats "optional pixel offsets"
- **WHAT:** `yOffset`/`xOffset` return row/column counts, and sugar-crush consumes them as cells when re-deriving overlay placement for zone restore (`sugar-crush/src/Renderer.php:5536-5547`, `overlayLeftShift` at :5679-5695). The "pixel" wording is an upstream-port artifact (bubbletea-overlay is pixel-based); a future consumer trusting it could divide by a cell size that does not exist.
- **FIX:** Reword both docblocks and the composer description to "cell/column-row offset".
- **USED-BY-CRUSH:** yes — `Position::CENTER->xOffset()/yOffset()` re-implemented in sugar-crush/src/Renderer.php (`liftZonesUnderOverlay`, `restoreLiftedZones`, `overlayLeftShift`), so the arithmetic is load-bearing there even though the wording is not.
- **STATUS:** NEW

### 4. [LOW] `penFromSgr` masks truecolor components with `& 0xFF` instead of clamping
- **WHERE:** `src/Veil.php` (in `penFromSgr`, the `38;2` branch: `($codes[$i + 2] & 0xFF) << 16 …`)
- **WHAT:** An out-of-range SGR component (e.g. `38;2;300;0;0`) wraps to 44 rather than clamping to 255 as xterm does, so the diff pen stamps a different color than the terminal paints; the next frame's cell comparison then either diffs a cell that didn't change or misses one that did. Input is the lib's own rendered output so it's unlikely in practice, but it's a parse-of-hostile-content path (`composite()` output flows from model-authored text in crush).
- **FIX:** `min(255, max(0, $codes[$i + $k]))` per component, matching how candy-buffer's private `styleFromSgr` treats it (or clamp-and-verify parity, since the docblock at Veil.php claims to mirror it).
- **USED-BY-CRUSH:** yes, transitively — every `composite()` delta path.
- **STATUS:** NEW

### 5. [LOW] `RenderSession::justClearedFrame` is unreachable through `composite()`
- **WHERE:** `src/RenderSession.php:40, 58-63, 84-90`
- **WHAT:** `rememberFull()` only sets `justClearedFrame` when `diffWasCalled && sameDimensions`, but every `rememberFull()` call inside `composite()` is preceded by `shouldEmitFull()` returning true, whose dimension-change branch already clears `diffWasCalled` and whose reset path clears both flags — so within the library the one-shot grant can never arm, and the flag's careful comment describes a state machine only a direct `RenderSession` user can enter. The logic is correct today but is complexity with no reachable witness; no test in tests/RenderSessionTest.php exercises the flag arming.
- **FIX:** Either drop the flag (the dimension + null checks already force a full frame) or add a test that drives `shouldEmitFull`/`diff`/`rememberFull` directly to pin the one-shot semantics.
- **USED-BY-CRUSH:** no (fresh Veil per render, session diffing never engages).
- **STATUS:** NEW

### 6. [LOW] Animations split lines with raw `explode("\n")`, disagreeing with `Veil::splitLines()` on trailing newlines
- **WHERE:** `src/Animation/Slide.php:66`, `src/Animation/Scale.php:52`
- **WHAT:** A foreground ending in `"\n"` counts one extra (empty) line in `Slide`'s height and `Scale`'s reveal budget, while `composite()` measures the same content without it — so a slide/scale's travel distance and line reveal are off by one row for trailing-newline content. Cosmetic, one-row magnitude.
- **FIX:** Reuse `Veil::splitLines()` semantics (or move it to a shared helper) in both animation classes.
- **USED-BY-CRUSH:** no (crush composites without animations).
- **STATUS:** NEW

### 7. [LOW] `VeilStack::maxZIndex()`/`minZIndex()` return 0 for an empty stack, conflating "empty" with z-index 0
- **WHERE:** `src/VeilStack.php:193-213`
- **WHAT:** A consumer picking the top overlay via `maxZIndex()` cannot distinguish "no veils" from "a veil at z 0" (the default for every `Veil::new()`), so an empty-stack guard written as `if (maxZIndex() > 0)` silently misbehaves the moment one default veil is added. `isEmpty()` exists but the API invites the numeric check.
- **FIX:** Return `?int` (null when empty) or document the sentinel loudly.
- **USED-BY-CRUSH:** no (VeilStack is not referenced anywhere in sugar-crush/src — only `Veil` and `Position` are).
- **STATUS:** NEW

### 8. [LOW] `Fade` animation remains a visual no-op
- **WHERE:** `src/Animation/Fade.php:47-52`
- **WHAT:** `apply()` returns the foreground unchanged; `opacity()` (src/Animation/Fade.php:60-69) is never called from `Veil::applyAnimation()` (src/Veil.php:395-397), so a `withAnimation(AnimationKind::FADE)` veil pops in at full opacity. The class and method docblocks now state this prominently (the plan's Option A), so it is documented rather than silent — but the animation enum case still promises an effect the library does not deliver.
- **FIX:** The plan's Option B (map `opacity()` onto a supported SGR dim during progress < 1) remains the open path; alternatively drop FADE from `AnimationKind` until it renders something.
- **USED-BY-CRUSH:** no.
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT (findings/sugar-veil.md Finding 2; plan names "document prominently (Option A, done) or implement SGR alternative (Option B, not done)")

### 9. [NIT] Dead constructor body and stale docblock fragments
- **WHERE:** `src/RenderSession.php:44-47` (empty `__construct()`); `src/Veil.php:344-348` (`scan()` docblock says "Scanner::scan() replaces zones wholesale, so reusing the shared instance would smuggle hidden state" — true, but it now also *discards* the scanned state on the next mutate, see issue 1)
- **WHAT:** The empty constructor adds nothing (property defaults suffice); the scan docblock's guarantee reads as the opposite of the actual failure mode.
- **FIX:** Remove `RenderSession::__construct()`; amend the `scan()` docblock when fixing issue 1.
- **USED-BY-CRUSH:** no.
- **STATUS:** NEW

### Checked and clean
`declare(strict_types=1)` in every src and test file; all public classes `final`, both enums are
unit enums, one type per file (`tools/check-one-type-per-file.php` exits 0, "60 libs, 2140 psr-4
files, 0 problems"); `::new()` factories and bare accessors throughout, no `get*`/`::create`/
`::make`/`::default`; `mutate()` uses the paired `…Set` sentinel idiom correctly for every
nullable field *except* the scanner (issue 1); no streams, timers, or child processes anywhere in
src, so the lifecycle-leak surface is nil; `RenderSession::release()` (the plan's Finding 4 fix)
exists. Findings 1, 3, 4, 5, 6, 7, 8, 9 from findings/sugar-veil.md are all verifiably fixed in
the current source (single docblock on `dimLine()` at src/Veil.php:571, RuntimeException on
unscanned at :325-330, `release()` at RenderSession.php:157, fresh-scanner `scan()`, Manager shim
gone, `compositeAll` docblock accurate, `$line[0] === "\e"` without `isset`).

---

# candy-pty

**MODE:** linked (`vendor/sugarcraft/*` are symlinks into the monorepo)
**TESTS:** 670 tests, 1885 assertions, 14 skipped, OK (54.6s, HangWatchdog silent). The 14 skips
are NOT the FFI gate — ext-ffi, ext-pcntl, `/dev/ptmx` and `/bin/bash` are all present on this
host, so `requirePtySyscalls()` skipped nothing; the skips are optional-dependency pins (candy-vcr
autoloadable, Darwin-only cases, per-binary probes).
**CHILD-LIFETIME-GATE:** pass — exit 0, "7 findings, 7 accounted rows, 0 problems"; both candy-pty
roster rows (`candy-pty/src/Spawn.php::proc`, `candy-pty/src/Posix/PosixProcess.php::spawn`) are
accounted for.
**DIRECT-REQUIRE:** no — `sugarcraft/candy-pty` is in sugar-crush's `require-dev` only
(composer.json:59), yet `src/Tools/Concerns/CapturesProcessOutput.php:398` uses `Pty::open()` from
production `src/`. It resolves in practice only because candy-core hard-requires candy-pty, and
the `class_exists` probe (ProcessContainment.php:469) degrades the feature to a silent refusal
rather than a crash — so the blast radius is a missing feature, not a fatal, but the direct use
without a direct require is a real latent hazard and should be a one-line `require` bump.
**VERDICT:** The modern Posix backend (`PosixPtySystem`/`PosixMasterPty`/pumps) is in good shape —
the old audit's criticals (EINTR, dup-leak, missing shim, `fd()` on the contract, encoding
artifact) are verifiably fixed — but the deprecated `Pty` facade that sugar-crush actually calls
is a stale, leaky parallel implementation, and the dependency is mis-declared as dev-only.

### 1. [CRITICAL] Legacy `Pty::open()` omits FD_CLOEXEC on the master fd — the exact leak PosixPtySystem documents as fatal
- **WHERE:** `src/Pty.php:41-69` (open(); zero fcntl/CLOEXEC occurrences in the file), vs `src/Posix/PosixPtySystem.php:69-77`
- **WHAT:** PosixPtySystem:70-77 documents that without `fcntl(F_SETFD, FD_CLOEXEC)` the child inherits the master fd across proc_open, the kernel's master-side refcount never drops to 0 when the parent closes, and `tty_hangup()`/SIGHUP never fires. `Pty::open()` — the class sugar-crush's interactive capture calls at CapturesProcessOutput.php:398 with `controllingTerminal:true` — never sets it, so every sugar-crush interactive tool run leaks one master fd per spawn into the child session and defers hangup; over a long REPL session this trends toward fd exhaustion, which is precisely the failure mode this audit was asked to check.
- **FIX:** Route `Pty::open()` through `PosixPtySystem::open()` (or copy the cloexec set + `requireCloexec` guard at PosixPtySystem.php:70-77) so the facade cannot diverge from the canonical path.
- **USED-BY-CRUSH:** yes — `sugar-crush/src/Tools/Concerns/CapturesProcessOutput.php:398` (`Pty::open()`), `ProcessContainment.php:469` (`class_exists` probe)
- **STATUS:** NEW (findings/candy-pty.md flagged the Pty/PosixPtySystem duplication only as "O_RDWR/oNoCtty" Medium; the cloexec divergence was not identified)

### 2. [HIGH] `Pty::spawn()` resizes before the slave is opened — the ordering PosixSlavePty documents as wrong, and open-time geometry is never applied
- **WHERE:** `src/Pty.php:85-87` (resize precedes Spawn::proc), vs `src/Posix/PosixSlavePty.php:57-66` and `src/Posix/PosixPtySystem.php:129-141`
- **WHAT:** PosixSlavePty:57-66 states macOS xnu zeroes the winsize on every fresh slave-fd open, so the resize must land AFTER proc_open; Pty::spawn does it before, so with `controllingTerminal:true` (sugar-crush's mode) the requested 80×24 can be clobbered by the spawn itself. Additionally `Pty::open()` never resizes at open (PosixPtySystem.php:129-141 does), so a `Pty` used without spawn reports the kernel's 0×0 default.
- **FIX:** Move the resize to after `Spawn::proc()` returns (mirroring PosixSlavePty::spawn's post-spawn best-effort block) and apply the default geometry in `Pty::open()`.
- **USED-BY-CRUSH:** yes — CapturesProcessOutput.php:399-406 (`$pty->spawn(..., 80, 24, true)`)
- **STATUS:** NEW

### 3. [HIGH] `PosixMasterPty::close()` leaks the master fd when `fclose()` of the stream fails
- **WHERE:** `src/Posix/PosixMasterPty.php:239-247` vs `:338`
- **WHAT:** If `@\fclose($stream)` returns false, close() throws at :239 before the libc `close($this->fd)` at :338 ever runs — the original posix_openpt fd is never released while `$this->closed` is already true, so no retry path exists. On a long-running consumer that materialises streams and hits a failed fclose, this is one leaked pty master per occurrence.
- **FIX:** Wrap the fclose branch so the throw is deferred (or the libc close + anchor release run in a `finally`) — close() must reach :338 on every path.
- **USED-BY-CRUSH:** yes — every `$pty->read()/write()` on the facade goes through the stream path before `$pty->close()` (CapturesProcessOutput.php:476 finally)
- **STATUS:** NEW

### 4. [MEDIUM] Silent failure: a failed libc close on the stream path is swallowed while `isClosed()` still reports true
- **WHERE:** `src/Posix/PosixMasterPty.php:344` (`if ($rc !== 0 && !$usedStream)`)
- **WHAT:** When a stream was materialised, `close()` discards a non-zero `libc::close($this->fd)` rc — the master fd can stay open (EBUSY/EINTR-class edge) yet the object claims closed, so callers that count on close() as the hangup trigger proceed with a live kernel pty.
- **FIX:** Surface the rc on the stream path too (log or throw), rather than restricting the check to `!$usedStream`.
- **USED-BY-CRUSH:** yes — same close() path as issue 3
- **STATUS:** NEW

### 5. [MEDIUM] sugar-crush declares candy-pty as require-dev while using it from production src/
- **WHERE:** `sugar-crush/composer.json:59` vs `sugar-crush/src/Tools/Concerns/CapturesProcessOutput.php:398`
- **WHAT:** The interactive-capture feature ships enabled only by candy-core's transitive `dev-master` pin (candy-core/composer.json:37); a future candy-core drop, or a consumer installing sugar-crush with `--no-dev` plus a flattened lock, silently degrades it to the "pty unavailable" refusal at ProcessContainment.php:469.
- **FIX:** Move `sugarcraft/candy-pty` into sugar-crush's `require` block (a `require` bump only — no path-repo entry, per the path-repo-closure convention).
- **USED-BY-CRUSH:** yes — the dependency declaration itself
- **STATUS:** NEW

### 6. [MEDIUM] `ext-posix` used unconditionally but not declared
- **WHERE:** `src/Posix/PosixChild.php:31` and `src/Posix/PosixProcess.php:126` (`posix_kill()` — no function_exists guard, unlike posix_isatty/posix_strerror elsewhere); composer.json require block has `ext-ffi` only
- **WHAT:** On a PHP build without ext-posix, `kill()` is a fatal "undefined function" at the moment a caller tries to terminate a wedged child — the worst possible time.
- **FIX:** Add `"ext-posix": "*"` to composer.json require, or guard `posix_kill` with a fallback as the other posix_* call sites are guarded.
- **USED-BY-CRUSH:** yes — sugar-crush's reaper ladder calls `$child->kill()`-equivalents via terminatePid on stuck children
- **STATUS:** NEW

### 7. [MEDIUM] `PosixPump` can stall `pendingStdin` under back-pressure — no write-readiness path
- **WHERE:** `src/Posix/PosixPump.php:171` (`$w = null` — the select never watches writability), partial-write buffer at `:262-266`
- **WHAT:** When the master write would-block, the remainder is parked in `$this->pendingStdin` and retried only when stdin next becomes readable; a caller that writes all of stdin then closes it (scripted-input harnesses) leaves the tail bytes never delivered, and the pump exits on the stdin-EOF grace with the child missing input. ReactPump handles this correctly via `addWriteStream` back-pressure (ReactPump.php:368-373) — the sync pump has no equivalent.
- **FIX:** In the pump loop, when `pendingStdin !== ''`, include the master stream in the select's write set (or add a bounded write-drain retry before returning) so the remainder flushes without new stdin input.
- **USED-BY-CRUSH:** no — sugar-crush reads the master directly, not through PosixPump
- **STATUS:** NEW

### 8. [LOW] PHP 8.4 implicit-nullable deprecation in a live signature
- **WHERE:** `src/Posix/PosixPump.php:87` (`PumpOptions $opts = null` — missing `?`)
- **WHAT:** Deprecated implicit nullable under PHP 8.4; repo targets 8.3+ (8.4 for Windows FFI), so this becomes a deprecation notice the moment the tree moves.
- **FIX:** `?PumpOptions $opts = null`.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW

### 9. [LOW] Convention break: factory named `::default()`
- **WHERE:** `src/PtySystemFactory.php:44`
- **WHAT:** The playbook mandates `::new()` as the root factory and "never `::create()`/`::make()`/`::default()`".
- **FIX:** Rename to `::new()` keeping `default()` as a deprecated alias (it is referenced in README).
- **USED-BY-CRUSH:** no — crush uses `Pty::open()` directly
- **STATUS:** NEW

### 10. [LOW] `Expect::send()` gives up after one 1 ms retry on a busy non-blocking master
- **WHERE:** `src/Expect.php:91-101` (throws RuntimeException after a single `usleep(1000)` retry when `write()` returns ≤0)
- **WHAT:** A child flooding the pty input queue makes a long scripted send throw spuriously; a bounded retry loop (e.g. 100 ms) would absorb normal back-pressure.
- **FIX:** Replace the single retry with a small deadline-bounded loop before throwing.
- **USED-BY-CRUSH:** no
- **STATUS:** NEW

### 11. [LOW] Termios fallback logged once per process
- **WHERE:** `src/TermiosFactory.php:26, :65-67` (static `$loggedFallback` boolean)
- **WHAT:** Subsequent FFI→stty fallbacks in a long-running process are silent, hindering diagnosis of a mid-session degradation.
- **FIX:** Log with a counter, per plan 2.3.
- **USED-BY-CRUSH:** no
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT (plan_candy-pty.md Phase 2.3)

### Positives
`Spawn::proc` closes the slave handle on every exit path via try/finally (Spawn.php:118-123) and
opens the slave once instead of thrice; `ChildPollTrait::pollDestruct` reaps non-running children
in the destructor so a dropped handle cannot leave a zombie (ChildPollTrait.php:318-331); the old
audit's P0s — EINTR-swallowing `@stream_select` (now `retryOnEintr` with deadline recomputation,
PosixMasterPty.php:389-440), the leaked `dup()` in close(), the missing `bin/pty-shim.php`, `fd()`
on the `MasterPty` contract, and the `泡泡` encoding artifact — are all verifiably fixed in the
current tree. No TODO/FIXME/HACK markers in src; `check-one-type-per-file` exits 0.
**Unaudited:** `src/Output/{AnsiOutputParser,SgrHandler,SgrState}.php`,
`src/Input/PtyInputDecoder.php` (first 40 lines only), most `src/Exception/*` and
`src/Contract/*` files (MasterPty, Child, Termios, PtyPair were read).

---

# candy-focus

**MODE:** dependency-free — `candy-focus/vendor/sugarcraft/` does not exist because the lib
requires no `sugarcraft/*` packages (composer.json requires only `php: ^8.3` + dev phpunit), so
neither symlinked nor published applies; the run is not subject to the Packagist-vs-symlink
ambiguity.
**TESTS:** `OK (79 tests, 189 assertions)` — the brief's "thinnest test surface" premise is wrong
in volume (one file, but 79 tests touching every public method); the real gap is edge-interaction
coverage, named in issue 7.
**VERDICT:** A well-built, genuinely immutable ring whose core traversal arithmetic (wrap at both
ends, empty ring, remove-while-focused, duplicate handling, string-id equality) is correct and
heavily tested, but `reorder()` corrupts the disabled-set bookkeeping in three observable ways,
one traversal path contradicts its own documented contract, and the README API table lists barely
half the public surface.

### 1. [HIGH] `reorder()` leaks phantom disabled entries for ids it removes
- **WHERE:** `src/FocusRing.php:266`
- **WHAT:** `new self($unique, $newIndex, $this->disabled)` carries the whole disabled map over without filtering to the surviving ids. Confirmed by execution: `FocusRing::of('a','b','c')->disable('b')->reorder('a','c')` reports `enabledCount()===1` while `count(enabledIds())===2`, `disabledCount()===1` while `disabledIds()===[]`, and `json_encode()` emits `"disabled":["b"]` for an id that is not even registered — breaking the session-restore round-trip the class advertises. Worse, the two member-adding paths then diverge: re-adding `b` via `reorder()` leaves it disabled, while `register()` (line 153) deliberately unsets the flag and re-enables it. `unregister()` (line 192) clears the flag correctly, so `reorder()` is the only leak. Traversal itself stays correct, and the constructor assertions at lines 51/58 do not catch it — and with `zend.assertions=-1` on this runtime they never run at all.
- **FIX:** In `reorder()`, intersect the carried map with the surviving ids before constructing — `array_intersect_key($this->disabled, array_fill_keys($unique, true))` — and add a regression test asserting `enabledCount() === count(enabledIds())` and `disabledIds() === []` after a reorder that drops a disabled id.
- **USED-BY-CRUSH:** no — sugar-crush's only call site (`Pane::ring()` at sugar-crush/src/Tui/Pane.php:69) builds a fresh all-enabled ring per call and never uses `reorder()`/`disable()`; latent for the app's stated growth path (dynamic docked-pane sets).
- **STATUS:** NEW — findings/candy-focus.md analyzed `reorder()`'s dedupe and index math but never its interaction with `$disabled`.

### 2. [MEDIUM] Traversal dead-ends on a disabled focus when exactly one region is enabled, contradicting the docblock
- **WHERE:** `src/FocusRing.php:284-286` (next) and `:324-326` (previous)
- **WHAT:** The docblocks at lines 268/308 promise "Disabling the focused region does not move focus; it is left in place and the next traversal carries it off." Confirmed by execution that it does not: `FocusRing::of('a','b','c')->focus('b')->disable('b')->disable('c')` leaves `current()==='b'` (disabled) and both `next()` and `previous()` return `$this` — the sole-enabled guard short-circuits before the "current is disabled, search forward/backward" loops at lines 292-305/331-344 can run. On a long-running TUI this is the worst shape of the bug: the user is parked on a dimmed panel, presses Tab, and nothing ever happens. The existing tests cover the *enabled*-current variant, so this contract violation is untested and unpinned.
- **FIX:** In both `next()` and `previous()`, apply the sole-enabled no-op guard only when the current index is itself in `enabledPositions`; when the current region is disabled, the single enabled position is a legitimate landing spot — move the `count($enabledPositions) === 1` check inside the `$currentEnabledIdx !== false` branch.
- **USED-BY-CRUSH:** no today (crush's ring is all-enabled with six members); yes the moment any pane gains a disable toggle.
- **STATUS:** NEW — findings §9.1 reviewed the disabled-current loop but missed that the earlier guard bypasses it.

### 3. [MEDIUM] README API table omits eleven of the public methods, including the entire disable/enable and reorder feature set
- **WHERE:** `README.md:68-81`
- **WHAT:** The table lists only the original surface. Missing: `ofStrict()`, `reorder()`, `disable()`, `enable()`, `isEnabled()`, `enabledIds()`, `disabledIds()`, `enabledCount()`, `disabledCount()`, `getIterator()`/`foreach` support, `jsonSerialize()`. The "Behaviour" section likewise documents none of the disable/skip semantics — including the "next traversal carries it off" guarantee that issue 2 shows is not universally true. candy-focus has no docs-drift guard (unlike sugar-crush), so nothing will catch further divergence. Plan item 3.5 (README update) was left undone.
- **FIX:** Extend the README API table and add a "Disabled regions" behaviour section mirroring the docblocks in `src/FocusRing.php`; tick plan Phase 3.5 in findings/plan_candy-focus.md.
- **USED-BY-CRUSH:** no (source-level consumer).
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT — plan_candy-focus.md Phase 3.5 names the fix and is still `[ ]`.

### 4. [LOW] `jsonSerialize()` loses string-ness of numeric region ids via PHP array-key coercion
- **WHERE:** `src/FocusRing.php:430-436` (and the `$disabled` map write at line 358)
- **WHAT:** `$disabled` is a PHP array keyed by id string, so a numeric id like `"1"` is coerced to an int key and `array_keys()` returns `int(1)`; confirmed: `FocusRing::of('0','1','2')->disable('1')` serializes `"disabled":[1]`. A consumer restoring a session under `declare(strict_types=1)` gets an int from `json_decode` and `disable(1)`/`isEnabled(1)` throws a TypeError. `ids` are values (safe); only the disabled keys are affected.
- **FIX:** Serialize with `array_map('strval', array_keys($this->disabled))`, or key the map with a non-numeric prefix internally.
- **USED-BY-CRUSH:** no.
- **STATUS:** NEW.

### 5. [LOW] Exposes `count()` but does not implement `\Countable`, so `count($ring)` throws
- **WHERE:** `src/FocusRing.php:467`
- **WHAT:** Confirmed: `count($ring)` raises `TypeError: count(): Argument #1 must be of type Countable|array`. The class already implements `IteratorAggregate` and `JsonSerializable`, so the omission reads as an oversight; the README advertises `count(): int` as a size helper.
- **FIX:** Add `\Countable` to the `implements` list at src/FocusRing.php:25 — `count()` already matches the required signature.
- **USED-BY-CRUSH:** no.
- **STATUS:** NEW.

### 6. [LOW] Style/duplication items from the prior review remain in the code
- **WHERE:** `src/FocusRing.php:279-299` vs `:319-338` (duplicated all-disabled/sole-enabled guard blocks); `:104-110, :124-131, :241-246` (triplicated first-wins `in_array` dedupe loop, O(n²)); `:284` and `:324` (misleading comment "wrap would land on self")
- **WHAT:** Plan Phases 2.1-2.5 name the fixes (extract a shared traversal helper and a `unique()` static, reword the two comments); none were done. The lib instead added an incremental `enabledPositions` cache, which fixed the hot-path rebuild but left the duplication and the comments. Negligible perf impact at realistic ring sizes (≤10).
- **FIX:** Execute plan Phases 2.2-2.5; fold issue 2's guard fix into the same extraction so the two traversal methods cannot drift again.
- **USED-BY-CRUSH:** no.
- **STATUS:** ALREADY-TRACKED-AND-STILL-PRESENT — findings/candy-focus.md §1.1, §1.2, §1.3, §3.1; plan_candy-focus.md Phases 2.1-2.5 and 4.1.

### 7. [LOW] Test-coverage gaps on exactly the behaviours this audit was scoped for
- **WHERE:** `tests/FocusRingTest.php`
- **WHAT:** Every public method has at least one test, but no test crosses `reorder()` with a non-empty disabled set (issue 1 would have been caught by an invariant check), none exercises traversal from a disabled focus with exactly one enabled region (issue 2), none round-trips `jsonSerialize()` back into a ring, and no numeric-string id is ever used (issue 4). The `assertEnabledPlusDisabledCountEqualsTotal` test at line 391 only checks against `count()`, not against `enabledIds()`, so it cannot see the phantom.
- **FIX:** Add the four targeted tests above alongside the fixes; extend `assertCacheConsistent` callers to include a `reorder()` step.
- **USED-BY-CRUSH:** no.
- **STATUS:** NEW.

### Checked and clean
`declare(strict_types=1)` present; class `final`; one type per file (repo gate exits 0); factories
are `new()`/`of()`/`ofStrict()` with no `::create/::make/default`; accessors bare (`getIterator`/
`jsonSerialize` are interface-mandated); no TODO/FIXME markers and no commented-out code anywhere
in src/tests/examples; every mutator returns a new instance or `$this` for no-ops, verified by
`testMutatorsDoNotMutateTheReceiver`; ring arithmetic at both ends wraps, empty-ring queries
return `null`/`-1` as documented, focused-member removal handles before/at/after/last cases
correctly, duplicate registration is a documented identity-preserving no-op; regions are string
ids compared with strict `in_array`/`array_search`, so the object-identity-vs-equality failure
mode does not apply; `focus()`/`disable()`/`enable()`/`unregister()` no-op on unknown ids is a
deliberate, documented, identity-detectable choice (CALIBER_LEARNINGS.md), and sugar-crush's
`Pane::step()` depends on it explicitly — not reported as a silent failure. No security surface:
pure in-memory string state, no I/O, no format strings.

---

# candy-kit

**MODE:** linked (`vendor/sugarcraft/*` are symlinks into the monorepo — candy-core,
candy-sprinkles, candy-testing et al.)
**TESTS:** `OK (150 tests, 535 assertions)`, 0.238s, PHP 8.3.6, PHPUnit 10.5.64 — all green
**UNUSED-DEP-GATE:** `tools/check-path-repos.php --unused` now reports sugar-crush→
`sugarcraft/candy-kit` as **DEFERRED_WIRING** (`sugar-crush/composer.json:82` row), not
PRUNE_REQUIRE_AND_REPO — the E453 note's own sentence saying the tool "reports it as
PRUNE_REQUIRE_AND_REPO" is stale; the suppression row works. The justification holds up in code:
`grep SugarCraft\Kit` across `sugar-crush/src`, `bin`, `examples` returns zero hits. The dep is
genuinely pending, not spent.
**ANSI-GUARD:** **no** — zero references to `NO_COLOR`, `isatty`, `TERM`, or `CLICOLOR` anywhere
in `candy-kit/src/`. Every presenter defaults `?Theme $theme` to `Theme::ansi()`
(`StatusLine.php:24,29,34,39,44`; `Section.php:31,66,92`; `Banner.php:20`; `Stage.php:40,65,97`;
`HelpText.php:33`), and `Style::render()` emits SGR unconditionally because candy-kit never
consults the profile-aware machinery that already exists in candy-core: `ColorProfile::detect()`
(`candy-core/src/Util/ColorProfile.php:41-121`) implements the full convention set —
NO_COLOR→Ascii, non-tty→NoTty, CLICOLOR_FORCE/FORCE_COLOR, TERM=dumb→Ascii — but candy-kit
hardcodes `Style`'s default `profile: TrueColor` and calls none of it. Measured: piping
`StatusLine::success("done")` through a pipe emits `\x1b[1m\x1b[92m✓\x1b[0m done`.
**VERDICT:** Healthy, well-tested presentation lib — all 24 prior findings' actionable items are
fixed or documented; the one real blocker is the missing color-capability guard, which is the
exact reason the sugar-crush wiring is deferred and belongs in this lib, plus two width-honesty
gaps in Section/HelpText on hostile input.

### 1. [HIGH] No color-capability guard: SGR emitted unconditionally, ignoring NO_COLOR / TERM=dumb / non-tty / CLICOLOR_FORCE
- **WHERE:** `src/Theme.php:39` (`ansi()` default), call sites `StatusLine.php:24`, `Section.php:31`, `Banner.php:20`, `Stage.php:40`, `HelpText.php:33`
- **WHAT:** Every presenter renders through `Theme::ansi()` when no theme is passed, and nothing in the lib checks `stream_isatty(STDOUT)`, `NO_COLOR`, `TERM=dumb`, or `CLICOLOR_FORCE` — piped `sugarcrush --help | less` would get raw escape bytes. This is the confirmed blocker on the E453 deferred restyle, and the E453 note's proposed fix ("a posix_isatty() guard") is misplaced: the guard belongs here, not at every call site.
- **FIX:** In `Theme::ansi()` (or a new `Theme::default()` the presenters fall back to), resolve the profile via `ColorProfile::detect(null, STDOUT)` from candy-core — it already encodes the entire NO_COLOR/no-tty/CLICOLOR_FORCE/TERM=dumb order — and pass it into each `Style` via `Style::colorProfile()` (candy-sprinkles/src/Style.php:615); `Ascii`/`NoTty` downgrades SGR to nothing. One function, every presenter inherits it, sugar-crush call sites stay untouched.
- **USED-BY-CRUSH:** no (pending — this is the deferred wiring's blocker)
- **STATUS:** NEW (the E453 comment asserts the behavior; no findings/plan item names it)

### 2. [MEDIUM] `Section::header()`/`subHeader()` output exceeds `$width` when the label is longer than the width
- **WHERE:** `src/Section.php:38-47` (header), `:94-104` (subHeader)
- **WHAT:** `$remaining = max(0, $width - Width::string($head))` clamps the *fill* to zero but never truncates `$head` itself. Measured: `header("A VERY LONG SECTION LABEL THAT OVERFLOWS", Theme::plain(), 2, 20)` returns 44 cells for a requested 20; `subHeader` with width 10 returns 27. The docblock promises "total cell width to fill", and in a `Frame`-wrapped context an over-wide line forces a terminal wrap — exactly the desync `Frame.php:26-33` documents as forbidden.
- **FIX:** Route `$label` through `Width::truncateAnsi(...)` + ellipsis (the pattern already in `Frame::truncateWithEllipsis()`, Frame.php:204-214) when the label exceeds `$width`, or document that `$width` is a minimum, not a cap. No test covers label > width.
- **USED-BY-CRUSH:** no (pending)
- **STATUS:** NEW

### 3. [MEDIUM] `HelpText` never wraps — long descriptions emit one over-wide line, breaking the two-column layout on any terminal
- **WHERE:** `src/HelpText.php:60-77` (`renderRows`), `:39-41` (usage)
- **WHAT:** There is no wrap logic anywhere in the lib: measured, a 100-word description renders as a single 507-cell line. On an 80-col terminal the terminal hard-wraps it mid-word at the edge and every continuation line lands in column 0, destroying the key/description alignment a fang-style help page exists to provide. This matters directly for the deferred `Cli\Help::screen()` restyle — sugar-crush's hand-written help wraps at ~80 columns by hand (e.g. the `--config` entry spans 9 indented lines, Help.php:112-121), and HelpText would silently lose that.
- **FIX:** Add a cell-aware word-wrap applied to the description column in `renderRows()`, with a `$width` parameter; without it, the restyle cannot be faithful.
- **USED-BY-CRUSH:** no (pending)
- **STATUS:** NEW

### 4. [LOW] `Banner::title()` does not sanitize its inputs, unlike every other presenter
- **WHERE:** `src/Banner.php:22-25`
- **WHAT:** `Section`, `StatusLine`, `Stage`, `HelpText` all run caller text through `SafeText::line()` (`Internal/SafeText.php:34`) to neutralize embedded escapes/control bytes; `Banner::title()` interpolates `$title`/`$subtitle` raw into styled bordered output, so a `\x1b` or `\n` in a title desyncs the border box. The class docblock states no "pre-rendered input" contract the way `Frame` does (Frame.php:35-37), so this reads as an oversight.
- **FIX:** Apply `SafeText::line()` to `$title` and `$subtitle`, or document the pre-rendered contract.
- **USED-BY-CRUSH:** no (pending)
- **STATUS:** NEW

### 5. [LOW] E453 note's claim about the `--unused` report is stale
- **WHERE:** `sugar-crush/composer.json:82`
- **WHAT:** The note says the tool "reports it as PRUNE_REQUIRE_AND_REPO"; the tool actually reports **DEFERRED_WIRING** — the row's own existence is what reclassifies it. Harmless, but the sentence teaches the next reader to expect a report the gate no longer makes.
- **FIX:** Amend the note to "would report PRUNE_REQUIRE_AND_REPO without this row; reports DEFERRED_WIRING with it".
- **USED-BY-CRUSH:** yes (doc accuracy for the deferred item)
- **STATUS:** NEW

### 6. [NIT] `HelpText::render()` uppercases section titles with byte-wise `strtoupper`
- **WHERE:** `src/HelpText.php:44`
- **WHAT:** `strtoupper(SafeText::line($title))` is ASCII-only; a title like `café` renders lowercase while English siblings uppercase — inconsistent, not corrupt. `mb_strtoupper` is the cell-safe choice.
- **FIX:** Swap to `mb_strtoupper(..., 'UTF-8')`.
- **USED-BY-CRUSH:** no (pending)
- **STATUS:** NEW

### 7. [NIT] `Section::rule()` docblock overstates the minimum-width guarantee
- **WHERE:** `src/Section.php:53-56` vs `:69`
- **WHAT:** The docblock says rule() "always produces at least 2 cells", but `rule($theme, 0)` computes `intdiv(max(1, 0), 1) = 1` → one cell. Only the `$width === null` path guarantees 2.
- **FIX:** Scope the docblock claim to the null case, or clamp `$width` to `max(2, …)`.
- **USED-BY-CRUSH:** no (pending)
- **STATUS:** NEW (docblock added by the plan 1.2 fix, imprecisely)

### Prior-findings reconciliation
`findings/candy-kit.md` / `plan_candy-kit.md` carry a stale `status: not-started` header (as of
2026-06-30) — most of the plan has since landed: #1 leftPad formula FIXED (Section.php:36,
rune-count semantics documented); #2 rule/header minimum FIXED but imprecisely (nit 7); #4
HelpText two-pass is the necessary alignment pass, correctly done, no action; #10 golden tests
FIXED (`GoldenRenderTest.php` covers Banner/Section×3/HelpText/Frame×2/Logo×2/StatusLine×2/Stage);
#13 unused path-repos in lib composer.json FIXED (no `repositories[]` block); #14 Frame
truncation duplication FIXED (`Frame.php:204 truncateWithEllipsis()`); #20 `Theme::auto()` FIXED
(Theme.php:78); #21 ThemeBuilder FIXED; #22 `withTitleText` FIXED (Frame.php:76); #23 `subHeader`
FIXED (Section.php:86); #24 progress variant FIXED (Stage.php:97); #3 byName type guard is not a
defect (strict_types makes `byName(123)` a TypeError at the boundary); #6/#7/#9/#15/#16/#17
remain documented trade-offs, none load-bearing.

### Sugar-crush side — quantifying the deferred restyle
`Cli\Help::screen()` (`sugar-crush/src/Cli/Help.php:36-268`) is one `<<<'HELP'` heredoc of **234
lines** returned whole, written to STDOUT at `bin/sugarcrush:228`. `tests/Cli/HelpTest.php` (690
lines) pins it with **7 regex assertions**, of which the line-start-anchored ones die on any SGR
prefix: `/^ {3}VAR(?![A-Z0-9_])/m` (HelpTest.php:160, :435), `/^ *(SUGARCRUSH_[A-Z0-9_]+)(?![A-Z0-9_])/m`
(:202), `/^ +(?:-[a-z], )?flag(?:[ =,]|$)/m` (:279). Beyond the regexes,
`environmentSectionOfTheScreen()` (:463-473) locates sections by raw
`strpos($screen, 'Environment variables:')` and `"\nExit codes"`, so a `Section::header()`-styled
(uppercased, rule-wrapped) heading breaks the section extraction itself, and exact-string pins
like HelpTest.php:170 break on reflowed columns. Net: the restyle needs either the issue-1 guard
in candy-kit (zero HelpTest churn when piped, colors only on a tty) or a rewrite of ~7 regex pins
+ 2 strpos anchors + the exact-string contains pins.

### Clean bills
No I/O, streams, children, or timers in `src/` — no lifecycle-leak surface; no TODO/FIXME markers;
every file starts `declare(strict_types=1)`; all 10 classes `final`;
`tools/check-one-type-per-file.php` exits 0 repo-wide; no `::create()`/`::make()`/`::default()`
(the one root factory is `Frame::new()`, Frame.php:60); no `get*` accessors; `Theme` uses public
readonly promoted props per the sugarcraft-model-pattern convention.

---

# Repair priority across the fleet

Ranked by blast radius on sugar-crush as a long-running TUI, with the cheapest high-value fixes
first. All are one-file, few-line changes in files that already contain the corrected pattern
elsewhere unless noted.

## Now (live on crush's hot path or a measured crash/hang)
1. **candy-pty #1/#2/#3** — route `Pty::open()`/`Pty::spawn()` through `PosixPtySystem` (cloexec +
   post-spawn resize) and make `PosixMasterPty::close()` reach its libc close on every path.
   crush's interactive tool capture leaks an fd per spawn today.
2. **sugar-mcp #1/#2** — `JSON_THROW_ON_ERROR` in `toJson()`; mirror the initialize-leg error gate
   on the tools/list leg. Together these close a wedged-agent hang and a silent "0 tools" server.
3. **candy-shine #1** — narrow `hasUnresolvedBracket()` to real link-reference candidates. crush
   re-renders whole replies per frame on any model output containing `]`.
4. **candy-core #2** — stop `Width::wrap()` from swallowing invalid-UTF-8 paragraphs.
5. **candy-sprinkles #1/#2** — Table divide-by-zero guard; unconditional center-title truncation.
6. **candy-mouse #1/#2** — validate sentinel ids on the decode side; handle CRLF in `Scan::parse()`.
7. **candy-mosaic #1** — resync the flip-walk clone (or drop the clone and read flip's offsets).
8. **candy-forms #1** — clamp multi-char insert to the remaining charLimit budget.

## Next (contract violations and teardown gaps on the crush path)
9. **candy-core #3/#4/#5** — one-shot timer + signal-handler teardown in `Program`; BEL terminator
   accounting in `Renderer::tokenByteLength()`; paste-buffer ceiling in `InputReader`.
10. **candy-layout #1** — integer rational share math in `DockLayout::stackHeights()` (called every
    dock render/resize).
11. **sugar-veil #1** — carry the scanner through `mutate()` (fixes the click-outside lie for every
    consumer, latent for crush).
12. **candy-focus #1/#2** — filter the disabled map in `reorder()`; fix the sole-enabled traversal
    dead-end.
13. **candy-forms #2** — enforce `Field::isHidden()` in Form (sugar-bits/candy-shell exposed).
14. **candy-kit #1** — the `ColorProfile::detect()` guard, which also unblocks the deferred
    `Cli\Help` restyle (E453) at zero HelpTest churn.
15. **candy-fuzzy #1/#3** — document the cap-boundary semantic flip; add `requireFullQuery` so the
    Ctrl+P palette stops listing stray-character hits.
16. **candy-sprinkles #4/#5** — flip `Theme::adaptive()` (or delete it, since crush already
    re-derives correctly); `truncateAnsi` on border titles.

## Backlog (convention debt, docs, hardening)
- sugar-crush `require` bump: move `sugarcraft/candy-pty` from require-dev to require (candy-pty #5);
  declare `ext-posix` (candy-pty #6).
- Layout GreedySolver sum-to-total gaps (#2/#3/#4), CassowarySolver `@` suppression (#5), stale
  sprinkles SolverFactory warning (#6).
- sugar-mcp liveness check in `readLine` (#3), `notify()` return (#4), spawnPlan string-command
  gate (#5), `ExchangeLock::create` rename coordinated with crush's `LspExchangeLock` twin (#6).
- forms TextArea viewport pan (#3), accessibleView array coercion (#4).
- mosaic format-mislabel (#2), allocation guards (#3/#4), Sixel env detection (#5), mintty
  investigation (#6).
- shine `headingCase` SGR corruption (#2), list-wrap indent (#3).
- veil empty-background session reset (#2) and the low/nit cluster.
- focus README roster (issue 3 — the lib has no drift guard to force it).
- sprinkles `get*` alias pass (#10) — alias-first, crush compiles against these today.
- Stale plan headers to flip: `plan_candy-core.md` (`not-started` but all items landed),
  `plan_candy-kit.md` (`not-started` but mostly landed), candy-kit E453 note wording (#5).

## Coverage gaps (so "not reported" isn't read as "clean")
- **candy-core:** ~5k LOC unread — the Tty backends, `BoundedShutdown`, `ColorProfile`, `Clipboard`,
  `Editor`, `Open`, most `Msg/*`, `Syntax/*`, `ProgramOptions`.
- **candy-forms:** the non-crush widgets — MultiSelect, Text, FilePicker, Date/Color/Slider,
  Validators, Vim, lang/ locale parity.
- **candy-pty:** `src/Output/{AnsiOutputParser,SgrHandler,SgrState}.php` and
  `src/Input/PtyInputDecoder.php`.
- **candy-mosaic:** items #2-#5 are code-reading-verified, not run end-to-end; #6 (mintty) is
  explicitly ~70% confidence and needs a live check.
- **candy-shine:** issue #3's exact statement lines carry ranges rather than pinned lines.
