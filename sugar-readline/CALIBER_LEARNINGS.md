# SugarReadline — Caliber Learnings

Accumulated patterns and gotchas. One scheme: `## YYYY-MM-DD — subject`, newest at the
bottom; genuinely open deferrals live in the final section.

Token glossary (legacy entries cite older rounds): `S-NN` is a 2026-11 review-pipeline
step id (`reviews/annotations/sugar-readline.md`), `G-N` a gated owner-ruling in the
same file, `finding NNN` a `sugar-readline-NNN` id in
`reviews/findings/sugar-readline.md`; `Source:` lines name the original `ai/<branch>`
work, and `step-NN` there is that era's orchestration sequence number.

## 2026-05-30 — InputDriver is injectable for tests; production defaults to STDIN
Pattern: Accept an `InputDriver` in the constructor; `null` means the loop lazily opens `php://stdin` itself (the `Readline::fromStdin()` factory names that intent, and under the pump-reuse law the pump is created once per `Readline` instance and reused across `run()` calls, so piped input keeps feeding successive prompts). This makes the readline loop testable by injecting a driver over a fixture stream.
Anti-pattern: Reaching for `STDIN` directly in the run loop — hard to test, couples to the global TTY. (Historical slip: this entry once spelled the default `StreamInputDriver::fromStdin()` — that factory never existed on the driver; the constructor takes a stream resource.)
Source: step-21 ai/sugar-readline-input

## 2026-05-31 — Vim keybindings via shared sugar-forms VimKeyHandler
Anti-pattern: Do NOT add new vim keybindings to per-lib branching logic (ViMode or TextInput vimMode flags). Always add new bindings to `VimAction` enum + `VimKeyHandler` so sugar-forms, sugar-widgets, and sugar-readline all benefit at once.
Source: step-24 ai/vim-mode-shared

## 2026-06-29 — Emacs word-delete/transpose O(n) replay deferred
The June word-operations remediation fixed emacs word operations to use handleKeyDirect instead of handleKey, eliminating infinite re-entry recursion. The O(n) replay (per-keystroke clone) for deleteWordBefore/deleteWordAfter/transposeChars was consciously deferred: adding a public TextPrompt::withBuffer() mutator would widen the API surface and risk breaking the immutability contract. The replay is O(word-length) per op, not per-keystroke-of-session, so the cost is bounded and acceptable until a dedicated perf pass can benchmark the impact.
Source: step-6 sugar-readline-fix

## 2026-07-01 — Atomic history writes, maxHistory, permissions
Three durability guarantees landed:
- FileHistory now uses atomic write via temp file + rename (crash-safe)
- InMemoryHistory enforces maxHistory limit with eviction of oldest entries
- History files are chmod 0600 (owner read/write only) to protect sensitive commands
- InMemoryHistory constructor accepts maxHistory param; FileHistory forwards it

(History: the cap later consolidated onto one live store — the S-30 setter law; the
README API roster catches up in the S-27 pass.)
Source: ai/sugar-readline-phase6

