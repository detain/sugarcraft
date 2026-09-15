<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use SugarCraft\Testing\Lang;

/**
 * Byte-level decoder for the Kitty graphics protocol.
 *
 * Understands two framings and both are treated as first-class input:
 *
 *  - the DCS-`q` form emitted by sugarcraft/candy-mosaic
 *    (`\x1bPq k=v,k=v \x1b\\ m=<more>,<b64>…  m=0 \x1b\\`), and
 *  - the standard APC form (`\x1b_G k=v,k=v;<b64> \x1b\\`).
 *
 * Payload base64 is reassembled across `m=1` continuation chunks and — for a
 * `f=1` transmit — zlib-inflated back into a PNG. Multi-image streams yield one
 * {@see KittyImage} per transmit.
 *
 * Mirrors charmbracelet/candy-mosaic KittyRenderer (inverse).
 */
final class KittyStream
{
    private const DCS_Q = "\x1bPq";

    private const APC_G = "\x1b_G";

    private const ST = "\x1b\\";

    private const DCS_TERMINATOR = "m=0\x1b\\";

    /** @var list<KittyImage> */
    private array $images = [];

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

        // candy-mosaic emits no gap between the header ST and the first `m=`
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
     * Parse one standard APC transmit and return the offset just past it.
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
        $payload = self::decodeBase64($payloadBase64);

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
     */
    private static function reassemble(string $region): string
    {
        if ($region === '') {
            return '';
        }

        $chunks = preg_split('/(?=m=[01],)/', $region, -1, PREG_SPLIT_NO_EMPTY);
        $base64 = '';
        foreach ($chunks as $chunk) {
            if (!preg_match('/^m=([01]),(.*)$/s', $chunk, $m)) {
                throw new MalformedGraphicsException(Lang::t('graphics.kitty.bad_chunk'));
            }
            $base64 .= $m[2];
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
     * Inflate a zlib-wrapped payload when `f=1` requested compression.
     *
     * @param array<string, string> $params
     */
    private static function maybeInflate(string $bytes, array $params): string
    {
        if (($params['f'] ?? null) !== '1' || $bytes === '') {
            return $bytes;
        }

        $inflated = @gzuncompress($bytes);
        if ($inflated === false) {
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
