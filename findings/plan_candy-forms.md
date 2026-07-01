# Implementation Plan: candy-forms Corrections

---
status: not-started
phase: 1
updated: 2026-06-30
---

## Goal

Address all findings from `findings/candy-forms.md` through systematic, severity-ordered PRs: critical correctness bugs first, then performance, then refactoring, then missing features.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Critical correctness bugs (pendingAsyncSeq, empty-string hasErrors, revalidate no-op) must be fixed before any performance work | These cause incorrect behavior, not just slow code | `findings/candy-forms.md:9-26` |
| Group related fixes into cohesive PRs of 2-4 items | Per AGENTS.md PR size guidance | `AGENTS.md:PR workflow` |
| Deprecation of FuzzyMatcher should use SmithWatermanMatcher from candy-fuzzy | candy-fuzzy is already a dep; external lib is proper replacement | `findings/candy-forms.md:10-11` |
| Duplicate Field interface files must be consolidated | Field.php (top-level) is canonical; Field/Field.php is unused | `findings/candy-forms.md:13` |
| Two-pass validateAll() traversal is correctness + performance | Reduces O(2n) to O(n) | `findings/candy-forms.md:33` |

---

## Phase 1: Critical Correctness Fixes [PENDING]

- [ ] **1.1** `Input.php:434-437` — `pendingAsyncSeq` captured by value in closure, never updated on instance → Fix: increment on instance before capturing, or use array-box pattern. Same issue in Select.php:320. → `ref:investigation`
- [ ] **1.2** `Form.php:646-649` — `hasErrors()` returns true for empty-string errors (`''`) → Fix: check `$e !== ''` in hasErrors or skip empty strings in errors() → `ref:investigation`
- [ ] **1.3** `Field/Select.php:377` — `revalidate()` is a no-op with no doc comment → Fix: add clarifying doc comment explaining Select has no validators (correct no-op) → `ref:investigation`
- [ ] **1.4** `Fuzzy/FuzzyMatcher.php:39-49` — byte-oriented `strlen`/`strtolower` for UTF-8 strings → Fix: migrate Input::withFuzzySuggestions to use SmithWatermanMatcher from candy-fuzzy (already a dep) → `ref:investigation`
- [ ] **1.5** `Field/Select.php:126-139` — workerPool parameter accepted but never used in scheduleAsyncSuggestions → Fix: either implement worker pool offloading or remove dead parameter from both Input and Select → `ref:investigation`
- [ ] **1.6** `Field/Field.php` — duplicate interface file, never imported by any implementation → Fix: delete Field/Field.php, keep only top-level Field.php (which already has revalidate at line 97) → `ref:investigation`
- [ ] **1.7** `Confirm.php:59-64` — `withValidator()` bypasses mutate() via clone + direct property write → Fix: add `?Closure $validator` param to mutate() and use `mutate(validator: $fn)` in withValidator() → `ref:investigation`
- [ ] **1.8** `TextInput.php:721` — `@preg_match('/' . $pattern . '/', '')` validates restrict pattern → Fix: use a known safe test string like `'test'` instead of empty string to avoid metacharacter false positives → `ref:investigation`
- [ ] **1.9** `Field/Input.php:397-411` — async suggestions closure captures old `$this` fetcher/debounce values → Fix: add clarifying doc comment explaining captured-value semantics are intentional (fetcher at time of keystroke is correct) → `ref:investigation`
- [ ] **1.10** `TextInput.php:926-933` — mutate() complex deferred validation timing with convoluted condition → Fix: extract to named boolean variables (`$shouldDefer`, `$shouldValidate`) for readability; add tests for Blur/Submit timing → `ref:investigation`

### Phase 1 Notes
- **PR grouping recommendation**: Split into 3 PRs:
  - PR-A (Items 1.6 + 1.3): Duplicate interface deletion + Select revalidate doc
  - PR-B (Items 1.1 + 1.5 + 1.9): Async sequence counter + workerPool + closure capture
  - PR-C (Items 1.2 + 1.4 + 1.7 + 1.8 + 1.10): hasErrors + FuzzyMatcher migration + Confirm bypass + restrict pattern + complex validation timing

