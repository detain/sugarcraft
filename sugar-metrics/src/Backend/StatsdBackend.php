<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Util;

/**
 * Mirrors charmbracelet/promwish statsd reporter family (the
 * WithStatsd option and its wire formatter).
 *
 * UDP StatsD emitter (etsy / DogStatsD wire format).
 *
 * Each metric is one UDP datagram:
 *
 *   - counter:   `{name}:{value}|c`   (DogStatsD: + `|#tag:value,...`)
 *   - gauge:     `{name}:{value}|g`
 *   - histogram: `{name}:{value}|h`   (legacy etsy uses `|ms` for timers)
 *
 * Set `dogstatsd: false` to drop the `|#tag` segment for legacy
 * StatsD servers that reject it — legacy mode DROPS every tag
 * (StatsD has no label concept; nothing is flattened into the
 * name), so prefer DogStatsD when dimensions matter.
 *
 * `failSilently: true` (the default) drops failed writes — telemetry
 * that crashes the host process is worse than missing telemetry.
 * With `failSilently: false` a short or failed fwrite throws a
 * RuntimeException naming the endpoint. The socket is opened by the
 * constructor itself (pass `existingSocket` for full control).
 *
 * @phpstan-type SocketType resource
 */
final class StatsdBackend implements Backend
{
    /** Bounded-retention ceiling for the two per-token fold caches below (sugar-metrics-015). */
    private const WIRE_MEMO_LIMIT = 1024;

    /** @var resource */
    private $sock;
    private bool $owns = false;

    /** @var array<string,string> raw metric name → wireName fold (sugar-metrics-015; see sendRaw for the retention note). */
    private array $wireNames = [];

    /** @var array<string,string> raw tag key → wireTagKey fold (sugar-metrics-015). */
    private array $wireTagKeys = [];

    /** `host:port` (or `resource` for injected sockets) — appears in refusal messages so the operator can locate the dead sink. */
    private readonly string $endpointLabel;

