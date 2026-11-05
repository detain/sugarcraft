<img src=".assets/icon.png" alt="sugar-metrics" width="160" align="right">

# SugarMetrics

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-metrics)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-metrics)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-metrics?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-metrics)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

Lightweight telemetry primitives for SugarCraft / SugarSshServer servers. Six instrument kinds — counters, gauges, histograms, up/down counters, async counters and async gauges — with pluggable backends and drop-in middleware for SSH session metrics.

> Formerly published as `sugarcraft/candy-metrics` (renamed 2026-10). <!-- @rename-keep -->

```sh
composer require sugarcraft/sugar-metrics
```

## Concepts

| Primitive        | Behaviour                                                                                                                               |
|------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| Counter          | Monotonic value that accumulates (connect counts, errors). Negative samples are refused.                                               |
| Gauge            | Instantaneous value that replaces on set (queue depth, RSS).                                                                            |
| Histogram        | Distribution of samples (latency, payload size). Negative samples are refused; the Prometheus backend emits 14 cumulative `le` buckets — the 11 classic client_golang boundaries extended with 25/50/100 — plus `+Inf`. |
| UpDownCounter    | Synchronous counter that supports positive *and* negative increments — used for values that go up and down (active connections).     |
| AsyncCounter     | Counter backed by a callback; call `observe()` (optionally with per-call tags) whenever you want the current external reading emitted.  |
| AsyncGauge       | Gauge backed by a callback; call `observe($tags)` to emit an instantaneous external reading (memory, queue depth, pool size).          |

A `Registry` is the application-facing facade; it forwards every emit to the configured `Backend`. Backends decide how to persist or forward. In this library a *tag* is a key/value pair attached to an emit; the Prometheus text format calls the same thing a *label* (`PrometheusFileBackend` translates).

## Instrument factory helpers

The registry exposes dedicated factory methods that return instrument objects — useful when an instrument is held for repeated `add()` / `observe()` calls:

```php
$reg = new Registry($backend);
$connCounter = $reg->newUpDownCounter('server.active_connections', 'Active server connections', ['host' => $host]);
$connCounter->add(1);    // connection opened
// ... later ...
$connCounter->add(-1);   // connection closed
```

Factory tags are *static instrument tags*: the `$tags` you pass to a factory ride on **every** `add()` / `observe()` from that instrument, and per-call tags merge on top (per-call wins on conflicts). `observe()` on the async instruments accepts an optional tag array for exactly that merge. The `$help` string is metadata carried by the instrument (`name()` / `help()`); the `# HELP` lines in the Prometheus textfile come from `Descriptor` registration below, not from factory calls.

Async instruments accept a callback that reads the external value each time you sample it:

```php
$gcCount = $reg->newAsyncCounter('jvm.gc.count', 'JVM garbage collection count', fn() => $jvm->gcCount());
$gcCount->observe();   // invokes the callback and emits the reading now — nothing sweeps automatically
$pool = $reg->newAsyncGauge('db.pool.size', 'Open database connections', fn() => $pdo->poolSize());
$pool->observe(['shard' => 'eu']);   // static + per-call tags merge on this one sample
```

Direct emits for every kind exist too — `$reg->upDownCounter()`, `$reg->asyncCounter()`, `$reg->asyncGauge()` take the value (and tags) inline without an instrument object. On the direct-emitting side, `counter()`'s value parameter defaults to `1.0` (calling it bare is a +1), and `upDownCounter()` names its numeric parameter `amount` where `counter()` names the same shape `value`.

## Names on the wire

Metric names and tag keys are *not* free text on every wire, and the folds differ per backend. The folds run on the *emit path* only — a registered `Descriptor` name must already be in the exposition charset (its constructor refuses dots, see *Descriptor registration*):

