<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;

/**
 * UDP StatsD emitter (etsy / DogStatsD wire format).
 *
 * Each metric is one UDP datagram:
 *
 *   - counter:   `{name}:{value}|c`   (DogStatsD: + `|#tag:value,...`)
 *   - gauge:     `{name}:{value}|g`
 *   - histogram: `{name}:{value}|h`   (legacy etsy uses `|ms` for timers)
 *
 * Set `dogstatsd: false` to drop the `|#tag` segment for legacy
 * StatsD servers that reject it.
 *
 * Failed writes are silently dropped — telemetry that crashes
 * the host process is worse than missing telemetry. Pass a
 * `fopen('udp://host:port', 'w')` resource directly for full
 * control, or use the convenience constructor that opens the
 * socket for you.
 */
final class StatsdBackend implements Backend
{
    /** @var resource */
    private $sock;
    private bool $owns = false;

    /**
     * @param resource|null $existingSocket If provided, used directly (e.g. for tests).
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 8125,
        private readonly bool $dogstatsd = true,
        private readonly bool $failSilently = true,
        float $timeout = 1.0,
        $existingSocket = null,
    ) {
        if ($existingSocket !== null) {
            if (!is_resource($existingSocket)) {
                throw new \InvalidArgumentException(Lang::t('statsd.socket_not_resource'));
            }
            $this->sock = $existingSocket;
            return;
        }
        $sock = @fsockopen("udp://{$host}", $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            throw new \RuntimeException(Lang::t('statsd.connect_failed', ['errstr' => (string) $errstr, 'errno' => (string) $errno]));
        }
        $this->sock = $sock;
        $this->owns = true;
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
    public function asyncCounter(string $name, float $value, array $tags = []): void      { $this->send($name, $value, 'c', $tags); }
    public function asyncGauge(string $name, float $value, array $tags = []): void        { $this->send($name, $value, 'g', $tags); }

    // StatsD / DogStatsD has no concept of pre-emitted TYPE/HELP metadata.
    public function describe(Descriptor $descriptor): void
    {
        // No-op: StatsD wire format does not support TYPE/HELP declarations.
    }

    // StatsD emits immediately via UDP; flush ensures the socket buffer is drained.
    public function flush(): void
    {
        if (is_resource($this->sock)) {
            fflush($this->sock);
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
        $line = $name . ':' . $valueStr . '|' . $kind;
        if ($tags !== [] && $this->dogstatsd) {
            $parts = [];
            foreach ($tags as $k => $v) {
                $parts[] = $k . ':' . $v;
            }
            $line .= '|#' . implode(',', $parts);
        }
        if ($this->failSilently) {
            @fwrite($this->sock, $line);
        } else {
            fwrite($this->sock, $line);
        }
    }

    private static function fmt(float $v): string
    {
        if ($v === floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }
}
