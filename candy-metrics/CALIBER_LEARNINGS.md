# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:prom-histogram-14-buckets]** `PrometheusFileBackend` emits 14 classic cumulative Prometheus bucket boundaries (`0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 25, 50, 100`) plus `+Inf` (which always equals total count). Buckets are cumulative: each bucket also contains all samples from smaller buckets. Use this pattern when porting histogram metrics to ensure Prometheus `histogram_quantile()` queries produce correct percentiles.
- **[pattern:descriptor-pre-emit-type-help]** `Descriptor` DTO carries `name`, `help`, `type`, and `labelKeys` for metric registration. Calling `Registry::register()` before recording samples lets the Prometheus textfile collector emit `# TYPE` and `# HELP` lines before any samples are observed — required so `node_exporter` can display metric metadata immediately on scrape, even before the first sample arrives.
