# Second-Stage Ecosystem Intelligence Report: charmbracelet/promwish

## 1. Repository Overview

**Repository:** charmbracelet/promwish
**Language:** Go
**Stars:** 48 (very niche, limited adoption)
**Forks:** 9
**Open Issues:** 2 (both Dependabot dependency updates)
**License:** MIT
**Description:** Prometheus middleware for Wish — exposes SSH session metrics (connect/disconnect/duration) to Prometheus
**Topics:** hacktoberfest, middleware, prometheus
**Status:** Stable but low-activity; most commits are dependency bumps

**Key Insight:** The repository is effectively feature-complete. There are no open user-facing issues. All activity is dependency maintenance (Dependabot) and occasional CI config syncing. This is a small, focused utility library that has found its niche.

---

## 2. Existing SugarCraft Mapping

| promwish | SugarCraft (candy-metrics) | Notes |
|----------|---------------------------|-------|
| `Middleware()`, `MiddlewareWithCommand()` | `SessionMetrics` | Both wrap SSH session lifecycle |
| `wish_sessions_created_total` | `wish.session.connect` counter | Direct mapping |
| `wish_sessions_finished_total` | `wish.session.duration` (stop fn) | Implicit via duration histogram |
| `wish_sessions_duration_seconds` counter | `wish.session.duration` histogram | Go uses counter (no percentiles); PHP uses proper histogram with buckets |
| `Listen()` + embedded HTTP server | External metrics collection | PHP uses file-based (Prometheus pushgateway style) or StatsD |
| `prometheus.Labels{"app": app}` | `Registry::withTags()` | App-level const label propagation |
| `CommandFn` interface | `extraTags` constructor param | Custom label extraction for command/user/term |
| `NewServer()` + `ListenAndServe()` + `Shutdown()` | `Backend` abstraction | Full lifecycle management |
| `promauto.With(registry)` | N/A (PHP uses custom registry) | Go auto-registers metrics; PHP manual registration |

**Key Architectural Divergence:**
- **Go:** Uses `promauto` for auto-registration, cumulative counters for duration
- **PHP:** Uses proper histogram with configurable buckets, cardinality management (max 10,000 label combos)

---

## 3. Previously Identified Gaps

From `repo_map/charmbracelet_promwish.md`:
- Limited metrics (no CPU, memory, custom app metrics)
- Duration is cumulative counter, not histogram (no percentile visibility)
- Single Prometheus format only
- No metric unregistration
- Hardcoded `/metrics` endpoint
- Only `command` label (missing user, term, remote IP)

**Gap Coverage Status:**
- PHP port addresses histogram buckets ✅
- PHP port addresses cardinality management ✅
- PHP port adds `wish.session.error` counter (exception tracking) ✅
- PHP port supports multiple backends (StatsD, JSON, PrometheusFile) ✅
- PHP port still lacks user/term labels in Go's `promwish` (though `SessionMetrics` has `extraTags` for extensibility)

---

## 4. High-Signal Open Issues

**None.** The 2 open issues are both Dependabot PRs:
- #81: `chore(deps): bump dependabot/fetch-metadata from 2 to 3`
- #80: `chore(deps): bump github.com/charmbracelet/log from 0.4.2 to 1.0.0`

These are automated dependency updates with no user engagement.

---

## 5. Important Closed Issues

### Issue #38: `feat: New, Start, Shutdown` (6 comments, merged)
**Summary:** Introduced the `Server` struct with `NewServer()`, `ListenAndServe()`, and `Shutdown()` methods for better lifecycle control.

**Key Discussion:**
- User `dezren39` needed context propagation, signal handling options, and waitgroups for graceful shutdown
- Maintainer `caarlos0` suggested making a proper `Server` struct instead of adding signal/waitgroup options
- Final solution: `Server` struct that users can control directly

**Relevance to SugarCraft:** The PHP implementation needs proper lifecycle management for backend servers. The Go approach (separate Server struct with explicit Listen/Start/Shutdown) is cleaner than the current PHP approach which relies on destructors or manual cleanup.