- **StatsD** (`StatsdBackend`): the metric name may keep dots (`http.requests` is idiomatic) but colons are flattened to `_` because `:` separates value from type on the wire. Tag keys keep letters/digits/dots/underscores *and all other punctuation the sweep below does not name* — the sweep is a denylist of exactly `:` `|` `,` space, control/DEL and the C1 range, so `-`, `/`, `@`, `=`, `#` survive raw.
- **Prometheus textfile** (`PrometheusFileBackend`): metric names keep `:` (a legal Prometheus name character) and fold every other illegal rune (including `.`) to `_`; label names never keep `:` — illegal runes become `_`.
- **JSON stream**: names are emitted verbatim inside a JSON string — the encoding itself is the framing.

Because the name/label folds are many-to-one, `PrometheusFileBackend` refuses silent collisions: two *distinct* raw series that fold onto the same metric name or the same label key throw a `RuntimeException` — at the second write, and at a `remove()` whose own raw never owned that folded key (see Cardinality management) — instead of merging or silently wiping the innocent twin behind your back.

Kind mapping to wire tokens:

| Registry kind    | StatsD                        | Textfile `# TYPE` | JSON `kind`       |
|------------------|-------------------------------|-------------------|-------------------|
| counter          | `\|c`                         | `counter`         | `counter`         |
| gauge            | `\|g`                         | `gauge`           | `gauge`           |
| histogram        | `\|h` (DogStatsD histogram token; etsy-style timers use `\|ms`) | `histogram`       | `histogram`       |
| upDownCounter    | signed `\|g`                  | `gauge`           | `up_down_counter` |
| asyncCounter     | absolute `\|g`                | `counter`         | `async_counter`   |
| asyncGauge       | `\|g`                         | `gauge`           | `async_gauge`     |

## Cardinality management

Every unique label-value combination is a distinct time series. High-cardinality labels (e.g. `user_id`, `request_id`) can exhaust memory if left unchecked.

The registry tracks per-metric cardinality and, when the limit (default: 10 000, must be ≥ 1 — anything else throws at construction) is exceeded, evicts the **oldest** combination (FIFO) — dropping it from the tracker *and* reclaiming that series from the backend, so a tag flood cannot grow backend memory without bound. Precisely: eviction delegates to the backend's `remove()` — storing backends (`InMemoryBackend`, `PrometheusFileBackend`) genuinely release the series, while `StatsdBackend` and `JsonStreamBackend` have nothing to retract once emitted and their `remove()` is a deliberate no-op, so against a push-only arm eviction bounds only the tracker. `MultiBackend` fans the call out to each child. The cardinality key is built from sorted, backslash-escaped `k=v` pairs (see `Util::tagKey()`), so lookalike tag sets such as `['x' => 'a|y=b']` and `['x' => 'a', 'y' => 'b']` stay distinct series. The tracker bounds *series per metric name*; the set of metric names itself is unbounded — treat untrusted strings as label values, never as metric names. A registry cloned with `withTags()` tracks its own budget independently of its parent.

```php
// Default limit of 10 000 label combinations per metric
$reg = new Registry($backend);

// Custom limit
$reg = new Registry($backend, [], 500);

// Inspect current cardinality, and the configured ceiling
$reg->cardinality('http.requests');      // → int
$reg->cardinalityLimit();                // → int (500)

// Drop one tracker entry only (default tags are merged in first, so you
// pass just the per-emit tags):
$reg->deleteLabelValues('http.requests', ['route' => '/logout']);

// Reclaim tracker entry AND (on storing backends) the backend series:
$reg->remove('http.requests', ['route' => '/logout']);

// Push the current state to the backend (textfile durability — see below)
$reg->flush();
```

Backends bound their own storage too. `InMemoryBackend` caps histogram samples per series (`maxSamplesPerKey`, default 4 096, reservoir-sampled) and — since this release — total series (`maxSeries`, default 0 = uncapped, oldest dropped FIFO when set). `PrometheusFileBackend` caps series per family (`maxSeriesPerFamily`, default 10 000, oldest dropped FIFO) and refuses a limit below 1. `Backend::clear()` resets a backend's accumulated state; the registry exposes it via `$reg->backend()->clear()`.

## Descriptor registration

The `Descriptor` DTO carries a metric's name, help text, type, and label keys. Register it with the registry so `PrometheusFileBackend` emits the family's `# TYPE` and `# HELP` lines even before the first sample arrives — informational metadata (the textfile format itself accepts sample-only families), handy for spotting declared-but-idle metrics:

