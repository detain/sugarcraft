# candy-metrics: Telemetry Patterns Research

**Library:** `sugarcraft/candy-metrics`
**Upstream:** `charmbracelet/promwish`
**Date:** 2026-05-13
**Status:** Phase 9+ — first cut (23 tests / 57 assertions)

---

## Executive Summary

candy-metrics provides a clean Registry + Backend facade for Counters, Gauges, and Histograms with pluggable backends (InMemory, StatsD UDP, Prometheus textfile, JSON stream, MultiBackend fanout). The implementation is solid but **lacks several key Prometheus features** that would bring it to parity with upstream Go/Rust clients.

**Highest-impact gaps:**
1. **No histogram buckets** — PromExporter emits `summary` (count+sum only) instead of `histogram` with `le` bucket labels
2. **No metric registration/description** — can't declare metrics upfront with help text, units, or type info
3. **No label cardinality management** — no `remove()` / `clear()` on child metrics
4. **No UpDownCounter** — gauge can't decrement atomically (differs from UpDown semantics)

---

## 1. Upstream & Ecosystem Analysis

### 1.1 charmbracelet/promwish (Go)

**Source:** https://github.com/charmbracelet/promwish

Promwish is lightweight middleware for Wish (SSH server library). It exposes a `/metrics` HTTP endpoint with basic counters/gauges for active connections, commands run, and errors. It wraps `prometheus/client_golang` under the hood.

Key traits:
- Middleware pattern (wraps SSH session lifecycle)
- Basic counter/gauge only — no histogram
- Uses global prometheus registry
- Exposes via HTTP on configurable port

### 1.2 prometheus/client_golang (Go)

**Source:** https://github.com/prometheus/client_golang

The canonical Prometheus client. Key patterns:

#### CounterVec / GaugeVec / HistogramVec

```go
// Define a metric with labels at startup
requestsTotal := prometheus.NewCounterVec(
    prometheus.CounterOpts{
        Namespace: "myapp",
        Subsystem: "http",
        Name:      "requests_total",
        Help:      "Total HTTP requests by handler and status",
    },
    []string{"handler", "method", "status"},
)
prometheus.MustRegister(requestsTotal)

// Use with label values (order-sensitive but fast)
requestsTotal.WithLabelValues("/api/users", "GET", "200").Inc()

// Or with labels map (slower but order-insensitive)
requestsTotal.With(prometheus.Labels{
    "handler": "/api/users",
    "method":  "GET",
    "status":  "200",
}).Inc()
```

#### Histogram with Buckets

```go
requestDuration := prometheus.NewHistogramVec(
    prometheus.HistogramOpts{
        Namespace: "myapp",
        Subsystem: "http",
        Name:      "request_duration_seconds",
        Help:      "HTTP request duration in seconds.",
        Buckets:   []float64{.001, .005, .01, .025, .05, .1, .25, .5, 1, 2.5, 5, 10},
    },
    []string{"handler", "method"},
)

// Record a value
requestDuration.WithLabelValues("/api/users", "GET").Observe(0.042)
```

#### Currying / Partial Application

```go
// Curry with fixed labels to reduce cardinality
methodMetric := requestsTotal.MustCurryWith(prometheus.Labels{"method": "GET"})
methodMetric.WithLabelValues("/api/users", "200").Inc()
```

#### Key Go Patterns

| Pattern | Purpose |
|---------|---------|
| `CounterOpts` / `GaugeOpts` / `HistogramOpts` | Config structs with Namespace, Subsystem, Name, Help, Buckets |
| `MustRegister` / `Register` | Global registry registration |
| `NewCounterVec` / `NewHistogramVec` | Vector metrics with label dimension |
| `WithLabelValues(...string)` | Fast label access (order-sensitive) |
| `With(Labels{...})` | Slow but order-insensitive label access |
| `CurryWith` | Fix some labels, return reduced-arity vector |
| `GetMetricWith*` | Lazy metric creation on first access |

### 1.3 metrics (Rust)

**Source:** https://docs.rs/metrics/0.24.3/metrics/

A batteries-included instrumentation ecosystem. Key patterns:

#### Macro-based emission

