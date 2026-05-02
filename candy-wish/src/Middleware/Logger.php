<?php

declare(strict_types=1);

namespace CandyCore\Wish\Middleware;

use CandyCore\Wish\Middleware;
use CandyCore\Wish\Session;

/**
 * Connect / disconnect logger.
 *
 * Writes a one-line JSON record at session start and another at
 * session end (with elapsed seconds). The log target is a file
 * path or a writable resource — the default `php://stderr` is
 * what `sshd` captures into the system journal automatically, so
 * production deployments can leave it as-is and read connection
 * history with `journalctl -u sshd`.
 */
final class Logger implements Middleware
{
    /** @var resource */
    private $stream;
    private bool $owns = false;

    /**
     * @param resource|string|null $target File path, open resource,
     *                                     or null for stderr.
     */
    public function __construct($target = null)
    {
        if ($target === null) {
            $stream = fopen('php://stderr', 'a');
            if ($stream === false) {
                throw new \RuntimeException('cannot open php://stderr');
            }
            $this->stream = $stream;
            $this->owns = true;
            return;
        }
        if (is_string($target)) {
            $stream = fopen($target, 'a');
            if ($stream === false) {
                throw new \RuntimeException("cannot open log target: {$target}");
            }
            $this->stream = $stream;
            $this->owns = true;
            return;
        }
        if (!is_resource($target)) {
            throw new \InvalidArgumentException('Logger target must be a path, resource, or null');
        }
        $this->stream = $target;
    }

    public function __destruct()
    {
        if ($this->owns && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function handle(Session $session, callable $next): void
    {
        $start = microtime(true);
        $this->write([
            'event' => 'session.start',
            'ts'    => date('c'),
            ...$session->toLogContext(),
        ]);

        try {
            $next($session);
        } finally {
            $this->write([
                'event'  => 'session.end',
                'ts'     => date('c'),
                'elapsed_s' => round(microtime(true) - $start, 3),
                ...$session->toLogContext(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $record
     */
    private function write(array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }
        fwrite($this->stream, $line . "\n");
    }
}