```php
use SugarCraft\Metrics\Registry;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;

$reg = new Registry(new PrometheusFileBackend('/var/lib/app/metrics.prom'));

$reg->register(new Descriptor(
    name: 'http_request_duration_seconds',
    help: 'HTTP request duration in seconds',
    type: 'histogram',
    labelKeys: ['route', 'status'],
));

$stop = $reg->time('http_request_duration_seconds', ['route' => '/api/foo']);
// … do the work …
$elapsed = $stop();   // emits the duration and returns it (float seconds)
```

The constructor validates its input: a non-empty name matching `[a-zA-Z_:][a-zA-Z0-9_:]*`, non-empty help free of C0 control bytes and DEL, a type in `counter|gauge|histogram|summary`, and non-empty string label keys — violations throw. Because the name is checked RAW, dotted names like `http.request.duration` are refused here even though they are idiomatic on StatsD and legal to *emit* (the backend folds them at write time): register descriptors under the folded name they will occupy in the exposition. `register()` is idempotent *only for identical re-registration*: same type **and** same help is a no-op; anything else throws rather than silently picking a winner. A `summary` descriptor emits only the family's `TYPE`/`HELP` lines; the quantile/`_sum`/`_count` samples remain your responsibility. The descriptor universe is the four Prometheus wire types, while the registry offers six instrument kinds; the exposition mapping is `counter` and `asyncCounter` → TYPE `counter`, `gauge`, `upDownCounter` and `asyncGauge` → TYPE `gauge`, `histogram` → TYPE `histogram`, and `summary` is descriptor-only — no registry kind backs it.

`time()` records the sample exactly once — a second call to the stop-fn re-reads the clock and returns the then-current elapsed seconds, but records nothing further. Elapsed is measured on the monotonic clock (`hrtime()`), so a system-clock step (NTP correction, manual set) can never drive a negative duration into the histogram — and out of your `finally` arm; the reading is a duration, never a wall-clock timestamp.

## Usage

```php
use SugarCraft\Metrics\Registry;
use SugarCraft\Metrics\Backend\StatsdBackend;

$reg = new Registry(new StatsdBackend('127.0.0.1', 8125));

$reg->counter('http.requests', 1, ['route' => '/api/foo', 'status' => '200']);
$reg->gauge  ('queue.depth',   42);

$stop = $reg->time('http.duration', ['route' => '/api/foo']);
// … do the work …
$stop();
```

`withTags()` returns a registry that pre-tags every emit:

```php
$req = $reg->withTags(['request_id' => $rid, 'user' => $userId]);
$req->counter('events');   // tagged with request_id + user automatically
```

If `$rid` / `$userId` come from untrusted callers, treat them like any other tag value: the backends sweep them for wire framing, but cardinality-wise every distinct value is a new series — hash or bucket before tagging.

## Backends

### `InMemoryBackend`

Useful for tests and as one arm of a fan-out. Counters add up, gauges hold the last value, histograms keep a bounded reservoir of samples per series (default 4 096). Until the reservoir fills it keeps every sample in arrival order; after that a new sample is accepted with probability cap/n — it replaces slot `j = random_int(0, n-1)` only when `j` falls inside the reservoir, so most later samples are discarded outright and the retained set stays a uniform random sample of the whole stream (Algorithm R). Set `maxSeries` to bound the total number of series (FIFO eviction; 0 leaves it uncapped).

**Reading it in tests.** All accumulated state is exposed directly: `counters()`, `gauges()`, `histograms()`, `upDownCounters()`, `asyncCounters()`, and `asyncGauges()` return the tracked maps keyed by the internal series key — the bare `name` for untagged series, `name|` plus the canonicalized tag key otherwise (`examples/showcase.php` prints the counter and gauge maps this way). The per-series accessors take the parts instead: `counterValue(name, tags)`, `upDownCounterValue(name, tags)`, and `asyncCounterValue(name, tags)` report `0.0` for a series that was never recorded, while `gaugeValue(name, tags)` and `asyncGaugeValue(name, tags)` return `null` — a gauge has no meaningful zero, so absent and zero are distinguishable. `histogramValues(name, tags)` returns the retained samples (`[]` for an unseen series), and `seriesCount()` / `clear()` do what they say. Two traps: the histogram store is the **reservoir**, not the stream — once a series has seen more than `maxSamplesPerKey` samples, `array_sum(histogramValues(...))` sums the retained subset, not the true total (assert on sample count and shape, or raise the cap); and the counter-zero vs gauge-`null` asymmetry means an unset gauge reads `null`, never `0.0`.