## 2026-07-04 — Search, clamps and text objects (folded from the old Deferred/Future lists)
- **Ctrl+R reverse history search**: `HistoryInterface::search()` (read-only, does not disturb navigation position) + a search state machine in TextPrompt (`startHistorySearch()`, intercepts keys in `handleKey()` BEFORE mode delegation so vi/emacs bindings can't hijack the search). Enter accepts the match without submitting; Escape/Ctrl+G cancels back to the pre-search line.
- **Emacs incremental search**: EmacsMode Ctrl+R/Ctrl+S start the shared TextPrompt search machine in reverse/forward direction; no search state lives in the mode itself.
- **Vi cursor off-by-one at line end**: `$` and Escape→normal clamp via `ViMode::clampToLastChar()` (one `Key::Left` when cursor >= length on non-empty buffer). No TextPrompt change needed.
- **Vi text objects**: implemented sugar-forms-first, per the 2026-05-31 shared-handler law: sugar-forms grew `TextObject` (pure resolver: buffer + cursor + `TextObjectScope::Inner/Around` + target key → `?[start,end)` in mb character indices), `VimOperator` (c/d/y), new `VimAction` cases (`ChangeLine`, `Change/Delete/YankTextObject`) and `VimKeyHandler::handleTextObject()`. ViMode consumes them via its existing pending-motion machinery extended with a `pendingScope` stage (operator → i/a → target), executing ranges through key replay (`moveCursorTo` + Backspace), consistent with `deleteLine()`. `cc` (change line) came along for free. Documented simplifications: `a"` takes no trailing whitespace, no `quoteescape`, no yank register (matches `yy`).

## 2026-10-23 — One key language: normalize at the choke

Keys arrive in two shapes: raw control bytes off a live stream and symbolic
names from sugar-input's decoder (`ctrl_a`, `esc`, `alt_b`). The 2026 docs
audit found the dispatch dead in practice — `Readline` fed symbolic names into
modes matching raw bytes. The cure is `Key::normalize()`: bytes are folded
into the canonical symbolic language at every entry point
(`TextPrompt::handleKey`/`handleKeyDirect`, both modes, `Readline::onKey`), so
match arms are written once, in names, and both spellings work. Mode-claiming
of typed characters goes through `ModeInterface::consumesCharacters()` (Emacs:
meta prefix armed; Vi: outside insert) — never via callers remembering to ask.
Byte aliases surviving in old arms are harmless dead weight after normalize;
delete them opportunistically. Tests must feed bytes through
`Readline::run()` (socket-pair driver), not just call handlers directly —
handler-level pins passed while the real dispatch was broken.

## 2026-10-24 — Accessor-deprecation era (bare twins)
- **[pattern:accessor-deprecation-era]** `getPrevious()`/`getNext()` on the history family were renamed to the bare twins `previous()`/`next()`, **with the interface**: `HistoryInterface` declares only the bare pair. PHP forbids interface method bodies (the default-method RFC was withdrawn), so the legacy names could not ride the contract — they live as one-line `@deprecated` BC forwarders marked `REMOVED-IN: 2.0.0` on `InMemoryHistory` (and therefore on `FileHistory` by inheritance). Navigation is stateful, so `tests/AccessorDeprecationTest.php` pins BC through full walks in both spellings (returns + position advance), forwarder shape, the interface contract, a discovery-first `src/` census, and the README table — a third deprecation without all rows goes red. `FileHistory`'s destructor flushes on teardown: scope the object in a closure so it rewrites the file *before* temp-file cleanup unlinks it.

## 2026-11-13 — i18n foundation era (S-09, finding 008)
- **[pattern:lib-lang-facade]** Chrome strings ride `lang/en.php` (14 keys, `readline` namespace) behind `src/Lang.php` (sugar-pty pattern: `extends Core\I18n\Lang`, NAMESPACE+DIR constants, lazy idempotent `T::register` on every `t()`). Full fleet parity is 17 files (en + the 16 LOCALES.md codes; sugar-toast is the exemplar — charts/core still miss zh-tw). Property defaults cannot call `Lang::t()` (PHP initialiser law): translate-able defaults are `?string = null` sentinels resolved at render, so an explicit host string — including `''` — stays opaque. Lookup law exact → base → en → **raw key** (a missing translation must surface visibly, never blank; `LangFoundationTest::testMissingKeySurfacesTheRawKey` pins it). `'[y/n]'` is key notation and rides verbatim in every locale; `'[multi-select]'` is a bracketed mode marker (translated as `[seleção múltipla]` etc.). `match_count` keeps the English `%s` morphology until S-10's full-string plural keys — translated files legitimately drop `%s` (extra sprintf args are ignored) and `LangFoundationTest::testPlaceholdersSurviveEveryLocaleValue` guards only the `%d` contract. Zero render diff on en is enforced BY the pre-existing goldens: flip one en byte and the suite goes red (mutation-proven 2026-11-13).

## 2026-11-15 — history diagnostics keyed (S-11, finding 041)
- **[pattern:diagnostics-key-vs-exempt]** Every `RuntimeException` thrown from `FileHistory` on a user-reachable construction/write path now reads `'sugar-readline: ' . Lang::t('history.<case>', [...])` — 15 keys × 17 locales, `{path}`/`{dir}`/`{what}` named tokens only (zero `sprintf(` in `src/`, structurally pinned by `LangFoundationTest`). The `sugar-readline: ` program tag stays an English literal at every call site: it is the grep-handle that routes a bug report to this library, and translators never see it. The three `error_log()` diagnostic channels (destructor flush failure, `tryOpen()` decline, read-lock degradation) ride a recorded **developer-facing English exemption** — operators tailing logs need stable English, and none of them reach an end user's screen. `FileHistoryDiagnosticsI18nTest` pins both halves: byte-identical en render at a throw, a German render at the same throw, and a structural zero-count of inlined English throw prose.

## 2026-11-15 — value-object idiom sweep (S-25, finding 027)
- **[pattern:hand-rolled-mutate]** Every fluent carrier in this lib now owns `private function mutate(array $changes)` that clones and assigns by property name — **not** sugar-core's `Concerns\Mutable`: the generic trait constructor-spreads `get_object_vars()` into `new static(...)`, which fatals unless every field is a promoted constructor parameter, and no carrier here stores its state that way. `SelectionEngine` is a trait, so its single `mutate` compiles into both list prompts (a class-level re-declaration would win over the trait silently — do not add one). `ValueObjectStyleTest` pins the shape: private `mutate` exists on all five carriers, and no `public function with*()` body may contain `clone $this` or skip the idiom. State-machine ops (`handleChar` arms, undo/redo, search) keep hand-clones on purpose — they derive fields, they do not just set them.
- **[ruling:no-sentinel-when-null-is-the-meaning]** `displayWidth`/`viewportRows` are tri-states (null = unset and adoption-eligible; 0 = explicit unbounded; >0 = bound), but per AGENTS law they get **no** paired `bool $XSet`: here null is not "unset alongside a meaningful null" — the absence *is* the inherit state. The accessor tri-state is the sentinel; `testTriStateWidthsStaySentinelFree` pins all three. G4's full-lib-sweep default shipped with this row; targeted-only was rejected because the half-migrated idiom drifts back.

## Future work (deferred pre-1.0)

### Integration with Terminfo
Using terminfo for terminal capability queries would require FFI bindings to the curses terminfo database. This is a significant integration effort.

### Integration with Ncurses
Using ncurses for lower-level terminal control would similarly require FFI bindings and significant architectural changes to the Readline loop.
