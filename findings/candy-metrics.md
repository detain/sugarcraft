# Code Review: candy-metrics

**Reviewed:** 2026-06-29  
**Files Reviewed:** 15 source files, 13 test files  
**Severity:** 🔴 Critical | 🟠 Major | 🟡 Minor | 🟢 Nitpick  
**Confidence:** ≥80% for all reported issues

---

## Summary

candy-metrics is a well-architected telemetry library implementing counters, gauges, histograms, and async instruments with pluggable backends (InMemory, StatsD UDP, Prometheus textfile, JSON stream, MultiBackend fanout). The code is generally clean, type-safe, and follows project conventions. However, several significant issues were identified including duplicate code blocks, missing interface methods, hard coupling to an optional dependency, and async pattern gaps.

---

## Critical Issues

### 🔴 1. SessionMetrics hard-coupled to optional dependency

**File:** `src/Middleware/SessionMetrics.php:8-10`

```php
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
```

`SessionMetrics` directly imports from `candy-wish`, but `composer.json` only lists it as a `suggest`, not a `require`. Any code that instantiates `SessionMetrics` without `candy-wish` installed will get a fatal "Class not found" error at runtime, not at composer install time.

**Impact:** Silent failure at runtime if candy-wish is not installed; composer cannot catch this at `require` time.

**Recommendation:** Either move `SessionMetrics` to a separate `candy-metrics-wish` bridge package, or declare `sugarcraft/candy-wish` as a hard `require` in `composer.json`.

---

### 🔴 2. Backend interface missing flush() contract

**File:** `src/Backend.php:20-70`

The `Backend` interface defines 7 emit methods but does **not** declare `flush()`. However, `PrometheusFileBackend` requires explicit `flush()` calls for output (line 132), and its destructor calls it implicitly (line 62-68). `InMemoryBackend` and `StatsdBackend` are no-ops for flush.

