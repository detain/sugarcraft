<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use SugarCraft\Testing\Lang;

/**
 * Byte-level decoder for the Kitty graphics protocol.
 *
 * Understands two framings and both are treated as first-class input:
 *
 *  - the standard APC form emitted by sugarcraft/sugar-image
 *    (`\x1b_G k=v,…,m=1; <b64> \x1b\\` per frame; begin + chunk frames
 *    stitched until the first `m=0;` closer), and
 *  - the legacy DCS-`q` form (`\x1bPq k=v,k=v \x1b\\ m=<more>,<b64>…  m=0
 *    \x1b\\`) — retained for decoding captures made before the ANSI audit
 *    fix moved the producer onto APC frames.
 *
 * Payload base64 is reassembled across `m=1` continuation chunks and — for a
 * `o=z` transmit (the spec's transmission-compression key) — zlib-inflated
 * back into a PNG. Every other payload travels untransformed, so a PNG
 * transmit (`f=100` in the upstream table, or the `f=12` synonym, without
 * `o=z`) yields the sender's bytes byte-for-byte. Multi-image streams
 * yield one {@see KittyImage} per transmit.
 *
 * Parse-side mirror (inverse) of sugarcraft/sugar-image's KittyRenderer.
 */
final class KittyStream
{
    private const DCS_Q = "\x1bPq";

    private const APC_G = "\x1b_G";

    private const ST = "\x1b\\";

    private const DCS_TERMINATOR = "m=0\x1b\\";

    /** @var list<KittyImage> */
    private array $images = [];

    /**
     * Attributes accumulated from `m=1` APC frames of the open transmission
     * (later frames override shared keys, so a per-chunk `z`/`f` is honored;
     * new keys are additive). Null when no chunked APC transaction is open.
     *
     * @var array<string, string>|null
     */
    private ?array $txParams = null;

    /** Base64 payload accumulated for the open APC transmission. */
    private string $txPayload = '';

    private function __construct()
    {
    }

    /**
     * Decode every Kitty transmit found in a byte stream.
     *
     * @throws MalformedGraphicsException when the stream holds no Kitty transmit
     */
    public static function decode(string $stream): self
    {
        $decoder = new self();
        $offset = 0;
        $length = strlen($stream);

        while ($offset < $length) {
            $next = self::nextIntroducer($stream, $offset);
            if ($next === null) {
                break;
            }
            [$at, $isApc] = $next;
            $offset = $isApc
                ? $decoder->consumeApc($stream, $at)
                : $decoder->consumeDcs($stream, $at);
        }

        if ($decoder->txParams !== null) {
            // Stream ended inside a chunked APC transmission — no m=0 closer.
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.missing_end'));
        }

        if ($decoder->images === []) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.missing_begin'));
        }

