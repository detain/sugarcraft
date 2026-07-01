## Summary

candy-forms is a well-structured TUI form library implementing the Bubble Tea/TEA pattern with immutable models. It provides TextInput, TextArea, Select, MultiSelect, Confirm, Note, FilePicker fields, plus Viewport, Scrollbar, Spinner, and Vim keybinding support. The codebase follows PSR-12, uses immutable+fluent with*() patterns, and has good test coverage (681-line FormTest.php, 901-line TextInputTest.php).

Critical issues: deprecated FuzzyMatcher still actively used; async suggestions CancellationSource pattern has subtle capture-by-value issues; Select::revalidate() is a no-op despite being part of the Field contract; ValidateOn::Change is declared but unused. High severity: the async suggestions pattern in Select doesn't forward the workerPool parameter; Select::withAsyncSuggestions accepts workerPool but scheduleAsyncSuggestions ignores it; Form::hasErrors() returns false for empty-string error messages. Medium: duplicate Field interface files; Cursor::$nextId static int persists across async context boundaries; VimKeyHandler has no 'down' key mapping for visual-line mode (j/k are swapped for left/right instead). Low: FuzzyMatcher uses byte-strlen not mb_strlen; countTrue in MultiSelect is manual loop instead of array_filter; Confirm::withValidator bypasses mutate().

## Critical Issues (file:line format)

1. **Input.php:434-437** — `$currentSeq = ++$this->pendingAsyncSeq` captures `$this->pendingAsyncSeq` by value in the closure, so `++$this->pendingAsyncSeq` increments a local copy, not the instance field. The `$currentSeq` used inside the closure IS the incremented value, so the sequence value itself is correct — BUT the underlying field's `pendingAsyncSeq` is never updated on the instance. This means the seq counter on the field instance stays at its old value, and multiple rapid keystrokes may schedule operations with duplicate sequence numbers that won't be distinguishable.

2. **Field/Select.php:126-139** — `withAsyncSuggestions` accepts `WorkerPool $workerPool = null` but `scheduleAsyncSuggestions` at line 312 doesn't take a workerPool parameter and never uses it. The offloading capability is dead code — async suggestions always run via Loop::addTimer on the main event loop regardless of what workerPool is passed.

3. **Field/Field.php** — This file is nearly identical to Field.php (the top-level interface). The Field/Field.php has the same interface contract plus the revalidate() method that was added later, but both files exist and the top-level Field.php is what is actually imported by all field implementations. Field/Field.php appears to be a refactoring leftover or parallel namespace that is never used.

4. **Fuzzy/FuzzyMatcher.php:48-49** — Uses `strlen($query)` and `strtolower($query[$i-1])` which are byte-oriented, not multibyte-safe. Input::withFuzzySuggestions() at line 221 wraps FuzzyMatcher::match() for suggestions, passing user input strings. For UTF-8 input with multibyte characters (e.g., Japanese, emoji in filenames), the scoring will be byte-oriented and potentially corrupt. The class is marked @deprecated but is still actively used by Field/Input::withFuzzySuggestions() at line 216-243.

5. **Field/Select.php:377** — `revalidate(): Field { return $this; }` is a no-op. The Field interface contract (Field.php:96-97) says revalidate() should "Re-run validators/constraints against the current value and return a new field instance with the recomputed error." Select has no validators, but this makes Form::validateAll() silently skip Select fields when checking for errors, which is correct behavior — but the no-op implementation may mislead future implementers. More critically, Text.php's revalidate() at line 120 delegates to validate() which DOES check $this->validator, making the contract inconsistently implemented.

6. **Form.php:646-649** — `hasErrors()` returns `errors() !== []`. If `errors()` returns `['field' => '']` (empty string error — which can happen if a validator returns ''), `hasErrors()` returns true but the error message displayed would be "! " (just the prefix, no message). This edge case produces visible artifacts in view() output.

7. **TextInput.php:721** — `withRestrict(string $pattern)` uses `@preg_match('/' . $pattern . '/', '')` to validate the pattern. The `@` suppresses warnings but if `$pattern` contains regex metacharacters like `.+*?` etc., the validation will pass or fail depending on how the PCRE engine interprets them. The method throws \InvalidArgumentException on failure but the test coverage for invalid patterns may be incomplete. Also, the restrict pattern is checked per-character in insert() at line 816 using preg_match without delimiters (correct for this API), but the pattern is used raw without escaping user input.

