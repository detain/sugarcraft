# Caliber Learnings — candy-forms

> Accumulated patterns and gotchas discovered during development.
> This file grows over time — do not delete entries from previous sessions.

<!-- Add learnings below as they are discovered -->

### 2026-05-29 — Golden-file snapshot convention (candy-testing)
Pattern: Render output tests use `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi($goldenPath, $actual)` — the actual ANSI bytes are compared against a fixture file at `$goldenPath`. Fixture files live in `tests/fixtures/` and carry a `.golden` extension. Set `UPDATE_GOLDENS=1` in the environment to auto-regenerate any missing fixture.
Anti-pattern: Do NOT use string equality (`assertEquals`) for ANSI output — ESC sequences in a mismatch produce an unreadable diff. Always use `assertGoldenAnsi` or `assertAnsiEquals`.
Source: step-15 ai/candy-forms-shared

### 2026-05-29 — FuzzyMatcher back-compat shim (candy-fuzzy)
Pattern: `SugarCraft\Forms\Fuzzy\FuzzyMatcher` (step-07) is now a deprecated alias for `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`. There is zero behavioural divergence — the internal Select/MultiSelect filter uses `SmithWatermanMatcher` directly. The public `withFilter(callable)` API is preserved; callers injecting a custom filter are unaffected.
Anti-pattern: None identified. Do not re-implement scoring logic in candy-forms when candy-fuzzy provides it.
Source: step-15 ai/candy-forms-shared

## Spinner absorbed from sugar-bits

`Spinner`, `Spinner\Style`, and `Spinner\TickMsg` were moved here from
`sugar-bits/src/Spinner/` (namespace `SugarCraft\Bits\Spinner` →
`SugarCraft\Forms\Spinner`). The sugar-bits files are now thin
`class_alias` shims — same pattern as the other extracted primitives.

## candy-forms owns its tests; leaf libs keep alias-integration tests

candy-forms now carries its own direct test suite under `tests/`
(namespace `SugarCraft\Forms\Tests\*`) exercising the real
`SugarCraft\Forms\*` classes. The original tests in `sugar-bits/tests/`
and `sugar-prompt/tests/` were COPIED (not moved) and stay in place — they
exercise the same classes through the `class_alias` shims, giving us
alias-integration coverage. Do not delete the leaf-lib copies.

## class_alias shim pattern (canonical)

A re-exporting leaf class is a 6-line file: `declare(strict_types=1);`,
the leaf namespace, a `// @deprecated Use SugarCraft\Forms\...` comment, and
a single `class_alias('SugarCraft\Forms\X\Y', 'SugarCraft\Bits\X\Y');`. The
alias only fires when the autoloader loads the shim (triggered by a `use` of
the leaf FQN), so every consuming lib must `require sugarcraft/candy-forms`
for the alias target to resolve.

## Watch for stray leaf-namespace refs after extraction

`Scrollbar\ScrollbarState` still imported `SugarCraft\Bits\Lang` after the
extraction — invisible inside sugar-bits (where that class autoloads) but a
fatal `Class not found` when candy-forms runs standalone. A direct test suite
catches these; grep `src/` for `SugarCraft\\Bits` / `SugarCraft\\Prompt` after
any extraction. Also keep the lang keys (`spinner.*`, `scrollbar.*`) in
`candy-forms/lang/en.php` in sync with what `src/` references.

## VimKeyHandler shared vim mode handler

`candy-forms/src/Vim/VimKeyHandler` provides a unified vim keybinding handler
(enum VimState: Insert/Normal/Visual/VisualLine; enum VimAction: CursorLeft,
CursorRight, CursorWord, DeleteChar, etc.). 4 libs now delegate to it:
candy-forms TextInput, sugar-readline ViMode, sugar-prompt (via class_alias),
sugar-bits (via class_alias). Add new bindings to VimAction enum + VimKeyHandler
so all consumers benefit. Mirrors: `docs/repo_map_step_24.md`.

Anti-pattern: Do NOT add new vim keybindings to per-lib branching logic.
Always add to `VimAction` enum + `VimKeyHandler` so all 4 libs benefit.

- **[pattern:async-suggestions:cancel]** Use `CancellationToken` for any user-cancellable async op. ReactPHP loop is shared — accept `LoopInterface`, don't construct. When implementing debounced async suggestions (e.g. `withAsyncSuggestions`), store a `CancellationSource` on the model and call `cancel()` on the previous source before scheduling a new timer, so rapid keystrokes only fire one fetch. The pattern: `$previous?->cancel(); $next = $next->withPendingAsyncCancellation(CancellationSource::new());`. Mirrors: `docs/repo_map_update.md §23.4`.

- **[pattern:mouse-zone-drag:slider]** crush_media W3.2 added Slider mouse-drag with ZERO new composer
  requires: it types `SugarCraft\Core\Msg\MouseMsg` directly (candy-core is already required). Two doors:
  `update()` answers the widget-relative form (focused + press on the track row; track is always view()'s
  LAST line — `trackLine()` counts title/description), and the public host-callable
  `handleZoneMouse(MouseMsg, int $zoneLeft, int $zoneWidth): ?self` serves geometry-owning hosts
  (sugar-crush runs candy-mouse and hit-tests the absolute rect itself). Mapping is endpoint-inclusive —
  `(x - zoneLeft) / ($zoneWidth - 1 floored at 1)` scaled into [min, max] — and the number ALWAYS routes
  through `withValue()` so normalise() stays the single clamp/snap site (SliderTest's `max(`/`min(` token
  census counts comments too: never write `max(1, ...)` in this file's prose, guard the span with an if).
  Press/Motion/Release identical in v1; wheel + non-left refused (crush Viewport already maps wheel to
  Up/Down KeyMsgs — answering it too would double-step). Keyboard arms untouched: the MouseMsg arm sits
  BEFORE the door (ItemList E736 5.14 shape). Readonly refusal lives in the handler's own `isReadonly()`
  gate — MouseMsg stays unclassified in `HasReadonly::isReadonlyMutatingMsg` by design.
