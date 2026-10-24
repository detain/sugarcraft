<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Git;

/**
 * The ONE pkt-line codec for every wire emitter in this package.
 *
 * Before this class existed, four ad-hoc encoders lived in the library
 * (GitDaemon, HttpSmartProtocol\Server, ReceivePack, UploadPack) and each
 * one broke the framing differently: two wrote the length as raw binary
 * bytes (pack('N') / hex2bin(dechex())) where the spec demands four ASCII
 * hex digits, every one of them appended a stray "\n" the spec does not
 * allow, and the flush packets disagreed on ASCII vs binary. The
 * docs-review-1023 C2 finding recorded the shapes and the deliberately
 * planted anti-regression pins (GitProtocolTest) documented them byte for
 * byte until the fix landed.
 *
 * Spec (https://git-scm.com/docs/protocol-common#packets):
 *  - a packet is its total length (payload + the 4 prefix bytes) as four
 *    lowercase ASCII hex digits, followed by the payload;
 *  - 0000 is the flush-pkt;
 *  - a payload may carry a trailing LF (git's own advertisement lines do);
 *  - packets larger than 65520 bytes may not be sent (binary length cap).
 *
 * Ground truth pinned in PktLineTest is transcribed from
 * `git upload-pack --stateless-rpc --advertise-refs` on this host (git
 * 2.43): e.g. payload "# service=git-upload-pack\n" (26 bytes, LF included)
 * encodes to "001e# service=git-upload-pack\n".
 *
 * Mirrors charmbracelet/soft-serve internal/pktline (Go's pkt-line writer).
 */
final class PktLine
{
    /** Size of the ASCII-hex length prefix, included in every packet length. */
    public const HEADER_BYTES = 4;

    /**
     * Largest total packet size on the wire — the spec's 65520-byte cap
     * (0xFFF0), header included. r1024/githttp2 review MINOR-2 cure: this
     * was 0xFFFF, which neither the class's own docblock rule nor git
     * (LARGE_PACKET_MAX = 65520) sanctions; both encode and decode now
     * enforce the spec value, and the dated pins that recorded the old
     * permissive cap were flipped in the same step.
     */
    public const MAX_PACKET_BYTES = 65520;

    /** Largest payload {@see self::encode()} will frame. */
    public const MAX_PAYLOAD_BYTES = self::MAX_PACKET_BYTES - self::HEADER_BYTES;

    /** The flush-pkt. */
    public const FLUSH = '0000';

    private function __construct()
    {
    }

    /**
     * Frame one payload as a spec pkt-line.
     *
     * By house convention an empty payload IS the flush packet (all four
     * former call sites meant exactly that when they framed ''), so
     * encode('') returns {@see self::FLUSH} rather than a '0004' packet.
     *
     * @throws \RuntimeException when the payload cannot fit the wire cap
     */
    public static function encode(string $payload): string
    {
        if ($payload === '') {
            return self::FLUSH;
        }

        $length = \strlen($payload) + self::HEADER_BYTES;
        if ($length > self::MAX_PACKET_BYTES) {
            throw new \RuntimeException(
                'pkt-line payload exceeds the ' . self::MAX_PACKET_BYTES . '-byte packet cap: '
                . \strlen($payload) . ' bytes'
            );
        }

        return \sprintf('%04x', $length) . $payload;
    }

    /** The flush-pkt. */
    public static function flush(): string
    {
        return self::FLUSH;
    }

    /**
     * Decode a whole buffer of concatenated packets.
     *
     * Tolerant where the spec asks for tolerance (uppercase hex lengths are
     * accepted on read even though we only ever emit lowercase), loud where
     * a stream cannot be trusted (bad prefix, truncated packet, reserved
     * 0001-0003 side-band-style markers, which belong to responses and
     * never appear in a request body).
     *
     * @return list<string|null> one entry per packet; null = flush-pkt
     * @throws \RuntimeException on any malformed framing
     */
    public static function decode(string $bytes): array
    {
        $packets = [];
        $offset  = 0;
        $total   = \strlen($bytes);

        while ($offset < $total) {
            if ($total - $offset < self::HEADER_BYTES) {
                throw new \RuntimeException('truncated pkt-line: ' . ($total - $offset) . ' byte(s) left, need a 4-byte length prefix');
            }

            $header = \substr($bytes, $offset, self::HEADER_BYTES);
            // ctype_xdigit accepts upper and lowercase hex — reading
            // tolerantly even though we only ever emit lowercase.
            if (\ctype_xdigit($header) !== true) {
                throw new \RuntimeException('malformed pkt-line length prefix: ' . \bin2hex($header));
            }

            $length = \hexdec($header);
            if ($length === 0) {
                // flush-pkt
                $packets[] = null;
                $offset   += self::HEADER_BYTES;
                continue;
            }
            if ($length < self::HEADER_BYTES) {
                throw new \RuntimeException('reserved or invalid pkt-line length ' . $header . ' (0001-0003 packets are response-side markers)');
            }
            if ($length > self::MAX_PACKET_BYTES) {
                // Spec side-band of tolerance: git itself refuses packets
                // over the 65520-byte cap, and so do we — an oversized
                // length is malformed framing, not a big packet.
                throw new \RuntimeException('oversized pkt-line length ' . $header . ' (cap ' . self::MAX_PACKET_BYTES . ')');
            }

            if ($total - $offset < $length) {
                throw new \RuntimeException('truncated pkt-line: header declares ' . $length . ' bytes, only ' . ($total - $offset) . ' remain');
            }

            $packets[] = \substr($bytes, $offset + self::HEADER_BYTES, $length - self::HEADER_BYTES);
            $offset   += $length;
        }

        return $packets;
    }

    /**
     * True when $payload is exactly the smart-HTTP service header for
     * $service ("# service=<name>", with or without the trailing LF git
     * clients include in the packet).
     */
    public static function isServiceHeader(string $payload, string $service): bool
    {
        return \rtrim($payload, "\n") === '# service=' . $service;
    }
}