---

## Phase 2: High-Severity Performance Issues [PENDING]

- [ ] **2.1** `Form.php:661-695` — `validateAll()` does two full traversals (lines 664-675 collect values only, 679-693 revalidate) → Fix: combine into single O(n) pass that collects values AND revalidates in one loop → `ref:investigation`
- [ ] **2.2** `Select.php:267-275` — `new SmithWatermanMatcher()` instantiated on every keystroke when filtering → Fix: store as instance property `private readonly SmithWatermanMatcher $matcher` initialized once → `ref:investigation`
- [ ] **2.3** `Field/Input.php:326-347` — `buildChainedValidator()` creates O(N²) closure nesting for N validators → Fix: maintain indexed array of validators, iterate with loop in validate() instead of re-wrapping all previous → `ref:investigation`
- [ ] **2.4** `TextArea.php:587-601` — `totalLength()` computed O(lines) on every character insert for charLimit check → Fix: cache `private readonly int $totalLength` as promoted property, compute once in constructor and update in mutate() when lines change → `ref:investigation`
- [ ] **2.5** `MultiSelect.php:218-225` — `countTrue()` manual foreach loop → Fix: replace with idiomatic `count(array_filter($set))` → `ref:investigation`
- [ ] **2.6** `Viewport.php:445-459` — `maxOffset()`/`maxXOffset()` computed on every update() without caching → Fix: cache both as derived properties (`$this->cachedMaxOffset`, `$this->cachedMaxXOffset`), recompute only in mutate() when lines/height/width change → `ref:investigation`
- [ ] **2.7** `FilePicker.php:286-329` — `@scandir()` silently swallows errors (long paths, broken symlinks, permissions) → Fix: replace `@scandir` with explicit error check, set `$this->error` when directory read fails so view() displays feedback → `ref:investigation`
- [ ] **2.8** `Confirm.php:155` — direct property write `$next->validator = $this->validator` after clone in mutate() → Fix: add `?Closure $validator = null` to mutate() parameters for consistent handling → `ref:investigation`
- [ ] **2.9** `Form.php:471-488` and `Form.php:824-838` — `values()` and `collectValues()` share logic (both iterate isHidden/skippable) → Fix: extract `private function iterateFields(callable $callback, ?int $maxGroupIndex = null): mixed` helper → `ref:investigation`

### Phase 2 Notes
- **PR grouping recommendation**: Split into 2-3 PRs:
  - PR-D (Items 2.1 + 2.9): Form validateAll + values/collectValues consolidation
  - PR-E (Items 2.2 + 2.3): Select SmithWatermanMatcher reuse + Input O(N²) validator fix
  - PR-F (Items 2.4 + 2.5 + 2.6): TextArea totalLength cache + MultiSelect countTrue + Viewport maxOffset cache
  - PR-G (Items 2.7 + 2.8): FilePicker error surfacing + Confirm mutate parameter

---

## Phase 3: Medium-Severity Issues [PENDING]