### `JsonStreamBackend`

Newline-delimited JSON, one event per line. The simplest, most diagnostic-friendly target. Default writes to `stderr`. Non-finite values render as the strings `"NaN"`, `"+Inf"`, `"-Inf"` (JSON has no number literal for them).

```jsonl
{"ts":"2026-05-02T16:30:00+00:00","kind":"counter","name":"hits","value":1,"tags":{"route":"/x"}}
```

A string file target's parent directory must exist and be writable — construction throws a `RuntimeException` naming the path otherwise. When `throwOnError` is set (the default), an unencodable emit or a failed flush throws instead of vanishing; with `throwOnError: false` the same conditions drop the event silently. A newly created string file's mode follows the process umask — the same consequence as `PrometheusFileBackend`'s staging file, so choose a umask your reader can open. And the stream records tag values as captured: values that pass through `SessionMetrics` can carry personal data (usernames, term strings), so choose the sink path accordingly.

### `StatsdBackend`

UDP datagrams in the etsy / DogStatsD wire format. The constructor takes six knobs: `host` (`127.0.0.1`) and `port` (`8125`) for the sink, `dogstatsd` (`true`) for the tag segment, `failSilently` (`true`), `timeout` (`1.0` seconds bounding the socket-open attempt), and `existingSocket` (pass a ready stream resource when you manage the socket yourself). Opening the UDP socket happens at construction and throws a `RuntimeException` naming the endpoint if it fails; from then on `failSilently: true` means telemetry never breaks the program it instruments — failed or short writes are dropped. UDP is fire-and-forget, so even a write the socket accepts is no proof the stats agent received the datagram: packets can still vanish silently on the way. With `failSilently: false` write-side failures raise instead (that cannot resurrect a packet the network already dropped). For first runs, point the registry at `JsonStreamBackend` on stderr or at `InMemoryBackend` — you see exactly what would be emitted before wiring a collector.

Tags emit as `|#k:v,...`; with `dogstatsd: false` tags are dropped entirely (legacy StatsD has no tag channel). Every wire token is flattened at the send chokepoint so untrusted label values can never forge a second field, pair or line: in metric names, control bytes, DEL, the C1 range, `|`, `,`, space — and `:` — become `_`; in tag keys the identical sweep applies (the same set, `:` included — measured: `a=b` rides raw, `a:b` folds to `a_b`); in tag values `|`, `,`, space and control/C1 bytes become `_` while `:` is kept (safe, since a pair token can no longer contain a separator). Colons inside a tag *value* therefore survive; colons in the *name* or in a *key* do not. The sweep is a denylist: other punctuation (`-`, `/`, `@`, `=`, `#`) survives everywhere.

```
hits:1|c|#route:/x,env:prod
```

Type tokens follow the table in *Names on the wire*; notably async counters emit an absolute `|g` (StatsD `\|c` is itself a monotonic counter, so re-publishing a cumulative external reading as `|c` would double-count it).

### `PrometheusFileBackend`

Atomically rewrites a `.prom` textfile-collector file with the current state of every metric. Pairs with `node_exporter --collector.textfile.directory=…`. Each `flush()` stages a fresh body into a *uniquely named* per-writer temp file (`<path>.tmp.<pid>-<entropy>`), fsyncs it, and publishes it with an atomic `rename()` — rename(2) is what carries the atomicity (a reader either sees the old file or the new one, never a half-written body), while the flock guards the staging handle against interleaved writes from the same writer. Simultaneous flushes resolve last-writer-wins; a crashed flush leaves at most one stale `.tmp.*` behind and never corrupts the target. The staged file's mode follows the process umask (as with any `fopen`), so run the collector writer with a umask your node exporter can read. The parent directory of the target must exist and be writable; `flush()` throws a `RuntimeException` naming the staged path if it is not.

