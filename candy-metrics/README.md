<img src=".assets/icon.png" alt="candy-metrics" width="160" align="right">

# CandyMetrics

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-metrics)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-metrics)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-metrics?label=packagist)](https://packagist.org/packages/sugarcraft/candy-metrics)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


Lightweight telemetry primitives for SugarCraft / CandyWish servers. Counters, gauges, histograms with pluggable backends — drop-in middleware for SSH session metrics.
```sh
composer require sugarcraft/candy-metrics
```

## Concepts

| Primitive        | Behaviour                                                                                                                               |
|------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| Counter          | Monotonic value that accumulates (connect counts, errors).                                                                             |
| Gauge            | Instantaneous value that replaces on set (queue depth, RSS).                                                                            |
| Histogram        | Distribution of samples (latency, payload size); Prometheus backend emits 14 classic `le` buckets +Inf.                          |
| UpDownCounter    | Synchronous counter that supports positive *and* negative increments — used for values that go up and down (active connections).     |
| AsyncCounter     | Asynchronous counter whose value is observed at collection time via a callback. External values (DB pool size, GC counts).               |
| AsyncGauge       | Asynchronous gauge whose value is observed at collection time via a callback. External instantaneous readings (memory, queue depth). |

A `Registry` is the application-facing facade; it forwards every emit to the configured `Backend`. Backends decide how to persist or forward.

## Instrument factory helpers

The registry exposes dedicated factory methods that return instrument objects — useful when an instrument is held for repeated `add()` / `observe()` calls:

```php
$connCounter = $reg->upDownCounter('server.active_connections', ['host' => $host]);
$connCounter->add(1);    // connection opened
// ... later ...
$connCounter->add(-1);    // connection closed
```

Async instruments accept a callback that is invoked at collection time:

```php
$gcCount = $reg->asyncCounter('jvm.gc.count', 'JVM garbage collection count', fn() => $jvm->gcCount());
$gcCount->observe();   // called during collection sweep
```

## Cardinality management

Every unique label-value combination is a distinct time series. High-cardinality labels (e.g. `user_id`, `request_id`) can exhaust memory if left unchecked.

The registry tracks per-metric cardinality and evicts the oldest combination when the limit (default: 10 000) is reached:

```php
// Default limit of 10 000 label combinations per metric
$reg = new Registry($backend);

// Custom limit
$reg = new Registry($backend, [], 500);

// Inspect current cardinality
$reg->cardinality('http.requests');   // → int

// Manually evict a label combination (e.g. after a session ends)
$reg->deleteLabelValues('http.requests', ['route' => '/logout']);

// Eviction is also called automatically — FIFO; the oldest entry
// is removed when the limit is exceeded.
```

## Descriptor registration

The `Descriptor` DTO carries a metric's name, help text, type, and label keys. Register it with the registry so backends can pre-emit `TYPE` and `HELP` lines before any samples are recorded — required by the Prometheus textfile collector for uninitialized metrics:

```php
use SugarCraft\Metrics\Registry;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;

$reg = new Registry(new PrometheusFileBackend('/var/lib/app/metrics.prom'));

$reg->register(new Descriptor(
    name: 'http.request.duration',
    help: 'HTTP request duration in seconds',
    type: 'histogram',
    labelKeys: ['route', 'status'],
));

$stop = $reg->time('http.request.duration', ['route' => '/api/foo']);
handleRequest();
$stop();
```

`register()` is idempotent — registering the same metric name twice is a no-op.

## Usage

```php
use SugarCraft\Metrics\Registry;
use SugarCraft\Metrics\Backend\StatsdBackend;

$reg = new Registry(new StatsdBackend('127.0.0.1', 8125));

$reg->counter('http.requests', 1, ['route' => '/api/foo', 'status' => '200']);
$reg->gauge  ('queue.depth',   42);

$stop = $reg->time('http.duration', ['route' => '/api/foo']);
handleRequest();
$stop();
```

`withTags()` returns a registry that pre-tags every emit:

```php
$req = $reg->withTags(['request_id' => $rid, 'user' => $userId]);
$req->counter('events');   // tagged with request_id + user automatically
```

## Backends

### `InMemoryBackend`

Useful for tests and for fanning out to multiple backends. Counters add up, gauges hold the last value, histograms keep every sample.

### `JsonStreamBackend`

Newline-delimited JSON, one event per line. The simplest, most diagnostic-friendly target. Default writes to `stderr`.

```jsonl
{"ts":"2026-05-02T16:30:00+00:00","kind":"counter","name":"hits","value":1,"tags":{"route":"/x"}}
```

### `StatsdBackend`

UDP datagrams in the etsy / DogStatsD wire format. Tags emitted as `|#k:v,...` (drop with `dogstatsd: false` for legacy servers).

```
hits:1|c|#route:/x,env:prod
```

### `PrometheusFileBackend`

Atomically rewrites a `.prom` textfile-collector file with the current state of every metric. Pairs with `node_exporter --collector.textfile.directory=…`. Counter values accumulate across `flush()`s; histograms emit all 14 classic cumulative bucket boundaries (`le="0.005"` … `le="100"`) plus `le="+Inf"`, alongside `_count` and `_sum`.

### `MultiBackend`

Fan out to multiple backends — e.g. live StatsD plus a JSON audit trail.

```php
$reg = new Registry(new MultiBackend(
    new StatsdBackend(),
    new JsonStreamBackend('/var/log/metrics.jsonl'),
));
```

## CandyWish session middleware

Wires session telemetry into a CandyWish stack:

```php
use SugarCraft\Wish\Server;
use SugarCraft\Metrics\Registry;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Middleware\SessionMetrics;

$reg = new Registry(new PrometheusFileBackend('/var/lib/wish/metrics.prom'));

Server::new()
    ->use(new SessionMetrics($reg))
    ->use(/* ... your stack ... */)
    ->serve();
```

Per session this emits:

| Metric                    | Type      | Tags                              |
|---------------------------|-----------|-----------------------------------|
| `wish.session.connect`    | counter   | `user`, `term`                    |
| `wish.session.duration`   | histogram | `user`, `term`                    |
| `wish.session.error`      | counter   | `user`, `term`, `exception`       |

Pass `extraTags` (a callable receiving the `Session`) to add things like client subnet, geo, build version.

## Status

Phase 9 — UpDownCounter, AsyncCounter, AsyncGauge instrument kinds + Registry cardinality tracking with FIFO DeleteLabelValues eviction. 41 tests across Registry, four backends, SessionMetrics middleware, and instrument coverage.
