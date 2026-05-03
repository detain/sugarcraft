<img src=".assets/icon.png" alt="candy-metrics" width="160" align="right">

# CandyMetrics

Lightweight telemetry primitives for CandyCore / CandyWish servers. Counters, gauges, histograms with pluggable backends — drop-in middleware for SSH session metrics.

## Concepts

| Primitive   | Behaviour                                                       |
|-------------|------------------------------------------------------------------|
| Counter     | Monotonic value that accumulates (connect counts, errors).       |
| Gauge       | Instantaneous value that replaces on set (queue depth, RSS).     |
| Histogram   | Distribution of samples (latency, payload size).                 |

A `Registry` is the application-facing facade; it forwards every emit to the configured `Backend`. Backends decide how to persist or forward.

## Usage

```php
use CandyCore\Metrics\Registry;
use CandyCore\Metrics\Backend\StatsdBackend;

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

Atomically rewrites a `.prom` textfile-collector file with the current state of every metric. Pairs with `node_exporter --collector.textfile.directory=…`. Counter values accumulate across `flush()`s; histograms expose `_count` and `_sum`.

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
use CandyCore\Wish\Server;
use CandyCore\Metrics\Registry;
use CandyCore\Metrics\Backend\PrometheusFileBackend;
use CandyCore\Metrics\Middleware\SessionMetrics;

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

Phase 9+ — first cut. 23 tests / 57 assertions across Registry, four backends, and the SessionMetrics middleware.