```rust
use metrics::{counter, histogram, gauge};

// Basic counter
let counter = counter!("some_metric_name");
counter.increment(1);

// With inline labels
counter!("requests_received", "service" => "http", "status" => "200").increment(1);

// Gauge
let gauge = gauge!("queue_depth");
gauge.set(42);

// Histogram
histogram!("request_duration").record(start.elapsed());
```

#### Recorder trait

```rust
pub trait Recorder {
    fn describe_counter(&self, key: KeyName, description: &'static str, unit: Option<Unit>);
    fn describe_gauge(&self, key: KeyName, description: &'static str, unit: Option<Unit>);
    fn describe_histogram(&self, key: KeyName, description: &'static str, unit: Option<Unit>);
    fn record_counter(&self, key: Key, value: u64);
    fn record_gauge(&self, key: Key, value: f64);
    fn record_histogram(&self, key: Key, value: f64);
}
```

#### Key Rust Patterns

| Pattern | Purpose |
|---------|---------|
| `describe_*` macros | Register metric metadata (help, unit) without emitting |
| `Key` (name + labels) | Composite metric identifier |
| `Label` (key/value) | Dimensional labels |
| `Recorder` trait | Abstracts over actual exporter |
| `LocalRecorderGuard` | Thread-local recorder for isolation |
| Handle caching | Returned handles can be cached for performance |

### 1.4 prometheus-client (Rust)

**Source:** https://docs.rs/prometheus-client/latest/prometheus_client/registry/struct.Registry.html

More Prometheus-specific Rust client with registry pattern:

```rust
let mut registry = Registry::default();
let counter: Counter = Counter::default();
registry.register("my_counter", "This is my counter", counter.clone());

// Sub-registry with prefix
let subsystem_a_registry = registry.sub_registry_with_prefix("subsystem_a");
subsystem_a_registry.register("counter_1", "", subsystem_a_counter_1.clone());

// Sub-registry with labels
let labeled_registry = registry.sub_registry_with_label(("env", "prod".into()));
```

### 1.5 OpenTelemetry Metrics SDK

**Source:** https://opentelemetry.io/docs/specs/otel/metrics/sdk

OTel provides a richer model:

| Instrument | Behavior | SugarCraft equivalent |
|------------|----------|----------------------|
| Counter | Monotonically increasing | Counter ✓ |
| UpDownCounter | Can increase or decrease | (missing) |
| Histogram | Distribution of values | Histogram ✓ |
| Gauge | Point-in-time observation | Gauge ✓ |
| Asynchronous Counter | Callback-based monotonic | (missing) |
| Asynchronous Gauge | Callback-based observation | (missing) |

#### View / Aggregation Configuration

```python
meter_provider.add_view(
    "Bar",
    instrument_name="Y",
    aggregation=HistogramAggregation(buckets=[5.0, 10.0, 25.0, 50.0, 100.0])
)
```

### 1.6 Prometheus Exposition Format

**Source:** https://prometheus.io/docs/instrumenting/exposition_formats/

#### Histogram with buckets (classic)

```
# HELP http_request_duration_seconds A histogram of the request duration.
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{le="0.05"} 24054
http_request_duration_seconds_bucket{le="0.1"} 33444
http_request_duration_seconds_bucket{le="0.2"} 100392
http_request_duration_seconds_bucket{le="0.5"} 129389
http_request_duration_seconds_bucket{le="1"} 133988
http_request_duration_seconds_bucket{le="+Inf"} 144320
http_request_duration_seconds_sum 53423
http_request_duration_seconds_count 144320
```

**Critical:** `le` is a reserved label name for histogram buckets — user-defined labels MUST NOT use `le`.

#### Native Histograms (Prometheus 2.40+)

Sparse bucketing with exponential schema — more efficient for high-cardinality histograms. Not yet widely adopted in client libs.

---

## 2. Gap Analysis: candy-metrics vs. Upstream

### 2.1 Current Implementation

```
candy-metrics/src/
├── Backend.php                    ← Interface (counter/gauge/histogram)
├── Registry.php                   ← Facade with time() + withTags()
├── Backend/
│   ├── InMemoryBackend.php        ← Accumulator for tests
│   ├── StatsdBackend.php          ← UDP DogStatsD format
│   ├── JsonStreamBackend.php      ← NDJSON to stream
│   ├── PrometheusFileBackend.php  ← .prom textfile (ATOMIC rename)
│   └── MultiBackend.php           ← Fanout to multiple backends
├── Middleware/
│   └── SessionMetrics.php         ← Wish session telemetry
└── Lang.php                       ← i18n wrapper
```