8. **Confirm.php:59-64** — `withValidator(?Closure $fn)` clones `$this` and directly sets `$clone->validator = $fn`, bypassing the `mutate()` method which handles revalidation. While the clone IS revalidated, the direct property write on a cloned instance bypasses any future invariants that mutate() might enforce. This is a maintenance hazard if the class evolves.

9. **Field/Input.php:397-411** — The async suggestions scheduling captures `$this->asyncSuggestionsFetcher` and `$this->asyncSuggestionsDebounceMs` directly from `$this` (the old instance at the time the closure is created). When rapid keystrokes create multiple pending async operations, each closure will use whatever fetcher was on the `$this` instance it was created from. If the Input field is mutated to replace the asyncSuggestionsFetcher between scheduling and execution, the old closure will still call the original fetcher. This is probably intentional (the fetcher at time of keystroke should be used) but worth noting as a subtle captured-value semantics issue.

10. **TextInput.php:926-933** — The mutate() method's auto-revalidation logic has complex deferred validation timing:
    - When `ValidateOn::Blur` and the value hasn't actually changed but validate is set, `err` is set to null at line 928-929 if `!$deferred` even though `value !== null`. This means calling blur() with ValidateOn::Blur clears errors even if the field was never validated on the previous blur event.
    - The condition `!$deferred && $resolvedValidate !== null && ($value !== null)` at line 930 means validation ONLY runs on the first edit when validateOn is None or Change; for Blur/Submit it always sets err=null unless errSet is true. This is correct for Blur/Submit timing but the logic is convoluted and hard to verify.

## High Severity Issues

1. **Form.php:661-695** — `validateAll()` does a full group/field traversal to populate `$accumulated` (lines 664-675), then immediately does ANOTHER full traversal (lines 679-693) for the actual revalidation. The first loop only collects accumulated values but performs no validation — this could be combined into a single pass.

2. **Form.php:471-488** — `values()` iterates all groups and fields, calling `isHidden()` and `skippable()` on every field, then builds an accumulated array. `collectValues()` at line 824-838 does a similar traversal but stops at `groupIndex` and skips hidden/skippable. These two methods have significant overlap and could share a helper.

3. **Viewport.php:445-459** — `maxOffset()` and `maxXOffset()` are called on every update() to clamp values, and `maxXOffset()` calls `Width::string()` on every line in the content array. For Viewports with large content (e.g., displaying a large file), this O(lines × avgLineWidth) computation on every keystroke could be a bottleneck. No caching of the max offset is present.

4. **Select.php:267-275** — Every update() when in filtering mode, a new SmithWatermanMatcher is instantiated (`$matcher = new SmithWatermanMatcher()`) inside the hot path. This creates a new object on every keystroke when filtering. Should be stored as state or reused.

5. **FilePicker.php:286-329** — `readDir()` uses `@scandir($this->cwd)` which suppresses all errors. If `$this->cwd` is a very long path, a broken symlink, or has permission issues, the error is silently ignored and an empty entry list is returned. The user gets no feedback about why their directory appears empty.

6. **Field/Input.php:326-347** — `buildChainedValidator()` wraps the existing validators in a new closure that iterates them. Every call to `withValidator()` creates a new wrapper closure AND re-wraps all previous validators. With N validator calls, this creates O(N²) closure nesting. For forms with many validators on an Input field, this is a performance concern.

7. **TextArea.php:587-601** — `insert()` validates `charLimit` on every character insertion by computing `totalLength()` (sum of mb_strlen of all lines plus newlines). For large TextAreas, this is O(n) per keystroke. No caching of total length.

8. **MultiSelect.php:218-225** — `countTrue()` iterates the selected array manually instead of using `array_filter(..., ARRAY_FILTER_USE_KEY)` or `count(array_filter($set))`. For large option sets, this is a minor performance issue.

9. **Confirm.php:155** — `$next->validator = $this->validator;` direct property write after clone — inconsistent with other fields that preserve trait properties via clone in mutate().

## Medium Severity Issues

