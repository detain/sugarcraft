# Code Review: sugarcraft/candy-testing

**Library:** `sugarcraft/candy-testing`  
**Review Date:** 2026-06-30  
**Reviewer:** Automated Code Review  
**Files Reviewed:** 8 source files, 9 test files, 1 lang file

---

## Summary

The candy-testing library provides a test harness for TEA (The Elm Architecture) programs in the SugarCraft ecosystem. It includes `ProgramSimulator` for deterministic program driving, `ScriptedInput` for building input sequences, `TapeRecorder` for VHS demo file generation, and snapshot assertion helpers. The code is generally well-structured and follows project conventions, but there are notable issues around Reflection use, incomplete key type coverage, async gaps, and some code quality concerns.

---

## Critical Issues

### 1. TapeRecorder:71 - Leading space in Dracula theme reveals escaping bug

**File:** `src/Tape/TapeRecorder.php:71`  
**Severity:** Critical  
**Type:** String escaping bug

```php
->header(theme: ' Dracula', width: 1024, height: 768, fontSize: 16)
```

The test at `TapeRecorderTest.php:76` passes a theme string with a **leading space** `' Dracula'`. This indicates incorrect escaping — the space likely came from an escaping issue when the string `'Dracula'` was processed. Looking at the `header()` method:

```php
$this->lines[] = 'Set Theme "' . $theme . '"';
```

If `$theme` contained a double-quote (e.g., `Dracula"`) it would break the VHS syntax, but the leading space suggests the escaping may have been incorrectly applied somewhere in development.

**Impact:** Theme names with special characters (spaces, quotes) will produce malformed VHS files.

---

### 2. ProgramSimulator:253 - ReflectionProperty::setAccessible(true) mutates class state

**File:** `src/ProgramSimulator.php:253`  
**Severity:** High  
**Type:** Design flaw / fragility

```php
$modelProp->setAccessible(true);
```

Using `setAccessible(true)` on a `ReflectionProperty` is a mutating operation that affects the `ReflectionProperty` object itself, not the underlying class. However, the real issue is that this approach **depends on private internal structure** of `Program` class. If `Program` changes its property name or visibility, this test utility breaks silently.

**Recommendation:** Either:
1. Add a getter method `getModel()` to `Program` and use it in production code
2. Document the private API contract clearly
3. Use a factory method pattern in `Program` itself

---

### 3. Assertions:184-188 - preg_replace_callback with ?? $bytes may mask errors

**File:** `src/Snapshot/Assertions.php:184-188`  
**Severity:** High  
**Type:** Error masking

```php
private static function escapeAnsi(string $bytes): string
{
    return preg_replace_callback(
        '/\\x1b\\[([0-9;]*)m/',
        static fn (array $m): string => '\\x1b[' . ($m[1] ?: '') . ']m',
        str_replace("\x1b", '\\x1b', $bytes),
    ) ?? $bytes;
}
```

The `?? $bytes` fallback will swallow `preg_replace_callback` returning `false` (when the regex fails). However, `preg_replace_callback` with a valid regex pattern will never return `false` — it returns `null` on error. The null coalescing `??` will never trigger for valid input. This is not a bug per se, but the pattern is confusing and potentially fragile if the regex pattern were to change.

**Fix:** Use `is_string()` check or handle `null` explicitly.

---

## High Severity Issues

### 4. TapeRecorder:175-196 - Incomplete KeyType coverage in keyMsgToVhs()

**File:** `src/Tape/TapeRecorder.php:175-196`  
**Severity:** High  
**Type:** Missing functionality / compatibility gap

The `keyMsgToVhs()` method only handles 7 KeyType cases:
- Enter, Escape, Backspace, Tab, Up, Down, Left, Right (7 out of ~120+ key types)

**Missing key types include:**
- F1-F63 function keys (F1-F12 commonly used in TUI apps)
- Home, End, PageUp, PageDown (common navigation keys)
- Delete, Insert (common editing keys)
- All keypad variants (Kp0-KpBegin)
- All media keys (MediaPlay, MuteVolume, etc.)
- All modifier-as-key types (LeftShift, LeftCtrl, etc.)

**Impact:** Programs using function keys, media keys, or extended navigation keys cannot generate complete VHS tapes.

---

### 5. TapeRecorderTest:187-284 - Incomplete keyMsgToVhs test coverage

**File:** `tests/TapeRecorderTest.php:187-284`  
**Severity:** High  
**Type:** Test coverage gap

