<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Backend;

use CandyCore\Metrics\Backend;

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
        $existingSocket = null,
    ) {
        if ($existingSocket !== null) {
            if (!is_resource($existingSocket)) {
                throw new \InvalidArgumentException('existingSocket must be a resource');
            }
            $this->sock = $existingSocket;
            return;
        }
        $sock = @fsockopen("udp://{$host}", $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            throw new \RuntimeException("statsd connect failed: {$errstr} ({$errno})");
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

    public function counter(string $name, float $value, array $tags = []): void   { $this->send($name, $value, 'c', $tags); }
    public function gauge(string $name, float $value, array $tags = []): void     { $this->send($name, $value, 'g', $tags); }
    public function histogram(string $name, float $value, array $tags = []): void { $this->send($name, $value, 'h', $tags); }

    /**
     * @param array<string,string> $tags
     */
    private function send(string $name, float $value, string $kind, array $tags): void
    {
        $line = $name . ':' . self::fmt($value) . '|' . $kind;
        if ($tags !== [] && $this->dogstatsd) {
            $parts = [];
            foreach ($tags as $k => $v) {
                $parts[] = $k . ':' . $v;
            }
            $line .= '|#' . implode(',', $parts);
        }
        @fwrite($this->sock, $line);
    }

    private static function fmt(float $v): string
    {
        if ($v === floor($v) && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }
}
