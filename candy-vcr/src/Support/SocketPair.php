<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Support;

/**
 * The address family for `stream_socket_pair()` on this host.
 *
 * Windows PHP cannot pair AF_UNIX sockets: `stream_socket_pair(STREAM_PF_UNIX,
 * …)` fails there with WSAEINVAL-class error 10042, which made every replay
 * throw before its first event. PHP's Windows socketpair emulation works over
 * loopback TCP, so the pair is asked for as AF_INET there. On POSIX hosts the
 * Unix pair stays: no port, no loopback stack.
 */
final class SocketPair
{
    public static function domain(): int
    {
        return \PHP_OS_FAMILY === 'Windows' ? \STREAM_PF_INET : \STREAM_PF_UNIX;
    }

    /**
     * A connected stream pair, or false when the host refuses one.
     *
     * @return array{0: resource, 1: resource}|false
     */
    public static function open(): array|false
    {
        return \stream_socket_pair(self::domain(), \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    }
}