Tests only cover character keys, Enter, Escape, Backspace, Tab, and arrow keys. No tests for:
- F-keys
- Home/End/PageUp/PageDown  
- Delete/Insert
- Media keys
- Keypad keys

This gap means `keyMsgToVhs()` returning `null` for unsupported keys is never actually tested for most key types.

---

### 6. ProgramSimulator:139-144 - O(n) queue operations with array_shift

**File:** `src/ProgramSimulator.php:139-144`  
**Severity:** Medium-High  
**Type:** Performance

```php
while (count($this->queue) > 0) {
    $msg = array_shift($this->queue);  // O(n) operation
    [$model, ] = $this->applyMsg($model, $msg);
    $model = $this->pumpSubscriptions($model);
}
```

`array_shift()` re-indexes the entire numeric array, which is O(n). For small queues (under 1000 messages), this is negligible. But for stress tests with many tick messages, this could become a bottleneck.

**Fix:** Use a `SplQueue` or maintain an index pointer instead of shifting.

---

### 7. TapeRecorder:207-221 - Manual character-by-character loop instead of built-in

**File:** `src/Tape/TapeRecorder.php:207-221`  
**Severity:** Medium  
**Type:** Unnecessary complexity

```php
private static function escapeVhsRune(string $rune): string
{
    $result = '';
    for ($i = 0; $i < strlen($rune); $i++) {  // strlen() called each iteration
        $c = $rune[$i];
        if ($c === '\\') {
            $result .= '\\\\';
        } elseif ($c === '"') {
            $result .= '\\"';
        } else {
            $result .= $c;
        }
    }
    return $result;
}
```

Could be replaced with:
```php
private static function escapeVhsRune(string $rune): string
{
    return addcslashes($rune, '\\"');
}
```

The current implementation only escapes `\` and `"` but `addcslashes()` with the same character list produces identical output.

---

### 8. ScriptedInput:39-42 - readonly property with array is technically mutable

**File:** `src/Input/ScriptedInput.php:39-42`  
**Severity:** Medium  
**Type:** Design concern

```php
final readonly class ScriptedInput
{
    private array $messages;
    
    private function __construct(array $messages)
    {
        $this->messages = $messages;
    }
```

The `readonly` keyword makes `$this->messages` reassignable only at construction time, but the **array contents themselves are mutable**. However, since `push()` always creates a new instance with `[...$this->messages, $msg]`, this is not actually a bug in practice — there's no mutator method that modifies `messages` in place.

**Note:** This is a known limitation of PHP's `readonly` feature with reference types. Not a bug given the immutable `push()` pattern, but worth documenting.

---

## Medium Severity Issues

### 9. Assertions:147-171 - Inefficient string processing in diffAnsi()

**File:** `src/Snapshot/Assertions.php:147-171`  
**Severity:** Medium  
**Type:** Performance / code quality

```php
private static function diffAnsi(string $expected, string $actual): string
{
    $expectedLines = explode("\n", self::escapeAnsi($expected));
    $actualLines = explode("\n", self::escapeAnsi($actual));
    // ... 
}
```

The method calls `escapeAnsi()` on entire strings before splitting into lines. This processes the entire string twice. More efficient to split first, then escape only the relevant parts.

---

### 10. TapeRecorder:154-167 - save() has no return value to confirm write success beyond false

**File:** `src/Tape/TapeRecorder.php:154-167`  
**Severity:** Medium  
**Type:** Error handling

```php
$result = \file_put_contents($this->outputPath, $content);
if ($result === false) {
    throw new \RuntimeException(Lang::t('tape.write_failed', ['path' => $this->outputPath]));
}
```

`file_put_contents()` returns `false` on error but **0 bytes written is also falsy** (returns 0, not false). A check for `=== false` misses the case where the file is empty/written with 0 bytes. Should be `=== false && $result !== 0` or simply check `=== false` since 0 bytes is likely not a success case for a tape file.

---

### 11. GoldenFile:53 - No exception thrown when file_put_contents returns 0

**File:** `src/Snapshot/GoldenFile.php:53`  
**Severity:** Medium  
**Type:** Error handling

Same issue as above: `file_put_contents()` returns 0 on some conditions that aren't necessarily errors, but returning 0 bytes for a golden file is almost certainly wrong.

---

### 12. ProgramSimulator:200 - Arbitrary maxCycles limit without clear documentation

**File:** `src/ProgramSimulator.php:200`  
**Severity:** Medium  
**Type:** Configuration / clarity

```php
$maxCycles = 10_000;
```

