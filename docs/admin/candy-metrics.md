# candy-metrics — Hub Admin Guide

`candy-metrics` is a PHP telemetry library — it does not run as a standalone
process. Applications embed it to emit counters, gauges, and histograms via a
chosen `Backend`. The operational concern for operators is therefore the
backend that receives the telemetry: a Prometheus textfile, a StatsD agent,
a JSON log, or a fan-out to multiple targets.

---

## Operational concerns

### What the process does

Embedding applications call `$registry->counter()` / `gauge()` /
`histogram()` to record metric samples. Each call forwards to the
configured `Backend`, which either:

| Backend | Effect |
|--------|--------|
| `InMemoryBackend` | Accumulates in process memory; lost on restart |
| `JsonStreamBackend` | Appends NDJSON lines to a file (default: `stderr`) |
| `StatsdBackend` | Sends UDP datagrams to a StatsD/StatsD-compatible collector |
| `PrometheusFileBackend` | Atomically rewrites a `.prom` file for `node_exporter --collector.textfile.directory` |
| `MultiBackend` | Fans out to multiple backends simultaneously |

### Prometheus textfile collector integration

The most common operational deployment pairs `PrometheusFileBackend` with
the `node_exporter --collector.textfile.directory` flag:

```php
$reg = new Registry(new PrometheusFileBackend('/var/lib/wish/metrics.prom'));
```

`node_exporter` scrapes every `.prom` file in that directory and exposes
the metrics at `http://host:9100/metrics`. The file is written atomically
(write-to-tmp + rename) and locked with `flock(LOCK_EX)` to serialise
concurrent writers.

### Histogram bucket semantics

`PrometheusFileBackend` emits 14 classic cumulative Prometheus bucket
boundaries:

```
le="0.005"  le="0.01"  le="0.025"  le="0.05"  le="0.1"
le="0.25"   le="0.5"   le="1"      le="2.5"   le="5"
le="10"     le="25"    le="50"     le="100"   le="+Inf"
```

Each bucket counts samples where `value <= le`. The `+Inf` bucket always
equals the total sample count. This matches the Prometheus client golang
default bucket layout and is the established convention for SLI/SLO
comparison.

A `Descriptor` DTO registered via `Registry::register()` pre-emits the
`# TYPE` and `# HELP` lines so `node_exporter` sees complete metadata
before the first sample is recorded.

### Signals handled

`PrometheusFileBackend` flushes automatically in its destructor. If the
embedding process receives `SIGTERM`, the destructor runs during graceful
shutdown and the final metric state is written before exit.

### Files written

| Path | When | Contents |
|------|------|----------|
| `$path.prom` (PrometheusFileBackend) | on `flush()` or destructor | Full metric state in Prometheus text format |
| `$path.prom.tmp` (PrometheusFileBackend) | during flush | Temporary staging file; renamed atomically |

The `.tmp` file is removed on every successful `flush()`. A hard crash
may leave a stale `.tmp` — it is safe to delete.

---

## Configuration

### Backend instantiation

Each backend is constructed in application code. The paths and addresses
are application configuration, not environment variables:

```php
// Prometheus textfile
$backend = new PrometheusFileBackend('/var/lib/app/metrics.prom');

// StatsD over UDP
$backend = new StatsdBackend('127.0.0.1', 8125);

// NDJSON to file
$backend = new JsonStreamBackend('/var/log/app/metrics.jsonl');

// Fan-out
$backend = new MultiBackend(
    new StatsdBackend('127.0.0.1', 8125),
    new PrometheusFileBackend('/var/lib/app/metrics.prom'),
);
```

### Descriptor registration

Register each metric's type and help text before recording samples:

```php
$reg->register(new Descriptor(
    name: 'app.http.request.duration',
    help: 'HTTP request duration in seconds',
    type: 'histogram',
    labelKeys: ['route', 'status'],
));
```

Registration is idempotent — duplicate names are ignored.

---

## Monitoring

### What to scrape

| Target | Endpoint / Path | What to watch |
|-------|----------------|---------------|
| `node_exporter` | `http://host:9100/metrics` (textfile) | All `.prom` files in `--collector.textfile.directory` |
| StatsD | Your StatsD deployment UI | Datagrams on port 8125 (default) |
| JSONL | `$JsonStreamBackend` path | `kind` field: `counter`, `gauge`, `histogram` |

### Key metrics emitted by SessionMetrics middleware

| Metric | Type | Labels | Meaning |
|--------|------|--------|---------|
| `wish.session.connect` | counter | `user`, `term` | New SSH sessions opened |
| `wish.session.duration` | histogram | `user`, `term` | Session lifetime distribution |
| `wish.session.error` | counter | `user`, `term`, `exception` | Session-level errors |

### Histogram SLI/SLO queries

```promql
# p50 latency
histogram_quantile(0.5, rate(wish_session_duration_bucket[5m]))

# p99 latency
histogram_quantile(0.99, rate(wish_session_duration_bucket[5m]))

# error rate
rate(wish_session_error_total[5m]) / rate(wish_session_connect_total[5m])
```

---

## Failure modes

| Scenario | Behaviour |
|----------|-----------|
| Metrics file directory does not exist | `RuntimeException` on first `flush()`; process continues without metrics |
| Disk full | `RuntimeException` on `flush()`; metrics for that interval are lost |
| StatsD receiver unavailable | UDP write fails silently; no exception propagates to application |
| Application crash before destructor | Last `flush()` may not run; node_exporter serves last successfully written file |
| Concurrent writers (MultiBackend) | `PrometheusFileBackend` serialises via `flock(LOCK_EX)`; StatsD/JSON backends are unprotected |

### Healthcheck pattern

For long-running processes, call `flush()` explicitly after each
reporting interval rather than relying on the destructor:

```php
register_shutdown_function(fn() => $backend->flush());
```
