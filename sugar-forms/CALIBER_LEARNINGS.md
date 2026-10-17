# Caliber Learnings — sugar-forms

> Accumulated patterns and gotchas discovered during development.
> This file grows over time — do not delete entries from previous sessions.

<!-- Add learnings below as they are discovered -->

### 2026-05-29 — Golden-file snapshot convention (sugar-testing)
Pattern: Render output tests use `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi($goldenPath, $actual)` — the actual ANSI bytes are compared against a fixture file at `$goldenPath`. Fixture files live in `tests/fixtures/` and carry a `.golden` extension. Set `UPDATE_GOLDENS=1` in the environment to auto-regenerate any missing fixture.
Anti-pattern: Do NOT use string equality (`assertEquals`) for ANSI output — ESC sequences in a mismatch produce an unreadable diff. Always use `assertGoldenAnsi` or `assertAnsiEquals`.
Source: step-15 ai/sugar-forms-shared

### 2026-05-29 — FuzzyMatcher back-compat shim (sugar-fuzzy)
Pattern: `SugarCraft\Forms\Fuzzy\FuzzyMatcher` (step-07) is now a deprecated alias for `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`. There is zero behavioural divergence — the internal Select/MultiSelect filter uses `SmithWatermanMatcher` directly. The public `withFilter(callable)` API is preserved; callers injecting a custom filter are unaffected.
Anti-pattern: None identified. Do not re-implement scoring logic in sugar-forms when sugar-fuzzy provides it.
Source: step-15 ai/sugar-forms-shared

## Spinner absorbed from sugar-widgets

`Spinner`, `Spinner\Style`, and `Spinner\TickMsg` were moved here from
`sugar-widgets/src/Spinner/` (namespace `SugarCraft\Widgets\Spinner` →
`SugarCraft\Forms\Spinner`). The sugar-widgets files are now thin
`class_alias` shims — same pattern as the other extracted primitives.

## sugar-forms owns its tests; leaf libs keep alias-integration tests

sugar-forms now carries its own direct test suite under `tests/`
(namespace `SugarCraft\Forms\Tests\*`) exercising the real
`SugarCraft\Forms\*` classes. The original tests in `sugar-widgets/tests/`
and `sugar-forms/tests/` were COPIED (not moved) and stay in place — they
exercise the same classes through the `class_alias` shims, giving us
alias-integration coverage. Do not delete the leaf-lib copies.

## class_alias shim pattern (canonical)

A re-exporting leaf class is a 6-line file: `declare(strict_types=1);`,
the leaf namespace, a `// @deprecated Use SugarCraft\Forms\...` comment, and
a single `class_alias('SugarCraft\Forms\X\Y', 'SugarCraft\Widgets\X\Y');`. The
alias only fires when the autoloader loads the shim (triggered by a `use` of
the leaf FQN), so every consuming lib must `require sugarcraft/sugar-forms`
for the alias target to resolve.

## Watch for stray leaf-namespace refs after extraction

`Scrollbar\ScrollbarState` still imported `SugarCraft\Widgets\Lang` after the
extraction — invisible inside sugar-widgets (where that class autoloads) but a
fatal `Class not found` when sugar-forms runs standalone. A direct test suite
catches these; grep `src/` for `SugarCraft\\Widgets` / `SugarCraft\\Forms` after
any extraction. Also keep the lang keys (`spinner.*`, `scrollbar.*`) in
`sugar-forms/lang/en.php` in sync with what `src/` references.

## VimKeyHandler shared vim mode handler

`sugar-forms/src/Vim/VimKeyHandler` provides a unified vim keybinding handler
(enum VimState: Insert/Normal/Visual/VisualLine; enum VimAction: CursorLeft,
CursorRight, CursorWord, DeleteChar, etc.). 4 libs now delegate to it:
sugar-forms TextInput, sugar-readline ViMode, sugar-forms (via class_alias),
sugar-widgets (via class_alias). Add new bindings to VimAction enum + VimKeyHandler
so all consumers benefit. Mirrors: `docs/repo_map_step_24.md`.

Anti-pattern: Do NOT add new vim keybindings to per-lib branching logic.
Always add to `VimAction` enum + `VimKeyHandler` so all 4 libs benefit.

- **[pattern:async-suggestions:cancel]** Use `CancellationToken` for any user-cancellable async op. ReactPHP loop is shared — accept `LoopInterface`, don't construct. When implementing debounced async suggestions (e.g. `withAsyncSuggestions`), store a `CancellationSource` on the model and call `cancel()` on the previous source before scheduling a new timer, so rapid keystrokes only fire one fetch. The pattern: `$previous?->cancel(); $next = $next->withPendingAsyncCancellation(CancellationSource::new());`. Mirrors: `docs/repo_map_update.md §23.4`.

