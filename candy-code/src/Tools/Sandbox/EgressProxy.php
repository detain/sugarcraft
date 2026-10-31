<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ServerInterface;
use React\Socket\SocketServer;
use Throwable;

/**
 * The host-side egress proxy served on a private unix socket.
 *
 * `serve()` binds a {@see SocketServer} on `unix://<path>` (the caller's
 * directory is expected to be 0700; the socket file itself is tightened to
 * 0600 the moment it exists) and speaks just enough HTTP for a sandboxed
 * command's proxy variables: `CONNECT host:port` for tunnelled protocols and
 * absolute-form `GET http://host/path` for plain HTTP, the two shapes
 * curl/wget/uv/pip actually send to a proxy.
 *
 * Every target goes through {@see EgressPolicy::vet()} and the proxy dials
 * the vetted IP literal, never the name — the jail-side resolver cannot
 * retarget a granted hostname between policy check and connect. A refused
 * target answers 403 with a body naming the host and the
 * `sandboxAllowedDomains` setting, so the model sees WHY egress stopped
 * rather than a bare connection reset.
 *
 * Time discipline (E646): only the upstream dial carries a timeout — a
 * connect ceiling, not a total-request bound — and tunnels are reaped when
 * IDLE, never by a wall on the whole exchange, so a slow legitimate download
 * survives while a forgotten-open pipe eventually closes. The proxy process
 * itself exits when its parent's stdin pipe closes or the parent pid changes,
 * so the socket can never outlive the launch that created it.
 */
final class EgressProxy
{
    /**
     * Bytes of request head accepted before the request line is judged bogus.
     */
    public const HEAD_CAP_BYTES = 65536;

    /**
     * Connect ceiling for the upstream dial only (E646: never a total-request
     * bound — an established tunnel is relayed until it goes idle).
     */
    public const CONNECT_TIMEOUT_SECONDS = 10.0;

    /**
     * Seconds a tunnel may carry no traffic either way before it is reaped.
     */
    public const IDLE_TIMEOUT_SECONDS = 300.0;

    /**
     * Interval of the idle-reap / liveness sweep.
     */
    public const SWEEP_INTERVAL_SECONDS = 1.0;

    /**
     * Serves the proxy on $socketPath until the loop stops (liveness loss,
     * SIGTERM, or the caller stopping the injected loop).
     */
    public static function serve(string $socketPath, array $entries, ?LoopInterface $loop = null): int
    {
        return self::servePolicy($socketPath, new EgressPolicy($entries), $loop);
    }

    /**
     * Serve seam with a pre-built policy — tests inject a fake resolver here
     * without touching the {@see EgressPolicy} construction defaults.
     */
    public static function servePolicy(string $socketPath, EgressPolicy $policy, ?LoopInterface $loop = null): int
    {
        $loop ??= Loop::get();
        $server = new SocketServer('unix://' . $socketPath, [], $loop);
        @chmod($socketPath, 0600);

        $closing = false;
        $close = static function () use (&$closing): void {
            $closing = true;
        };

        $server->on('connection', static function (ConnectionInterface $connection) use ($policy, $loop): void {
            self::handle($connection, $policy, $loop);
        });

        $parentPid = function_exists('posix_getppid') ? posix_getppid() : 0;
        $stdin = defined('STDIN') ? STDIN : null;
        if (is_resource($stdin)) {
            @stream_set_blocking($stdin, false);
        }

        $timer = null;
        $timer = $loop->addPeriodicTimer(self::SWEEP_INTERVAL_SECONDS, static function () use ($loop, &$closing, &$timer, $server, $socketPath, $stdin, $parentPid): void {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if (!$closing && is_resource($stdin)) {
                $chunk = @fread($stdin, 64);
                if ($chunk === '' && feof($stdin)) {
                    $closing = true;
                }
            }
            if (!$closing && function_exists('posix_getppid') && posix_getppid() !== $parentPid) {
                $closing = true;
            }
            if (!$closing) {
                return;
            }
            $loop->cancelTimer($timer);
            $server->close();
            @unlink($socketPath);
            $loop->stop();
        });

        if (function_exists('pcntl_signal')) {
            @pcntl_signal(SIGTERM, $close);
            @pcntl_signal(SIGHUP, $close);
        }

        $loop->run();
        return 0;
    }

