# charmbracelet/promwish

## Metadata
- **URL:** https://github.com/charmbracelet/promwish
- **Language:** Go
- **Stars:** Unknown (GitHub API unavailable in environment)
- **License:** MIT (Copyright 2019-2023 Charmbracelet, Inc)
- **Description:** Package promwish provides a simple wish middleware exposing some Prometheus metrics.

## Feature List
- **SSH Session Metrics Middleware** — Wraps SSH session lifecycle (connect/disconnect) with Prometheus counters
- **Duration Tracking** — Measures session duration in seconds with a histogram-style counter
- **Command Label Extraction** — Flexible `CommandFn` to extract command names for per-command metric labeling
- **HTTP Metrics Server** — Built-in HTTP server to expose `/metrics` endpoint for Prometheus scraping
- **Custom Registry Support** — Can use non-default Prometheus registerer for advanced use cases
- **Graceful Shutdown** — Signal handling for clean metrics server shutdown
- **Middleware Composition** — Works as a standard `wish.Middleware` that composes with other middleware

## Key Classes and Methods

### Main Package (`promwish`)

- **`Middleware(address, app string) wish.Middleware`** — Convenience factory that starts HTTP server on address and returns a wish middleware. Creates metrics with an "app" const label.

- **`MiddlewareWithCommand(address, app string, fn CommandFn) wish.Middleware`** — Same as Middleware but accepts custom CommandFn for extracting command labels.

- **`MiddlewareRegistry(registry prometheus.Registerer, constLabels prometheus.Labels, fn CommandFn) wish.Middleware`** — Core middleware factory. Sets up three CounterVec metrics and wraps SSH handler. **This is the most important entry point for understanding the pattern.**

- **`Listen(address string)`** — Creates and starts an HTTP metrics server on the given address, serving `/metrics` from default registerer. Handles SIGINT/SIGTERM for graceful shutdown.

- **`NewServer(address string, promHandler http.Handler) *Server`** — Creates a new metrics HTTP server with custom handler.

- **`Server.ListenAndServe() error`** — Starts the HTTP server.

- **`Server.Shutdown(ctx context.Context) error`** — Gracefully shuts down the server.

- **`DefaultCommandFn(s ssh.Session) string`** — Returns the first word of `s.Command()` or empty string.

- **`CommandFn` interface** — `func(s ssh.Session) string` — used to extract the `command` label value from sessions.

## Metrics Exposed

| Metric Name | Type | Labels | Description |
|-------------|------|--------|-------------|
| `wish_sessions_created_total` | CounterVec | `command` | Total number of sessions created |
| `wish_sessions_finished_total` | CounterVec | `command` | Total number of sessions finished |
| `wish_sessions_duration_seconds` | CounterVec | `command` | Total session duration in seconds |

## Notable Algorithms / Named Patterns

### Middleware Wrapper Pattern
The core implementation wraps an SSH handler in a closure that:
1. Records start time with `time.Now()`
2. Increments `sessionsCreated` counter
3. Calls the wrapped handler `sh(s)`
4. In a `defer`, increments `sessionsFinished` and adds duration to `sessionsDuration`

```go
return func(sh ssh.Handler) ssh.Handler {
    return func(s ssh.Session) {
        n := time.Now()
        sessionsCreated.WithLabelValues(fn(s)).Inc()
        defer func() {
            sessionsFinished.WithLabelValues(fn(s)).Inc()
            sessionsDuration.WithLabelValues(fn(s)).Add(time.Since(n).Seconds())
        }()
        sh(s)
    }
}
```

### Graceful Shutdown Pattern
Uses Go channels and signal notification for clean shutdown:
```go
done := make(chan os.Signal, 1)
signal.Notify(done, os.Interrupt, syscall.SIGINT, syscall.SIGTERM)
```

### Prometheus CounterVec with ConstLabels
Metrics are created with `promauto.With(registry).NewCounterVec(...)` — promauto auto-registers with the given registry and ensures metrics are registered only once.

