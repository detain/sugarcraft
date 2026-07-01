# Implementation Plan: candy-metrics

**Status:** not-started
**Phase:** 1
**Updated:** 2026-06-30

## Goal

Address all findings from the candy-metrics code review, ranging from critical runtime coupling issues to minor cleanup items, organized into phased implementation groups.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| SessionMetrics must be decoupled from candy-wish | Hard-coupled optional dependency causes runtime fatal errors, not composer-time detection | `src/Middleware/SessionMetrics.php:8-10` |
| Backend interface needs flush() contract | MultiBackend cannot propagate flush to PrometheusFileBackend children | `src/Backend.php:20-70` |
| PrometheusFileBackend needs dirty-flag optimization | Current implementation re-emits all metrics every flush(), causing unnecessary I/O | `src/Backend/PrometheusFileBackend.php:132-273` |
| DRY extraction for TYPE/HELP emission blocks | 5 near-identical blocks (~80 lines) violate maintainability | `src/Backend/PrometheusFileBackend.php:143-246` |
| Shared utility for tag-key building | tag-sorting logic duplicated in InMemoryBackend and Registry | `src/Backend/InMemoryBackend.php:120-131` vs `src/Registry.php:269-280` |

---

## Phase 1: Critical Issues [PENDING]

### 1.1 SessionMetrics hard-coupled to candy-wish [PENDING] ← CURRENT

**What:** `src/Middleware/SessionMetrics.php:8-10` directly imports `SugarCraft\Wish\Context`, `SugarCraft\Wish\Middleware`, `SugarCraft\Wish\Session` from `candy-wish`, but `composer.json:39-41` only lists it as a `suggest`, not a `require`. Any code instantiating `SessionMetrics` without `candy-wish` installed gets a fatal "Class not found" at runtime.

**Why:** Silent failure at runtime if candy-wish is not installed; composer cannot catch this at `require` time.

**Severity:** Critical

**Conditions for Success:**
- SessionMetrics is moved to a bridge package OR `sugarcraft/candy-wish` is added as a hard `require` in composer.json
- Running `composer install` without candy-wish does not produce a broken state
- `composer validate` passes

**Related Code:**
- `src/Middleware/SessionMetrics.php:8-10` — problematic imports
- `composer.json:39-41` — `suggest` section
- `src/Middleware/SessionMetrics.php:27-59` — full middleware implementation

**Investigation Notes:**
The imports at lines 8-10 pull in three classes from candy-wish (`Context`, `Middleware`, `Session`). The `handle()` method at line 42 is the core entry point. The `Session` type is used at line 44 to access `$session->user` and `$session->term`. Since `candy-wish` is only in `suggest`, projects using candy-metrics without candy-wish will get a fatal error when trying to use SessionMetrics. The fix options are: (1) create a new `candy-metrics-wish` bridge package that depends on both, or (2) hard-require candy-wish. Given the middleware is specifically for "CandyWish session telemetry" (per the docblock), a bridge package is the cleaner architectural solution.

---

### 1.2 Backend interface missing flush() contract [PENDING]

**What:** `src/Backend.php:20-70` defines 7 emit methods (`counter`, `gauge`, `histogram`, `upDownCounter`, `asyncCounter`, `asyncGauge`, `describe`) but does NOT declare `flush()`. However, `PrometheusFileBackend` requires explicit `flush()` calls (line 132), and its destructor calls it implicitly (line 62-68). `InMemoryBackend` and `StatsdBackend` are no-ops for flush.

**Why:** No interface contract for flush(); `MultiBackend` has no way to propagate flush to children. Calling `$multiBackend->flush()` would not forward to children — only `describe()` is forwarded (line 82-85).

**Severity:** Critical

