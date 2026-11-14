<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Support;

use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;

/**
 * Socket-pair driver harness for full-frame repaint goldens (finding 031).
 *
 * Writes $keys to a stream-backed InputDriver, runs the Readline pump
 * with $output on the writer end of a second socket pair, then drains the
 * reader end and returns every painted byte. Every drive must reach
 * submit/abort: an injected driver reports null for both EOF and
 * momentarily-live-empty reads (see Readline::runLoop), so a key sequence
 * that never completes would idle the loop forever.
 */
final class RepaintDriver
{
    public static function capture(object $prompt, string $keys): string
    {
        $in  = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $out = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($in === false || $out === false) {
            self::fail('stream_socket_pair unavailable for the repaint harness');
        }
        [$iw, $ir] = $in;
        [$ow, $or] = $out;

        \fwrite($iw, $keys);
        \fclose($iw);

        try {
            (new Readline(new StreamInputDriver($ir)))->run($prompt, $ow);
        } finally {
            \fclose($ir);
            \fclose($ow);
        }

        $captured = '';
        while (false !== ($chunk = \fread($or, 65536)) && $chunk !== '') {
            $captured .= $chunk;
        }
        \fclose($or);
        return $captured;
    }

    private static function fail(string $message): void
    {
        throw new \RuntimeException($message);
    }
}
