# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:prom-histogram-14-buckets]** `PrometheusFileBackend` emits 14 classic cumulative Prometheus bucket boundaries (`0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 25, 50, 100`) plus `+Inf` (which always equals total count). Buckets are cumulative: each bucket also contains all samples from smaller buckets. Use this pattern when porting histogram metrics to ensure Prometheus `histogram_quantile()` queries produce correct percentiles.
- **[pattern:descriptor-pre-emit-type-help]** `Descriptor` DTO carries `name`, `help`, `type`, and `labelKeys` for metric registration. Calling `Registry::register()` before recording samples lets the Prometheus textfile collector emit `# TYPE` and `# HELP` lines before any samples are observed — required so `node_exporter` can display metric metadata immediately on scrape, even before the first sample arrives.
- **[pattern:cardinality-fifo-eviction]** `Registry` tracks per-metric cardinality using a label-value cache. When the configured limit (default 10 000) is exceeded, the oldest entry is evicted via `reset()` + `key()` — FIFO, not LRU. This prevents memory exhaustion from unbounded label combinations (e.g. per-request `request_id` labels). `deleteLabelValues()` also supports manual eviction for explicit cleanup (session teardown). Backends that accumulate large label sets should call `deleteLabelValues()` to reclaim memory.
- **[pattern:async-instrument-ownership]** `AsyncCounter` and `AsyncGauge` observe external values via a `\Closure(): float` callback invoked at collection time. Unlike synchronous instruments, the value is not owned by the metrics library — it is read on-demand from an external source (JVM GC count, DB pool size, device temperature). This matches the OpenTelemetry async instrument semantics: the callback is called once per `observe()` invocation, not continuously.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
