<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The docker engine's container list over its unix socket — btop PR #1873
 * `Ctr::docker_containers()`: one `GET /containers/json` (HTTP/1.0, so
 * the reply is unchunked and the daemon closes the connection), bounded
 * by a 1 s timeout, `DOCKER_HOST` honoured when it is a `unix://` path.
 *
 * No child process: a stream socket only. Everything degrades to "": a
 * missing or unreadable socket (the common non-root case), a daemon that
 * does not answer in time, a path too long for `sun_path`. The read is
 * also capped at {@see MAX_BYTES}, which btop does not do — the reply is
 * parsed in memory on the loop thread.
 */
final class DockerSocket
{
    public const DEFAULT_PATH = '/var/run/docker.sock';

    /** btop's SO_RCVTIMEO / SO_SNDTIMEO, used here as the whole exchange's deadline. */
    public const TIMEOUT_SEC = 1.0;

    /** sizeof(sockaddr_un::sun_path) on Linux; btop refuses a path that does not fit. */
    public const SUN_PATH = 108;

    public const MAX_BYTES = 8 * 1024 * 1024;

    private const REQUEST = "GET /containers/json HTTP/1.0\r\nHost: docker\r\n\r\n";

    private function __construct()
    {
    }

    /** The socket path: `DOCKER_HOST` minus `unix://` when it is one, else the default. */
    public static function path(?string $dockerHost): string
    {
        return $dockerHost !== null && str_starts_with($dockerHost, 'unix://') ? substr($dockerHost, 7) : self::DEFAULT_PATH;
    }

    /** The raw HTTP reply, or "" when the socket is not reachable. */
    public static function fetch(?string $path = null): string
    {
        $env = getenv('DOCKER_HOST');
        $path ??= self::path($env === false ? null : $env);
        if ($path === '' || \strlen($path) >= self::SUN_PATH || !file_exists($path)) {
            return '';
        }
        // One deadline for the whole exchange, connect included: btop's
        // per-call SO_RCVTIMEO would let a daemon trickling a byte every
        // 0.9 s hold the loop thread indefinitely.
        $deadline = hrtime(true) + (int) (self::TIMEOUT_SEC * 1e9);
        $socket = @stream_socket_client('unix://' . $path, $errno, $error, self::TIMEOUT_SEC);
        if ($socket === false) {
            return '';
        }
        $response = '';
        try {
            if (!self::armed($socket, $deadline) || @fwrite($socket, self::REQUEST) !== \strlen(self::REQUEST)) {
                return '';
            }
            while (!feof($socket) && \strlen($response) < self::MAX_BYTES && self::armed($socket, $deadline)) {
                $chunk = @fread($socket, 8192);
                if ($chunk === false || ($chunk === '' && (stream_get_meta_data($socket)['timed_out'] ?? false))) {
                    break;
                }
                $response .= $chunk;
            }
        } finally {
            fclose($socket);
        }

        return $response;
    }

    /**
     * Give the next socket call only the time left before `$deadline`
     * (hrtime ns); false once it has passed.
     *
     * @param resource $socket
     */
    private static function armed($socket, int $deadline): bool
    {
        $left = intdiv($deadline - hrtime(true), 1000);
        if ($left <= 0) {
            return false;
        }

        return stream_set_timeout($socket, intdiv($left, 1_000_000), max(1, $left % 1_000_000));
    }

    /**
     * The name of the container whose id starts with `$id` in a
     * `/containers/json` reply, or "" — btop PR #1873 `Ctr::docker_name`,
     * byte for byte: the entry must read `{"Id":"<64 hex>","Names":["/<name>"`
     * and the name may use only docker's own alphabet (it reaches the
     * terminal).
     */
    public static function name(string $response, string $id): string
    {
        $idKey = '"Id":"';
        $namesKey = '","Names":["/';
        if ($id === '') {
            return '';
        }
        $pos = strpos($response, $idKey . $id);
        if ($pos === false) {
            return '';
        }
        $pos += \strlen($idKey) + 64;
        if ($pos + \strlen($namesKey) > \strlen($response) || substr($response, $pos, \strlen($namesKey)) !== $namesKey) {
            return '';
        }
        $pos += \strlen($namesKey);
        $end = strpos($response, '"', $pos);
        $name = $end === false ? substr($response, $pos) : substr($response, $pos, $end - $pos);

        return preg_match('/^[A-Za-z0-9_.-]+$/D', $name) === 1 ? $name : '';
    }
}