- [ ] **3.1** `Cursor.php:26` — static `$nextId` monotonically increases forever in long-running daemons → Fix: replace with `spl_object_id()` which PHP provides natively, eliminating static counter → `ref:investigation`
- [ ] **3.2** `VimKeyHandler.php:228-234` — j/k mapped to left/right in visual-line mode with misleading comment → Fix: update comment from "// down one line" to "// select next/previous line" to reflect ItemList semantics → `ref:investigation`
- [ ] **3.3** `Form.php:498` — `get()` recomputes `values()` on every call, no memoization → Fix: add `$this->valuesCache` lazily computed and invalidated on any field mutation → `ref:investigation`
- [ ] **3.4** `MultiSelect.php:232-241` — `computeConstraintError()` calls `Lang::t()` on every toggle (Space keypress) → Fix: benchmark to determine overhead; if significant, cache resolved translation strings → `ref:investigation`
- [ ] **3.5** `Theme.php:184-187` — `catalog()` returns hardcoded list, must be manually updated for new themes → Fix: keep hardcoded (themes are frozen value objects), add doc comment explaining manual maintenance required → `ref:investigation`
- [ ] **3.6** `HasHideFunc.php:28` and `HasDynamicLabels.php:34-38` — clone+direct-write bypasses concrete mutate() → Fix: document pattern as intentional (concrete mutate() doesn't handle trait properties; clone-based approach is pragmatic) → `ref:investigation`
- [ ] **3.7** `ItemList.php:487-507` and `FilePicker.php:332-347` — `moveCursor()` nearly identical in both → Fix: extract to `private static function moveCursorInViewport(int $cursor, int $offset, int $height, int $itemCount, bool $infiniteScrolling): array{0:int,1:int}` → `ref:investigation`
- [ ] **3.8** `TextInput.php:686-696` — validator deferral logic interaction between `withValidator()` and `withValidateOn()` complex → Fix: add inline doc explaining Blur/Submit timing: "On Blur/Submit, error is deferred; on None/Change, error is immediate on first edit" → `ref:investigation`
- [ ] **3.9** `Confirm.php:122-125` — manual `Ansi::sgr(Ansi::REVERSE)`/`Ansi::reset()` instead of Style-based approach → Fix: evaluate refactoring to Style-based approach for consistency; if complex, document why manual ANSI is preferred → `ref:investigation`
- [ ] **3.10** `TextArea.php:781-788` — `renderCursorLine()` manually constructs ANSI instead of fully delegating to Cursor → Fix: line 787 already calls `$this->cursor->setChar($charAt)->view()`; document why before/after substr is manual → `ref:investigation`

---

## Phase 4: Low-Severity Issues [PENDING]

- [ ] **4.1** `FuzzyMatcher.php:39` — `strlen` not `mb_strlen` for multibyte → **Covered by Phase 1 Item 1.4** (FuzzyMatcher migration)
- [ ] **4.2** `MultiSelect.php:218` — countTrue manual loop → **Covered by Phase 2 Item 2.5** (array_filter replacement)
- [ ] **4.3** `TextInput.php:101-105` — `init()` returns null, cursor blink from `focus()` not `init()` → Fix: document as intentional (Form::init() propagates focus chain which schedules blink; init returning null is correct) → `ref:investigation`
- [ ] **4.4** `FilePicker.php:55` — `getcwd()` evaluated at construction time → Fix: document as intentional (working directory doesn't change during TUI session) → `ref:investigation`
- [ ] **4.5** `Confirm.php:61` — shallow clone in `withValidator()` → **Covered by Phase 1 Item 1.7** (withValidator uses mutate)
- [ ] **4.6** `Form.php:201` — `withKeyMap()` null-to-reset semantics implicit → Fix: add doc `@param KeyMap|null $keyMap pass null to reset to default keyMap` → `ref:investigation`
- [ ] **4.7** `TextInput.php:462-474` — `setValue()` skips re-validation on unchanged value, `withValidator()` does not → Fix: document asymmetry as intentional (programmatic setValue vs user-driven validator attachment) → `ref:investigation`
- [ ] **4.8** `Form.php:226-233` — short-form aliases lack explicit `self` return type → Fix: add explicit `: self` return type to all short-form alias methods → `ref:investigation`

---

## Phase 5: Missing Features [PENDING]

> These are feature requests, not bugs. Address in separate enhancement PRs as needed, in dependency order (foundation libs first).

### High Priority Missing Features
- [ ] **5.1** No per-field blur validation forwarding — `Field::update()` doesn't receive blur message → Design and implement blur message forwarding from Form to field for `ValidateOn::Blur` support
- [ ] **5.2** No async `Form::validateAll()` — always synchronous blocking → Add `validateAllAsync(): AsyncCmd` variant that returns async command for slow validators (network lookup, etc.)
- [ ] **5.3** No `Form::focusField(string $key)` for programmatic focus management → Add method to programmatically move focus to specific field by key
- [ ] **5.4** No per-field keybinding overrides — KeyMap applies to Form navigation only → Add per-field `withKeyMap()` override so individual fields can customize their key handling

### Medium Priority Missing Features
- [ ] **5.5** No input masking for credit cards/phone numbers/SSN (password field withEchoMode exists, but pattern-based masking like showing only last 4 digits doesn't)
- [ ] **5.6** No date/time/datetime-local picker fields — common form inputs not available
- [ ] **5.7** No range/slider numeric field — 1-10 slider input common in forms
- [ ] **5.8** No color picker field — color selection not implemented
- [ ] **5.9** No form state persistence — no mechanism to serialize form state to JSON and restore
- [ ] **5.10** No readonly fields — Note is display-only but skip is separate from readonly; need fields that display but don't accept input
- [ ] **5.11** No help text per validation error — validators return single error string, not contextual help
- [ ] **5.12** No keyboard shortcut for numbered field navigation — no `1-9` jump-to-field
- [ ] **5.13** No clipboard/copy support in TextArea — Ctrl+C in TextArea doesn't copy selected text (no selection in current impl)
- [ ] **5.14** No mouse click selection in ItemList — update() only handles KeyMsg, not MouseMsg
- [ ] **5.15** No infinite scrolling / load-more callback for ItemList — `infiniteScrolling` flag exists but no callback fires at end

### Low Priority Missing Features
- [ ] **5.16** No autocomplete="off" equivalent — browser-like autocomplete attribute for username/password fields not supported
- [ ] **5.17** No drag-and-drop support for FilePicker — only keyboard navigation

---

## Phase 6: Async Pattern Improvements [PENDING]

- [ ] **6.1** No stream-based async suggestions — current pattern uses single Deferred that resolves once → Design and implement streaming suggestions API (returns AsyncGenerator or multiple Updates) or document as out of scope
- [ ] **6.2** CancellationSource/Debouncer pattern could be simplified — Timer + CancellationToken + Promise boilerplate → Design unified `AsyncSuggestionRequest` object wrapping all three
- [ ] **6.3** workerPool parameter dead code → **Covered by Phase 1 Item 1.5** (implement or remove)
- [ ] **6.4** Many simultaneous `Loop::addTimer` debounce timers in ReactPHP app (Input.php:449, Select.php:337) → Design shared debounce timer wheel or document as acceptable for typical use
- [ ] **6.5** No timeout on async suggestion fetch promises — never-resolving fetcher hangs form forever → Add `->timeout($seconds)` to promise chain in scheduleAsyncSuggestions closure
- [ ] **6.6** Unhandled promise rejection when CancellationToken cancelled (Input.php:472 returns AsyncCmd with unhandled rejection) → Add `$promise->otherwise(static fn() => null)` to suppress ReactPHP warning about unhandled rejection → `ref:investigation`
- [ ] **6.7** Viewport uses `SubscriptionCapable` trait but doesn't use it — `subscriptions()` returns null explicitly → Remove the `use \SugarCraft\Core\SubscriptionCapable;` trait since Viewport implements its own null return anyway → `ref:investigation`
- [ ] **6.8** No structured concurrency — form submission/abort doesn't cancel pending async operations → Add cleanup of orphaned timers/Deferreds when Form::update() returns early at line 298-300 (submitted/aborted check)
- [ ] **6.9** AsyncCmd cold-start orphan risk — async operation could complete before Form::init() is called → Document timing constraint: "Async suggestions should be pre-loaded via focus() cmd, not before init()"

---

## Phase 7: Compatibility Issues [PENDING]

- [ ] **7.1** Inconsistent fuzzy matching: Input uses deprecated byte-oriented FuzzyMatcher, Select uses proper SmithWatermanMatcher → **Covered by Phase 1 Item 1.4** (migrate Input to SmithWatermanMatcher)
- [ ] **7.2** PHP 8.4 deprecates implicit defaults for readonly properties — library targets PHP 8.3+ → Verify no implicit defaults on readonly properties; test on PHP 8.4 when available
- [ ] **7.3** `FilePicker::Entry::icon()` returns Unicode emoji that may not render in all terminal emulators → Document emoji font requirement; consider ASCII fallback option (e.g., `[D]` for directory, `[F]` for file)
- [ ] **7.4** `composer.json` uses `dev-master` versions with `minimum-stability: dev` → Document as intentional for monorepo development; plan proper `^1.0` version pinning at stable release

---

## Phase 8: Refactoring Opportunities [PENDING]

- [ ] **8.1** `Field/Field.php` duplicate interface → **Covered by Phase 1 Item 1.6**
- [ ] **8.2** `moveCursor()` duplication → **Covered by Phase 3 Item 3.7**
- [ ] **8.3** `buildChainedValidator` O(N²) → **Covered by Phase 2 Item 2.3**
- [ ] **8.4** `validate()` early return optimization — Input.php:509 already has early exit; no further optimization needed
- [ ] **8.5** `TextInput::$this->length()` called on every insert/backspace/delete/moveCursor → Add `private readonly int $length` cached in mutate() for O(1) access (computed from mb_strlen on value) → `ref:investigation`
- [ ] **8.6** Theme static factories hardcode `Color::ansi()`/`Color::hex()` calls throughout → Extract color values to named constants (e.g., `private const ACCENT = '#ff5fd2'`) or color palette config to make themes more maintainable
- [ ] **8.7** `withDescriptionFunc` and `withTitleFunc` structurally nearly identical → Extract to single `withLabelFunc(string $type, ?Closure $fn)` method with switch on type, or keep as-is for clarity

---

## Implementation Notes

### General
- All changes must maintain `declare(strict_types=1)` and PSR-12 compliance
- All changes must pass `vendor/bin/phpunit` for the candy-forms package
- All changes must follow immutable+fluent `with*()` pattern per AGENTS.md
- PR size: 2-4 related items per PR per AGENTS.md PR workflow section
- Author: Joe Huss `<detain@interserver.net>` per AGENTS.md

### Critical Path Execution Order
1. **Phase 1 (Critical)** — Must be addressed first; correctness bugs
2. **Phase 2 (High Performance)** — Performance issues that may become correctness problems at scale
3. **Phase 3 (Medium)** — Code quality and maintainability
4. **Phase 4 (Low)** — Documentation and minor cleanup
5. **Phases 5-8 (Features/Refactoring)** — Future enhancement PRs, not blocking

### Recommended PR Sequence
| PR | Items | Description |
|----|-------|-------------|
| PR-1 | 1.6, 1.3 | Delete duplicate Field/Field.php + Select revalidate doc |
| PR-2 | 1.1, 1.5, 1.9 | Async sequence counter + workerPool + closure capture |
| PR-3 | 1.2, 1.4, 1.7, 1.8, 1.10 | hasErrors + FuzzyMatcher + Confirm + restrict + validation timing |
| PR-4 | 2.1, 2.9 | Form validateAll single-pass + values/collectValues consolidation |
| PR-5 | 2.2, 2.3 | Select matcher reuse + Input O(N²) validator fix |
| PR-6 | 2.4, 2.5, 2.6 | TextArea totalLength cache + MultiSelect countTrue + Viewport maxOffset |
| PR-7 | 2.7, 2.8 | FilePicker error surfacing + Confirm mutate parameter |
| PR-8 | 3.x, 4.x | Medium and low severity items (bundled 2-4 per PR) |
| PR-9 | 6.x | Async pattern improvements (6.5, 6.6, 6.7 are quick wins) |

### Verification Strategy
- Each PR: run `vendor/bin/phpunit` in candy-forms directory
- Each PR: run `composer validate` (without `--strict` per AGENTS.md note)
- Each PR: verify no new PHPStan errors if level is configured
- End-to-end: VHS demo still renders correctly if visual output changed