**Impact:** No interface contract for flush; `MultiBackend` has no way to propagate flush to children, so fanout to a PrometheusFileBackend + JsonStreamBackend would only flush the first one if you call `multiBackend.flush()`. (MultiBackend doesn't forward flush at all — line 82-85 only forwards `describe()`.)

**Recommendation:** Either add `flush(): void` to the `Backend` interface (with default no-op implementation) or document clearly that `flush()` is only applicable to certain backends and must be called directly on them.

---

### 🔴 3. PrometheusFileBackend flush() re-emits all metrics on every call

**File:** `src/Backend/PrometheusFileBackend.php:132-273`

Each `flush()` call re-serializes and writes the **entire** current state of all accumulated metrics to the file. This means:
1. File is rewritten even if no new data arrived since last flush
2. Concurrent scrapers can read a half-written state during `fwrite` before the `rename()`
3. The atomic `rename()` at line 270 only covers the rename, not the full write-to-temp-then-rename sequence

**Impact:** For long-running processes that flush periodically, this creates ongoing I/O overhead even with no new data. Scrapers during the write window see incomplete data.

**Recommendation:** Track whether any data was recorded since the last flush; skip re-writing if no new data. Or use a separate temp file approach where rename is the commit.

---

## Major Issues

### 🟠 1. PrometheusFileBackend::flush() has 5 near-identical TYPE/HELP emission blocks

**File:** `src/Backend/PrometheusFileBackend.php:143-246`

Lines 143-158 (counters), 159-174 (upDownCounters), 175-190 (asyncCounters), 191-206 (asyncGauges), 207-222 (gauges) all follow the same pattern:

```php
if (!isset($typeEmitted[$name])) {
    $typeEmitted[$name] = true;
    if (isset($this->descriptors[$name])) {
        $d = $this->descriptors[$name];
        $body .= "# HELP {$name} " . self::escapeHelp($d->help) . "\n";
        $body .= "# TYPE {$name} {$d->type}\n";
        $descriptorEmitted[$name] = true;
    } else {
        $body .= "# TYPE {$name} counter\n"; // or gauge
    }
}
$body .= "{$name}{$labels} " . self::fmt($val) . "\n";
```

This is a clear DRY violation across ~80 lines.

**Recommendation:** Extract to:
```php
private function emitFamilyHeader(string $name, ?Descriptor $descriptor, string $defaultType): string { ... }
private function emitSample(string $name, string $labels, string $value): string { ... }
```

---

### 🟠 2. InMemoryBackend::key() and Registry::tagKey() duplicate tag-sorting logic

**File:** `src/Backend/InMemoryBackend.php:120-131` vs `src/Registry.php:269-280`

Both methods:
1. Return early for empty tags
2. Call `ksort($tags)`
3. Iterate and build `"k=v"` pairs
4. Join with a separator

The only difference is `InMemoryBackend::key()` prepends the metric name (`$name . '|' . ...`), while `Registry::tagKey()` does not include the name.

**Impact:** Two places to maintain the same logic; if tag-sorting behavior changes, both must be updated.

**Recommendation:** Extract shared tag-key building to a utility function in a shared location (e.g., `SugarCraft\Metrics\Util`).

---

### 🟠 3. PrometheusFileBackend bucket line construction is fragile string manipulation

**File:** `src/Backend/PrometheusFileBackend.php:239-243`

```php
$leAttr = $labels !== '' ? substr($labels, 0, -1) . ',le="' . $b . '"}' : '{le="' . $b . '"}';
$body .= "{$name}_bucket{$leAttr} {$h['buckets'][(string) $b]}\n";
```

This strips the trailing `}` from `$labels` and appends `,le="..."}`. This works but is hard to read and verify. The matching `+Inf` construction at line 242-243 repeats the same pattern.

**Recommendation:** Use a proper label-building approach:
```php
private function buildBucketLabels(string $labels, string $le): string
{
    if ($labels === '') {
        return '{le="' . $le . '"}';
    }
    return substr($labels, 0, -1) . ',le="' . $le . '"}';
}
```

---

### 🟠 4. StatsdBackend suppresses write errors silently

**File:** `src/Backend/StatsdBackend.php:106`

```php
@fwrite($this->sock, $line);
```

The `@` operator suppresses all errors. If the UDP socket is broken (e.g., network issue, buffer full), metrics are silently dropped with no logging, visibility, or fallback.

**Impact:** Production systems may lose metrics with no indication anything went wrong.

**Recommendation:** Consider making error handling configurable (fail-silently vs throw-once-per-interval). At minimum, log the error somewhere when suppression is enabled. Alternatively, throw in test/dev mode and suppress only in production-configured instances.

---

### 🟠 5. MultiBackend::fanout() only reports first error

**File:** `src/Backend/MultiBackend.php:100-114`

When `continueOnError` is true and multiple children fail, only `$errors[0]` is reported in the exception message and as the `previous` exception:

```php
throw new \RuntimeException(
    'MultiBackend: ' . count($errors) . ' child backend(s) failed. First: ' . $errors[0]->getMessage(),
    previous: $errors[0]
);
```

**Impact:** If StatsD fails and Prometheus file fails, only StatsD's error is visible.

**Recommendation:** Use `AggregateException`-style pattern: collect all errors and include all messages. Or at minimum, include the count and first error, and store all errors in an exception property for programmatic access.

---

### 🟠 6. Registry::tagKey() and InMemoryBackend::key() key format inconsistency

**File:** `src/Registry.php:269-280` vs `src/Backend/InMemoryBackend.php:120-131`

- `Registry::tagKey()`: Uses `|` separator, returns `""` for empty tags, format `"k=v|k2=v2"`
- `InMemoryBackend::key()`: Uses `|` separator, returns bare `$name` for empty tags, format `"name|k=v|k2=v2"`

The delimiters match (`|`) but the Registry key lacks the metric name prefix. This means `$registry->cardinality('foo')` tracks `"user=bob"` while `$backend->counter('foo', 1, ['user' => 'bob'])` stores `"foo|user=bob"`. These are used for different purposes (cardinality tracking vs backend storage) so it's intentional, but it's a subtle inconsistency that could confuse future maintainers.

**Recommendation:** Add a comment explaining the difference, or consider using a shared key-format constant.

---

### 🟠 7. Histogram bucket boundaries are hardcoded and not configurable

**File:** `src/Backend/PrometheusFileBackend.php:41`

```php
private const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 25.0, 50.0, 100.0];
```

The 14 classic Prometheus buckets are hardcoded. There is no way to configure custom boundaries per histogram. For some use cases (e.g., SLO-based buckets), custom boundaries are needed.

**Recommendation:** Add a `$buckets` parameter to `histogram()` or a constructor parameter to `PrometheusFileBackend` to override the default buckets per-histogram or globally.

---

### 🟠 8. No way to reset or remove metrics

**Impact:** For long-running processes, if cardinality eviction or other cleanup is needed, there is no API to:
- Clear all metrics from InMemoryBackend (useful for testing)
- Remove a specific metric series
- Reset a counter or gauge to zero

**Recommendation:** Add:
- `InMemoryBackend::clear(): void` — resets all accumulators
- `InMemoryBackend::remove(string $name): void` — removes a specific metric
- `Registry::remove(string $name, array $tags = []): void` — removes from both backend and cardinality cache

---

### 🟠 9. Missing `newUpDownCounter()` factory method

**File:** `src/Registry.php:133-136`

The Registry provides factory methods for `AsyncCounter` (line 144), `AsyncGauge` (line 155), but NOT for `UpDownCounter`:

```php
public function newUpDownCounter(string $name, string $help = '', array $tags = []): Instrument\UpDownCounter
{
    return new Instrument\UpDownCounter($this, $name, $help);
}
```

This inconsistency means the UpDownCounter must be constructed directly with `new UpDownCounter($registry, ...)`, breaking the fluent factory pattern used for the other two async instruments.

**Recommendation:** Add `newUpDownCounter()` factory matching the pattern of `newAsyncCounter()` and `newAsyncGauge()`.

---

### 🟠 10. AsyncCounter and AsyncGauge have no cleanup/deregistration mechanism

**File:** `src/Instrument/AsyncCounter.php` and `src/Instrument/AsyncGauge.php`

Once created, async instruments hold a reference to the Registry. If many async instruments are created dynamically (e.g., per-connection), they accumulate in memory with no `remove()` or `destroy()` method.

**Recommendation:** Add an `destroy(): void` method to both AsyncCounter and AsyncGauge that removes their observations from the registry.

---

## Minor Issues

### 🟡 1. Registry::$defaultTags is not readonly

**File:** `src/Registry.php:38`

```php
private array $defaultTags;
```

The property is set once in the constructor and never modified. Following the project's immutable/fluent pattern, it should be `readonly`.

**Recommendation:** Change to `private readonly array $defaultTags;`

---

### 🟡 2. InMemoryBackend counter/gauge return type inconsistency

**File:** `src/Backend/InMemoryBackend.php:86-94`

- `counterValue()` returns `float` (0.0 if missing)
- `gaugeValue()` returns `?float` (null if missing)
- `asyncCounterValue()` returns `float` (0.0 if missing)
- `asyncGaugeValue()` returns `?float` (null if missing)

The inconsistency means callers must handle different return types for "not found" cases.

**Recommendation:** Pick one convention (prefer `?float` for all gauge-like methods since absent gauges are semantically null, not zero).

---

### 🟡 3. Missing test for newUpDownCounter factory

**File:** `tests/RegistryTest.php:119-142`

`newAsyncCounter()` and `newAsyncGauge()` are tested, but `newUpDownCounter()` is not tested in the factory method test block.

**Recommendation:** Add `newUpDownCounter()` to the factory test.

---

### 🟡 4. PrometheusFileBackend uses NUL byte as internal key separator

**File:** `src/Backend/PrometheusFileBackend.php:289`

```php
return $name . "\0{" . implode(',', $parts) . '}';
```

Using `\0` (NUL) as a separator is unusual and could cause subtle issues if combined with string functions that treat it unexpectedly (though `strpos` and `substr` handle it correctly). It's also invisible when debugging key strings.

**Recommendation:** Consider using a more visible separator like `\x00` in a constant with a descriptive name, or add a comment explaining why NUL was chosen (it cannot appear in Prometheus metric names after sanitization, making it safe).

---

### 🟡 5. No coverage of MultiBackend::describe() forwarding in tests

**File:** `tests/Backend/MultiBackendTest.php`

The test fanout tests cover counter/gauge/histogram/upDownCounter/asyncCounter/asyncGauge but NOT `describe()`.

**Recommendation:** Add a test that verifies `describe()` is forwarded to all children.

---

### 🟡 6. SessionMetrics middleware throws exceptions from handle()

**File:** `src/Middleware/SessionMetrics.php:42-59`

The `handle()` method re-throws exceptions after recording the error counter. If `$this->registry->counter()` or `$this->registry->time()` throws, the exception behavior is undefined (could suppress the original exception, double-throw, etc.).

**Recommendation:** Wrap registry operations in try-catch so they cannot interfere with the session middleware's core contract (propagate `$next`'s exception).

