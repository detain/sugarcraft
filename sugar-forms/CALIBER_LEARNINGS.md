# Caliber Learnings — sugar-forms

> Accumulated patterns and gotchas discovered during development.
> This file grows over time — do not delete entries from previous sessions.
> One format: `### YYYY-MM-DD — Title` with Pattern / Anti-pattern / Source
> prose (SF-15, 2026-11-13: the three historical shapes — undated `##`
> sections and bare `- **[pattern:…]` bullets — were normalized to this
> shape; content is byte-true otherwise (heading dates = the entry's internal
> date, else its git-introduction date), corrections are date-stamped in
> place, never deleted).

<!-- Add learnings below as they are discovered -->

### 2026-05-29 — Golden-file snapshot convention (sugar-testing)

Pattern: Render output tests use `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi($goldenPath, $actual)` — the actual ANSI bytes are compared against a fixture file at `$goldenPath`. Fixture files live in `tests/fixtures/` and carry a `.golden` extension. Set `UPDATE_GOLDENS=1` in the environment to auto-regenerate any missing fixture.

Anti-pattern: Do NOT use string equality (`assertEquals`) for ANSI output — ESC sequences in a mismatch produce an unreadable diff. Always use `assertGoldenAnsi` or `assertAnsiEquals`.

Source: step-15 ai/sugar-forms-shared

### 2026-05-29 — FuzzyMatcher back-compat shim (sugar-fuzzy) — CORRECTED 2026-11-13 (SF-15 / sugar-forms-014)

Pattern: `SugarCraft\Forms\Fuzzy\FuzzyMatcher` is a DELEGATING shim, not a zero-divergence alias: the former hand-rolled two-row DP core is gone and all scoring runs through the canonical sugar-fuzzy SSOT `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` (default `ScoringProfile`, bit-equivalent to the historical constants for ASCII input), but the shim keeps two DELIBERATE divergences pinned by its own docblock: (a) the legacy `match(string, array): list<[string, int]>` ranking-tuple shape the SSOT no longer exposes, and (b) the legacy negative empty-candidate score (the SSOT returns 0 there).

Divergence correction (was "zero behavioural divergence", false-to-disk): see (a)/(b) above — deliberate, documented, pinned.

Filter correction (was "the internal Select/MultiSelect filter uses SmithWatermanMatcher directly", false-to-disk): the interactive ItemList filter is a plain case-insensitive SUBSTRING match against `Item::filterValue()`; the shim is consumed ONLY by the `withFuzzySuggestions()` field setters (Select and Input) that score candidate lists.

Symbol correction (was "The public `withFilter(callable)` API is preserved", false-to-disk): no `withFilter` symbol exists anywhere in `src/` (grep-zero re-verified 2026-11-13).

Anti-pattern: Do not re-implement scoring logic in sugar-forms when sugar-fuzzy provides it.

Source: step-15 ai/sugar-forms-shared; correction per reviews/annotations/sugar-forms.md SF-15

### 2026-10-16 — Spinner absorbed from sugar-widgets

Pattern: `Spinner`, `Spinner\Style`, and `Spinner\TickMsg` were moved here from `sugar-widgets/src/Spinner/` (namespace `SugarCraft\Widgets\Spinner` → `SugarCraft\Forms\Spinner`). The sugar-widgets files are now thin `class_alias` shims — same pattern as the other extracted primitives.

### 2026-10-16 — sugar-forms owns its tests; leaf libs keep alias-integration tests

Pattern: sugar-forms now carries its own direct test suite under `tests/` (namespace `SugarCraft\Forms\Tests\*`) exercising the real `SugarCraft\Forms\*` classes. The original tests in `sugar-widgets/tests/` and `sugar-forms/tests/` were COPIED (not moved) and stay in place — they exercise the same classes through the `class_alias` shims, giving us alias-integration coverage. Do not delete the leaf-lib copies.

### 2026-10-16 — class_alias shim pattern (canonical)

Pattern: A re-exporting leaf class is a 6-line file: `declare(strict_types=1);`, the leaf namespace, a `// @deprecated Use SugarCraft\Forms\...` comment, and a single `class_alias('SugarCraft\Forms\X\Y', 'SugarCraft\Widgets\X\Y');`. The alias only fires when the autoloader loads the shim (triggered by a `use` of the leaf FQN), so every consuming lib must `require sugarcraft/sugar-forms` for the alias target to resolve.

### 2026-10-16 — Watch for stray leaf-namespace refs after extraction

