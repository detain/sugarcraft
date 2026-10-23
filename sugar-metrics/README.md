<img src=".assets/icon.png" alt="sugar-metrics" width="160" align="right">

# SugarMetrics

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-metrics)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-metrics)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-metrics?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-metrics)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

Lightweight telemetry primitives for SugarCraft / SugarSshServer servers. Counters, gauges, histograms with pluggable backends — drop-in middleware for SSH session metrics.

> Formerly published as `sugarcraft/candy-metrics` (renamed 2026-10). <!-- @rename-keep -->

```sh
composer require sugarcraft/sugar-metrics
```

## Concepts

| Primitive        | Behaviour                                                                                                                               |
|------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| Counter          | Monotonic value that accumulates (connect counts, errors).                                                                             |
| Gauge            | Instantaneous value that replaces on set (queue depth, RSS).                                                                            |
| Histogram        | Distribution of samples (latency, payload size); Prometheus backend emits 14 classic `le` buckets +Inf.                          |
| UpDownCounter    | Synchronous counter that supports positive *and* negative increments — used for values that go up and down (active connections).     |
| AsyncCounter     | Asynchronous counter backed by a callback; you call `observe()` whenever you want the current external reading emitted.                |
| AsyncGauge       | Asynchronous gauge backed by a callback; you call `observe()` to emit an instantaneous external reading (memory, queue depth). |

A `Registry` is the application-facing facade; it forwards every emit to the configured `Backend`. Backends decide how to persist or forward.

## Instrument factory helpers

The registry exposes dedicated factory methods that return instrument objects — useful when an instrument is held for repeated `add()` / `observe()` calls:

```php
$connCounter = $reg->newUpDownCounter('server.active_connections', 'Active server connections', ['host' => $host]);
$connCounter->add(1);    // connection opened
// ... later ...
$connCounter->add(-1);   // connection closed
```

Factory tags are *static instrument tags*: the `$tags` you pass to a factory ride on **every** `add()` / `observe()` from that instrument, and per-call tags merge on top (per-call wins on conflicts). The `$help` string is metadata carried by the instrument (`name()` / `help()`); the `# HELP` lines in the Prometheus textfile come from `Descriptor` registration below, not from factory calls.

Async instruments accept a callback that reads the external value each time you sample it:

```php
$gcCount = $reg->newAsyncCounter('jvm.gc.count', 'JVM garbage collection count', fn() => $jvm->gcCount());
$gcCount->observe();   // invokes the callback and emits the reading now — nothing sweeps automatically
```

## Cardinality management

Every unique label-value combination is a distinct time series. High-cardinality labels (e.g. `user_id`, `request_id`) can exhaust memory if left unchecked.

The registry tracks per-metric cardinality and, when the limit (default: 10 000) is exceeded, evicts the **oldest** combination (FIFO) — dropping it from the tracker *and* reclaiming that series from the backend, so a tag flood cannot grow backend memory without bound. The cardinality key is built from sorted, backslash-escaped `k=v` pairs (see `Util::tagKey()`), so lookalike tag sets such as `['x' => 'a|y=b']` and `['x' => 'a', 'y' => 'b']` stay distinct series.

```php
// Default limit of 10 000 label combinations per metric
$reg = new Registry($backend);

// Custom limit
$reg = new Registry($backend, [], 500);

// Inspect current cardinality
$reg->cardinality('http.requests');   // → int

// Drop one tracker entry only (default tags are merged in first, so you
// pass just the per-emit tags):
$reg->deleteLabelValues('http.requests', ['route' => '/logout']);

// Reclaim tracker entry AND the backend series:
$reg->remove('http.requests', ['route' => '/logout']);
```

Backends bound their own storage too: `InMemoryBackend` caps histogram samples per series (`maxSamplesPerKey`, default 4 096, reservoir-sampled) and `PrometheusFileBackend` caps series per family (`maxSeriesPerFamily`, default 10 000, oldest dropped FIFO). `Backend::clear()` resets a backend's accumulated state; the registry exposes it via `$reg->backend()->clear()`.

## Descriptor registration