    /**
     * Reads one request head from a client connection, vets the target and
     * either wires a tunnel or answers the refusal.
     */
    private static function handle(ConnectionInterface $connection, EgressPolicy $policy, LoopInterface $loop): void
    {
        $head = '';
        $established = false;
        $upstream = null;
        $pending = '';
        $clientClosed = false;

        $fail = static function (int $status, string $reason) use ($connection): void {
            // end(), never write()+close(): react buffers writes in userspace
            // until the loop's next tick, and close() discards that buffer —
            // a refusal written and closed in the same tick would never
            // reach the client (measured: peer got zero bytes).
            $connection->end(self::buildResponse($status, $reason));
        };

        $onData = null;
        $onData = static function (string $chunk) use (
            $connection,
            $policy,
            $loop,
            &$head,
            &$established,
            &$upstream,
            &$pending,
            &$onData,
            $fail
        ): void {
            if ($established) {
                // Dial still in flight (the bridge installs its own relay and
                // detaches this listener the moment it lands): buffer so no
                // tunnel byte is lost or double-forwarded.
                $pending .= $chunk;
                return;
            }
            $head .= $chunk;
            if (strlen($head) > self::HEAD_CAP_BYTES) {
                $fail(400, 'Proxy request header exceeds ' . self::HEAD_CAP_BYTES . ' bytes.');
                return;
            }
            $end = self::headEnd($head);
            if ($end === null) {
                return;
            }
            $target = self::parseTarget(substr($head, 0, $end));
            if ($target === null) {
                $fail(400, 'Unsupported proxy request line; expected CONNECT host:port or an absolute http:// URL.');
                return;
            }
            $address = $policy->vet($target['host'], $target['port']);
            if ($address === null) {
                $fail(
                    403,
                    'Host "' . $target['host'] . '" is not in the sandboxAllowedDomains egress allow-list'
                    . ' (port ' . $target['port'] . '). Add it to sandboxAllowedDomains to allow this destination.',
                );
                return;
            }
            $established = true;
            $pending = substr($head, $end);
            $connector = new Connector(['timeout' => self::CONNECT_TIMEOUT_SECONDS], $loop);
            $connector
                ->connect('tcp://' . (str_contains($address, ':') ? '[' . $address . ']' : $address) . ':' . $target['port'])
                ->then(
                    static function (ConnectionInterface $dial) use ($connection, $target, $head, $end, &$upstream, &$pending, &$onData, &$clientClosed, $loop): void {
                        if ($clientClosed) {
                            // Client vanished before the dial landed.
                            $dial->close();
                            return;
                        }
                        if ($target['connect']) {
                            $connection->write("HTTP/1.1 200 Connection Established\r\n\r\n");
                        } else {
                            $dial->write(self::rewriteToOriginForm($head, $end));
                        }
                        $upstream = $dial;
                        if ($pending !== '') {
                            $dial->write($pending);
                            $pending = '';
                        }
                        self::bridge($connection, $dial, $loop);
                        $connection->removeListener('data', $onData);
                    },
                    static function (Throwable $error) use ($connection): void {
                        $connection->end(self::buildResponse(502, 'Upstream dial failed: ' . $error->getMessage()));
                    },
                );
        };
        $connection->on('data', $onData);

        $connection->on('close', static function () use (&$upstream, &$clientClosed): void {
            $clientClosed = true;
            if (is_object($upstream)) {
                $upstream->close();
            }
        });
    }

    /**
     * Byte offset just past the terminating blank line, or null while the
     * head is still arriving.
     */
    private static function headEnd(string $head): ?int
    {
        $crlf = strpos($head, "\r\n\r\n");
        $lf = strpos($head, "\n\n");
        if ($crlf === false && $lf === false) {
            return null;
        }
        if ($crlf === false) {
            return $lf + 2;
        }
        if ($lf === false || $crlf < $lf) {
            return $crlf + 4;
        }
        return $lf + 2;
    }

    /**
     * Extracts {host, port, connect} from the request line, or null when the
     * line is neither CONNECT nor absolute-form HTTP.
     *
     * @return array{host: string, port: int, connect: bool}|null
     */
    private static function parseTarget(string $requestLine): ?array
    {
        $parts = explode(' ', trim($requestLine));
        if (count($parts) < 2) {
            return null;
        }
        if (strtoupper($parts[0]) === 'CONNECT') {
            if (str_contains($parts[1], '://')) {
                return null;
            }
            [$host, $port] = self::splitHostPort($parts[1], 443);
            if ($host === '') {
                return null;
            }
            return ['host' => $host, 'port' => $port, 'connect' => true];
        }
        // Scheme door spelled as prefix comparisons: a '#^https?://#i' preg
        // literal is glob-shaped and would silently widen the PathGlob
        // census corpus (lane law: no wildcard-shaped string literals).
        $scheme = null;
        $schemeLen = 0;
        foreach (['https://' => 'https', 'http://' => 'http'] as $prefix => $name) {
            if (strncasecmp($parts[1], $prefix, strlen($prefix)) === 0) {
                $scheme = $name;
                $schemeLen = strlen($prefix);
                break;
            }
        }
        if ($scheme === null) {
            return null;
        }
        $rest = substr($parts[1], $schemeLen);
        $slash = strpos($rest, '/');
        $authority = $slash === false ? $rest : substr($rest, 0, $slash);
        if (str_contains($authority, '@')) {
            return null;
        }
        [$host, $port] = self::splitHostPort($authority, $scheme === 'https' ? 443 : 80);
        if ($host === '') {
            return null;
        }
        return ['host' => $host, 'port' => $port, 'connect' => false];
    }