**Conditions for Success:**
- `flush(): void` is added to the `Backend` interface (with default empty implementation for backends that don't need it)
- OR documentation clearly states `flush()` must be called directly on backends that need it
- `MultiBackend` forwards `flush()` to all children
- All existing backends implement the interface correctly

**Related Code:**
- `src/Backend.php:20-70` — interface definition
- `src/Backend/PrometheusFileBackend.php:132-273` — `flush()` implementation
- `src/Backend/MultiBackend.php:82-85` — `describe()` forwarding (no `flush()` forwarding)
- `src/Backend/StatsdBackend.php` — no `flush()` method
- `src/Backend/InMemoryBackend.php` — no `flush()` method

**Investigation Notes:**
The Backend interface is clean with 7 methods. Adding `flush(): void` with a default empty implementation in the interface would be the cleanest solution (PHP 8.1+ supports default implementations in interfaces). Alternatively, the interface could have no flush and MultiBackend could explicitly handle only backends that support flush. But for simplicity, a default empty implementation is the standard OOP approach.

---

### 1.3 PrometheusFileBackend flush() re-emits all metrics every call [PENDING]

**What:** `src/Backend/PrometheusFileBackend.php:132-273` — each `flush()` call re-serializes and writes the entire current state of all accumulated metrics to the file. This means the file is rewritten even if no new data arrived since last flush. The atomic `rename()` at line 270 only covers the rename, not the full write-to-temp-then-rename sequence.

**Why:** For long-running processes that flush periodically, this creates ongoing I/O overhead even with no new data. Scrapers during the write window see incomplete data.

**Severity:** Critical

**Conditions for Success:**
- Add a private `$dirty bool` property that tracks whether any data was recorded since last flush
- Skip re-writing the file if `$dirty === false`
- Set `$dirty = true` in `counter()`, `gauge()`, `histogram()`, `upDownCounter()`, `asyncCounter()`, `asyncGauge()`
- Reset `$dirty = false` at the end of successful `flush()`
- Unit test verifies no file rewrite when no data recorded between flushes

**Related Code:**
- `src/Backend/PrometheusFileBackend.php:132-273` — full `flush()` method
- `src/Backend/PrometheusFileBackend.php:71-123` — all emit methods that should set dirty flag
- `src/Backend/PrometheusFileBackend.php:256-273` — file write + rename

**Investigation Notes:**
The 6 emit methods (counter, gauge, histogram, upDownCounter, asyncCounter, asyncGauge) all mutate the internal accumulator arrays. A boolean `$dirty` flag set to `true` on any mutation, and reset to `false` at the end of flush(), would skip the expensive file I/O when no data changed. This is a standard "dirty flag" optimization pattern.

---

## Phase 2: Major Issues [PENDING]

### 2.1 PrometheusFileBackend::flush() has 5 near-identical TYPE/HELP emission blocks [PENDING] ← CURRENT

**What:** `src/Backend/PrometheusFileBackend.php:143-246` — lines 143-158 (counters), 159-174 (upDownCounters), 175-190 (asyncCounters), 191-206 (asyncGauges), 207-222 (gauges) all follow the same pattern.

**Why:** Clear DRY violation across ~80 lines. Two places to maintain the same logic.

**Severity:** Major

**Conditions for Success:**
- Extract helper method: `private function emitMetricLine(string $name, string $labels, float $value, ?Descriptor $descriptor, string $defaultType): string`
- Replace each block with a call to the helper
- Histogram block (lines 223-246) needs separate handling since it emits bucket lines
- All 41 existing tests still pass

**Related Code:**
- `src/Backend/PrometheusFileBackend.php:143-246` — the 5 blocks
- `src/Backend/PrometheusFileBackend.php:223-246` — histogram special handling

**Investigation Notes:**
The 5 blocks differ only in: (1) which internal array is iterated, (2) the default TYPE string ('counter', 'gauge'). The structure is:
```php
if (!isset($typeEmitted[$name])) {
    $typeEmitted[$name] = true;
    if (isset($this->descriptors[$name])) {
        $d = $this->descriptors[$name];
        $body .= "# HELP {$name} " . self::escapeHelp($d->help) . "\n";
        $body .= "# TYPE {$name} {$d->type}\n";
    } else {
        $body .= "# TYPE {$name} {$defaultType}\n";
    }
}
$body .= "{$name}{$labels} " . self::fmt($val) . "\n";
```
A helper method would reduce this significantly. The histogram block is structurally different (bucket lines, count, sum) and would remain separate.

---

### 2.2 InMemoryBackend::key() and Registry::tagKey() duplicate tag-sorting logic [PENDING]

**What:** `src/Backend/InMemoryBackend.php:120-131` vs `src/Registry.php:269-280` — both methods: (1) return early for empty tags, (2) call `ksort($tags)`, (3) iterate and build `"k=v"` pairs, (4) join with a separator. The only difference is InMemoryBackend::key() prepends the metric name.

**Why:** Two places to maintain the same logic; if tag-sorting behavior changes, both must be updated.

**Severity:** Major

**Conditions for Success:**
- Create `SugarCraft\Metrics\Util::tagKey(array $tags): string` — sorts tags and builds the `k=v|k2=v2` string
- Update `InMemoryBackend::key()` to use `Util::tagKey()` and prepend the name
- Update `Registry::tagKey()` to use `Util::tagKey()`
- All existing tests pass (the key format is unchanged, only implementation deduplicated)

**Related Code:**
- `src/Backend/InMemoryBackend.php:120-131`
- `src/Registry.php:269-280`

**Investigation Notes:**
InMemoryBackend::key() returns `name|k=v|k2=v2` format, while Registry::tagKey() returns `k=v|k2=v2` format (no name prefix). The Util method should return just the tag portion, and each caller prepends its own prefix if needed. The separator is `|` (pipe) in both cases.

---

### 2.3 PrometheusFileBackend bucket line construction is fragile string manipulation [PENDING]

**What:** `src/Backend/PrometheusFileBackend.php:239-243` — strips the trailing `}` from `$labels` and appends `,le="..."}`. Works but hard to read and verify.

**Why:** Fragile string manipulation could break in edge cases. The matching `+Inf` construction at line 242-243 repeats the same pattern.

**Severity:** Major

**Conditions for Success:**
- Extract `private function buildBucketLabels(string $labels, string $le): string`
- Use for both regular bucket and +Inf bucket construction
- Add unit test for edge case: empty labels, labels with multiple values
- All existing tests pass

**Related Code:**
- `src/Backend/PrometheusFileBackend.php:239-243`

**Investigation Notes:**
Current code: `$leAttr = $labels !== '' ? substr($labels, 0, -1) . ',le="' . $b . '"}' : '{le="' . $b . '"}';` The helper method would be more readable and self-documenting.

---

### 2.4 StatsdBackend suppresses write errors silently [PENDING]

**What:** `src/Backend/StatsdBackend.php:106` — `@fwrite($this->sock, $line)` suppresses all errors. If the UDP socket is broken, metrics are silently dropped with no logging, visibility, or fallback.

**Why:** Production systems may lose metrics with no indication anything went wrong.

**Severity:** Major

**Conditions for Success:**
- Add a constructor parameter `$failSilently = true` to control error handling behavior
- When `$failSilently = false`, throw on fwrite failure or at least log via error_log()
- Default to `true` for backward compatibility
- Add a phpunit test that verifies behavior with failSilently configuration

**Related Code:**
- `src/Backend/StatsdBackend.php:106`
- `src/Backend/StatsdBackend.php:38-57` — constructor

**Investigation Notes:**
The comment at lines 23-27 in StatsdBackend.php says "Failed writes are silently dropped — telemetry that crashes the host process is worse than missing telemetry." This is a deliberate design choice. The fix should make this configurable rather than removing the behavior entirely.

---

### 2.5 MultiBackend::fanout() only reports first error [PENDING]

**What:** `src/Backend/MultiBackend.php:100-114` — when `continueOnError` is true and multiple children fail, only `$errors[0]` is reported.

**Why:** If StatsD fails and Prometheus file fails, only StatsD's error is visible. All errors should be accessible programmatically.

**Severity:** Major

**Conditions for Success:**
- Create a custom `MultiBackendException` class that extends RuntimeException and holds all errors
- The exception should have `getErrors(): list<Throwable>` method
- Update `fanout()` to use this exception when multiple children fail
- Error message should include count AND all error messages
- Update tests to verify all errors are accessible

**Related Code:**
- `src/Backend/MultiBackend.php:100-114`

**Investigation Notes:**
Current exception message format: "MultiBackend: N child backend(s) failed. First: $errors[0]->getMessage()". PHP doesn't have a built-in AggregateException, so a custom class is needed.

---

### 2.6 Registry::tagKey() and InMemoryBackend::key() key format inconsistency [PENDING]

**What:** `src/Registry.php:269-280` vs `src/Backend/InMemoryBackend.php:120-131` — both use `|` separator but Registry returns empty string for empty tags while InMemoryBackend returns bare `$name`. Registry lacks the metric name prefix.

**Why:** Subtle inconsistency that could confuse future maintainers. Though used for different purposes (cardinality tracking vs backend storage), a comment or shared constant would help.

**Severity:** Major

**Conditions for Success:**
- Add a doc comment on `Registry::tagKey()` explaining it returns tag-key only (no name) and is used for cardinality tracking
- Add a doc comment on `InMemoryBackend::key()` explaining it returns name-prefixed key for storage
- Add a shared constant `public const TAG_SEPARATOR = '|'` to formalize the separator
- All existing tests pass without modification

**Related Code:**
- `src/Registry.php:269-280`
- `src/Backend/InMemoryBackend.php:120-131`

**Investigation Notes:**
This is partially addressed by issue 2.2 (extracting shared utility). After extracting the shared utility, the inconsistency should be documented with clear docblocks explaining the different purposes of each key format.

---

### 2.7 Histogram bucket boundaries are hardcoded and not configurable [PENDING]

**What:** `src/Backend/PrometheusFileBackend.php:41` — `private const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 25.0, 50.0, 100.0]` is hardcoded. No way to configure custom boundaries per histogram.

**Why:** For some use cases (e.g., SLO-based buckets), custom boundaries are needed.

**Severity:** Major

**Conditions for Success:**
- Add constructor parameter `?list<float> $buckets = null` to PrometheusFileBackend
- Default to the 14 classic buckets if null
- Add a public constant `DEFAULT_BUCKETS` for the classic set
- All existing histogram tests pass

**Related Code:**
- `src/Backend/PrometheusFileBackend.php:41` — BUCKETS constant
- `src/Backend/PrometheusFileBackend.php:82-96` — histogram() method

**Investigation Notes:**
To make this configurable, the constructor would accept an optional `?list<float> $buckets` and store it as an instance property. The histogram() method would then use `$this->buckets` instead of `self::BUCKETS`. This is a non-breaking change.

---

### 2.8 No way to reset or remove metrics [PENDING]

**What:** No API to clear all metrics from InMemoryBackend, remove a specific metric series, or reset a counter/gauge to zero.

**Why:** For long-running processes, if cardinality eviction or other cleanup is needed, there's no way to do it. Also useful for testing.

**Severity:** Major

**Conditions for Success:**
- Add `InMemoryBackend::clear(): void` — resets all accumulators (counters, gauges, histograms, etc.)
- Add `InMemoryBackend::remove(string $name, array $tags = []): void` — removes a specific metric series
- Add `Registry::remove(string $name, array $tags = []): void` — removes from both backend and cardinality cache
- Add unit tests for all new methods

**Related Code:**
- `src/Backend/InMemoryBackend.php` — all accumulator arrays
- `src/Registry.php` — labelValueCache for cardinality tracking

**Investigation Notes:**
The InMemoryBackend has 6 accumulator arrays. A `clear()` method would reset all of these to empty arrays. A `remove()` method would use the `key()` method to build the key and unset from the appropriate array. The Registry's `remove()` would call both the backend's remove and `deleteLabelValues()`.

---

### 2.9 Missing newUpDownCounter() factory method [PENDING]

**What:** `src/Registry.php:133-136` — the Registry provides factory methods for `AsyncCounter` (line 144), `AsyncGauge` (line 155), but looking at the code, `newUpDownCounter()` IS implemented at lines 133-136. The finding may refer to test coverage.

**Why:** This inconsistency means UpDownCounter must be constructed directly with `new UpDownCounter($registry, ...)`, breaking the fluent factory pattern used for the other two async instruments.

**Severity:** Major

**Conditions for Success:**
- Verify the factory method at `src/Registry.php:133-136` is correctly implemented
- If it exists and works correctly, the finding is already addressed (confirm via test)
- The test at `tests/RegistryTest.php:119-142` should include UpDownCounter factory test

**Related Code:**
- `src/Registry.php:133-136` — newUpDownCounter() factory
- `tests/RegistryTest.php:119-142` — factory tests

**Investigation Notes:**
Looking at the source code, `newUpDownCounter()` IS implemented at lines 133-136. Looking at the test at lines 125-129, it IS tested: `$counter = $r->newUpDownCounter('conns', 'Active connections')`. So this finding seems already resolved — only the test coverage gap (if any) remains.

---

### 2.10 AsyncCounter and AsyncGauge have no cleanup/deregistration mechanism [PENDING]

**What:** `src/Instrument/AsyncCounter.php` and `src/Instrument/AsyncGauge.php` — once created, async instruments hold a reference to the Registry. If many async instruments are created dynamically (e.g., per-connection), they accumulate in memory with no `remove()` or `destroy()` method.

**Why:** For dynamic scenarios (per-connection async instruments), memory can grow unbounded without cleanup.

**Severity:** Major

**Conditions for Success:**
- Add `destroy(): void` method to AsyncCounter that removes their observations from the registry
- Add `destroy(): void` method to AsyncGauge that removes their observations from the registry
- OR: document that async instruments are meant to be long-lived and not created dynamically in large numbers
- Add unit tests for destroy()

**Related Code:**
- `src/Instrument/AsyncCounter.php:26-32` — constructor
- `src/Instrument/AsyncGauge.php:26-32` — constructor
- `src/Registry.php` — would need to track async instruments

**Investigation Notes:**
The async instruments are passive — they hold a closure callback and call `observe()` when invoked by the application. They don't auto-register with the registry. A `destroy()` method would null out the callback and any registry reference. But since there's no central tracking of async instruments in Registry, this would need a `?Registry` property in the instruments and a method to detach.

---

## Phase 3: Minor Issues [PENDING]

### 3.1 Registry::$defaultTags is not readonly [PENDING] ← CURRENT

**What:** `src/Registry.php:38` — `private array $defaultTags;` is set once in constructor but not marked `readonly`.

**Why:** Following the project's immutable/fluent pattern, it should be `readonly`.

**Severity:** Minor

**Conditions for Success:**
- Change to `private readonly array $defaultTags;`
- All tests pass

**Related Code:**
- `src/Registry.php:38`
- `src/Registry.php:55-62` — constructor

---

### 3.2 InMemoryBackend counter/gauge return type inconsistency [PENDING]

**What:** `src/Backend/InMemoryBackend.php:86-94` — `counterValue()` returns `float` (0.0 if missing), `gaugeValue()` returns `?float` (null if missing). Same for asyncCounter (float) vs asyncGauge (?float).

**Why:** Callers must handle different return types for "not found" cases. Inconsistent API.

**Severity:** Minor

**Conditions for Success:**
- Standardize all value methods to return `?float` (null if missing)
- Update return type declarations and docblocks
- Update any callers that rely on the old return type
- All tests pass

**Related Code:**
- `src/Backend/InMemoryBackend.php:86-115` — all value methods

---

### 3.3 Missing test for newUpDownCounter factory [PENDING]

**What:** `tests/RegistryTest.php:119-142` — `newAsyncCounter()` and `newAsyncGauge()` are tested, but `newUpDownCounter()` may not have a dedicated test (though it appears to be tested at lines 125-129).

**Why:** Every public method should have test coverage.

**Severity:** Minor

**Conditions for Success:**
- Verify newUpDownCounter() factory is tested (appears to be at lines 125-129)
- If test exists and is adequate, no action needed
- If test is missing, add it

**Related Code:**
- `tests/RegistryTest.php:119-142`

**Investigation Notes:**
Looking at the test code, lines 125-129 already test `newUpDownCounter`: `$counter = $r->newUpDownCounter('conns', 'Active connections'); $this->assertInstanceOf(UpDownCounter::class, $counter); $counter->add(1); $counter->add(-1); $this->assertSame(0.0, $b->upDownCounterValue('conns'));`. So this finding seems already addressed.

---

### 3.4 PrometheusFileBackend uses NUL byte as internal key separator [PENDING]

**What:** `src/Backend/PrometheusFileBackend.php:289` — `return $name . "\0{" . implode(',', $parts) . '}';` uses `\0` (NUL) as separator.

**Why:** Unusual and invisible when debugging key strings. Could cause subtle issues with string functions.

**Severity:** Minor

**Conditions for Success:**
- Add a descriptive constant: `private const KEY_SEPARATOR = "\0";`
- Use the constant in `key()` and `splitKey()` methods
- Add a docblock explaining why NUL was chosen (cannot appear in Prometheus metric names after sanitization)
- All tests pass

**Related Code:**
- `src/Backend/PrometheusFileBackend.php:278-302`

---

### 3.5 No coverage of MultiBackend::describe() forwarding in tests [PENDING]

**What:** `tests/Backend/MultiBackendTest.php` — tests fanout for counter/gauge/histogram/etc but NOT `describe()`.

**Why:** `describe()` is part of the Backend interface and MultiBackend does forward it (line 82-85), but there's no test verifying this.

**Severity:** Minor

**Conditions for Success:**
- Add a test that verifies `describe()` is forwarded to all children
- Use a mock/spy backend that tracks describe() calls
- All existing tests pass

**Related Code:**
- `tests/Backend/MultiBackendTest.php`
- `src/Backend/MultiBackend.php:82-85`

---

### 3.6 SessionMetrics middleware throws exceptions from handle() [PENDING]

**What:** `src/Middleware/SessionMetrics.php:42-59` — the `handle()` method re-throws exceptions after recording the error counter. If `$this->registry->counter()` or `$this->registry->time()` throws, the exception behavior is undefined.

**Why:** Registry operations should not interfere with the session middleware's core contract (propagate `$next`'s exception).