### 2.2 Identified Gaps

| Gap | Severity | Description | Upstream Reference |
|-----|----------|-------------|-------------------|
| **Histogram buckets** | 🔴 High | PrometheusFileBackend emits `summary` type (count+sum) instead of `histogram` with `_bucket{le=X}` series | prometheus/client_golang has `Buckets: []float64{...}` |
| **Metric description** | 🔴 High | No `describe_counter()` / `describe_histogram()` to register help text and units | Go: `CounterOpts{Help: "...", ...}`; Rust: `describe_counter!` macro |
| **UpDownCounter** | 🟡 Medium | Gauge allows set but not atomic increment/decrement | OpenTelemetry: UpDownCounter |
| **Label cardinality control** | 🟡 Medium | No `remove(labelValues)` or `clear()` to delete child metrics | Go: `Delete(Labels)`, `Reset()` |
| **Metric handles** | 🟡 Medium | No cached handle pattern — every emit does key computation | Go: `WithLabelValues()` returns cached child |
| **Currying / partial tags** | 🟡 Medium | `withTags()` creates new Registry; no partial application on Vec | Go: `CurryWith()`; Rust: handle caching |
| **Sampling rate (StatsD)** | 🟡 Medium | No `sampleRate` parameter for StatsD | DogStatsD supports `|@0.1` sampling |
| **Exemplars** | 🟢 Low | No exemplar support (trace ID on histogram) | Prometheus: Exemplars |
| **Native histograms** | 🟢 Low | No support for exponential/spare histograms | Prometheus 2.40+ native histograms |

---

## 3. Recommendations

### 3.1 Critical: Histogram Buckets (High Priority)

**Current state:**
`PrometheusFileBackend::histogram()` emits:
```
# TYPE lat summary
lat_count 2
lat_sum 0.400000
```

**Should emit (Prometheus classic histogram):**
```
# TYPE lat histogram
lat_bucket{le="0.1"} 1
lat_bucket{le="0.25"} 2
lat_bucket{le="0.5"} 2
lat_bucket{le="1"} 2
lat_bucket{le="+Inf"} 2
lat_sum 0.400000
lat_count 2
```

**Implementation approach:**

```php
// Add to Backend interface
interface Backend {
    public function counter(string $name, float $value, array $tags = []): void;
    public function gauge(string $name, float $value, array $tags = []): void;
    public function histogram(string $name, float $value, array $tags = [], array $buckets = null): void;
}
```

Default buckets (matching Go client):
```php
public const DEFAULT_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];
```

For `PrometheusFileBackend`, accumulate into bucket counts:
```php
private array $histograms = []; // [key => ['count' => int, 'sum' => float, 'buckets' => ['0.1' => count, ...]]]
```

**Effort:** 3-4 hours

---

### 3.2 Critical: Metric Description / Registration (High Priority)

**Go pattern:**
```go
prometheus.MustRegister(prometheus.NewCounterVec(
    prometheus.CounterOpts{
        Name: "http_requests_total",
        Help: "Total HTTP requests",
    },
    []string{"method", "status"},
))
```

**Recommended PHP approach:**

```php
final class Registry {
    /** @var array<string, MetricDescriptor> */
    private array $descriptors = [];

    public function describeCounter(string $name, string $help, array $tags = []): void;
    public function describeGauge(string $name, string $help, array $tags = []): void;
    public function describeHistogram(string $name, string $help, array $tags = [], ?array $buckets = null): void;
}

final class MetricDescriptor {
    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly string $type, // 'counter'|'gauge'|'histogram'
        public readonly array $tags,
        public readonly ?array $buckets = null,
    ) {}
}
```

**Effort:** 2-3 hours

---

### 3.3 Medium: Label Cardinality Control (Medium Priority)

**Go pattern:**
```go
// Remove a specific label combination
requestsTotal.DeleteLabelValues("GET", "200")

// Clear all children
requestsTotal.Reset()
```