    /**
     * @param resource|null $existingSocket If provided, used directly (e.g. for tests).
     * @param float $timeout Bounds the socket CONNECT only. There is no UDP
     *        handshake, and PHP applies this timeout to connect(), not to the
     *        blocking gethostbyname() beneath a named host — a blackholed
     *        resolver can stall construction past this bound (sugar-metrics-064).
     *        Hard-wall deployments pass an IP literal or a pre-resolved
     *        $existingSocket.
     */
    /**
     * House-idiom factory (sugar-metrics-014): `StatsdBackend::new()`.
     */
    public static function new(
        string $host = '127.0.0.1',
        int $port = 8125,
        bool $dogstatsd = true,
        bool $failSilently = true,
        float $timeout = 1.0,
        mixed $existingSocket = null,
    ): self {
        return new self($host, $port, $dogstatsd, $failSilently, $timeout, $existingSocket);
    }

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 8125,
        private readonly bool $dogstatsd = true,
        private readonly bool $failSilently = true,
        float $timeout = 1.0,
        mixed $existingSocket = null,
    ) {
        if ($existingSocket !== null) {
            if (is_resource($existingSocket) === false) {
                throw new \InvalidArgumentException(Lang::t('statsd.socket_not_resource'));
            }
            $this->sock = $existingSocket;
            $this->endpointLabel = 'resource#' . (string) intval($existingSocket);
            return;
        }
        $sock = @fsockopen("udp://{$host}", $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            throw new \RuntimeException(Lang::t('statsd.connect_failed', [
                'errstr'   => Util::displaySafe((string) $errstr),
                'errno'    => (string) $errno,
                'endpoint' => "{$host}:{$port}",
            ]));
        }
        $this->sock = $sock;
        $this->owns = true;
        $this->endpointLabel = "{$host}:{$port}";
    }

    public function __destruct()
    {
        if ($this->owns && is_resource($this->sock)) {
            fclose($this->sock);
        }
    }

    public function counter(string $name, float $value, array $tags = []): void       { $this->send($name, $value, 'c', $tags); }
    public function gauge(string $name, float $value, array $tags = []): void         { $this->send($name, $value, 'g', $tags); }
    public function histogram(string $name, float $value, array $tags = []): void       { $this->send($name, $value, 'h', $tags); }
    public function upDownCounter(string $name, float $amount, array $tags = []): void
    {
        // StatsD up/down counters require signed-delta gauge format: name:+1|g / name:-1|g
        // A bare-value gauge would SET the value, not increment/decrement it.
        $signed = ($amount >= 0 ? '+' : '') . self::fmt($amount);
        $this->sendRaw($name, $signed, 'g', $tags);
    }
    public function asyncCounter(string $name, float $value, array $tags = []): void
    {
        // The callback reading is CUMULATIVE TOTALS, but a `|c` datagram is
        // a DELTA every collector sums — re-sending a total as a delta grows
        // the seen counter quadratically. StatsD has no absolute-counter
        // token, so the total rides as a gauge SET (the same compromise the
        // file/JSON backends express by storing the reading directly).
        $this->send($name, $value, 'g', $tags);
    }

    public function asyncGauge(string $name, float $value, array $tags = []): void        { $this->send($name, $value, 'g', $tags); }

    // StatsD / DogStatsD has no concept of pre-emitted TYPE/HELP metadata.
    public function describe(Descriptor $descriptor): void
    {
        // No-op: StatsD wire format does not support TYPE/HELP declarations.
    }

    // UDP streams are unbuffered: fflush() here is a deliberate no-op on the
    // wire (sugar-metrics-064), kept to honor the Backend contract and to
    // mirror the remove()/describe() no-op honesty. The throw below is an
    // unreachable-but-loud safety net (sugar-metrics-041): no stream
    // reachable through the public API fails fflush() while is_resource()
    // stays true, so fwrite() already owns every real send failure. It is
    // pinned by StatsdBackendTest flush probes on closed sockets.
    public function flush(): void
    {
        if (is_resource($this->sock) && @fflush($this->sock) === false && $this->failSilently === false) {
            throw new \RuntimeException(Lang::t('statsd.write_failed', ['endpoint' => $this->endpointLabel]));
        }
    }

    /**
     * StatsD is fire-and-forget; metrics cannot be removed once sent.
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void
    {
        // No-op: UDP datagrams are already on the wire.
    }

    /**
     * StatsD is fire-and-forget; the only retained state is the bounded
     * per-token fold cache (sugar-metrics-015), dropped here with the call.
     */
    public function clear(): void
    {
        $this->wireNames = [];
        $this->wireTagKeys = [];
    }

    /**
     * @param array<string,string> $tags
     */
    private function send(string $name, float $value, string $kind, array $tags): void
    {
        $this->sendRaw($name, self::fmt($value), $kind, $tags);
    }

    /**
     * @param array<string,string> $tags
     */
    private function sendRaw(string $name, string $valueStr, string $kind, array $tags): void
    {
        // StatsD's wire grammar is line-oriented and field-separated: a
        // control byte in any token forges extra lines, a space ends a
        // packet on classic collectors, a `|` smuggles values into
        // neighbouring fields, a `,` forges an extra DogStatsD tag pair, and
        // a `:` inside the metric name or a tag key re-splits what follows
        // it. Every token reaching the socket is flattened at this one
        // chokepoint, so hostile tag payloads can never split or fake a
        // packet — for line framing the sweep covers both byte-level new
        // lines and the Unicode LINE/PARAGRAPH SEPARATORS a UTF-8-decoding
        // line splitter would honour (063). The sweep is argued complete
        // against the four grammars a Datadog-tagged agent dispatches on
        // this port: plain metric lines (`{name}:{value}|{kind}`, colon
        // folded in names and keys), DogStatsD tag segments (`#k:v,...`,
        // comma and pipe folded), the event grammar `_e{<l1>,<l2>}:...` and
        // the service-check grammar `_sc{<check>}:<status>|...` — the last
        // two are prefix-dispatched and need no banned byte, so braces fold
        // in wireName (052). See wireToken()/wireName()/wireTagKey() for
        // the exact per-position separator alphabets.
        $line = ($this->wireNames[$name] ??= self::wireName($name)) . ':' . self::wireToken($valueStr) . '|' . $kind;
        if ($tags !== [] && $this->dogstatsd) {
            // sugar-metrics-015: the name and tag-KEY sweeps are pure
            // per-token folds and memoized; the VALUE sweep rides every
            // emit because values vary and are unbounded. This backend was
            // fire-and-forget, so the two caches are its only retained
            // state — bounded by WIRE_MEMO_LIMIT, dropped wholesale when
            // full (the folds are deterministic, so eviction can never
            // change a byte on the wire).
            $parts = [];
            foreach ($tags as $k => $v) {
                $parts[] = ($this->wireTagKeys[$k] ??= self::wireTagKey($k)) . ':' . self::wireToken($v);
            }
            $line .= '|#' . implode(',', $parts);
        }
        if (count($this->wireNames) > self::WIRE_MEMO_LIMIT || count($this->wireTagKeys) > self::WIRE_MEMO_LIMIT) {
            $this->wireNames = [];
            $this->wireTagKeys = [];
        }
        $bytes = strlen($line);
        if ($this->failSilently) {
            @fwrite($this->sock, $line);
            return;
        }
        // The loud mode must actually be loud: a bare fwrite whose return is
        // discarded is byte-identical to the silent branch. fwrite() returns
        // the byte count written (0 on a dead socket) or false.
        $written = @fwrite($this->sock, $line);
        if ($written === false || $written < $bytes) {
            throw new \RuntimeException(Lang::t('statsd.write_failed', [
                'endpoint' => $this->endpointLabel,
            ]));
        }
    }

    /**
     * Flatten a metric NAME. On top of the general wire-token sweep, the
     * name may not contain `:` — that byte separates the name from the
     * value in `{name}:{value}|{kind}`, so a colon inside a hostile name
     * makes a first-colon collector re-key the packet (splitting
     * `ev_il:x_steal` misparses the value). Colons are legal in Prometheus
     * recording-rule names, so this is a wire-format rule, not a charset
     * rule; the file backend keeps colons.
     *
     * Braces fold here too (sugar-metrics-052): DogStatsD agents
     * demultiplex the statsd port BY LINE PREFIX, and the service-check
     * grammar `_sc{<check>}:<status>|...` needs no byte the sweep bans —
     * a raw-brace name `gauge('_sc{dead.mans.switch}', 0.0)` used to emit
     * the exact bytes of a parseable OK-service-check with a fully
     * attacker-influenced name (gauge values 0-3 reach all four statuses).
     * Folding `{`/`}` kills the dispatch prefix at the source; the event
     * grammar `_e{<l1>,<l2>}:...` was previously defeated only by the
     * incidental comma fold, and is now closed by braces directly. Braces
     * are not legal in Prometheus names and meaningless in names on this
     * wire, so every well-formed emission stays byte-identical. Tag
     * positions keep braces raw (harmless inside the `#k:v` grammar).
     */
    private static function wireName(string $name): string
    {
        return \str_replace([':', '{', '}'], '_', self::wireToken($name));
    }

    /**
     * Flatten a wire token: control bytes (NUL-US, DEL), the C1 codepoints
     * U+0080-U+009F, the field separator `|`, the DogStatsD tag-pair
     * separator `,`, the classic StatsD packet separator SPACE and the
     * Unicode LINE/PARAGRAPH SEPARATORS U+2028/U+2029 all become `_`.
     * Ordinary letters/digits/punctuation the grammar has no use for are
     * untouched, so well-formed payloads stay byte-identical.
     *
     * The sweep is BYTE-oriented (no /u): a byte class `[\x80-\x9F]` would
     * shred valid UTF-8 (emoji `U+1F600` = F0 9F 98 80 contains 9F), so C1
     * is matched in its UTF-8 encoding `C2 80..9F` instead — the only byte
     * sequence that legitimately denotes a C1 codepoint. C0/DEL bytes can
     * never appear inside a valid multi-byte sequence (every continuation
     * byte is >= 0x80), so matching them raw is lossless for UTF-8 text.
     * U+2028/U+2029 join the sweep by the same argument (sugar-metrics-063):
     * their canonical spellings `E2 80 A8`/`E2 80 A9` are matched whole, so
     * a collector that decodes UTF-8 and then splits lines (Python
     * `str.splitlines()`, several JVM/JS stacks) still sees exactly one
     * packet per datagram. High bytes outside these named grammars keep
     * their raw bytes — inert to the Go datagram-per-metric agent, which
     * frames on the datagram boundary, not on content bytes.
     */
    private static function wireToken(string $token): string
    {
        return (string) \preg_replace('/[\x00-\x1F\x7F|, ]|\xC2[\x80-\x9F]|\xE2\x80[\xA8\xA9]/', '_', $token);
    }

    /**
     * Flatten a DogStatsD tag KEY. On top of the general wire tokens, a key
     * may not contain `:` — the tag segment's own key/value separator. Left
     * raw, `['a:b' => 'v']` would ride the wire as `a:b:v` and a collector
     * splitting on the first colon would file it under key `a` with value
     * `b:v`, silently re-keying the series. Tag VALUES keep their colons:
     * the first colon of the pair already owns the separator position, so a
     * later colon cannot forge a new pair.
     */
    private static function wireTagKey(string $key): string
    {
        return \str_replace(':', '_', self::wireToken($key));
    }

    private static function fmt(float $v): string
    {
        // sprintf('%.6f', -INF) is "INF" — the sign silently flips, so a
        // falling counter reads as rising. Spell non-finite explicitly.
        $special = Util::nonFiniteToken($v);
        if ($special !== null) {
            return $special;
        }
        if ($v === floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        // %.6f collapses sub-microsecond durations to "0.000000" and prints
        // six digits of noise past 1e15; switch to exponent form there.
        if (Util::needsExponentForm($v)) {
            return self::formatDecimal($v, '%.6e');
        }
        return rtrim(rtrim(self::formatDecimal($v, '%.6f'), '0'), '.');
    }

    /**
     * sprintf's %f/%e inheritance of LC_NUMERIC: a comma-decimal locale
     * would render '1,500000' and the trailing-zero rtrim would hand
     * wireToken a literal comma to fold into '_' — a mutated sample value
     * on the wire. The exposition doctrine (sugar-metrics-030) is one true
     * decimal point at every value chokepoint, line backends included.
     */
    private static function formatDecimal(float $v, string $format): string
    {
        return self::normalizeDecimalPoint(
            sprintf($format, $v),
            (string) (localeconv()['decimal_point'] ?? '')
        );
    }

    /** Pure: rewrite $rendered's foreign decimal separator to '.'. */
    private static function normalizeDecimalPoint(string $rendered, string $point): string
    {
        if ($point === '' || $point === '.') {
            return $rendered;
        }
        // %.6f/%.6e carry no thousands grouping, so the separator is the
        // only locale glyph in the render; str_replace is belt-and-cover
        // (a single occurrence either way).
        return \str_replace($point, '.', $rendered);
    }
}