        return $decoder;
    }

    /**
     * @return list<KittyImage>
     */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * The first decoded image.
     *
     * @throws MalformedGraphicsException when nothing was decoded (guarded by decode)
     */
    public function image(): KittyImage
    {
        return $this->images[0];
    }

    public function count(): int
    {
        return count($this->images);
    }

    public function id(): ?int
    {
        return $this->image()->id();
    }

    public function action(): string
    {
        return $this->image()->action();
    }

    public function cols(): ?int
    {
        return $this->image()->cols();
    }

    public function rows(): ?int
    {
        return $this->image()->rows();
    }

    public function png(): string
    {
        return $this->image()->png();
    }

    /**
     * Locate the earliest remaining transmit introducer.
     *
     * @return array{int, bool}|null [offset, isApc]
     */
    private static function nextIntroducer(string $stream, int $offset): ?array
    {
        $dcs = strpos($stream, self::DCS_Q, $offset);
        $apc = strpos($stream, self::APC_G, $offset);

        if ($dcs === false && $apc === false) {
            return null;
        }
        if ($apc === false || ($dcs !== false && $dcs < $apc)) {
            return [$dcs, false];
        }

        return [$apc, true];
    }

    /**
     * Parse one DCS-`q` transmit and return the offset just past its terminator.
     */
    private function consumeDcs(string $stream, int $at): int
    {
        $paramsStart = $at + strlen(self::DCS_Q);
        $beginEnd = strpos($stream, self::ST, $paramsStart);
        if ($beginEnd === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.unterminated_begin'));
        }

        $params = self::parseParams(substr($stream, $paramsStart, $beginEnd - $paramsStart));
        $chunkStart = $beginEnd + strlen(self::ST);

        // sugar-image emits no gap between the header ST and the first `m=`
        // chunk; skip any stray whitespace so a padded transmission is never
        // misread as a bare placement (the probe and region below both start here).
        while (in_array(substr($stream, $chunkStart, 1), [' ', "\t", "\r", "\n"], true)) {
            $chunkStart++;
        }

        // A transmission that carries data is followed by `m=` chunks and closed
        // by an `m=0` ST terminator. A bare placement (`a=p` with no stored data)
        // is a complete self-closing DCS — the begin ST ends it. Distinguish the
        // two by what immediately follows the header.
        if (!str_starts_with(substr($stream, $chunkStart, 2), 'm=')) {
            $this->images[] = $this->buildImage($params, '');

            return $chunkStart;
        }

        $terminator = strpos($stream, self::DCS_TERMINATOR, $chunkStart);
        if ($terminator === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.missing_end'));
        }

        $region = substr($stream, $chunkStart, $terminator - $chunkStart);
        $payload = self::decodeBase64(self::reassemble($region));

        $this->images[] = $this->buildImage($params, $payload);

        return $terminator + strlen(self::DCS_TERMINATOR);
    }

    /**
     * Parse one standard APC frame and return the offset just past it.
     *
     * Frames with `m=1` open (or continue) a chunked transmission: the
     * attribute set merges from all frames (later frames may override shared
     * keys), payloads concatenate in order, and the first `m=0` frame closes
     * the transaction and yields the image — the exact mirror of
     * `Ansi::kittyGraphicsBegin()` + `Ansi::kittyGraphicsChunk()` as
     * sugar-image emits them (one self-framed APC sequence per chunk since the
     * ANSI audit fix).
     *
     * The open transaction lives on the decoder rather than on frame adjacency,
     * so this reader tolerates a capture that interleaves unrelated traffic
     * between chunks: screen text, SGR, OSC 1337 or a Sixel image all sit
     * outside the two Kitty introducers the scanner looks for (`\x1b_G` and the
     * bare `\x1bPq`) and are skipped without splitting the frame stream. That is
     * lenience for captures, not a licence to emit — upstream requires a client
     * to finish every chunk of one image before sending any other
     * graphics-related escape code.
     */
    private function consumeApc(string $stream, int $at): int
    {
        $bodyStart = $at + strlen(self::APC_G);
        $end = self::findApcEnd($stream, $bodyStart);
        if ($end === null) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.unterminated_apc'));
        }

        [$body, $after] = $end;
        $separator = strpos($body, ';');
        $paramsString = $separator === false ? $body : substr($body, 0, $separator);
        $payloadBase64 = $separator === false ? '' : substr($body, $separator + 1);

        $params = self::parseParams($paramsString);

        if (($params['m'] ?? '0') === '1') {
            // More chunks follow — accumulate; later frames may override shared
            // keys (per-chunk z/f are legal in the protocol).
            $this->txParams = array_merge($this->txParams ?? [], $params);
            $this->txPayload .= $payloadBase64;

            return $after;
        }

        // m=0 (or absent): close any open transaction, or decode this frame
        // as a standalone single-frame transmit. The closer's own attributes
        // win (it is the last chunk), and the `m` chunking flag is framing
        // state — it never belongs to the decoded image's metadata.
        $params = array_merge($this->txParams ?? [], $params);
        unset($params['m']);
        $payload = self::decodeBase64($this->txPayload . $payloadBase64);
        $this->txParams = null;
        $this->txPayload = '';

        $this->images[] = $this->buildImage($params, $payload);

        return $after;
    }

    /**
     * @return array{string, int}|null [body, offset after terminator]
     */
    private static function findApcEnd(string $stream, int $from): ?array
    {
        $st = strpos($stream, self::ST, $from);
        $bel = strpos($stream, "\x07", $from);
        if ($st === false && $bel === false) {
            return null;
        }
        if ($bel === false || ($st !== false && $st < $bel)) {
            return [substr($stream, $from, $st - $from), $st + strlen(self::ST)];
        }

        return [substr($stream, $from, $bel - $from), $bel + 1];
    }

    /**
     * Concatenate the base64 of every `m=<flag>,<b64>` chunk in a DCS region.
     *
     * Framing discipline, not blind concatenation: the old code split at
     * every `m=[01],` occurrence and glued the fragments together, so
     * corrupt bytes that spelled a header vanished into framing (payload
     * `AAAAm=0,BBBB` silently became `AAAABBBB`). Two wire rules catch
     * that now: an m=0 (final) chunk can never be followed by another
     * chunk, and every intermediate chunk must carry unpadded base64 of a
     * whole 4-character quantum - padding belongs to the end of a stream,
     * never to its middle.
     */
    private static function reassemble(string $region): string
    {
        if ($region === '') {
            return '';
        }

        $chunks = preg_split('/(?=m=[01],)/', $region, -1, PREG_SPLIT_NO_EMPTY);
        if ($chunks === false || $chunks === []) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.bad_chunk'));
        }

        $chunks = array_values($chunks);
        $last   = count($chunks) - 1;
        $base64 = '';

        foreach ($chunks as $index => $chunk) {
            if (!preg_match('/^m=([01]),(.*)$/s', $chunk, $m)) {
                throw new MalformedGraphicsException(Lang::t('graphics.kitty.bad_chunk'));
            }

            [, $flag, $payload] = $m;

            if ($flag === '0' && $index !== $last) {
                // Either the emitter broke the protocol or corrupt payload
                // bytes were reframed into a phantom boundary - refuse.
                throw new MalformedGraphicsException(Lang::t('graphics.kitty.chunk_after_end'));
            }

            if ($index !== $last && (str_contains($payload, '=') || strlen($payload) % 4 !== 0)) {
                throw new MalformedGraphicsException(Lang::t('graphics.kitty.chunk_alignment', [
                    'len' => strlen($payload),
                ]));
            }

            $base64 .= $payload;
        }

        return $base64;
    }

    /**
     * Strictly base64-decode a reassembled payload (inflation happens per-image).
     */
    private static function decodeBase64(string $base64): string
    {
        if ($base64 === '') {
            return '';
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.invalid_base64'));
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $params
     */
    private function buildImage(array $params, string $payload): KittyImage
    {
        if ($payload !== '') {
            $payload = self::maybeInflate($payload, $params);
        }

        return KittyImage::fromTransmit($params, $payload);
    }

    /**
     * Inflate a zlib-wrapped payload when — and only when — `o=z` declares one.
     *
     * Per the kitty graphics protocol `o` is the transmission-compression key
     * (`z` = zlib) and `f` is only ever a data format, so this gate reads `o`
     * and accepts nothing else: an absent or other `o` value travels untouched.
     * (Before M2/round-LL both SugarCraft sides misspelled compression as
     * `f=1`; legacy captures in that spelling now surface as an undecodable
     * raw payload rather than a guessed inflate.)
     *
     * A PNG transmit (`f=100` upstream, or the `f=12` synonym) without `o=z`
     * already holds the whole image, so inflating it would turn a valid payload
     * into a `decompress_failed` error. And `z` is the image z-index, never a
     * compression hint: `KittyOptions::withZIndex(1)` emits an uncompressed PNG
     * under exactly `f=100,z=1`.
     *
     * @param array<string, string> $params
     */
    private static function maybeInflate(string $bytes, array $params): string
    {
        if (($params['o'] ?? null) !== KittyImage::COMPRESSION_ZLIB || $bytes === '') {
            return $bytes;
        }

        // Budgeted streaming inflate: a few hundred KB of zlib data can
        // expand a thousand-fold, so the cap must apply to the OUTPUT as it
        // accrues — a plain gzuncompress() turns such a bomb into a killed
        // runner instead of a test failure. (Measured: 271 KB inflating
        // 1030x.)
        // ZLIB_ENCODING_DEFLATE is the zlib-wrapped (RFC 1950) window mode —
        // the format gzcompress() emits. Its ZLIB_ENCODING_ZLIB alias is not
        // registered on every build, so this spells the always-present name.
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        $inflated = '';
        $offset = 0;
        $size = strlen($bytes);

        while (true) {
            $slice = substr($bytes, $offset, 32_768);
            $offset += strlen($slice);
            // ext-zlib raises E_WARNING ('data error') on corrupt input and
            // returns false — the false is the contract, the diagnostic is
            // noise a budgeted decoder does not leak into the runner log.
            $part = @inflate_add($context, $slice, $offset >= $size ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            if ($part === false) {
                throw new MalformedGraphicsException(Lang::t('graphics.kitty.decompress_failed'));
            }
            $inflated .= $part;
            if (strlen($inflated) > DecodeLimits::MAX_DECOMPRESSED_BYTES) {
                throw new MalformedGraphicsException(Lang::t('graphics.kitty.decompress_too_large', [
                    'max' => DecodeLimits::MAX_DECOMPRESSED_BYTES,
                ]));
            }
            if ($offset >= $size) {
                break;
            }
        }

        // A completed zlib stream reports ZLIB_STREAM_END; anything else —
        // truncated (-5), corrupt checksum (-3), still in progress (0) —
        // gets the same loud refusal gzuncompress() gave before the budget
        // made streaming necessary.
        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.decompress_failed'));
        }

        return $inflated;
    }

    /**
     * Split a `k=v,k=v` control parameter block.
     *
     * @return array<string, string>
     */
    private static function parseParams(string $block): array
    {
        $params = [];
        foreach (explode(',', $block) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                throw new MalformedGraphicsException(Lang::t('graphics.kitty.bad_parameter', ['token' => $pair]));
            }
            $params[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }

        return $params;
    }
}