---

### 🟡 7. JsonStreamBackend throws on partial write

**File:** `src/Backend/JsonStreamBackend.php:92-95`

```php
if ($written === false || $written < strlen($line) + 1) {
    throw new \RuntimeException(Lang::t('jsonstream.write_failed', ['name' => $name]));
}
```

A partial write throws, which could crash the application. For a metrics backend, silently dropping is often preferable. Consider making this configurable.

---

### 🟡 8. StatsdBackend constructor timeout is hardcoded to 1 second

**File:** `src/Backend/StatsdBackend.php:51`

```php
$sock = @fsockopen("udp://{$host}", $port, $errno, $errstr, 1.0);
```

The 1.0 second timeout is hardcoded and not configurable. On a overloaded network, this could block.

**Recommendation:** Add a `$timeout` parameter to the constructor.

---

### 🟡 9. PrometheusFileBackend describe() does not apply sanitization

**File:** `src/Backend/PrometheusFileBackend.php:125-130`

```php
public function describe(Descriptor $descriptor): void
{
    $this->descriptors[self::sanitizeName($descriptor->name)] = $descriptor;
}
```

`describe()` sanitizes the name for storage in `$this->descriptors`. But when `flush()` looks up descriptors at line 148-152, it uses the same `splitKey($key)` which extracts the name and looks up `$this->descriptors[$name]`. This appears correct — but the descriptor's original (unsanitized) name in the Descriptor object itself is never verified to be valid Prometheus syntax.