Anti-pattern: `Scrollbar\ScrollbarState` still imported `SugarCraft\Widgets\Lang` after the extraction — invisible inside sugar-widgets (where that class autoloads) but a fatal `Class not found` when sugar-forms runs standalone. A direct test suite catches these; grep `src/` for `SugarCraft\\Widgets` / `SugarCraft\\Forms` after any extraction. Also keep the lang keys (`spinner.*`, `scrollbar.*`) in `sugar-forms/lang/en.php` in sync with what `src/` references.

### 2026-10-16 — VimKeyHandler shared vim mode handler

Pattern: `sugar-forms/src/Vim/VimKeyHandler` provides a unified vim keybinding handler (enum VimState: Insert/Normal/Visual/VisualLine; enum VimAction: CursorLeft, CursorRight, CursorWord, DeleteChar, etc.). 3 libs delegate to it (count corrected 2026-11-13 per sugar-forms-052 — the old "4 libs" double-counted sugar-forms): sugar-forms TextInput + TextArea (TUI-3B: vertical CursorUp/CursorDown motion and character-wise visual selection ride the same shared vocabulary; sugar-forms also reaches it via class_alias), sugar-readline ViMode, and sugar-widgets (via class_alias). Add new bindings to the VimAction enum + VimKeyHandler so all consumers benefit. Mirrors: `docs/old/repo_map_step_24.md`.

Note (TUI-3B, campaign R4 pending): the visual-LINE mode's pinned j→CursorLeft/k→CursorRight direction is NOT flipped by the new vertical cases — TextArea opens character-wise visual for both `v` and `V`; the line-wise direction stays an open owner ruling (findings/plan_sugar-forms.md 3.2).

Anti-pattern: Do NOT add new vim keybindings to per-lib branching logic. Always add to the `VimAction` enum + `VimKeyHandler` so all 3 delegating libs benefit at once.

### 2026-10-16 — Async suggestions cancel pattern [pattern:async-suggestions:cancel]

Pattern: Use `CancellationToken` for any user-cancellable async op. ReactPHP loop is shared — accept `LoopInterface`, don't construct. When implementing debounced async suggestions (e.g. `withAsyncSuggestions`), store a `CancellationSource` on the model and call `cancel()` on the previous source before scheduling a new timer, so rapid keystrokes only fire one fetch. The pattern: `$previous?->cancel(); $next = $next->withPendingAsyncCancellation(CancellationSource::new());`. Mirrors: `docs/old/repo_map_update.md §23.4`.

### 2026-10-16 — Slider mouse-zone drag pattern [pattern:mouse-zone-drag:slider]