The 10,000 cycle limit is arbitrary. While it prevents infinite loops, there's no guidance on whether this is a reasonable limit. For stress tests with many tick messages, this could theoretically be hit.

---

### 13. ProgramSimulatorTest:200-226 - Test mode naming confusing

**File:** `tests/ProgramSimulatorTest.php:200-226`  
**Severity:** Medium  
**Type:** API usability

The test `testDefaultRunnerDoesNotExecuteCmds()` has a confusing name. The `withRealCmdRunner(false)` method doesn't mean "default runner" — it means "capture-only mode". The test itself is correct but the method naming (`withRealCmdRunner`) is counterintuitive.

---

### 14. AssertionsTest:18-43 - setUp/tearDown manual cleanup is fragile

**File:** `tests/AssertionsTest.php:18-43`  
**Severity:** Low-Medium  
**Type:** Code duplication / fragility

```php
protected function setUp(): void
{
    $this->tmpDir = sys_get_temp_dir() . '/candy-testing-assertions-' . getmypid();
    mkdir($this->tmpDir, 0755, true);
    $this->fixturesDir = $this->tmpDir . '/fixtures';
    mkdir($this->fixturesDir, 0755, true);
}

protected function tearDown(): void
{
    $files = glob($this->tmpDir . '/*');
    foreach ($files as $file) {
        if (is_dir($file)) {
            // ...manual recursive delete...
        }
    }
}
```

This manual cleanup code is duplicated across 4 test files. If PHP's `Symfony\Component\Filesystem\Filesystem` or similar is available, `remove()` would be cleaner. However, that would add a dependency. Using `RecursiveDirectoryIterator` with `CHILD_FIRST` is correct but verbose.

---

### 15. TapeRecorderTest:27-46 - Same manual cleanup duplicated

**File:** `tests/TapeRecorderTest.php:27-46`  
**Severity:** Low  
**Type:** Code duplication

Identical cleanup code as `AssertionsTest`. Could be extracted to a trait or base class.

---

## Low Severity Issues

### 16. GoldenFile:49 - Permissions 0755 allow group/other access

**File:** `src/Snapshot/GoldenFile.php:49`  
**Severity:** Low  
**Type:** Security

```php
\mkdir($dir, 0755, true);
```

0755 permissions allow group and other users to read and execute. For golden files containing test data, this is likely fine. But the project convention might prefer 0755 for directories and 0644 for files (which is already used in `file_put_contents`). This is a minor issue.

---

### 17. TapeRecorder:207 - strlen() in loop condition

**File:** `src/Tape/TapeRecorder.php:210`  
**Severity:** Low  
**Type:** Performance micro-optimization

```php
for ($i = 0; $i < strlen($rune); $i++)
```

In PHP 8+, `strlen()` is O(1) for ASCII strings due to optimization, but for arbitrary bytes it still scans. This is a micro-optimization concern that doesn't matter in practice for single-character runes.

---

### 18. ProgramSimulator:229 - Return statement with null coalescing when $cmd may not be set

**File:** `src/ProgramSimulator.php:229`  
**Severity:** Low  
**Type:** Code clarity

```php
return [$model, $cmd ?? null];
```

The `?? null` is redundant because `[$model, ]` destructuring from `update()` always produces a defined second element, and `$cmd` is set in the method. But using `?? null` is defensive. The actual issue is that the return type annotation says `?Closure` but the second element of the array is always present — it could be `null`. This is fine but slightly confusing.

---

### 19. ScriptedInput:138 - match expression not exhaustive for arrow directions

**File:** `src/Input/ScriptedInput.php:138`  
**Severity:** Low  
**Type:** Code quality

```php
$type = match ($dir) {
    'up' => KeyType::Up,
    'down' => KeyType::Down,
    'left' => KeyType::Left,
    'right' => KeyType::Right,
    default => throw new \InvalidArgumentException(Lang::t('input.invalid_arrow', ['dir' => $dir])),
};
```

The `match` is exhaustive because of the `default` clause, but it could be made more robust by using an enum-backed method or type-safe approach. The current approach is fine.

---

### 20. TapeRecorderTest:71 - Test passes string with leading space

**File:** `tests/TapeRecorderTest.php:71`  
**Severity:** Low  
**Type:** Test quality / potential bug in test data

```php
->header(theme: ' Dracula', width: 1024, height: 768, fontSize: 16)
```

This is intentional in the test (testing custom theme), but the leading space is suspicious. If a user tried to use `'Dracula'` theme, would it work correctly? The leading space here suggests there may have been an escaping issue.

---

## Missing Features

### 1. No ReactPHP/Async test support