**Severity:** Minor

**Conditions for Success:**
- Wrap registry operations in try-catch in the handle() method
- The try block should contain `$next($ctx, $session)` and the catch should record the error counter
- Registry operation failures should not prevent exception propagation
- Add a test that verifies registry exceptions don't suppress the original exception

**Related Code:**
- `src/Middleware/SessionMetrics.php:42-59`

---

### 3.7 JsonStreamBackend throws on partial write [PENDING]

**What:** `src/Backend/JsonStreamBackend.php:92-95` — a partial write throws RuntimeException, which could crash the application. For a metrics backend, silently dropping is often preferable.

**Why:** A metrics backend that crashes the app is worse than dropped metrics. Consider configurable behavior.

**Severity:** Minor

**Conditions for Success:**
- Add a constructor parameter `$throwOnError = true` to control behavior
- When `$throwOnError = false`, silently drop metrics on partial write
- Default to `true` for backward compatibility
- Add tests for both behaviors

**Related Code:**
- `src/Backend/JsonStreamBackend.php:29-53` — constructor
- `src/Backend/JsonStreamBackend.php:92-95` — emit/write logic

---

### 3.8 StatsdBackend constructor timeout is hardcoded to 1 second [PENDING]

**What:** `src/Backend/StatsdBackend.php:51` — `$sock = @fsockopen("udp://{$host}", $port, $errno, $errstr, 1.0);` — the 1.0 second timeout is hardcoded and not configurable.