Pattern: crush_media W3.2 added Slider mouse-drag with ZERO new composer requires: it types `SugarCraft\Core\Msg\MouseMsg` directly (sugar-core is already required). Two doors: `update()` answers the widget-relative form (focused + press on the track row; track is always view()'s LAST line — `trackLine()` counts title/description), and the public host-callable `handleZoneMouse(MouseMsg, int $zoneLeft, int $zoneWidth): ?self` serves geometry-owning hosts (candy-code runs sugar-mouse and hit-tests the absolute rect itself). Mapping is endpoint-inclusive — `(x - zoneLeft) / ($zoneWidth - 1 floored at 1)` scaled into [min, max] — and the number ALWAYS routes through `withValue()` so normalise() stays the single clamp/snap site (SliderTest's `max(`/`min(` token census counts comments too: never write `max(1, ...)` in this file's prose, guard the span with an if). Press/Motion/Release identical in v1; wheel + non-left refused (crush Viewport already maps wheel to Up/Down KeyMsgs — answering it too would double-step). Keyboard arms untouched: the MouseMsg arm sits BEFORE the door (ItemList E736 5.14 shape). Readonly refusal lives in the handler's own `isReadonly()` gate — MouseMsg stays unclassified in `HasReadonly::isReadonlyMutatingMsg` by design.

### 2026-10-16 — NumberField int-lattice exactness [pattern:int-lattice-exact:numberfield]

Pattern: crush_media W3.3 added `Field\NumberField` (draft-string + committed-value over TextInput) and `Field\RadioPills`. The int64 law: when min/max/step AND the raw are all int and the span fits int, coercion runs an overflow-free intdiv lattice walk (clamp + half-up tie, ties away from min — same rule as Slider's EPS law); anything floatish DELEGATES to `Slider::withValue()` so Slider's normalise() stays the library's ONE float clamp/snap site. Never let a seed cross `(float)` — `99999999999999999` must round-trip digit-exact (pin); beyond-int64 drafts are REFUSED with a Lang message, and thousands separators are refused, never guessed. Empty draft is not a commit (never-set sentinel `hasValue()`, empty != 0); commits land on Enter/blur/revalidate; consumes() claims only Up/Down (Date/Color precedent, Enter stays Form submit). RadioPills is a VALUE EDITOR in the E736 5.10 split (the highlight IS the value): readonly freezes arrows + mouse, unlike picker navigation. Adding either class means bumping the TraitStateCarryFamilyTest adopter census (12 after W3.3) — that fail-closed count is the roster, re-judge it in the same commit.

### 2026-10-16 — Fuzzy two-row DP core (historical; superseded) [pattern:fuzzy-smith-waterman-two-row]

Pattern: The former in-lib Smith-Waterman core used a two-row DP matrix instead of a full O(m×n) table for local alignment scoring. Rows are swapped (not copied) on each query-character iteration, keeping space at O(c) where c is candidate length. Adjacent consecutive matches receive a +5 bonus; mismatches cost -3. This was the canonical pattern for any in-memory fuzzy string scoring in PHP where memory must stay bounded. (Folded in from the retired prompt façade's learnings.) SUPERSEDED 2026-11-13: the hand-rolled core is removed; scoring delegates to sugar-fuzzy (see the corrected FuzzyMatcher entry above). Kept as history per the fix-not-delete ruling.

### 2026-10-16 — Async suggestions debounce shape [pattern:async-suggestions-debounce]

Pattern: `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)` on Input/Select uses `React\EventLoop\Loop::addTimer()` for debouncing, `React\Async\defer()` for async fetch wrapping, and dispatches `SugarCraft\Core\Msg\SuggestionsReadyMsg` when results arrive. The 150 ms default balances responsiveness against API call volume.

### 2026-10-16 — Validator short-form methods [pattern:validator-short-form-methods]

Pattern: Input field exposes short-form validator chains (`required()`, `email()`, `minlength(int)`, `maxlength(int)`) that delegate to `withValidator(new ValidatorType(...))`. This reduces boilerplate from `Input::new('x')->withValidator(new Required())->withValidator(new Email())` to `Input::new('x')->required()->email()`.

### 2026-10-16 — Fuzzy alias repoint with back-compat [pattern:fuzzy-alias-repoint-with-back-compat]

Pattern: The `Fuzzy\FuzzyMatcher` class was repointed to delegate to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` (the sugar-fuzzy implementation) with a back-compat shim kept at the old FQN so existing call sites resolve unchanged. When repointing a class_alias, always verify the old FQN is still loadable and add a dedicated alias-resolution test to catch accidental breakage.

### 2026-10-24 — Anti-pattern: vim keybindings per-lib [anti-pattern:vim-keybindings-per-lib]

Anti-pattern: Do NOT add new vim keybindings to per-lib branching logic. Vim mode is handled by `sugar-forms/src/Vim/VimKeyHandler` (shared). Always add new bindings to the `VimAction` enum + `VimKeyHandler` so sugar-widgets, sugar-forms, and sugar-readline all benefit at once. Mirrors: `docs/old/repo_map_step_24.md`.

### 2026-10-24 — Bare-accessor era (sugar-forms) [pattern:bare-accessor-era-forms]

Pattern: sugar-forms' bare-accessor era (2026-10-24) renamed 34 `get*` reads to bare twins (each keeping a one-line `@deprecated`/`REMOVED-IN: 2.0.0` forwarder): the `Field` contract's `getDescription()`/`getError()` plus the twelve fields' `description()`/`error()`, `Form::string()/int()/bool()/array()/focusedField()`, `TextArea::rowOffset()`, `Viewport::width()/height()`. 19 reads are documented legal exceptions — the bare slot is a live fluent setter (`title()`, `nextLabel()`, `height()`, `width()`, `styles()`) or the interface binds the name (`Field::getTitle`, `RadioPills::getTitle` via contract), and PHP admits one method per name. `tests/AccessorDeprecationTest.php` pins census==roster, marker set, one-line delegation, BC identity, the exception NOTEs (read from each method's OWN reflection docblock — an orphan comment stacked above a one-liner docblock is invisible to `getDocComment()`, proven by mutation), and the README tie. Do not plant a twin on an exception row; do not drop a NOTE. Exceptions are owner-pending a vocabulary ruling, exactly like sugar-style's 31 colliders.

## 2026-11-13 — sugar-added surface has no upstream counterpart

The seven Theme presets beyond the researched pair are sugar additions; charmbracelet/huh ships no equivalent. Never cite charmbracelet for them — the honest wording is 'no upstream symbol' (SF-14 precedent: a paraphrased cite proved wrong the same way). Citation sweep of 2026-11-13 (sugar-forms-058) re-spelled shortened Mirrors prose to canonical grammar and footnoted the unverified claims; per-symbol verification stays gated on a pinned research checkout.
