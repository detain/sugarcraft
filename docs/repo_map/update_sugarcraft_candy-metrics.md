# Overview

**Package:** `sugarcraft/candy-metrics` (metrics)  
**Status:** 🟢 v1 ready, 41 tests  
**Type:** Telemetry primitives library (counters, gauges, histograms, async instruments)  
**Ecosystem Position:** Leaf library in SugarCraft monorepo; bridges `candy-wish` SSH server with observability backends (Prometheus, StatsD, JSON, InMemory)

candy-metrics is a well-architected telemetry library that significantly surpasses its primary upstream `charmbracelet/promwish` in capability breadth. It provides 6 metric instrument types with 5 pluggable backend implementations and robust cardinality management. The library is production-ready for basic use cases but lacks several high-value features for advanced observability scenarios.

**Biggest opportunities:** HTTP metrics server (like Go's promwish), OpenTelemetry OTLP export backend, configurable histogram bucket boundaries, exemplar/trace integration, and push gateway support.

**Biggest gaps:** No native OTEL export, hardcoded histogram buckets, no HTTP scrape endpoint, no summary type implementation, and silent failure modes that can hide misconfiguration.

---

# Internal Capability Summary

## Current Architecture

```
Registry (facade)
    ├── Backend (interface)
    │   ├── InMemoryBackend
    │   ├── JsonStreamBackend
    │   ├── StatsdBackend
    │   ├── PrometheusFileBackend
    │   └── MultiBackend
    ├── Descriptor (registration DTO)
    └── Instrument (value objects)
        ├── UpDownCounter
        ├── AsyncCounter
        └── AsyncGauge
```

**Registry** is the application-facing facade. It:
- Forwards all metric emissions to the configured Backend
- Tracks per-metric cardinality with FIFO eviction (default 10,000 limit)
- Provides `time()` helper returning a closure that records elapsed wall-clock seconds
- Supports `withTags()` for child registries with pre-merged default tags
- Exposes `register(Descriptor)` for early TYPE/HELP pre-emission

**Backend Interface** defines 6 metric types:
```php
public function counter(string $name, float $value, array $tags = []): void;
public function gauge(string $name, float $value, array $tags = []): void;
public function histogram(string $name, float $value, array $tags = []): void;
public function upDownCounter(string $name, float $amount, array $tags = []): void;
public function asyncCounter(string $name, float $value, array $tags = []): void;
public function asyncGauge(string $name, float $value, array $tags = []): void;
```

## Current Features

### Metric Instruments
| Instrument | Behavior | Use Case |
|-----------|---------|---------|
| Counter | Monotonically accumulating | Connection counts, error counts |
| Gauge | Instantaneous value (replaces) | Queue depth, RSS memory |
| Histogram | Distribution of samples | Latency, payload size with 14 classic buckets |
| UpDownCounter | Positive + negative increments | Active connections, queue size |
| AsyncCounter | Monotonic, observed via callback | JVM GC counts, DB pool size |
| AsyncGauge | Non-monotonic, observed via callback | Memory usage, queue depth |

### Backend Implementations
| Backend | Export Format | Key Behavior |
|--------|-------------|-------------|
| InMemoryBackend | PHP arrays | Counters accumulate, gauges replace, histograms keep all samples |
| JsonStreamBackend | NDJSON | One event per line with ts/kind/name/value/tags |
| StatsdBackend | UDP datagrams | DogStatsD format with `dogstatsd: false` legacy mode |
| PrometheusFileBackend | Prometheus textfile | Atomic rewrite, 14 classic cumulative buckets, flock |
| MultiBackend | Fanout | Delegates to all child backends |

### Middleware
**SessionMetrics** - CandyWish middleware emitting:
- `wish.session.connect` (counter) with `user`, `term` tags
- `wish.session.duration` (histogram) with `user`, `term` tags
- `wish.session.error` (counter) with `user`, `term`, `exception` tags

Supports `extraTags` callable for custom label extraction (client subnet, geo, build version).

## Strengths

1. **Multi-backend flexibility** - Single API with swappable backends; no lock-in to single export format
2. **Proper histogram semantics** - Uses cumulative classic bucket boundaries matching Prometheus client library v2 semantics; enables `histogram_quantile()` queries
3. **Cardinality management** - FIFO eviction at 10,000 label combinations per metric; prevents memory exhaustion from unbounded high-cardinality labels
4. **OpenTelemetry alignment** - AsyncCounter/AsyncGauge mirror OTEL async instrument API; callbacks invoked at collection time
5. **Descriptor pre-registration** - Enables early TYPE/HELP emission before samples arrive
6. **Atomic Prometheus flush** - flock(LOCK_EX) + rename ensures concurrent writer safety
7. **Rich session middleware** - Exception labels, extensible via extraTags callable
8. **Comprehensive i18n** - 18 locale files via Lang facade
9. **Well-tested** - 41 tests covering all instruments, all backends, cardinality, descriptors, and middleware

## Weaknesses

1. **No HTTP metrics server** - Unlike Go's promwish which exposes `/metrics` for Prometheus scraping, candy-metrics requires external component (node_exporter, StatsD server)
2. **No summary type** - Descriptor accepts `summary` but no backend implements quantile estimation
3. **No atomic multi-counter** - Process crash between flushes loses data; Go Prometheus client handles via transactional isolation
4. **No OTEL export backend** - No backend emits OTLP format for OTEL collectors
5. **Hardcoded histogram buckets** - 14 classic buckets private constant; no API for custom boundaries
6. **No push gateway support** - Prometheus pushgateway not supported for ephemeral jobs
7. **Silent failures in StatsdBackend** - `@fwrite()` suppresses failures; misconfigured backends fail silently
8. **MultiBackend failure propagation** - Single failing backend can crash metrics call site; no isolation
9. **No exemplar/tracing integration** - No storage or emission of trace IDs alongside metric samples

---

# Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|-------------------------|----------|
| `charmbracelet/promwish` | Primary upstream | HTTP metrics server, middleware pattern, Prometheus integration | Critical |
| `charmbracelet/log` | Observability sibling | Structured logging, slog.Handler pattern, context propagation | High |
| `charmbracelet/bubbletea` | Framework reference | Elm architecture, component patterns, testing patterns | Medium |
| `charmbracelet/confettysh` | Middleware user | promwish middleware integration pattern | Low |
| `charmbracelet/charm` | Server reference | Server lifecycle patterns, Prometheus metrics support | Medium |
| `textualize/textual` | Python TUI reference | Reactive state, worker patterns, logging integration | Low |

---

# Feature Gap Analysis

## Critical

### 1. HTTP Metrics Server
**Title:** Add HTTP server for Prometheus scraping  
**Description:** promwish exposes an HTTP `/metrics` endpoint. candy-metrics has no equivalent—requires external node_exporter or StatsD server.  
**Why it matters:** Self-contained metric exposure enables simpler deployments; no separate exporter process needed.  
**Source repo:** `docs/repo_map/charmbracelet_promwish.md`  
**Source PR/issue:** Issue #38 (Server struct lifecycle), `docs/repo_map/pr_charmbracelet_promwish.md` lines 68-79  
**Implementation ideas:**
- Add `HttpBackend` implementing `Backend` interface with ReactPHP HTTP server
- Expose `/metrics` endpoint returning Prometheus text format
- Support `/health` and `/ready` endpoints
- Add graceful shutdown with configurable timeout
**Estimated complexity:** Medium (requires ReactPHP HTTP server integration)  
**Expected impact:** High—enables self-contained Prometheus scraping without external exporter

### 2. OpenTelemetry OTLP Export Backend
**Title:** Add OtelBackend for OTEL collector export  
**Description:** No backend emits in OTLP format. OTEL is the emerging standard for observability.  
**Why it matters:** Vendor neutrality; OTEL collectors can route to Datadog, Grafana, Jaeger, etc.  
**Source repo:** `docs/repo_map/charmbracelet_promwish.md` (mentioned in "trends")  
**Implementation ideas:**
- Add `OtelBackend` using HTTP/Protobuf OTLP export
- Map internal metric types to OTEL metrics protocol
- Support both metric and trace exemplar correlation
- Implement retry with exponential backoff
**Estimated complexity:** High (requires OTEL protobuf encoding)  
**Expected impact:** Medium—future-proofs for OTEL ecosystem

## High Value

### 3. Configurable Histogram Bucket Boundaries
**Title:** Allow custom bucket boundaries for Prometheus histogram  
**Description:** The 14 classic buckets are hardcoded private constant in PrometheusFileBackend.  
**Why it matters:** Different use cases need different ranges (e.g., p99.999 boundaries for API latency, different ranges for payload sizes).  
**Source repo:** `docs/repo_map/sugarcraft_candy-metrics.md` line 477  
**Implementation ideas:**
- Add `$buckets` parameter to `PrometheusFileBackend` constructor
- Accept array of float bucket boundaries
- Validate sorted, finite values
- Pass custom buckets from Registry if provided
**Estimated complexity:** Low  
**Expected impact:** Medium—enables use-case-specific bucket ranges

### 4. Summary Type Implementation
**Title:** Implement summary metric type with pre-computed percentiles  
**Description:** Descriptor accepts `summary` type but no backend implements quantile pre-computation.  
**Why it matters:** Summary type is required for some OTEL compatibility scenarios; provides client-side quantile calculation.  
**Source repo:** `docs/repo_map/sugarcraft_candy-metrics.md` line 471  
**Implementation ideas:**
- Add `SummaryBackend` implementing sliding window quantile estimation
- Use t-digest algorithm for accurate percentile approximation
- Emit `_count`, `_sum`, and quantile values (p50, p90, p99, etc.)
**Estimated complexity:** Medium  
**Expected impact:** Low—rarely needed in practice (histogram usually preferred)

### 5. Push Gateway Backend
**Title:** Add Prometheus pushgateway support  
**Description:** Ephemeral jobs (cron jobs, serverless functions) cannot be scraped; need to push metrics.  
**Why it matters:** Common pattern for short-lived processes and batch jobs.  
**Source repo:** `docs/repo_map/sugarcraft_candy-metrics.md` line 479  
**Implementation ideas:**
- Add `PushGatewayBackend` with POST to pushgateway
- Support job grouping labels
- Implement periodic push or push-on-shutdown
**Estimated complexity:** Medium  
**Expected impact:** Medium for batch/cron job scenarios

## Medium

### 6. Exemplar/Trace Integration
**Title:** Store and emit trace IDs alongside metric samples  
**Description:** OpenTelemetry exemplars link metrics to traces. No backend stores trace context.  
**Why it matters:** Enables correlation between metrics and traces in observability platforms.  
**Source repo:** `docs/repo_map/sugarcraft_candy-metrics.md` line 485  
**Implementation ideas:**
- Add `$traceContext` parameter to histogram/counter emits
- Store trace ID in histogram samples
- Emit exemplar in Prometheus textfile format: `metric_name{..., trace_id="..."}`
**Estimated complexity:** Medium  
**Expected impact:** Medium—improves debugging/troubleshooting

### 7. Metrics Shutdown Hook
**Title:** Add explicit lifecycle methods to Backend interface  
**Description:** Go's promwish evolved to explicit Server lifecycle. PHP backends have implicit cleanup via destructors.  
**Why it matters:** Production deployments need controlled startup/shutdown ordering.  
**Source repo:** `docs/repo_map/pr_charmbracelet_promwish.md` lines 329-341  
**Implementation ideas:**
- Add `start()` and `stop(timeout)` methods to Backend interface with default empty implementations
- Registry calls `start()` on all backends when initialized
- Add explicit `flush()` and `shutdown()` methods with timeout
**Estimated complexity:** Low  
**Expected impact:** Medium—improves production reliability

### 8. MultiBackend Failure Isolation
**Title:** Isolate backends to prevent cascade failures  
**Description:** Single failing backend in MultiBackend propagates exception to caller.  
**Why it matters:** StatsD unreachable should not crash metrics call site.  
**Source repo:** `docs/repo_map/sugarcraft_candy-metrics.md` line 483  
**Implementation ideas:**
- Wrap each backend emit in try/catch within MultiBackend
- Log errors from failing backends but continue with others
- Add `$failIsolated` option to propagate failures
**Estimated complexity:** Low  
**Expected impact:** Medium—improves production robustness

## Low Priority

### 9. StatsD Failure Reporting
**Title:** Add error callback for silent StatsD failures  
**Description:** StatsdBackend uses `@fwrite()` for silent failure; misconfigurations go unnoticed.  
**Why it matters:** Production debugging of metrics pipeline issues.  
**Source repo:** `docs/repo_map/sugarcraft_candy-metrics.md` line 481  
**Implementation ideas:**
- Add `$onError` callable to StatsdBackend constructor
- Invoke error callback instead of/suppress silent suppression
- Log to a designated error logger
**Estimated complexity:** Low  
**Expected impact:** Low—development convenience

### 10. Custom Tag Key Canonicalization
**Title:** Allow pluggable tag key normalization  
**Description:** Tags are always sorted alphabetically; no way to customize.  
**Why it matters:** Some backends may need different normalization schemes.  
**Source repo:** `docs/repo_map/sugarcraft_candy-metrics.md` lines 297-332  
**Implementation ideas:**
- Add `$keyStrategy` callback to Registry constructor
- Default to `ksort` + implode
- Allow custom implementations
**Estimated complexity:** Low  
**Expected impact:** Low—edge case for unusual backend requirements

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### Histogram Buckets: Cumulative vs Native

**candy-metrics approach:** Cumulative classic buckets (0.005 → 0.01 → ... → +Inf) matching Prometheus client library v2 semantics. A sample at 0.042 increments all buckets ≤ 0.042.

**External approach (Go prometheus/client_golang):** Also cumulative, but allows custom boundaries and provides native histogram with `Observe()` API.

**Why external is better:** Cumulative buckets are correct for Prometheus `histogram_quantile()` queries. The current implementation is correct.

**Tradeoffs:** Hardcoded buckets prevent use-case-specific ranges. Some environments need finer p99.99 buckets or industry-specific ranges (e.g., SLI/SLO boundaries).

**Applicability:** Configure bucket boundaries to match SLO/SLI requirements.

---

# Architecture Improvements

## Explicit Backend Lifecycle

Add lifecycle methods to Backend interface:

```php
interface Backend {
    public function counter(string $name, float $value, array $tags = []): void;
    // ... other methods ...

    public function start(): void;  // NEW: initialize connections, start background processes
    public function stop(float $timeout = 5.0): void;  // NEW: graceful shutdown with timeout
    public function flush(): void;  // NEW: force flush pending data
}
```

**Rationale:** Go's promwish evolved from implicit lifecycle (goroutine in constructor) to explicit `Server` struct. PHP should follow the same pattern to avoid implicit initialization issues in production.

**Source:** `docs/repo_map/pr_charmbracelet_promwish.md` lines 329-341

---

## Backend Failure Isolation

MultiBackend should isolate failures:

```php
final class MultiBackend implements Backend {
    public function __construct(
        /** @var list<Backend> */ 
        private readonly array $backends,
        private readonly bool $propagateFailures = false,
    ) {}
}
```

**Rationale:** StatsD backend unreachable should not crash the SSH session or metrics call site.

**Source:** `docs/repo_map/sugarcraft_candy-metrics.md` line 483

---

# API / Developer Experience Improvements

## 1. Typed Time Helper Return

Current `time()` returns `callable(): float`. Consider returning a dedicated `Stopwatch` interface:

```php
interface Stopwatch {
    public function __invoke(): float;  // record elapsed, return value
    public function elapsed(): float;  // get elapsed without recording
}
```

**Rationale:** More discoverable API; enables IDE autocompletion.

---

## 2. Metric Registration with Fluent Builder

```php
$reg->register(
    Descriptor::histogram('http.request.duration')
        ->help('HTTP request duration in seconds')
        ->labelKeys(['route', 'method', 'status'])
        ->buckets([0.01, 0.05, 0.1, 0.5, 1.0, 5.0])  // NEW: custom buckets
);
```

**Rationale:** More readable registration; aligns with builder pattern elsewhere in ecosystem.

---

## 3. Backward Compatibility Note

Current `Descriptor` accepts 4 constructor args without names. Future improvements should use named args or builder pattern to avoid breaking change.

---

# Documentation / Cookbook Opportunities

## 1. Expanded Examples

promwish's `_examples/` folder is the primary documentation (`docs/repo_map/pr_charmbracelet_promwish.md` lines 382-386).

**Needed examples:**
- `examples/custom-metrics.php` - Emitting business metrics alongside SessionMetrics
- `examples/multi-backend-fanout.php` - StatsD + JSON audit trail
- `examples/prometheus-setup.php` - Complete Prometheus stack with node_exporter
- `examples/statsd-aggregation.php` - Multi-instance deployment with StatsD aggregation
- `examples/error-handling.php` - Handling backend unavailability
- `examples/http-backend.php` - Self-contained HTTP scrape endpoint

---

## 2. Cookbook: Horizontal Scaling with StatsD

Document multi-instance deployment pattern:

```php
// Each instance sends to centralized StatsD
$reg = new Registry(new StatsdBackend('statsd-aggregator.local', 8125));

// StatsD agent forwards to Prometheus
// or use DogStatsD with Datadog agent
```

---

## 3. Cookbook: Custom Session Labels

```php
Server::new()
    ->use(new SessionMetrics($registry, function(Session $s) {
        return [
            'client_ip' => $s->clientIp,
            'geo' => geoip_lookup($s->clientIp),
            'build_version' => $s->env['BUILD_VERSION'] ?? 'unknown',
        ];
    }))
    ->serve();
```

---

# UX / TUI Improvements

Not applicable - candy-metrics is a backend/telemetry library, not a TUI component.

---

# Testing / Reliability Improvements

## 1. Benchmark Tests

Add microbenchmarks for hot paths:

```php
public function testCounterThroughput(): void
{
    $backend = new InMemoryBackend();
    $reg = new Registry($backend);
    
    $start = microtime(true);
    for ($i = 0; $i < 100_000; $i++) {
        $reg->counter('test', 1.0, ['k' => 'v']);
    }
    $elapsed = microtime(true) - $start;
    
    $this->assertLessThan(1.0, $elapsed, '100k counter emissions should complete in < 1s');
}
```

---

## 2. Fault Injection Tests

Test MultiBackend isolation:

```php
public function testMultiBackendIsolatesFailingBackend(): void
{
    $failing = new class implements Backend {
        public function counter(string $name, float $value, array $tags = []): void
        {
            throw new \RuntimeException('Backend failure');
        }
        // ... other methods ...
    };
    
    $multi = new MultiBackend([$failing, new InMemoryBackend()]);
    $reg = new Registry($multi);
    
    // Should not throw
    $reg->counter('test', 1.0);
    $this->assertSame(1.0, $reg->snapshot()->counters['test'] ?? 0.0);
}
```

---

## 3. Cardinality Stress Tests

```php
public function testCardinalityEvictionUnderPressure(): void
{
    $reg = new Registry(new InMemoryBackend(), [], 100);  // Low limit for testing
    
    // Generate 150 unique label combinations
    for ($i = 0; $i < 150; $i++) {
        $reg->counter('test', 1.0, ['request_id' => "req-{$i}"]);
    }
    
    // Should evict oldest entries
    $this->assertLessThanOrEqual(100, $reg->cardinality('test'));
}
```

---

# Ecosystem / Integration Opportunities

## 1. OTEL SDK Integration

Provide adapter for OTEL PHP SDK:

```php
// Enable OTEL SDK to use candy-metrics as backend
$otelSdk->registerMetrics($registry);
```

**Rationale:** OTEL PHP SDK is emerging; integration enables hybrid ecosystems.

---

## 2. ReactPHP Event Loop Integration

Add async emission mode:

```php
// Non-blocking emit for high-throughput scenarios
yield $registry->async()->counter('events', 1.0, $tags);
```

**Rationale:** ReactPHP apps benefit from non-blocking metric emission.

---

## 3. Laravel Integration Package

```php
// sugarcraft/laravel-metrics
// Service provider that wires candy-metrics into Laravel
$this->app->singleton(Registry::class, function ($app) {
    return new Registry(
        new PrometheusFileBackend(storage_path('prometheus/metrics.prom'))
    );
});
```

**Rationale:** Laravel is the dominant PHP framework; first-class integration drives adoption.

---

# Notable PRs / Issues / Discussions

## promwish Issue #38: New, Start, Shutdown (merged)

**Summary:** Introduced `Server` struct with explicit lifecycle methods.  
**Key insight:** The Go maintainer evolved from implicit lifecycle (goroutine in constructor) to explicit `NewServer()`/`ListenAndServe()`/`Shutdown()`. This is a clear pattern for backend lifecycle management.  
**Lesson for SugarCraft:** Add explicit `start()`/`stop()` to Backend interface.

**Source:** `docs/repo_map/pr_charmbracelet_promwish.md` lines 68-79

---

## promwish Issue #36: Context/Waitgroup Options (closed, led to #38)

**Summary:** User requested context, waitgroup, and signal options directly on middleware.  
**Maintainer response:** Created proper `Server` struct rather than piling on options.  
**Lesson for SugarCraft:** Prefer composition over configuration; avoid adding options to middleware.

**Source:** `docs/repo_map/pr_charmbracelet_promwish.md` lines 80-88

---

## promwish Issue #14: Command Label (merged)

**Summary:** Added `CommandFn` interface for pluggable label extraction.  
**Key insight:** Default behavior (first word of command) is reasonable, but made it pluggable for flexibility.  
**SugarCraft already does this better:** `SessionMetrics` accepts `extraTags` callable returning multiple tags.

**Source:** `docs/repo_map/pr_charmbracelet_promwish.md` lines 108-116

---

## promwish Issue #39: MiddlewareWithServer (not merged)

**Summary:** Proposal to let middleware hold reference to HTTP server.  
**Maintainer response:** Redirected to custom registry pattern; middleware shouldn't know about servers.  
**Lesson for SugarCraft:** Separation of concerns—middleware only writes to Registry; how metrics get exposed is application concern.

**Source:** `docs/repo_map/pr_charmbracelet_promwish.md` lines 90-107

---

# Recommended Roadmap

## Immediate Wins (0-2 weeks)

1. **Add Backend lifecycle methods** - `start()`/`stop()` interface additions with default empty implementations; update Registry to call `start()` on initialization
2. **StatsD error callback** - Add `$onError` callable to StatsdBackend; log failures instead of silent suppression
3. **PrometheusFileBackend bucket configuration** - Add `$buckets` constructor parameter for custom boundaries
4. **Expand examples** - Add 3-4 examples covering custom metrics, multi-backend, error handling

## Medium-Term Improvements (1-3 months)

5. **HTTP Backend** - Add ReactPHP-based HTTP metrics server with `/metrics` endpoint for Prometheus scraping
6. **MultiBackend failure isolation** - Wrap each backend emit in try/catch; add `$propagateFailures` option
7. **OTelBackend** - Add OTLP export backend using HTTP/Protobuf
8. **PushGatewayBackend** - Add Prometheus pushgateway support for ephemeral jobs

## Major Architectural Upgrades (3-6 months)

9. **Exemplar support** - Store trace context in histogram samples; emit exemplars in Prometheus format
10. **Summary type implementation** - Add client-side quantile pre-computation using t-digest
11. **Laravel integration package** - `sugarcraft/laravel-metrics` package

## Experimental Ideas

12. **Continuous aggregation** - Sliding window histograms for real-time percentile computation
13. **Adaptive cardinality limits** - Auto-tune limits based on memory pressure
14. **Distributed metrics** - Built-in support for multi-instance aggregation via consistent hashing

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| Backend lifecycle (start/stop) | High | Low | Low | **P0 - Immediate** |
| Custom histogram buckets | Medium | Low | Low | **P0 - Immediate** |
| StatsD error callback | Low | Low | Low | **P1 - Week 1** |
| Multi-backend failure isolation | Medium | Low | Medium | **P1 - Week 1** |
| Expand examples | Medium | Low | Low | **P1 - Week 1-2** |
| HTTP Backend | High | Medium | Medium | **P2 - Month 1** |
| OTEL Backend | Medium | High | Medium | **P3 - Month 2** |
| PushGateway support | Medium | Medium | Low | **P3 - Month 2** |
| Exemplar support | Medium | Medium | Low | **P4 - Month 3** |
| Summary type | Low | Medium | Low | **P5 - Backlog** |
| Laravel integration | Medium | Medium | Low | **P4 - Month 3** |
| Continuous aggregation | High | High | High | **P6 - Future** |

---

# Final Strategic Assessment

candy-metrics is a well-architected telemetry library that achieves its primary goal: providing pluggable metric instruments with production-ready backends for the SugarCraft ecosystem. The library significantly exceeds its primary upstream (charmbracelet/promwish) in key areas: multiple backend support, cardinality management, proper histogram semantics, and richer session metadata.

**Core strengths to preserve:**
1. Multi-backend flexibility (critical differentiator from Go upstream)
2. Cardinality management (addresses real production concern Go lacks)
3. OpenTelemetry alignment (future-proofs for ecosystem trends)
4. Clean Registry/Backend separation (enables composability)

**Areas requiring strategic investment:**

The most significant gap is the **lack of HTTP metrics server**. The Go promwish exposes `/metrics` for Prometheus scraping—candy-metrics has no equivalent, requiring external components (node_exporter, StatsD server) for metric collection. This is the #1 gap for self-contained deployments and should be prioritized. The implementation should use ReactPHP's HTTP server and follow the lifecycle patterns established in the Go library (explicit start/stop, not implicit).

The second strategic gap is **OpenTelemetry export**. OTEL is becoming the industry standard for observability, and lacking an OTLP backend limits vendor portability. The complexity is higher (requires protobuf encoding), but the strategic value justifies the investment.

**Technical debt to address:**

The **hardcoded histogram buckets** and **MultiBackend failure isolation** are medium-priority technical debt items that improve production robustness. Neither is complex, but both prevent use-case-specific optimizations.

**Competitive positioning:**

candy-metrics competes well within the PHP ecosystem for observability primitives. Against pure Prometheus clients (like `promphp/prometheus_client_php`), it offers better abstraction (multiple backends) and cardinality protection. Against full observability platforms (Datadog, New Relic), it's lighter weight and self-contained.

The library should position itself as: **"The PHP telemetry library with Prometheus semantics and multi-backend flexibility for modern PHP applications."**

**Key risks:**

1. **Maintenance bandwidth** - The library is feature-complete for v1. Feature additions should be evaluated against maintenance cost.
2. **Ecosystem fragmentation** - If OTEL PHP SDK matures, integration becomes critical.
3. **Performance at scale** - Cardinality management is memory-bounded; high-cardinality environments may need streaming approaches.

**Verdict:** Ready for production use in standard deployment scenarios. Should prioritize HTTP backend for self-contained Prometheus deployments, then invest in OTEL integration for long-term ecosystem alignment.