Counter values accumulate across `flush()`s; histograms emit the 14 cumulative bucket boundaries (`le="0.005"` … `le="100"` — the classic client_golang set extended with 25/50/100) plus `le="+Inf"`, alongside `_count` and `_sum`. Bucket boundaries passed to the constructor must be finite and strictly increasing. A caller-supplied `le` tag is refused on histograms — it would forge a second bucket label. Series per family are capped (`maxSeriesPerFamily`, default 10 000, oldest dropped FIFO), and `remove()` / `clear()` reclaim accumulated series from memory so a tag flood cannot grow the backend without bound. Exposition integrity is guarded at flush time: a histogram sharing its base name with any other kind of metric throws a loud `RuntimeException` even under different label sets (a histogram emits only derived `_bucket`/`_count`/`_sum` lines, so such a pair would otherwise publish them under the other kind's `# TYPE`), and two series with identical emitted name+labels (possible only through a sanitizing fold the registry could not see, or a counter colliding with a histogram's derived `_count`/`_sum`) likewise throw, naming the offender instead of publishing a file Prometheus will reject wholesale.

**Always call `flush()` explicitly** — e.g. on every scrape-adjacent tick or session close — to guarantee delivery and surface errors; the library arms no timers of its own. The destructor invokes `flush()` automatically, but everything it throws is silently swallowed in `__destruct` — a failed open, a short write, a failed fflush or fsync, a failed rename, or even an exposition-integrity refusal all vanish at shutdown (destructors must not throw, so nothing surfaces there). Explicit calls throw a `RuntimeException` on failure — a failed open of the staging path, a short write, a failed fflush or fsync, or a failed rename. `clear()` resets accumulated state but does not delete the already-written `.prom` file — the next `flush()` rewrites whatever remains.

### `MultiBackend`

Fan out to multiple backends — e.g. live StatsD plus a JSON audit trail. Constructing it with zero children throws.

```php
$reg = new Registry(new MultiBackend(
    new StatsdBackend(),
    new JsonStreamBackend('./metrics.jsonl'),
));
```

Error isolation: with the plain constructor the fan-out stops at the first failing child and that failure propagates unchanged. Build with `MultiBackend::withContinueOnError(...)` instead and every child gets a turn: all failures are collected and rethrown as one `MultiBackendException`, whose `errors()` method returns the collected `Throwable`s. The rule holds for *every* operation — emits, `remove()` and `clear()` alike: `remove()` / `clear()` propagate the first child failure by default and aggregate under `withContinueOnError`.

## SugarSshServer session middleware

Wires session telemetry into a SugarSshServer stack:

```php
use SugarCraft\SshServer\Server;
use SugarCraft\Metrics\Registry;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Middleware\SessionMetrics;

$reg = new Registry(new PrometheusFileBackend('/var/lib/ssh/metrics.prom'));

Server::new()
    ->use(new SessionMetrics($reg))
    ->use(/* ... your stack ... */)
    ->serve();
```

Per session this emits:

| Metric                    | Type      | Tags                              |
|---------------------------|-----------|-----------------------------------|
| `ssh.session.connect`     | counter   | `user`, `term`                    |
| `ssh.session.duration`    | histogram | `user`, `term`                    |
| `ssh.session.error`       | counter   | `user`, `term`, `exception`       |

`user` and `term` are attacker-controllable, so they are normalised to `[A-Za-z0-9._:@/-]` (everything else collapses to `_`) and clamped to 64 bytes. The `exception` tag carries the throwable class name normalised the same way (namespace backslashes fold to `_`); it is assigned *after* `extraTags` merge, so an `extraTags` closure can never shadow it. Values you add via `extraTags` are otherwise passed through unmodified — sanitise them yourself if they can contain hostile input.

Pass `extraTags` (a callable receiving the `Session`) to add things like client subnet, geo, build version.