### Issue #36: `add context & waitgroup` (6 comments, closed—not merged directly)
**Summary:** User proposed adding context, waitgroup, and signal options directly to the middleware.

**Key Discussion:**
- User needed: external cancellation via context, ability to disable/modify default signals, waitgroup for graceful shutdown coordination
- Maintainer response: preferred to add a proper `Server` struct rather than pile on options
- This led to issue #38 which implemented the cleaner solution

**Relevance to SugarCraft:** The PHP `SessionMetrics` middleware has no lifecycle concerns (it just records metrics). However, the `Backend` implementations that consume metrics may need proper lifecycle management (start/stop).

### Issue #39: `allow server input to middleware` (1 comment, closed—not merged)
**Summary:** Proposed allowing the middleware to reference an external server for shared middleware across multiple server instances.

**Discussion:**
```go
promSrv := promwish.NewServer("localhost:8080")
s, err := wish.NewServer(
  wish.WithMiddleware(
    promwish.MiddlewareWithServer(promSrv, "my-app")
  )
)
promSrv.Shutdown(context.Background())
```

**Outcome:** Not merged — the custom-registry example demonstrates the intended pattern.

**Relevance to SugarCraft:** PHP's approach (Registry injection) handles this more elegantly. A middleware doesn't need to know about the server — it just writes metrics to a backend.

### Issue #14: `feat: inc metrics for each command ran` (2 comments, merged)
**Summary:** Added `command` label to all metrics with a `CommandFn` for customization.

**Key Design Decision:**
- Default: first word of `session.Command()`
- Made it pluggable so users can extract different labels (user, term type, etc.)

**Relevance to SugarCraft:** The PHP `SessionMetrics` uses `extraTags` callback with user/term hardcoded. The Go approach (pluggable `CommandFn`) is more flexible — SugarCraft could allow callers to pass a custom tag extractor.

---

## 6. Recurring Pain Points

**None identified.** The repository is too small and low-activity for recurring patterns to emerge. Pain points that do exist:

