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
    /** @var resource */
    private $sock;
    private bool $owns = false;

    /** `host:port` (or `resource` for injected sockets) — appears in refusal messages so the operator can locate the dead sink. */
    private readonly string $endpointLabel;

    /**
     * @param resource|null $existingSocket If provided, used directly (e.g. for tests).
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

    // StatsD emits immediately via UDP; flush ensures the socket buffer is
    // drained. A failed flush is loud when the backend was built with
    // failSilently: false — same contract as the write path.
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
     * StatsD is fire-and-forget; there is nothing to clear.
     */
    public function clear(): void
    {
        // No-op: StatsD has no local state to reset.
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
        // packet. See wireToken()/wireName()/wireTagKey() for the exact
        // per-position separator alphabets.
        $line = self::wireName($name) . ':' . self::wireToken($valueStr) . '|' . $kind;
        if ($tags !== [] && $this->dogstatsd) {
            $parts = [];
            foreach ($tags as $k => $v) {
                $parts[] = self::wireTagKey($k) . ':' . self::wireToken($v);
            }
            $line .= '|#' . implode(',', $parts);
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
     */
    private static function wireName(string $name): string
    {
        return \str_replace(':', '_', self::wireToken($name));
    }

    /**
     * Flatten a wire token: control bytes (NUL-US, DEL), the C1 codepoints
     * U+0080-U+009F, the field separator `|`, the DogStatsD tag-pair
     * separator `,` and the classic StatsD packet separator SPACE all
     * become `_`. Ordinary letters/digits/punctuation the grammar has no
     * use for are untouched, so well-formed payloads stay byte-identical.
     *
     * The sweep is BYTE-oriented (no /u): a byte class `[\x80-\x9F]` would
     * shred valid UTF-8 (emoji `U+1F600` = F0 9F 98 80 contains 9F), so C1
     * is matched in its UTF-8 encoding `C2 80..9F` instead — the only byte
     * sequence that legitimately denotes a C1 codepoint. C0/DEL bytes can
     * never appear inside a valid multi-byte sequence (every continuation
     * byte is >= 0x80), so matching them raw is lossless for UTF-8 text
     * and still refuses hostile raw streams (which simply keep their
     * stray high bytes — harmless to the grammar).
     */
    private static function wireToken(string $token): string
    {
        return (string) \preg_replace('/[\x00-\x1F\x7F|, ]|\xC2[\x80-\x9F]/', '_', $token);
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
            return sprintf('%.6e', $v);
        }
        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }
}