1. **Field/Field.php is a duplicate** of Field.php (top-level interface). Both declare the same interface. Field/Field.php appears to be the new version with revalidate() added, while Field.php is the original. All implementations import `SugarCraft\Forms\Field` (the top-level one), not `SugarCraft\Forms\Field\Field`. The namespace `SugarCraft\Forms\Field\` is used for ALL field implementation classes (Input.php, Select.php, etc.) but the interface in that namespace (Field/Field.php) is never imported by any implementation. This creates confusion about which interface is canonical.

2. **Cursor.php:26** — `private static int $nextId = 0;` is a static int that monotonically increases across ALL Cursor instances in the PHP process. In a long-running ReactPHP daemon with many forms created and destroyed, this counter grows indefinitely. While PHP int is unbounded, it's a memory/CPU concern in async workloads creating many cursors over time. IDs are used to route BlinkMsg to the correct cursor, and instances without an explicitly passed id get `++self::$nextId`. No id reuse.

3. **MultiSelect.php:232-241** — `computeConstraintError()` uses Lang::t() which does a translation lookup. This is called on every toggle() operation (every Space keypress). For high-frequency interaction, this is fine — but if many MultiSelect fields exist with min/max constraints, the Lang::t() dispatch overhead accumulates.

4. **VimKeyHandler.php:228-234** — Visual-line mode maps 'j' to `VimAction::CursorLeft` and 'k' to `VimAction::CursorRight`. In standard vim, j/k move down/up (linewise), not left/right. This is intentional for the ItemList context where visual-line mode selects whole lines and j/k navigate between lines — but the comment at line 229 says "// down one line" which is misleading since the action is CursorLeft (not CursorDown). The action name is semantically wrong for what it actually does in ItemList.

5. **Form.php:498** — `get()` calls `values()` which does a full O(fields) traversal, then does an `array_key_exists` on the result. For repeated `get()` calls on a form with many fields, this recomputes values every time. No memoization.

6. **TextInput.php:686-696** — The validator deferral logic in `withValidator()` is inconsistent: when `validateOn === ValidateOn::Blur`, the error is set to null on attachment (line 695) but `mutate()` at lines 926-933 will re-run validation immediately if `value !== null`. So calling `withValidator(fn)` on a focused TextInput with a value will immediately validate. This is correct behavior but the interaction between `withValidator()` and `withValidateOn()` timing is complex.

7. **Theme.php:184-187** — `catalog()` returns a hardcoded list of theme names. If a new theme factory is added, this list must be manually updated or it becomes stale. The theme name list is not derived from theme class reflection or a registry pattern.

8. **HasHideFunc.php:28** — `withHideFunc()` clones `$this` and directly sets `$clone->hideFunc = $fn`. This bypasses any potential invariants in the concrete field's `mutate()` method. For Input, the mutate() signature doesn't include hideFunc parameters, so a clone-based approach is reasonable — but it's inconsistent with how the same trait is supposed to work (the AGENTS.md example shows mutate() carrying trait properties forward).

9. **HasDynamicLabels.php:34-38** — Same issue: `withTitleFunc()` and `withDescriptionFunc()` use clone + direct property write rather than going through the concrete field's mutate(). If the concrete field's mutate() ever needs to know about titleFunc/descriptionFunc changes (e.g., to recompute derived state), these trait methods would bypass that.

10. **ItemList.php:487-507** — `moveCursor()` is duplicated across FilePicker and ItemList with nearly identical logic (cursor clamping + offset management). FilePicker also has `reclamp()` (line 349-352) and `moveCursor()` (line 332-347) that are structurally identical to ItemList's versions. A shared scrollable widget base trait or trait composition could eliminate this duplication.

## Low Severity Issues

1. **FuzzyMatcher.php:39-41** — Uses `strlen($query)` (byte count) instead of `mb_strlen($query, 'UTF-8')`. For pure ASCII this is fine, but for any internationalized input this produces incorrect indices. The matcher scores byte positions but the results are used to filter/rank string candidates — if input is UTF-8, the alignment scoring will be misaligned with the actual string.

2. **MultiSelect.php:218** — `countTrue()` is a static private method using a manual foreach loop. Could be `count(array_filter($set, static fn($v): bool => $v))` or `count(array_keys(array_filter($set)))`.

3. **TextInput.php:101-105** — `init()` returns null, but TextInput embeds a Cursor that has its own init() which schedules the blink tick. The Cursor's blink scheduling is triggered from focus() returning a Cmd, not from init(). If a TextInput is created and its init() is called directly (not via focus()), the cursor will not blink. This is probably fine since Form::init() propagates the focused field's focus() Cmd — but it's a subtle asymmetry that init() doesn't start blinking.

4. **FilePicker.php:55** — `getcwd()` is called when no $cwd is passed. This is evaluated at construction time. If the working directory changes between construction and first render (unlikely but possible in long-running daemons), the cwd used may be stale. Should probably capture it once.

5. **Confirm.php:61** — `clone $this` in withValidator() creates a shallow clone. If Confirm had any object properties beyond the primitive validator closure, they'd be shared with the clone. Currently not a problem (validator is a closure) but a maintenance hazard.

6. **Form.php:201** — `withKeyMap()` passes `keyMapSet: true` to mutate() but the mutate() signature at line 886 has `?KeyMap $keyMap = null, bool $keyMapSet = false`. When $keyMapSet is true and $keyMap is null, this passes null to the keyMap parameter. The ternary at line 905 `$keyMap ?? $this->keyMap` would then use the existing keyMap (correct if clearing). But if someone calls withKeyMap(KeyMap::default()) to reset, the boolean flag is set and null is passed — this works but the semantics of "null to reset" are implicit in the flag, not explicit.

7. **Theme.php:42-59** — The `ansi()` theme uses `Color::ansi(13)` for accent (bright magenta). Color::ansi() is called with numeric indices but it's unclear if these always map consistently across terminal emulators. Theme classes are frozen value objects with no validation of color indices. If Color::ansi() returns invalid values, the theme silently produces wrong colors.

8. **ScrollbarState.php:31-48** — Constructor validates invariants and throws \InvalidArgumentException. This is good defensive programming but the exception messages come from Lang::t() which is a good approach. However, there's no factory constructor for default-construction (the `new()` static at line 53 exists but still requires valid parameters).

9. **TextInput.php:462-474** — `setValue()` returns early if `$v === $this->value` (line 468) without running validation. This is correct (skip spurious re-validation on unchanged value) but the side effect is that if a validator was set with `ValidateOn::Blur`, attaching it to an existing TextInput via `withValidator()` immediately validates (line 244 in TextInputTest), but `setValue()` on the same instance would skip re-validation. The test confirms attach-then-set triggers validation, but setValue-on-same-value skips it.

10. **Form.php:226-233** — Short-form aliases (e.g., `title(string $t): self`) call the long-form method (e.g., `withTitle(string $t): self`) which is correct, but the alias method signature doesn't include return type `self` in some cases. The PSR-12 compliance is unclear on whether void return type on an alias method is required.

## Missing Features

1. **No Field validation on blur** — Input, Text, Confirm all validate on the `validate()` call in their update() loop (immediate on keystroke). There is no `ValidateOn::Blur` for Form fields (only for TextInput's embedded TextInput widget). Fields don't get a blur event forwarded — `Field::update()` doesn't receive a blur message. This means per-field blur validation isn't possible in the Form context.

2. **No async Field validation** — Form::validateAll() is synchronous and blocks. For slow validators (e.g., network lookup to check username uniqueness), there's no async support. AsyncCmd could be returned from validateAll() but it returns `array<string, string>` synchronously.

3. **No Form-level timeout enforcement** — Form stores `timeoutMs` but there is no runtime enforcement. The comment says "The form stores the budget for the runtime to consult — it does not start a timer itself" — meaning the caller/runtime is responsible for enforcing. There's no sugar to make this automatic.

4. **No built-in input masking for credit cards, phone numbers, etc.** — Password field is supported (withEchoMode), but there is no pattern-based masking (e.g., showing only last 4 digits of SSN).

5. **No date/time picker field** — Date, time, and datetime-local inputs common in forms are not provided.

6. **No range/slider field** — Numeric range inputs (1-10 slider) aren't available.

7. **No color picker field** — Color selection isn't implemented.

8. **No autocomplete="off" equivalent** — The browser-like autocomplete attribute for username/password fields isn't supported.

9. **No form persistence** — No built-in mechanism to serialize form state to JSON and restore it later.

10. **No readonly fields** — Fields that can display values but not be edited aren't available (Note is display-only but skip is separate from readonly).

11. **No help text per validation error** — Validators return a single error string, not contextual help about what valid input looks like.

12. **No keyboard shortcut for jumping directly to a specific field** — No numbered field navigation or similar.

13. **No programmatic focus management** — No `Form::focusField(string $key)` to programmatically move focus to a specific field.

14. **No field-level keybinding overrides** — KeyMap applies to Form navigation, not per-field bindings. There's no per-field keybinding customization (e.g., changing Input's Enter behavior).

15. **No clipboard/copy support for TextArea** — Ctrl+C in TextArea doesn't copy selected text (there's no text selection in this implementation).

16. **No drag-and-drop support for FilePicker** — Only keyboard navigation.

17. **No mouse click to select in ItemList** — ItemList update() only handles KeyMsg, not mouse events. Can't click to select.

18. **No infinite scrolling / load-more callback for ItemList** — The infiniteScrolling flag exists but no callback fires when reaching the end.

## Duplicated Logic / Refactoring Opportunities

1. **Field/Field.php vs Field.php** — The Field interface is declared twice. `SugarCraft\Forms\Field` (top-level, 98 lines) and `SugarCraft\Forms\Field\Field` (87 lines, nearly identical, missing revalidate in one version check). Consolidate into one. The top-level Field.php appears to be the canonical one in use.

2. **countTrue in MultiSelect.php:218-225** — Manual iteration loop instead of `count(array_filter($set))`.

3. **moveCursor logic duplicated in FilePicker.php:332-347 and ItemList.php:487-507** — Both implement the same cursor-clamping + offset-adjustment algorithm. Extract to a shared `moveCursorInViewport(int $cursor, int $offset, int $height, int $itemCount): array{0:int, 1:int}` helper.

4. **collectValues() in Form.php:824-838 and values() iteration in Form.php:471-488 share logic** — Both iterate groups/fields checking isHidden() and skippable(). Could be a single helper that optionally collects values while iterating.

5. **validateAll() in Form.php:661-695 does two full iterations** — Lines 664-675 and 679-693 traverse the same structure. Combine into a single pass.

6. **SmithWatermanMatcher instantiation in Select.php:268** — New instance every update() in filter mode. Move to a static or store as instance property.

7. **buildChainedValidator in Input.php:326-347** — Creates O(N²) wrapper closures for N validators. Could use a simple indexed array + loop index instead of re-wrapping all previous validators.

8. **validate() in Input.php:502-548** — The error === $this->error early-return optimization is good, but the full validator loop (lines 507-529) could short-circuit earlier with a break statement when the first error is found.

9. **renderCursorLine in TextArea.php:781-788** — Duplicates the cursor rendering logic that Cursor::view() already handles. TextArea::renderCursorLine constructs ANSI output manually instead of delegating to Cursor's view().

10. **Ansi::sgr/reset usage in Confirm.php:122-125** — Manual ANSI string construction for the focused option. Could use a Style-based approach like other components do for consistency.

11. **FuzzyMatcher uses byte operations on what may be UTF-8 strings** — Should use mb_strlen/mb_substr throughout for proper multibyte handling. Alternatively, mark as ASCII-only and document the constraint.

12. **HasHideFunc and HasDynamicLabels clone-based approach vs mutate** — Both traits use `clone $this` + direct property write instead of going through the concrete field's mutate(). This creates two different patterns for state updates in the same class hierarchy.

13. **Theme static factories in Theme.php:41-177** — All hardcode Color::ansi() or Color::hex() calls. The color values could be extracted to constants or a color palette config to make themes more maintainable.

14. **withDescriptionFunc and withTitleFunc in HasDynamicLabels.php:33-49** — Both nearly identical. Could be a single `withLabelFunc(string $type, ?Closure $fn)` method.

15. **TextInput uses $this->length() everywhere** — `length()` method computes `mb_strlen($this->value, 'UTF-8')`. This is called frequently (on every insert, backspace, delete, moveCursor). Could be cached as a derived property since value changes are known points.

16. **TextArea totalLength() method (line 717-725)** — Called on every insert() to check charLimit. Iterates all lines summing mb_strlen. Should be cached as `$this->totalLength` updated in mutate().

## Compatibility Issues

1. **candy-fuzzy dependency is deprecated in favor of candy-fuzzy v2** — FuzzyMatcher.php is marked @deprecated but Input and Select use it directly via `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` in Select and `SugarCraft\Forms\Fuzzy\FuzzyMatcher` in Input. The class-level deprecation comment at line 10-11 says to use `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` — which is what Select::update() already does at line 268. But Input::withFuzzySuggestions() uses the internal `FuzzyMatcher` class at line 218, not SmithWatermanMatcher. Inconsistent: Select uses the proper external lib, Input uses the deprecated internal shim.

2. **ItemList uses `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`** but `FuzzyMatcher` (the internal one) uses its own Smith-Waterman implementation. Two different fuzzy matching implementations in the same library — may produce different ranking results for the same query/candidates.

3. **candy-forms depends on candy-async, candy-buffer, candy-core, candy-fuzzy, candy-layout, candy-sprinkles** — This is a heavy dependency. The lib is labeled "Foundation lib for form primitives" in composer.json but pulls in layout, fuzzy, async, etc. If other libs (like sugar-bits or sugar-prompt) also use candy-forms as a base, the dependency graph needs careful management.

4. **FuzzyMatcher is not multibyte-safe** — This is a correctness bug for non-ASCII input. Systems processing Japanese/Chinese filenames or emoji in file paths will get incorrect fuzzy matching results.

5. **No PHP 8.4 readonly property enforcement** — The library targets PHP 8.3+ and uses promoted readonly properties. PHP 8.4 deprecates implicit defaults for readonly properties — but all usage looks compliant since no implicit default is used.

6. **subscriptionCapable trait in Viewport** — Viewport uses `use \SugarCraft\Core\SubscriptionCapable;` but `subscriptions()` at line 573 returns null. If this trait provides default subscription behavior, the null return may conflict with it. Need to verify what the trait does.

7. **candy-forms composer.json requires dev-master versions** — `"minimum-stability": "dev"` with `prefer-stable: true` means dev-master deps may not be stable. This is expected for a monorepo under development but could cause issues when using released versions of sibling libs.

8. **FilePicker::Entry::icon() returns emoji** — The icon() method returns actual Unicode emoji characters (📁, 📜, etc.). These require font support and may not render in all terminal emulators. The fallback path (default icon 📄) handles unknown types, but the specific icons may display as tofu (□) in environments without emoji fonts. This is a rendering compatibility concern for cross-platform TUI use.

## Async Pattern Improvements

1. **No stream-based async suggestions** — The current async suggestions pattern uses a single Deferred that resolves once per suggestion fetch. There's no support for streaming suggestions (e.g., a search API that returns partial results as they arrive). Could add `withStreamingSuggestions()` that returns an AsyncGenerator or multiple Updates via stream.

2. **CancellationSource pattern is correct but could be simplified** — The withPendingAsyncCancellation / CancellationSource token pattern in Input.php and Select.php correctly handles debounce cancellation. However, the pattern requires storing a CancellationSource on the field instance and cloning it through mutations. A simpler abstraction could wrap the Timer + CancellationToken + Promise into a single `Debouncer` or `AsyncSuggestionRequest` object.

3. **workerPool parameter is dead code in Select** — The withAsyncSuggestions accepts a WorkerPool but it is never used (see Critical Issues). This suggests the original intent was to offload suggestion fetching to a worker process pool for true parallelism, but this was never implemented. The debounce + Deferred pattern still runs on the main event loop via Loop::addTimer.

4. **Loop::addTimer in Input.php:449 and Select.php:337** — Both use React\EventLoop\Loop::addTimer for debouncing. In a ReactPHP application with many concurrent async operations, many simultaneous timers could cause performance issues. A shared timer wheel or a debounce manager that coalesces rapid keystrokes into a single timer per field could improve performance.

5. **No timeout on async suggestion fetch promises** — The Deferred resolves when the `$promise` (returned by the fetcher callable) resolves. If the fetcher returns a never-resolving promise (network hang), the form will wait forever. No `timeout()` is set on the promise chain.

6. **AsyncCmd returned from scheduleAsyncSuggestions** — At Input.php:472, `return new \SugarCraft\Core\AsyncCmd($deferred->promise());` is returned from the Cmd closure. This is correct Bubble Tea async pattern. However, if the user rapidly types and the CancellationToken is cancelled, the deferred is rejected with RuntimeException. This rejection is silently caught nowhere — the AsyncCmd promise rejection is unhandled. In ReactPHP, this would generate a warning about an unhandled promise rejection. Should add an error handler: `$promise->otherwise(static fn() => null)` or similar to suppress warnings.

7. **SubscriptionCapable trait in Viewport** — Viewport uses `use \SugarCraft\Core\SubscriptionCapable;` but subscriptions() returns null. This trait likely provides `addSubscription()` etc. The Viewport doesn't use it, suggesting leftover from a previous implementation or copy-paste. If not needed, the trait should be removed.

8. **No structured concurrency** — When multiple fields have async suggestions active simultaneously, they each maintain their own Deferred + timer. There's no coordination or cancellation of ALL pending async operations when the Form is submitted or aborted. Each field cleans up its own pending async on the next keystroke, but if the form is suddenly destroyed, orphaned timers/Deferreds may fire and reference stale field instances.

9. **Form::init() returns a Cmd** — The `init()` method at Form.php:106-109 returns `$this->initCmd` which is the first focused field's focus() Cmd (e.g., Cursor blink). However, if a field has async suggestions preloaded and those complete before the first render, the SuggestionsReadyMsg would be handled in update(). But if the async operation completes before init() is even called (unlikely but possible in a cold-start scenario), the message would be orphaned.

10. **The AsyncCmd return from scheduleAsyncSuggestions** — The closure captures `$deferred` which captures `$token` which is the CancellationToken from `$cancellationSource->token()`. When the form is mutated (new field instances created), the old field's cancellation source is cancelled. The new field has its own cancellation source. The async operations on the old field will be cancelled correctly. However, if the form is submitted or aborted (Form.update() returns early at line 298-300 because submitted/aborted check), no further update() calls happen and the pending async timers continue to fire and try to resolve. They will check `isCancelled()` and early-return, but this is wasted CPU work. A subscriptions cleanup on Form submission/abort would help.

## Recommendations Summary table

| Severity | File:Line | Issue | Recommendation |
|---|---|---|---|
| Critical | Input.php:434 | pendingAsyncSeq captured by value in closure, seq never updated on instance | Pass pendingAsyncSeq as mutable ref or use array object |
| Critical | Field/Select.php:126-139 | workerPool parameter accepted but never used | Implement worker pool offloading or remove parameter |
| Critical | Field/Field.php | Duplicate interface file, never imported by implementations | Delete Field/Field.php and keep only top-level Field.php |
| Critical | Fuzzy/FuzzyMatcher.php:48 | Byte-oriented strlen used for UTF-8 strings | Use mb_strlen/mb_substr or restrict to ASCII |
| Critical | Field/Select.php:377 | revalidate() is a no-op, inconsistent with contract | Implement actual validation or document no-op behavior |
| High | Form.php:661-695 | validateAll() does two full traversals | Combine into single-pass with value collection |
| High | Select.php:268 | SmithWatermanMatcher instantiated on every keystroke | Store as instance property or static reuse |
| High | Field/Input.php:326-347 | O(N²) validator wrapping for N validators | Use indexed array + loop counter |
| High | TextArea.php:587-601 | totalLength() computed O(n) per character insert | Cache totalLength as derived property |
| Medium | Cursor.php:26 | Static $nextId monotonically increases forever | Use a UUID or reset in long-running daemon |
| Medium | VimKeyHandler.php:228 | j/k mapped to left/right in visual-line (semantically wrong) | Correct action names or document ItemList-specific behavior |
| Low | FuzzyMatcher.php:39 | strlen not mb_strlen for multibyte | Add mb_ prefix or add ASCII-only constraint comment |
| Low | MultiSelect.php:218 | countTrue manual loop | Replace with array_filter + count |
| Low | Theme.php:184-187 | catalog() hardcoded list | Derive from theme class reflection or registry |