1. **Signal handling inflexibility** (Issue #36) — Users couldn't disable or customize default signal handling. Fixed via Server struct.
2. **No access to the metrics server from middleware** (Issue #39) — Users wanted to share middleware across multiple server instances. Fixed via custom registry pattern.

---

## 7. Frequently Requested Features

Based on issue history, no features have been repeatedly requested. The library has settled into dependency-maintenance mode. Historical feature requests:

1. **Command labeling** (Issue #14) — Added `command` label via `CommandFn`
2. **Graceful shutdown** (Issues #1, #36, #38) — Evolved from basic signal handling → context/waitgroup → proper Server struct
3. **Custom registry support** (implicit in all issues) — The `MiddlewareRegistry` function was added early on

**Signal to SugarCraft:** The Go library doesn't need many features — users primarily wanted the metrics to work and proper lifecycle control. The PHP port should focus on:
1. Proper histogram buckets (done)
2. Cardinality management (done)
3. Multiple backend support (done)
4. Clean lifecycle for backend servers (needs attention)

---

## 8. Important PRs

### PR #38 (merged: 2024-03-01)
**Title:** `feat: New, Start, Shutdown`
**Impact:** Introduced `Server` struct, enabling proper lifecycle management outside the middleware

**Code change:** Added `Server` struct with `NewServer()`, `ListenAndServe()`, `Shutdown()` methods

### PR #14 (merged: 2022-12-19)
**Title:** `feat: inc metrics for each command ran`
**Impact:** Added `command` label and `CommandFn` interface

### PR #1 (merged: 2021-12-06)
**Title:** `feat: more customization options, graceful shutdown`
**Impact:** Initial version with `Middleware()` convenience function and `Listen()` with signal handling

### PR #2 (merged: 2021-12-08)
**Title:** `add examples`
**Impact:** Added `_examples` folder, enabling users to understand usage

---

## 9. Architectural Changes

### v1 → v2 Migration
There is a `feat!: v2` PR (#64) but it was closed without merge. The repository appears to have stabilized at v1.x semantics.

### Evolution of Middleware Pattern:
```
v1:     Middleware(address, app) → wish.Middleware
        (implicitly starts HTTP server in goroutine)

v1.x:   MiddlewareRegistry(registry, labels, fn) → wish.Middleware
        (allows custom registry, separation of concerns)

v2-ish: Server struct with NewServer(), ListenAndServe(), Shutdown()
        (explicit lifecycle, no implicit goroutines)
```

**Lesson:** The Go library started with convenience (implicit goroutine for HTTP server) but had to evolve toward explicitness (Server struct). SugarCraft's PHP implementation should avoid implicit lifecycle magic.

---

## 10. Performance Discussions

**None found.** The library is a thin wrapper around Prometheus client_golang — no performance discussions because there's nothing to optimize. The middleware adds minimal overhead (three counter increments and a time.Since() call per session).

**Direct Risk to SugarCraft:** Low. The middleware pattern is simple and performant. The PHP implementation's time measurement (`$this->registry->time()`) returns a callable stopwatch — this is a reasonable approach but should be verified in high-throughput scenarios.

---

## 11. Extensibility Discussions

### Issue #39 (not merged): MiddlewareWithServer
**Attempted pattern:**
```go
promwish.MiddlewareWithServer(promSrv, "my-app")
```
This would let middleware hold a reference to the HTTP server.

**Why rejected:** The custom-registry example shows the idiomatic pattern — create the registry first, pass to both the server and middleware. The middleware shouldn't hold server references.

**SugarCraft implication:** PHP's Registry pattern is correct. The middleware should only know about the Registry (where to write metrics), not about how those metrics are eventually exposed (which backend).

---

## 12. API/UX Complaints

**None found.** The API is simple enough that there are no complaints. The only friction points were:
1. Signal handling can't be disabled (fixed via Server struct)
2. No access to the server from middleware (addressed via custom registry pattern)

**SugarCraft:** Current PHP API is clean. `SessionMetrics` constructor takes a `Registry` and optional `extraTags` callback. This is appropriately minimal.

---

## 13. Migration Problems

**None reported.** No one has asked about migrating from v1 to v2 because v2 was never released. The library has maintained API stability.

**However:** The `feat!: v2` PR #64 with breaking changes was closed. The maintainers chose stability over breaking changes.

**SugarCraft implication:** The PHP port should aim for API stability. Breaking changes after release will generate friction.

---

## 14. Clever Fixes & Workarounds

### The Custom Registry Pattern
Users who needed multiple server instances sharing metrics discovered they could:
1. Create a single `prometheus.Registry`
2. Pass it to both `promwish.MiddlewareRegistry()` and `promwish.NewServer()`
3. Manually manage the metrics server lifecycle

**Example from upstream:**
```go
registry := prometheus.NewRegistry()
promwish.MiddlewareRegistry(registry, labels, fn)  // for middleware
promwish.NewServer("localhost:9222", promhttp.HandlerFor(registry, ...))  // for server
```

**SugarCraft parallel:** The `MultiBackend` allows fanning out to multiple backends, which is more flexible than the Go pattern.

### Graceful Shutdown via Context Timeout
The `Listen()` function uses a 5-second timeout for graceful shutdown:
```go
ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
defer func() { cancel() }()
```

**SugarCraft implication:** Consider making the shutdown timeout configurable rather than hardcoded.

---

## 15. Community Workarounds

**None documented.** The repository has too few users to generate community workarounds. The examples serve as the de facto documentation.

---

## 16. Maintainer Guidance Patterns

**Pattern 1: Separate Server from Middleware**
Maintainer consistently pushed users toward separating metric collection (Server) from metric emission (Middleware). When users asked for middleware to hold server references, maintainer redirected to custom registry pattern.

**Pattern 2: Prefer Composition over Configuration**
When Issue #36 proposed adding context/waitgroup/signals options, maintainer instead created a `Server` struct that could be composed with user's own context management.

**Pattern 3: Example-Driven Documentation**
The `_examples/` folder is the primary documentation. When users asked about custom metrics (#30), maintainer simply added an example.

**Pattern 4: Close PRs Rather Than Merge Breaking Changes**
The `feat!: v2` PR was closed without merging. Maintainer prefers evolutionary API additions over breaking changes.

**SugarCraft Guidance:**
1. Keep Server/backend lifecycle separate from middleware
2. Composition over configuration for options
3. Use examples as primary documentation
4. Avoid breaking changes

---

## 17. Rejected Ideas Worth Revisiting

### Issue #39: MiddlewareWithServer
**Rejected but conceptually sound.** The idea of middleware being able to access the underlying metrics server has merit for advanced use cases (e.g., flushing metrics on demand, accessing server state).

**SugarCraft opportunity:** The PHP `Registry` could expose a `flush()` method for backends that need explicit flushing, and a `shutdown()` method for graceful backend termination.

### Issue #36: Context/Waitgroup Options
**Rejected in favor of Server struct.** However, the underlying need (external cancellation, coordination) is legitimate.

**SugarCraft opportunity:** The PHP `Backend` interface could include `start()` and `stop()` methods, with implementations managing their own lifecycle.

---

## 18. Problems Likely Relevant To SugarCraft

### 1. Cardinality Explosion
**Go has no protection.** The `CommandFn` can return any string, leading to unbounded label cardinality if user-controlled commands are used as labels.

**SugarCraft already addresses this:** `Registry` has cardinality management (max 10,000 label combinations per metric). This is a significant improvement over Go.

### 2. Metric Registration Conflicts
**Go issue.** `promauto` auto-registers metrics. If the same metric is created twice (e.g., middleware applied twice), it panics.

**SugarCraft mitigation:** PHP's Registry doesn't auto-register; it stores in memory. However, if the same descriptor is registered twice, appropriate error handling is needed.

### 3. Histogram vs Counter for Duration
**Go uses counter.** `wish_sessions_duration_seconds` is a CounterVec (cumulative seconds) rather than a HistogramVec. This means:
- Cannot see percentiles (p50, p99)
- Cannot see distribution of session durations
- Only total volume

**SugarCraft does it right:** Uses proper histogram with configurable buckets. This is a clear win for the PHP port.

### 4. Shutdown Ordering
**Go issue.** When the SSH server and metrics server both need shutdown, there's no explicit ordering. The examples show manual coordination with channels.

**SugarCraft:** The `Registry` and `Backend` don't have explicit start/stop. For production use with file-based backends or StatsD, proper shutdown ordering matters.

---

## 19. Features Sugarcraft Should Consider

### 1. Explicit Backend Lifecycle
**Rationale:** Go's evolution showed users need control over server lifecycle (start/stop/shutdown). PHP backends (especially `PrometheusFileBackend` and `StatsdBackend`) need similar control.

**Proposed API:**
```php
$backend->start();  // Initialize connection, start background processes
$backend->stop();  // Graceful shutdown with timeout
```

### 2. Shutdown Timeout Configuration
**Rationale:** Go hardcodes 5-second timeout. Users with slow backends need configurability.

**Proposed:** Add optional timeout parameter to `Registry::shutdown()` or individual `Backend::stop()`.

### 3. Middleware Access to Registry for Custom Metrics
**Rationale:** Users want to emit their own custom metrics alongside session metrics (see example in `_examples/simple/main.go` which defines `keyTypeCounter` separately).

**Current PHP state:** `SessionMetrics` only emits predefined metrics. Users can add their own via `$registry->counter()` but the middleware doesn't help with this.

**Opportunity:** Document how to use `SessionMetrics` alongside custom metric emission via the same Registry.

### 4. CommandFn-equivalent for Session Labeling
**Rationale:** Go's `CommandFn` is pluggable. PHP's `extraTags` is hardcoded to user/term.

**Current PHP:**
```php
new SessionMetrics($registry);  // Only user + term
new SessionMetrics($registry, function(Session $s) {
    return ['command' => $s->command[0] ?? 'unknown'];
});
```

**This is already better than Go** because it's more generic. No change needed.

---

## 20. Architectural Lessons

### Lesson 1: Convenience Implicitness Creates Debt
The Go library started with `Middleware()` implicitly starting an HTTP server in a goroutine. This caused issues (#36) when users needed explicit lifecycle control. The `Server` struct was the fix.

**SugarCraft:** Avoid implicit lifecycle (e.g., don't start background processes in constructors). Use explicit `start()`/`stop()` patterns.

### Lesson 2: Middleware Should Not Know About Servers
The rejected idea in #39 (MiddlewareWithServer) tried to let middleware hold a server reference. This violates separation of concerns. The Go maintainer correctly redirected to the registry pattern.

**SugarCraft:** `SessionMetrics` middleware should only know about `Registry`. How metrics get exposed is the concern of the application, not the middleware.

### Lesson 3: Simple Libraries Stay Simple by Saying No
With only ~200 stars and no feature requests, promwish has found its niche. The maintainer closed the v2 PR that would have introduced breaking changes.

**SugarCraft:** Be wary of feature creep. `candy-metrics` is already more featureful than `promwish` (multiple backends, cardinality management). Don't add features just because they're possible.

### Lesson 4: Examples Are Documentation
The `_examples/` folder is the real documentation. Every non-obvious use case (custom registry, custom metrics) is demonstrated there.

**SugarCraft:** Invest in examples. The `examples/wish-with-metrics.php` is a start but more examples would help.

---

## 21. Defensive Design Lessons

### Lesson 1: Cardinality Protection is Essential
Go's promwish has no protection against unbounded label cardinality. A malicious or careless `CommandFn` can create millions of time series.

**SugarCraft already has this:** The `Registry` enforces max 10,000 label combinations per metric.

### Lesson 2: Metric Registration Must Be Idempotent
Go's `promauto` panics on duplicate registration. This is a footgun.

**SugarCraft:** Consider using a tryRegister pattern or allow overwriting in development mode. In production, duplicate registration should fail loudly.

### Lesson 3: Duration Should Be Histogram, Not Counter
Using a counter for duration means you can only see totals, not distributions. Percentiles are usually more actionable.

**SugarCraft already does this right:** Proper histogram with configurable buckets.

### Lesson 4: Separate Metrics Emission from Metrics Exposure
promwish mixes these (middleware also starts HTTP server). The `MiddlewareRegistry` function is the correct separation.

**SugarCraft:** `SessionMetrics` only emits. `Backend` implementations handle exposure. Keep this separation clean.

---

## 22. Ecosystem Trends

### Trend 1: Toward Explicit Lifecycle
The promwish evolution shows a trend from implicit (goroutine, automatic signal handling) to explicit (Server struct, manual Listen/Stop).

**SugarCraft implication:** PHP frameworks have implicit request lifecycle. sugar-wish should use explicit lifecycle for SSH servers and metrics backends.

### Trend 2: Pluggable Label Extraction
The `CommandFn` interface was a good design decision — it allows users to label by command, user, term type, or any combination without changing the library.

**SugarCraft's `extraTags` is already this pattern,** but more flexible (callable returning multiple tags). No change needed.

### Trend 3: Prometheus-Only is Limiting
promwish is strictly Prometheus-focused. Other monitoring backends (StatsD, Datadog, etc.) are not supported.

**SugarCraft addresses this** with multiple backend support. This is a genuine advantage over the Go library.

---

## 23. Strategic Opportunities

### Opportunity 1: Backpressure and Rate Limiting for Metric Emission
The Go library emits metrics synchronously. If the metrics backend is slow, it blocks the SSH session.

**SugarCraft opportunity:** Add async emission mode (already partially implemented via `AsyncCounter`/`AsyncGauge`). Document when to use sync vs async.

### Opportunity 2: Structured Log Integration
promwish recently adopted `charmbracelet/log` for structured logging (#31, #32).

**SugarCraft opportunity:** Ensure the PHP metrics library doesn't silently swallow errors. Log when metrics backend is unavailable, when cardinality limits are hit, etc.

### Opportunity 3: Horizontal Scaling Support
The Go library stores metrics in a single registry. In horizontally scaled deployments, each instance has its own metrics.

**SugarCraft opportunity:** StatsD backend is already a step toward aggregation. Document multi-instance deployment patterns.

---

## 24. Cross-Ecosystem Pattern Matches

### promwish ↔ SugarCraft SessionMetrics
- **Similarity:** Both track SSH session lifecycle (connect/disconnect/duration)
- **Divergence:** Go uses counters for duration (cumulative), PHP uses histogram (buckets)
- **Winner:** PHP for observability, Go for simplicity

### promwish Server struct ↔ SugarCraft Backend interface
- **Similarity:** Both provide lifecycle management for metrics exposure
- **Divergence:** Go uses concrete struct, PHP uses interface
- **Winner:** PHP (interface allows swapping backends)

### promwish CommandFn ↔ SugarCraft extraTags
- **Similarity:** Both allow custom label extraction
- **Divergence:** Go uses single-function return (string), PHP uses callable return (array)
- **Winner:** PHP (more flexible, can return multiple tags at once)

---

## 25. High ROI Recommendations

### Priority 1: Document Backend Lifecycle (Low effort, high value)
**Action:** Add `start()`/`stop()` methods to `Backend` interface with default empty implementations. Update `Registry` to call `start()` on all backends when the registry starts.

**Rationale:** Prevents implicit initialization issues. Follows the Go evolutionary pattern.

### Priority 2: Add Graceful Shutdown Timeout to Registry (Low effort, medium value)
**Action:** Add `$shutdownTimeout` parameter to `Registry::__destruct()` or add explicit `flush()` and `stop()` methods with timeout.

**Rationale:** Go hardcoded 5 seconds. Users with slow backends need configurability.

### Priority 3: Expand Examples (Medium effort, high value)
**Action:** Add examples showing:
- Custom metrics alongside SessionMetrics
- Multi-backend fanout (StatsD + PrometheusFile)
- Horizontal scaling with StatsD aggregation
- Error handling when metrics backend is unavailable

**Rationale:** promwish examples are the primary documentation. SugarCraft should follow this pattern.

### Priority 4: Add Instrumented Middleware Variants (Medium effort, medium value)
**Action:** Consider adding middleware variants that emit additional metrics:
- `SessionMetricsWithErrors` — captures exception types
- `SessionMetricsWithNetwork` — adds client IP, bytes sent/received (if available)

**Rationale:** The Go library's only label is `command`. SugarCraft's `extraTags` can already do this, but convenience wrappers would help.

### Priority 5: Consider OpenTelemetry Export (High effort, medium value)
**Action:** Add `OtelBackend` that converts internal metrics to OTLP for export to OTEL-compatible backends.

**Rationale:** Prometheus-only is limiting. OpenTelemetry is the emerging standard. This would differentiate SugarCraft from Go promwish.

---

## Conclusion

promwish is a small, stable, feature-complete library that has found its niche as a simple Prometheus middleware for SSH servers. The ecosystem has revealed:

1. **No major pain points** — The library is too simple to have complex problems
2. **Clear evolutionary path** — From implicit to explicit lifecycle management
3. **SugarCraft has addressed key limitations** — Histogram buckets, cardinality management, multiple backends
4. **Focus areas for SugarCraft** — Backend lifecycle, graceful shutdown, expanded examples

The PHP port (`candy-metrics`) is architecturally superior in several ways (histogram, cardinality, backends). The main opportunity is cleaning up lifecycle management and expanding documentation/examples to match the Go library's community sophistication.