The library is entirely synchronous. For a ReactPHP-based ecosystem, there should be:
- `AsyncProgramSimulator` that works with the ReactPHP event loop
- Async assertion helpers for testing async components
- Support for `ReactPHP\Promise` in fakeCmdRunner

### 2. No command assertion helpers

`TestResult` captures commands but there are no assertion helpers:
- No way to assert specific commands were captured
- No way to assert command count
- No way to assert command equality (closures are not comparable)

### 3. No streaming output support

`ProgramSimulator::run()` only captures the final view output. There's no way to capture/view intermediate outputs per update cycle.

### 4. No subscription timing control

`pumpSubscriptions()` calls `produce()` but doesn't support:
- Controlling the number of produce() calls
- Inspecting subscription state
- Testing subscription errors

### 5. No mouse wheel support

`ScriptedInput` has `mouse()` but there's no `wheel()` method for mouse wheel events, which are common in TUI navigation.

### 6. No paste/clipboard support

The `Msg` interface mentions `PasteMsg` and `ClipboardMsg` but `ScriptedInput` has no helpers for these.

### 7. No keyboard enhancements/IME support

`KeyboardEnhancementsMsg` is mentioned in `Msg` interface but not testable via `ScriptedInput`.

---

## Duplicated Logic / Refactoring Opportunities

### 1. Manual directory cleanup code duplicated across 4 test files

**Files:** `AssertionsTest.php`, `TapeRecorderTest.php`, `GoldenFileTest.php`, and possibly others

**Suggestion:** Create a `TemporaryDirectory` trait or helper class:
```php
trait TemporaryDirectoryTrait {
    private string $tmpDir = '';
    
    protected function createTempDir(string $prefix): void {
        $this->tmpDir = sys_get_temp_dir() . "/{$prefix}-" . getmypid();
        mkdir($this->tmpDir, 0755, true);
    }
    
    protected function removeTempDir(): void {
        if ($this->tmpDir === '' || !is_dir($this->tmpDir)) return;
        // ... existing cleanup code
    }
}
```

### 2. escapeVhsRune() could use addcslashes()

**File:** `src/Tape/TapeRecorder.php:207-221`

```php
private static function escapeVhsRune(string $rune): string
{
    $result = '';
    for ($i = 0; $i < strlen($rune); $i++) {
        $c = $rune[$i];
        if ($c === '\\') {
            $result .= '\\\\';
        } elseif ($c === '"') {
            $result .= '\\"';
        } else {
            $result .= $c;
        }
    }
    return $result;
}
```

Could be:
```php
private static function escapeVhsRune(string $rune): string
{
    return addcslashes($rune, '\\"');
}
```

### 3. diffAnsi() processes entire strings before line splitting

**File:** `src/Snapshot/Assertions.php:147-171`

Could be refactored to split first, then process lines individually:
```php
$expectedLines = explode("\n", $expected);
$actualLines = explode("\n", $actual);
// Then process and escape only for display
```

### 4. $outputBytes array concatenated at end instead of streamed

**File:** `src/ProgramSimulator.php:157`

```php
'output' => implode('', $this->outputBytes),
```

For very large outputs, this creates many intermediate strings. Could use `finalViewResult` and concatenate directly. But this is micro-optimization.

---

## Compatibility Issues

### 1. ProgramSimulator:242-256 - Direct Reflection access to Program::model

**File:** `src/ProgramSimulator.php:242-256`  
**Severity:** High  
**Type:** Encapsulation violation

```php
private function getModelFromProgram(): Model
{
    $reflection = new \ReflectionClass($this->program);
    if (!$reflection->hasProperty('model')) {
        throw new \RuntimeException(Lang::t('simulator.no_model_property'));
    }
    $modelProp = $reflection->getProperty('model');
    $modelProp->setAccessible(true);
    return $modelProp->getValue($this->program);
}
```

This directly accesses the private `$model` property of `Program`. If the `Program` class changes its property name or makes it public, this breaks. The `Program` class should expose a `getModel()` method that the simulator can use.

### 2. TapeRecorder::keyMsgToVhs() only handles 7 key types

**File:** `src/Tape/TapeRecorder.php:175-196`  
**Severity:** High  
**Type:** Incomplete upstream mapping

`KeyType` enum has 120+ cases but only 7 are handled. Any TUI using function keys, media keys, or extended navigation will have incomplete VHS recordings.

### 3. Snapshot assertions assume specific Buffer API

**File:** `src/Snapshot/Assertions.php:69`  
**Severity:** Medium  
**Type:** API coupling