## Strengths
- **Minimal and Focused** — Single responsibility: expose SSH session metrics to Prometheus
- **Clean Middleware Abstraction** — Uses standard `wish.Middleware` interface, easy to compose
- **Flexible Label Extraction** — `CommandFn` allows custom labeling strategies
- **Self-Contained HTTP Server** — No need to run a separate exporter process
- **Graceful Shutdown** — Proper signal handling prevents metrics loss on restart
- **MIT Licensed** — Permissive open source license
- **Well-Tested** — Includes integration tests with `testsession` that verify metric output

## Weaknesses
- **Limited Metrics** — Only tracks sessions created/finished/duration — no CPU, memory, or custom application metrics
- **No Histogram Buckets** — Duration is a counter (cumulative seconds), not a proper histogram with percentile buckets
- **Single Prometheus Format** — No StatsD, Datadog, or other backend support built-in
- **No Metric Unregistration** — Metrics are registered once at startup with `promauto` — no dynamic removal
- **Hardcoded Metrics Endpoint** — Always serves on `/metrics`, no customization
- **No Labels for Session Metadata** — Only `command` label; missing user, term, remote IP, etc.

## SugarCraft Mapping

| promwish | SugarCraft (candy-metrics) | Notes |
|----------|---------------------------|-------|
| `Middleware()`, `MiddlewareWithCommand()` | `SessionMetrics` middleware | Both wrap SSH session lifecycle |
| `wish_sessions_created_total` | `wish.session.connect` counter | Session connection tracking |
| `wish_sessions_finished_total` | (implicit in duration) | Not directly mapped |
| `wish_sessions_duration_seconds` counter | `wish.session.duration` histogram | PHP port properly uses histogram with buckets |
| `Listen()` + HTTP server | External metrics collection | PHP uses Prometheus file backend or StatsD |
| `prometheus.Labels{"app": app}` | `Registry::withTags()` | App-level label propagation |
| `CommandFn` | `SessionMetrics` constructor | Command extraction for labeling |

**Many-to-Many Mapping Details:**

- **candy-metrics** (`SugarCraft\Metrics`) is a broader telemetry library that *includes* session metrics as one use case
- The upstream `promwish` is specifically a `wish` middleware — the PHP port generalizes this to work with any SSH server via `SessionMetrics` middleware
- `candy-metrics` has multiple backends (InMemory, JsonStream, StatsD, PrometheusFile, Multi) vs. Go's single HTTP/prometheus approach
- The PHP version adds cardinality management (max 10,000 label combinations per metric) which the Go original lacks
- PHP version uses proper histograms with percentile buckets vs. Go's cumulative counter for duration

## Analysis

`promwish` is a focused, lightweight middleware library from the Charmbracelet ecosystem that bridges SSH servers (built with `wish`) and Prometheus monitoring. Its core value proposition is adding zero-config telemetry to SSH applications with three lines of code. The design embraces the middleware composition model that `wish` pioneered — instead of subclassing or modifying the server, you simply attach `promwish.Middleware()` and get metrics for free.

The library's simplicity is both its strength and limitation. It tracks only session-level events (created, finished, duration) with a single label dimension (command). There are no CPU, memory, or custom application metrics — you can't emit business-level KPIs through this middleware. The Go implementation uses cumulative counters for duration rather than proper histograms, meaning you lose visibility into percentile latencies (p50, p99) which are typically more actionable than total duration.

The SugarCraft port (`candy-metrics`) significantly extends the upstream design. While the Go library is tightly coupled to Prometheus, the PHP version provides pluggable backends (StatsD, JSON streaming, Prometheus textfile, multi-backend fanout) and uses proper histogram instruments with classic bucket boundaries. The cardinality management feature — evicting oldest label combinations when exceeding 10,000 — addresses a real production issue that the Go original doesn't consider. The `SessionMetrics` middleware in PHP also includes richer session metadata (user, term, exception info) compared to the Go version's command-only label.

For projects already in the Charmbracelet/Go ecosystem, `promwish` is the canonical choice for SSH observability. For PHP projects, `candy-metrics` provides equivalent functionality with more flexibility and better production readiness for high-cardinality scenarios.