**Why:** On overloaded networks, this could block. Timeout should be configurable.

**Severity:** Minor

**Conditions for Success:**
- Add a `$timeout = 1.0` parameter to the constructor
- Pass it to fsockopen
- Default to 1.0 for backward compatibility
- Add a test with a custom timeout value

**Related Code:**
- `src/Backend/StatsdBackend.php:38-57` — constructor
- `src/Backend/StatsdBackend.php:51` — fsockopen call

---

### 3.9 PrometheusFileBackend describe() does not apply sanitization [PENDING]

**What:** `src/Backend/PrometheusFileBackend.php:125-130` — `describe()` stores descriptors keyed by sanitized name. But callers reading the descriptor get the unsanitized name. If a descriptor is registered with name `"http.request.duration"`, it gets stored under `"http_request_duration"`, but `$descriptor->name` still returns `"http.request.duration"`.

**Why:** Descriptor names should be validated at registration time (throw if invalid Prometheus syntax) or the sanitized name should be stored in the Descriptor.

**Severity:** Minor

**Conditions for Success:**
- Add validation in `describe()` that throws if the descriptor name doesn't match Prometheus syntax
- OR modify the `Descriptor` class to store both original and sanitized names
- Add a test that verifies invalid descriptor names throw an exception
- All existing tests pass