    /**
     * Splits an authority into host and port, honouring IPv6 brackets; a
     * bracketed host without an explicit port dials the default.
     *
     * @return array{0: string, 1: int}
     */
    private static function splitHostPort(string $authority, int $defaultPort): array
    {
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            if ($close === false) {
                return ['', $defaultPort];
            }
            $host = substr($authority, 0, $close + 1);
            $tail = substr($authority, $close + 1);
            if ($tail === '') {
                return [$host, $defaultPort];
            }
            if (!str_starts_with($tail, ':') || !ctype_digit(substr($tail, 1))) {
                return ['', $defaultPort];
            }
            return [$host, (int) substr($tail, 1)];
        }
        $colon = strrpos($authority, ':');
        if ($colon === false) {
            return [$authority, $defaultPort];
        }
        $suffix = substr($authority, $colon + 1);
        if ($suffix === '' || !ctype_digit($suffix)) {
            return ['', $defaultPort];
        }
        $port = (int) $suffix;
        if ($port < 1 || $port > 65535) {
            return ['', $defaultPort];
        }
        return [substr($authority, 0, $colon), $port];
    }

    /**
     * Rewrites an absolute-form request line to origin-form so the upstream
     * server sees the request a plain HTTP client would have sent.
     */
    private static function rewriteToOriginForm(string $head, int $end): string
    {
        $headBytes = substr($head, 0, $end);
        $lines = explode("\r\n", rtrim($headBytes, "\r\n"));
        $parts = explode(' ', trim((string) $lines[0]), 3);
        $withoutScheme = $parts[1];
        // Prefix strip rather than a preg: a '#^https?://#i' literal is
        // glob-shaped and would silently widen the PathGlob census corpus.
        foreach (['https://', 'http://'] as $scheme) {
            if (strncasecmp($withoutScheme, $scheme, strlen($scheme)) === 0) {
                $withoutScheme = substr($withoutScheme, strlen($scheme));
                break;
            }
        }
        $slash = strpos($withoutScheme, '/');
        $path = $slash === false ? '/' : substr($withoutScheme, $slash);
        $lines[0] = $parts[0] . ' ' . $path . ' ' . ($parts[2] ?? 'HTTP/1.1');
        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    /**
     * Bidirectional relay with backpressure, idle reaping and close
     * propagation. No total-time bound anywhere (E646).
     */
    private static function bridge(ConnectionInterface $down, ConnectionInterface $up, LoopInterface $loop): void
    {
        $idle = [microtime(true)];
        $drainTimer = null;

        $flush = static function () use (&$idle): void {
            $idle[0] = microtime(true);
        };

        $link = static function (ConnectionInterface $from, ConnectionInterface $to) use ($flush): void {
            $from->on('data', static function (string $chunk) use ($from, $to, $flush): void {
                $flush();
                if ($to->write($chunk) === false) {
                    $from->pause();
                }
            });
            $to->on('drain', static function () use ($from): void {
                $from->resume();
            });
        };

        $link($down, $up);
        $link($up, $down);

        $teardown = static function () use (&$drainTimer, $loop): void {
            if ($drainTimer !== null) {
                $loop->cancelTimer($drainTimer);
                $drainTimer = null;
            }
        };

        $down->on('close', static function () use ($up, $teardown): void {
            $teardown();
            // end() over close(): queued bytes (e.g. the final tunnel chunk)
            // flush and FIN gracefully instead of being discarded.
            $up->end();
        });
        $up->on('close', static function () use ($down, $teardown): void {
            $teardown();
            $down->end();
        });

        $drainTimer = $loop->addPeriodicTimer(self::SWEEP_INTERVAL_SECONDS, static function () use ($down, $up, $loop, &$idle, &$drainTimer): void {
            if (microtime(true) - $idle[0] <= self::IDLE_TIMEOUT_SECONDS) {
                return;
            }
            $loop->cancelTimer($drainTimer);
            $drainTimer = null;
            $down->close();
            $up->close();
        });
    }

    /**
     * Minimal text/plain proxy response with no keep-alive ambiguity.
     * Returned as one string so callers hand it to end() and the buffer
     * flushes before the FIN.
     */
    private static function buildResponse(int $status, string $reason): string
    {
        $label = match ($status) {
            403 => 'Forbidden',
            502 => 'Bad Gateway',
            default => 'Bad Request',
        };
        $body = $reason . "\n";
        return 'HTTP/1.1 ' . $status . ' ' . $label . "\r\n"
            . 'Content-Type: text/plain; charset=utf-8' . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . 'Connection: close' . "\r\n\r\n"
            . $body;
    }
}