If a descriptor is registered with name `"http.request.duration"`, it gets stored under `"http_request_duration"`, but `$descriptor->name` still returns `"http.request.duration"`. Callers reading the descriptor get the unsanitized name.

**Recommendation:** Either validate descriptor names at registration time (throw if invalid) or store the sanitized name in the Descriptor or a wrapper.

---

### 🟡 10. phpunit.xml missing failOnWarning and cacheDirectory

**File:** `candy-metrics/phpunit.xml:1-16`

Per the AGENTS.md skeleton, phpunit.xml should have:
```xml
failOnWarning="true"
cacheDirectory=".phpunit.cache"
```

Both are absent. This means warnings don't fail the build locally vs in CI.

---

## Positive Observations

### 🟢 Well-designed backend interface
`src/Backend.php` is a clean, focused interface with clear method contracts. The split between synchronous (counter/gauge/histogram/upDownCounter) and asynchronous (asyncCounter/asyncGauge) is thoughtful and matches the OpenTelemetry model.

### 🟢 Comprehensive test coverage
41 tests covering Registry, all 5 backends, all instruments, cardinality management, histogram buckets, and the SessionMetrics middleware. Tests use proper Arrange-Act-Assert patterns.

### 🟢 Good Prometheus textfile format compliance
Atomic rename with flock, proper HELP/TYPE ordering, bucket ordering, label escaping, name sanitization, and summary TYPE emission (without quantile lines) all correctly implemented.

### 🟢 Cardinality protection with FIFO eviction
The label-value cache with per-metric cardinality limits and FIFO eviction (lines 256-260 in Registry.php) is a robust defense against memory exhaustion from unbounded label combinations.

### 🟢 Proper use of PHP 8.1+ features
Readonly classes, constructor property promotion, named arguments, first-class callables, and strict_types are used consistently.

### 🟢 Idempotent descriptor registration
`Registry::register()` (line 68-75) correctly guards against duplicate registration, preventing double-emit of TYPE/HELP lines.

### 🟢 Extensive i18n support
16 language translation files covering en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar — well above the typical library investment.

---

## Compatibility Issues

### ⚠️ candy-wish hard-coupling (covered above as Critical #1)

### ⚠️ Histogram values unbounded in InMemoryBackend
`src/Backend/InMemoryBackend.php:46-49` — histogram samples are stored as a `list<float>` with no sampling or aggregation. For high-volume metrics, this can exhaust memory. No `exponential histogram` or `HR timestamp histogram` support (OTel compatible).

**Recommendation:** Consider adding a sampling strategy or aggregated histogram mode.

---

## Async Pattern Analysis

### Current async pattern: callback-based observation

**How it works:**
- `AsyncCounter` / `AsyncGauge` are created with a `\Closure(): float` callback
- Application code calls `observe()` to read the callback and record the value
- No automatic periodic collection; caller controls when to observe

**Gap: No collector/or周期性 collection**
A typical OTel async instrument pattern involves a "meter" that holds all async instruments and periodically calls `observe()` on each one during a collection cycle. This library has no equivalent.

**Recommendation:** Add a `Collector` class that:
```php
final class Collector
{
    /** @var list<AsyncCounter|AsyncGauge> */
    private array $instruments = [];
    
    public function register(AsyncCounter|AsyncGauge $instrument): void;
    public function collectAll(): void; // calls observe() on each
}
```

### StatsdBackend blocking I/O
`src/Backend/StatsdBackend.php:51` — `fsockopen` with 1s timeout and synchronous `fwrite`. For high-throughput scenarios, this could block. Consider:
- Non-blocking I/O via ReactPHP socket
- Fire-and-forget with `stream_socket_sendto` 
- Background loop that batches and sends periodically

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
| Major | Missing UpDownCounter factory | Add newUpDownCounter() |
| Minor | defaultTags not readonly | Add readonly modifier |
| Minor | InMemoryBackend return type inconsistency | Standardize on ?float |
| Minor | phpunit.xml missing failOnWarning | Add failOnWarning="true" |
| Minor | StatsdBackend timeout not configurable | Add $timeout parameter |
| Enhancement | Add Collector for periodic async observation | New class for batch observe() |
| Enhancement | Add ReactPHP async Statsd emission | Non-blocking UDP send |