The `Descriptor` DTO carries a metric's name, help text, type, and label keys. Register it with the registry so `PrometheusFileBackend` emits the family's `# TYPE` and `# HELP` lines even before the first sample arrives — informational metadata (the textfile format itself accepts sample-only families), handy for spotting declared-but-idle metrics:

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
$elapsed = $stop();   // emits the duration and returns it (float seconds)
```

`register()` is idempotent — registering the same metric name twice is a no-op. A `summary` descriptor emits only the family's `TYPE`/`HELP` lines; the quantile/`_sum`/`_count` samples remain your responsibility.

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

Useful for tests and for fanning out to multiple backends. Counters add up, gauges hold the last value, histograms keep a bounded reservoir of samples per series (default 4 096; once full, older samples may be replaced by newer ones).

### `JsonStreamBackend`

Newline-delimited JSON, one event per line. The simplest, most diagnostic-friendly target. Default writes to `stderr`.

```jsonl
{"ts":"2026-05-02T16:30:00+00:00","kind":"counter","name":"hits","value":1,"tags":{"route":"/x"}}
```

A string file target's parent directory must exist and be writable — construction throws a `RuntimeException` naming the path otherwise.

### `StatsdBackend`

UDP datagrams in the etsy / DogStatsD wire format. Tags emitted as `|#k:v,...` (drop with `dogstatsd: false` for legacy servers). Every wire token (metric name, tag keys and values) is flattened at the send chokepoint: control bytes and `|` become `_`, so untrusted label values cannot forge extra datagram lines or inject wire fields.

```
hits:1|c|#route:/x,env:prod
```

### `PrometheusFileBackend`

Atomically rewrites a `.prom` textfile-collector file with the current state of every metric. Pairs with `node_exporter --collector.textfile.directory=…`. Each `flush()` stages a fresh body into a unique per-writer temp file (`<path>.tmp.<pid>…`), flock-guarded, and publishes it with an atomic `rename()` — concurrent writers never share staging and a reader never sees a half-written body; simultaneous flushes resolve last-writer-wins. The parent directory of the target must exist and be writable; `flush()` throws a `RuntimeException` naming the staged path if it is not. Counter values accumulate across `flush()`s; histograms emit all 14 classic cumulative bucket boundaries (`le="0.005"` … `le="100"`) plus `le="+Inf"`, alongside `_count` and `_sum`. Series per family are capped (`maxSeriesPerFamily`, default 10 000, oldest dropped FIFO), and `remove()` / `clear()` reclaim accumulated series from memory so a tag flood cannot grow the backend without bound.

**Always call `flush()` explicitly** to guarantee delivery and surface errors. The destructor invokes `flush()` automatically, but errors during the atomic rename are silently swallowed in `__destruct`. Explicit calls throw a `RuntimeException` on failure. `clear()` resets accumulated state but does not delete the already-written `.prom` file — the next `flush()` rewrites whatever remains.

### `MultiBackend`

Fan out to multiple backends — e.g. live StatsD plus a JSON audit trail.

```php
$reg = new Registry(new MultiBackend(
    new StatsdBackend(),
    new JsonStreamBackend('/var/log/metrics.jsonl'),
));
```

Error isolation: with the plain constructor the fan-out stops at the first failing child and that failure propagates unchanged. Build with `MultiBackend::withContinueOnError(...)` instead and every child gets a turn: all failures are collected and rethrown as one `MultiBackendException`, whose `errors()` method returns the collected `Throwable`s. `remove()` / `clear()` are idempotent cleanup and always swallow child errors regardless of mode.

## SugarSshServer session middleware

Wires session telemetry into a SugarSshServer stack:

```php
use SugarCraft\SshServer\Server;
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

`user` and `term` are attacker-controllable, so they are normalised to `[A-Za-z0-9._:@/-]` (everything else collapses to `_`) and clamped to 64 bytes. The `exception` tag carries the raw throwable class name, and values you add via `extraTags` are passed through unmodified — sanitise them yourself if they can contain hostile input.

Pass `extraTags` (a callable receiving the `Session`) to add things like client subnet, geo, build version.

## Status

Complete and in production use: UpDownCounter, AsyncCounter and AsyncGauge instrument kinds, Registry cardinality tracking with FIFO eviction and backend reclaim, and five backends — `InMemory`, `JsonStream`, `Statsd`, `PrometheusFile` and `Multi`. 196 tests (0.15s baseline suite: Registry, each backend, SessionMetrics middleware, wire-format and tag-key collision pins, i18n locale parity across all 16 shipped locales, and example smoke checks).

## Breaking changes

**`MultiBackendException::getErrors()` → `errors()`.** The accessor now follows
the library's bare-accessor convention (no `get` prefix). A repo-wide grep
found no call sites outside sugar-metrics itself, so the rename landed complete
in one pass; external code still calling `getErrors()` must switch to
`errors()`.