**Related Code:**
- `src/Backend/PrometheusFileBackend.php:125-130`
- `src/Backend/PrometheusFileBackend.php:309-320` — sanitizeName() method

---

### 3.10 phpunit.xml missing failOnWarning and cacheDirectory [PENDING]

**What:** `candy-metrics/phpunit.xml:1-16` — missing `failOnWarning="true"` and `cacheDirectory=".phpunit.cache"` attributes that are required per the project skeleton in AGENTS.md.

**Why:** Warnings don't fail the build locally vs in CI. Inconsistent with other project libraries.

**Severity:** Minor

**Conditions for Success:**
- Add `failOnWarning="true"` to phpunit.xml root element
- Add `cacheDirectory=".phpunit.cache"` to phpunit.xml root element
- Reference: `candy-core/phpunit.xml` has both attributes

**Related Code:**
- `candy-metrics/phpunit.xml:1-16`
- `candy-core/phpunit.xml:1-18` — reference implementation

---

## Phase 4: Enhancement Suggestions [PENDING]

### 4.1 Add Collector for periodic async observation [PENDING]

**What:** No collector/or periodic collection for async instruments. A typical OTel async instrument pattern involves a "meter" that holds all async instruments and periodically calls `observe()` on each one.

**Why:** Without a collector, callers must manually call `observe()` on each async instrument. A Collector class would batch this.

