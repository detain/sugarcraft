<?php

declare(strict_types=1);

namespace CandyCore\Wish\Middleware;

use CandyCore\Wish\Middleware;
use CandyCore\Wish\Session;

/**
 * Per-IP connection rate-limiter using a token-bucket persisted to
 * a file. Each unique `Session::$clientHost` gets its own bucket;
 * a bucket starts full at `$burst` tokens and refills at
 * `$ratePerSec` tokens/second.
 *
 * Each connect attempt costs one token. When a bucket is empty we
 * reject the session with a one-line message on stderr and don't
 * invoke `$next`.
 *
 * The persistence file is a JSON map of `{ ip: [tokens, last_ts] }`
 * — `flock(LOCK_EX)` serialises concurrent updates from sibling
 * sshd-spawned processes. The file is rewritten in place each
 * connect; for high-volume deployments swap in Redis (this class
 * is intentionally dependency-light).
 */
final class RateLimit implements Middleware
{
    /** @var resource */
    private $stderr;

    /**
     * @param string         $statePath  File the bucket map lives in
     * @param int            $burst      Max simultaneous tokens
     * @param float          $ratePerSec Refill rate
     * @param resource|null  $stderr
     */
    public function __construct(
        private readonly string $statePath,
        private readonly int    $burst = 5,
        private readonly float  $ratePerSec = 0.5,
        $stderr = null,
    ) {
        if ($stderr === null) {
            $stream = fopen('php://stderr', 'w');
            if ($stream === false) {
                throw new \RuntimeException('cannot open php://stderr');
            }
            $this->stderr = $stream;
            return;
        }
        if (!is_resource($stderr)) {
            throw new \InvalidArgumentException('stderr must be a resource');
        }
        $this->stderr = $stderr;
    }

    public function handle(Session $session, callable $next): void
    {
        $key = $session->clientHost === '' ? '_unknown' : $session->clientHost;
        if (!$this->take($key)) {
            fwrite($this->stderr, "Rate limit exceeded. Try again later.\n");
            return;
        }
        $next($session);
    }

    private function take(string $key): bool
    {
        $fh = fopen($this->statePath, 'c+');
        if ($fh === false) {
            // If we can't open the state file we err open rather than
            // locking everyone out.
            return true;
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
            $now = microtime(true);
            $entry = $data[$key] ?? ['tokens' => $this->burst, 'last' => $now];
            $tokens = (float) $entry['tokens'];
            $last   = (float) $entry['last'];
            $tokens = min($this->burst, $tokens + ($now - $last) * $this->ratePerSec);
            if ($tokens < 1.0) {
                $data[$key] = ['tokens' => $tokens, 'last' => $now];
                $this->writeBack($fh, $data);
                return false;
            }
            $tokens -= 1.0;
            $data[$key] = ['tokens' => $tokens, 'last' => $now];
            $this->writeBack($fh, $data);
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param resource             $fh
     * @param array<string,array{tokens:float,last:float}> $data
     */
    private function writeBack($fh, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $payload);
    }
}