- **[pattern:mouse-zone-drag:slider]** crush_media W3.2 added Slider mouse-drag with ZERO new composer
  requires: it types `SugarCraft\Core\Msg\MouseMsg` directly (sugar-core is already required). Two doors:
  `update()` answers the widget-relative form (focused + press on the track row; track is always view()'s
  LAST line — `trackLine()` counts title/description), and the public host-callable
  `handleZoneMouse(MouseMsg, int $zoneLeft, int $zoneWidth): ?self` serves geometry-owning hosts
  (candy-code runs sugar-mouse and hit-tests the absolute rect itself). Mapping is endpoint-inclusive —
  `(x - zoneLeft) / ($zoneWidth - 1 floored at 1)` scaled into [min, max] — and the number ALWAYS routes
  through `withValue()` so normalise() stays the single clamp/snap site (SliderTest's `max(`/`min(` token
  census counts comments too: never write `max(1, ...)` in this file's prose, guard the span with an if).
  Press/Motion/Release identical in v1; wheel + non-left refused (crush Viewport already maps wheel to
  Up/Down KeyMsgs — answering it too would double-step). Keyboard arms untouched: the MouseMsg arm sits
  BEFORE the door (ItemList E736 5.14 shape). Readonly refusal lives in the handler's own `isReadonly()`
  gate — MouseMsg stays unclassified in `HasReadonly::isReadonlyMutatingMsg` by design.

- **[pattern:int-lattice-exact:numberfield]** crush_media W3.3 added `Field\NumberField` (draft-string +
  committed-value over TextInput) and `Field\RadioPills`. The int64 law: when min/max/step AND the raw are
  all int and the span fits int, coercion runs an overflow-free intdiv lattice walk (clamp + half-up tie,
  ties away from min — same rule as Slider's EPS law); anything floatish DELEGATES to
  `Slider::withValue()` so Slider's normalise() stays the library's ONE float clamp/snap site. Never let a
  seed cross `(float)` — `99999999999999999` must round-trip digit-exact (pin); beyond-int64 drafts are
  REFUSED with a Lang message, and thousands separators are refused, never guessed. Empty draft is not a
  commit (never-set sentinel `hasValue()`, empty != 0); commits land on Enter/blur/revalidate; consumes()
  claims only Up/Down (Date/Color precedent, Enter stays Form submit). RadioPills is a VALUE EDITOR in the
  E736 5.10 split (the highlight IS the value): readonly freezes arrows + mouse, unlike picker navigation.
  Adding either class means bumping the TraitStateCarryFamilyTest adopter census (12 after W3.3) — that
  fail-closed count is the roster, re-judge it in the same commit.

- **[pattern:fuzzy-smith-waterman-two-row]** FuzzyMatcher uses a two-row DP matrix instead of a full O(m×n) table for Smith-Waterman local alignment scoring. Rows are swapped (not copied) on each query-character iteration, keeping space at O(c) where c is candidate length. Adjacent consecutive matches receive a +5 bonus; mismatches cost -3. This is the canonical pattern for any in-memory fuzzy string scoring in PHP where memory must stay bounded. (Folded in from the retired prompt façade's learnings.)
- **[pattern:async-suggestions-debounce]** `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)` on Input/Select uses `React\EventLoop\Loop::addTimer()` for debouncing, `React\Async\defer()` for async fetch wrapping, and dispatches `SugarCraft\Core\Msg\SuggestionsReadyMsg` when results arrive. The 150 ms default balances responsiveness against API call volume.
- **[pattern:validator-short-form-methods]** Input field exposes short-form validator chains (`required()`, `email()`, `minlength(int)`, `maxlength(int)`) that delegate to `withValidator(new ValidatorType(...))`. This reduces boilerplate from `Input::new('x')->withValidator(new Required())->withValidator(new Email())` to `Input::new('x')->required()->email()`.
- **[pattern:fuzzy-alias-repoint-with-back-compat]** The `Fuzzy\FuzzyMatcher` class was repointed to delegate to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` (the sugar-fuzzy implementation) with a back-compat shim kept at the old FQN so existing call sites resolve unchanged. When repointing a class_alias, always verify the oldFQN is still loadable and add a dedicated alias-resolution test to catch accidental breakage.
- **[anti-pattern:vim-keybindings-per-lib]** Do NOT add new vim keybindings to per-lib branching logic. Vim mode is handled by `sugar-forms/src/Vim/VimKeyHandler` (shared). Always add new bindings to `VimAction` enum + `VimKeyHandler` so sugar-widgets, sugar-forms, and sugar-readline all benefit at once. Mirrors: `docs/repo_map_step_24.md`.