**Recommended PHP approach:**

```php
final class InMemoryBackend {
    public function counter(string $name, float $value, array $tags = []): void;
    public function deleteCounter(string $name, array $tags): bool;
    public function clearCounter(string $name): void;

    public function gauge(string $name, float $value, array $tags = []): void;
    public function deleteGauge(string $name, array $tags): bool;

    public function histogram(string $name, float $value, array $tags = []): void;
    public function deleteHistogram(string $name, array $tags): bool;
}
```

Note: `PrometheusFileBackend` cannot retroactively delete metrics (append-only format). But `InMemoryBackend` and future backends can support this.

**Effort:** 2 hours

---

### 3.4 Medium: UpDownCounter Semantics (Medium Priority)

OpenTelemetry's `UpDownCounter` allows atomic increment/decrement (not just set). Current `gauge()` does `set` semantics.

**Recommended addition:**

```php
final class Registry {
    public function counter(string $name, float $value = 1.0, array $tags = []): void;
    public function gauge(string $name, float $value, array $tags = []): void;
    public function upDownCounter(string $name, float $value, array $tags = []): void; // NEW

    // Convenience helpers for gauge
    public function gaugeIncrement(string $name, float $delta = 1.0, array $tags = []): void;
    public function gaugeDecrement(string $name, float $delta = 1.0, array $tags = []): void;
}
```

For `InMemoryBackend`:
```php
public function upDownCounter(string $name, float $value, array $tags = []): void {
    $key = $this->key($name, $tags);
    $this->upDownCounters[$key] = ($this->upDownCounters[$key] ?? 0.0) + $value;
}
```

**Effort:** 2 hours

---

### 3.5 Medium: Cached Metric Handles (Medium Priority)

In Go, `counter.WithLabelValues(...)` returns a cached `Counter` handle. This avoids repeated key computation in hot paths.

**Recommended PHP approach:**

```php
final class Registry {
    private const MAX_CACHED_HANDLES = 1000;

    /** @var array<string, CachedHandle> */
    private array $handleCache = [];

    public function counter(string $name, float $value = 1.0, array $tags = []): void {
        $key = $this->handleKey($name, $tags);
        if (isset($this->handleCache[$key])) {
            $this->handleCache[$key]->counter($value);
            return;
        }
        $this->backend->counter($name, $value, $this->mergeTags($tags));
    }

    private function handleKey(string $name, array $tags): string {
        ksort($tags);
        return $name . "\0" . json_encode($tags);
    }
}

final class CachedHandle {
    public function __construct(
        private readonly Backend $backend,
        private readonly string $name,
        private readonly array $tags,
    ) {}

    public function counter(float $value): void {
        $this->backend->counter($this->name, $value, $this->tags);
    }

    public function gauge(float $value): void {
        $this->backend->gauge($this->name, $value, $this->tags);
    }

    public function histogram(float $value): void {
        $this->backend->histogram($this->name, $value, $this->tags);
    }
}
```

**Effort:** 3-4 hours

---

### 3.6 Low: Sampling Rate for StatsD (Low Priority)

DogStatsD supports `sample_rate` to reduce UDP traffic:

```
metric:1|c|@0.1|#tag:value
```

```php
final class StatsdBackend {
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 8125,
        private readonly bool $dogstatsd = true,
        private readonly float $sampleRate = 1.0, // NEW
        $existingSocket = null,
    ) {}

    private function send(string $name, float $value, string $kind, array $tags): void {
        if ($this->sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) > $this->sampleRate) {
            return; // Skip due to sampling
        }
        // ...
    }
}
```

**Effort:** 1 hour

---

## 4. Implementation Priority Order

| Priority | Item | Effort | Impact |
|----------|------|--------|--------|
| 1 | Histogram buckets | 3-4h | High — required for Prometheus compliance |
| 2 | Metric description | 2-3h | High — enables `promtool textfmt` validation |
| 3 | UpDownCounter + gauge increment/decrement | 2h | Medium — completes metric type coverage |
| 4 | Label cardinality control (delete/clear) | 2h | Medium — prevents memory leaks |
| 5 | Cached metric handles | 3-4h | Medium — performance for high-frequency emits |
| 6 | Sampling rate (StatsD) | 1h | Low — UDP optimization |
| 7 | Exemplars support | 4-6h | Low — tracing integration |