**Severity:** Enhancement

**Conditions for Success:**
- Create `final class Collector` with `register(AsyncCounter|AsyncGauge $instrument): void` and `collectAll(): void` methods
- `collectAll()` calls `observe()` on each registered instrument
- Add tests for the Collector
- Document usage

**Related Code:**
- `src/Instrument/AsyncCounter.php` — observe() method
- `src/Instrument/AsyncGauge.php` — observe() method

---

### 4.2 Add ReactPHP async StatsD emission [PENDING]

**What:** `src/Backend/StatsdBackend.php:51` — `fsockopen` with 1s timeout and synchronous `fwrite`. For high-throughput scenarios, this could block.

**Why:** Non-blocking I/O would improve performance under load.

**Severity:** Enhancement

**Conditions for Success:**
- This is a significant enhancement requiring a new backend class (e.g., `ReactStatsdBackend`)
- Would depend on react/socket package
- Not for the current PR scope — document as future enhancement

**Related Code:**
- `src/Backend/StatsdBackend.php`

---

## Recommendations Summary

| Priority | Issue | Recommendation |
|----------|-------|----------------|
| Critical | SessionMetrics hard-couples candy-wish | Move to bridge package or hard-require |
| Critical | Backend interface missing flush() | Add `flush(): void` or document optionality |
| Critical | flush() re-emits all metrics every call | Track dirty flag; skip if clean |
| Major | 5 duplicate TYPE/HELP blocks in flush() | Extract helper method |
| Major | tag-sorting duplicated in 2 places | Extract to shared Util class |
| Major | No metric reset/remove | Add clear/remove methods |
| Major | Hardcoded histogram buckets | Add configurable buckets |
| Major | MultiBackend only reports first error | Use AggregateException pattern |
| Minor | defaultTags not readonly | Add readonly modifier |
| Minor | InMemoryBackend return type inconsistency | Standardize on ?float |
| Minor | phpunit.xml missing failOnWarning | Add failOnWarning="true" |
| Minor | StatsdBackend timeout not configurable | Add $timeout parameter |
| Enhancement | Add Collector for periodic async observation | New class for batch observe() |
| Enhancement | Add ReactPHP async StatsD emission | Non-blocking UDP send |

---

## Notes

- **2026-06-30:** Plan created from code review findings. All findings from `findings/candy-metrics.md` addressed.
- **Verification:** After each fix, run `cd candy-metrics && vendor/bin/phpunit` to ensure all tests pass.
- **Breaking changes:** None of the fixes should be breaking except possibly the `InMemoryBackend::clear()`/`remove()` additions (additive API).
- **Priority order:** Critical issues first (Phase 1), then Major (Phase 2), then Minor (Phase 3), then Enhancements (Phase 4).