## Localization

Every exception message in this library is looked up through `SugarCraft\Core\I18n\Lang` (the metrics namespace facade), so failures render in the operator's locale. The *lookup* flows through `Lang`; the *selection* lives on `T`, and the library never sets the locale itself — out of the box every message renders from `lang/en.php`. Select one at startup:

```php
\SugarCraft\Core\I18n\T::setLocale('de');   // messages arrive in German
```

`T::detect()` (reads `LC_ALL`, `LC_MESSAGES`, `LANG`) is the opt-in alternative; prefer pinning the locale per service over detecting it.

Sixteen locales ship: `ar`, `cs`, `de`, `en`, `es`, `fr`, `it`, `ja`, `ko`, `nl`, `pl`, `pt`, `pt-br`, `ru`, `tr`, `zh-cn`. Locale parity is pinned by the test suite.

## Status

Feature-complete and in production use: UpDownCounter, AsyncCounter and AsyncGauge instrument kinds, Registry cardinality tracking with FIFO eviction and backend reclaim, exposition-integrity guards, fsync-before-rename durability, and five backends — `InMemory`, `JsonStream`, `Statsd`, `PrometheusFile` and `Multi`. 234 tests (1,086 assertions; ≈4 s baseline suite: Registry, each backend, SessionMetrics middleware, wire-format and tag-key collision pins, hex-level forgery pins, i18n locale parity across all 16 shipped locales, and example smoke checks).

## Breaking changes

**`MultiBackendException::getErrors()` → `errors()`.** The accessor now follows
the library's bare-accessor convention (no `get` prefix). A repo-wide grep
found no call sites outside sugar-metrics itself, so the rename landed complete
in one pass; external code still calling `getErrors()` must switch to
`errors()`.

**Wire-integrity hardening (2026-11).** These were silent-corruption or
silent-loss behaviours; they are now loud:

- StatsD lines: colons in metric *names* are flattened to `_` (they forged the
  value/type separator), and `,`, space, pipe, control bytes and the C1 range
  are swept out of every wire token — attacker-influenced tag values can no
  longer forge pairs, fields or datagram lines.
- `PrometheusFileBackend` refuses, at write time, two distinct raw names/tag
  keys whose sanitising fold would collide (previously one silently overwrote
  the other, or the fold produced a duplicate label that made the *entire*
  exposition scrapable-nothing); one metric name used as two kinds — a
  histogram and any other kind sharing the base name, even under different
  label sets — and duplicate emitted series (a metric name clashing with a
  histogram's derived `_count`/`_sum`, or the old silent `newUpDownCounter`
  tag-drop replaying as a re-type) now throw at flush instead of poisoning the
  file.
- Histograms reject a caller-supplied `le` tag; bucket-boundary lists must be
  finite and strictly increasing (validated in the constructor).
- `Registry` cardinality limits below 1 throw at construction; negative values
  on monotonic counters and histograms throw (gauges, up/down counters and
  async instruments still accept negatives); re-`register()` with a different
  type or help throws instead of first-write-wins.
- `Descriptor` constructor validates name charset, help text, type and label
  keys (previously six literal `throw`s with unvalidated input landing in the
  exposition).
- `MultiBackend` with zero children throws; `remove()` / `clear()` now honour
  the error mode (they previously swallowed child failures even in default
  mode).
- `JsonStreamBackend`: the `updowncounter` kind string is now
  `up_down_counter`, and an unencodable payload throws in loud mode instead of
  dropping the record.
- `Registry::flush()` is public — long-lived textfile deployments should drive
  it (the library still never arms timers of its own).
- `observe()` on `AsyncCounter`/`AsyncGauge` takes optional per-call tags
  merged over the instrument's static tags.
- SessionMetrics metrics renamed `wish.session.*` → `ssh.session.*` (the wish
  lineage name predated the 2026-10 rename); `InMemoryBackend` gained a
  `maxSeries` cap knob; `StatsdBackend` async counters emit absolute `|g`
  instead of signed `|c` deltas (StatsD counters are monotonic — publishing a
  cumulative external reading as `|c` double-counted it).