---

## 5. Suggested File Changes

### 5.1 New Files

```
candy-metrics/src/
├── Descriptor.php                 ← NEW: MetricDescriptor value object
├── Handle/
│   ├── CounterHandle.php         ← NEW: Cached counter handle
│   ├── GaugeHandle.php           ← NEW: Cached gauge handle
│   └── HistogramHandle.php       ← NEW: Cached histogram handle
└── Backend/
    └── PrometheusPushBackend.php ← NEW: PushGateway support (future)
```

### 5.2 Modified Files

| File | Changes |
|------|---------|
| `Backend.php` | Add `describe*()` methods (optional, backends may ignore) |
| `Registry.php` | Add `describeCounter/Gauge/Histogram()`, `upDownCounter()`, handle caching |
| `Backend/InMemoryBackend.php` | Add bucket accumulation, delete/clear, upDownCounter |
| `Backend/PrometheusFileBackend.php` | Add histogram bucket emission, fix TYPE to `histogram` not `summary` |
| `Backend/StatsdBackend.php` | Add sample rate support |

---

## 6. Testing Recommendations

### 6.1 New Test Cases

```php
public function testHistogramRendersBuckets(): void {
    $b = new PrometheusFileBackend($this->path);
    $b->histogram('lat', 0.05);
    $b->histogram('lat', 0.15);
    $b->histogram('lat', 0.35);
    $b->flush();

    $content = file_get_contents($this->path);
    $this->assertStringContainsString('# TYPE lat histogram', $content);
    $this->assertStringContainsString('lat_bucket{le="0.1"} 1', $content);
    $this->assertStringContainsString('lat_bucket{le="0.25"} 2', $content);
    $this->assertStringContainsString('lat_bucket{le="+Inf"} 3', $content);
}

public function testDeleteRemovesCounterChild(): void {
    $b = new InMemoryBackend();
    $b->counter('hits', 1.0, ['user' => 'alice']);
    $b->counter('hits', 1.0, ['user' => 'bob']);
    $this->assertSame(2.0, $b->counterValue('hits', ['user' => 'alice']));

    $b->deleteCounter('hits', ['user' => 'alice']);
    $this->assertSame(0.0, $b->counterValue('hits', ['user' => 'alice']));
    $this->assertSame(1.0, $b->counterValue('hits', ['user' => 'bob']));
}

public function testGaugeIncrementAndDecrement(): void {
    $b = new InMemoryBackend();
    $b->gauge('queue', 10);
    $b->gaugeIncrement('queue', 3);
    $this->assertSame(13.0, $b->gaugeValue('queue'));

    $b->gaugeDecrement('queue', 5);
    $this->assertSame(8.0, $b->gaugeValue('queue'));
}
```

---

## 7. References

| Source | URL |
|--------|-----|
| promwish (upstream) | https://github.com/charmbracelet/promwish |
| prometheus/client_golang | https://github.com/prometheus/client_golang |
| prometheus exposition formats | https://prometheus.io/docs/instrumenting/exposition_formats/ |
| native histograms | https://prometheus.io/docs/specs/native_histograms/ |
| Rust metrics crate | https://docs.rs/metrics/0.24.3/metrics/ |
| prometheus-client (Rust) | https://docs.rs/prometheus-client/latest/prometheus_client/ |
| OpenTelemetry Metrics SDK | https://opentelemetry.io/docs/specs/otel/metrics/sdk |
| Go client best practices | https://prometheus.io/docs/guides/go-application/ |
| Writing client libs spec | https://next.prometheus.io/docs/instrumenting/writing_clientlibs/ |

---

## 8. Questions to Resolve

1. **Phase 2 scope**: Should histogram buckets be configurable per-call or only per-metric registration?
2. **Tag ordering**: The Go client uses label order for hashing (order-sensitive). candy-metrics sorts by key. This is correct — confirm no regression.
3. **Backwards compatibility**: Adding `buckets` parameter to `histogram()` is a breaking change for existing callers. Deprecation path needed?

---

*Research compiled 2026-05-13*