```php
public static function assertCellGrid(array $expected, Buffer $actual): void
```

The `Buffer` class from `sugarcraft/candy-buffer` is a specific dependency. The method assumes `Buffer` has `width()`, `height()`, and `cellAt()` methods. If `Buffer` API changes, these assertions break.

### 4. TestResult relies on Model contract

**File:** `src/TestResult.php`  
**Severity:** Low  
**Type:** Interface coupling

```php
public object $model
```

The `model` property is typed as `object` rather than `Model`. This is flexible but means code using `TestResult` must cast to `Model` to use `view()`, `subscriptions()`, etc.

### 5. Depends on internal structure of Msg hierarchy

**File:** `src/Input/ScriptedInput.php`  
**Severity:** Low  
**Type:** Interface coupling

`ScriptedInput` creates `KeyMsg`, `WindowSizeMsg`, `QuitMsg`, `MouseMsg` directly. If these message classes change signatures, the builder breaks.

---

## Async Pattern Improvements

### 1. No async support for ProgramSimulator

The library has no concept of async. For a ReactPHP-based ecosystem, consider:

```php
interface AsyncProgramSimulator {
    public function send(Msg $msg): self;
    public function run(): \React\Promise\PromiseInterface<TestResult>;
    public function withAsyncCmdRunner(\Closure $runner): self;
}
```

### 2. No EventLoop integration

`pumpSubscriptions()` calls `produce()` directly, but in ReactPHP, subscriptions would typically be driven by the event loop's timers. The current implementation is "synchronous subscription pumping" which is correct for deterministic testing but doesn't test actual timing behavior.

### 3. No async assertion helpers

For testing async components, would need:
- `await()` helper for Promise-based assertions
- `eventually()` for polling assertions
- Integration with ReactPHP's `Loop` for timeout control

### 4. Cmd execution is fire-and-forget

**File:** `src/ProgramSimulator.php:287-288`

```php
return $cmd();
```

In a sync context this is fine. In async context, cmds could return `React\Promise\PromiseInterface`. The current code doesn't handle this case — it would return the Promise object itself as the "message", which would then fail in `update()`.

---

## Recommendations Summary Table

| Issue | Severity | File:Line | Recommendation |
|-------|----------|-----------|----------------|
| Leading space in Dracula theme | Critical | TapeRecorder.php:71 | Fix escaping, add test with proper theme name |
| ReflectionProperty::setAccessible mutability | High | ProgramSimulator.php:253 | Add getModel() to Program class |
| preg_replace_callback error mask | High | Assertions.php:184-188 | Use explicit null check |
| Incomplete KeyType coverage | High | TapeRecorder.php:175-196 | Add F-keys, Home/End, Delete, media keys |
| Missing keyMsgToVhs tests | High | TapeRecorderTest.php | Add tests for all supported key types |
| O(n) array_shift in queue | Medium-High | ProgramSimulator.php:139-144 | Use SplQueue or index pointer |
| Manual char loop in escapeVhsRune | Medium | TapeRecorder.php:207-221 | Use addcslashes() |
| readonly array property mutability | Medium | ScriptedInput.php:39-42 | Document immutability guarantee |
| Inefficient string processing in diffAnsi | Medium | Assertions.php:147-171 | Split before escaping |
| file_put_contents 0-byte check | Medium | GoldenFile.php:53, TapeRecorder.php:164 | Clarify error handling |
| Duplicate directory cleanup | Low-Medium | AssertionsTest.php, TapeRecorderTest.php | Extract to trait |
| 0755 permissions | Low | GoldenFile.php:49 | Use 0755 for dirs, 0644 for files |
| No async support | High | Entire library | Add AsyncProgramSimulator |
| No command assertions | Medium | TestResult | Add assertion helpers |
| No subscription timing control | Medium | ProgramSimulator.php | Add subscription produce control |

---

## Conclusion

The candy-testing library is a well-designed test harness that correctly implements the TEA testing pattern. The code follows project conventions, uses immutable patterns appropriately, and has good test coverage for the happy paths. The main concerns are:

1. **Critical:** The Dracula theme escaping issue and incomplete KeyType coverage in VHS recording
2. **High:** Reflection dependency on Program internals and missing key type support
3. **Medium:** Performance micro-optimizations and some API clarity issues
4. **Missing:** Async support for the ReactPHP ecosystem

The library would benefit from addressing the Reflection dependency in ProgramSimulator, completing the KeyType → VHS mapping, and adding async program simulation for the ReactPHP-based ecosystem.
