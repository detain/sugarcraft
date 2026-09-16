<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use RuntimeException;
use SugarCraft\Testing\Lang;

/**
 * One decoded Kitty graphics transmission (a single image or placement).
 *
 * Holds the parsed control parameters plus the fully reassembled payload bytes
 * (already base64-decoded and, for `f=1`, zlib-inflated into a PNG). A `a=p`
 * placement carries no payload — only an id and a target offset.
 *
 * Every other transmission format travels untransformed: a PNG transmit already
 * carries a whole image, so this decoder hands those bytes over byte-for-byte —
 * no inflate, no re-encode.
 *
 * The `f` (transmission format) codes, and who says what. The kitty graphics
 * protocol's control-data reference lists `f` as one of `24` (RGB — three bytes
 * per pixel), `32` (RGBA) or `100` (PNG), defaulting to `32`, with transmission
 * compression carried SEPARATELY by the `o=z` key and `z` itself meaning the
 * image z-index. SugarCraft's own producers widen that: candy-mosaic's
 * `KittyOptions::withCompression(1)` declares a zlib-wrapped payload as `f=1`,
 * and `f=12` is accepted as a second PNG spelling so a capture written by
 * tooling that uses it still round-trips (nothing in this monorepo emits `12`).
 * Rather than privilege one spelling, {@see PNG_PASSTHROUGH_FORMATS} accepts
 * `100` and `12` alike and inflates only on `f=1`; any other (or absent) `f`
 * travels untouched, so an unknown code can never silently corrupt a capture —
 * the decoder mirrors what a terminal would see instead of guessing at a payload
 * it was not told how to read.
 *
 * Mirrors the kitty graphics protocol transmit semantics (inverse).
 */
final class KittyImage
{
    /** Zlib-wrapped payload — candy-mosaic's `f=1` convention; the only code {@see KittyStream} inflates. */
    public const FORMAT_ZLIB = '1';

    /** PNG — the upstream protocol's own code for a complete PNG payload. */
    public const FORMAT_PNG = '100';

    /** A second PNG spelling decoded leniently so a capture that uses it round-trips; no SugarCraft producer emits it. */
    public const FORMAT_PNG_ALT = '12';

    /**
     * `f` values whose payload is a complete PNG delivered untransformed.
     *
     * @var list<string>
     */
    public const PNG_PASSTHROUGH_FORMATS = [self::FORMAT_PNG_ALT, self::FORMAT_PNG];

    /**
     * Control keys that carry an integer value.
     *
     * `z` is separate because the kitty spec types it as a *signed* 32-bit integer
     * (a negative z-index, or a negative animation frame gap for gapless frames),
     * while every other key here is a non-negative count/offset/identifier.
     */
    private const NUMERIC_KEYS = ['i', 'I', 'c', 'r', 's', 'v', 'x', 'y'];

    private const SIGNED_KEYS = ['z'];

    /**
     * @param array<string, string> $params raw control keys (a,i,c,r,s,v,x,y,z,f,q,…)
     */
    private function __construct(
        private readonly array $params,
        private readonly string $payload,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public static function fromTransmit(array $params, string $payload): self
    {
        self::assertNumericParameters($params);

        return new self($params, $payload);
    }

    /**
     * Fail fast at the boundary when an integer control value is malformed, so a
     * corrupt `i=abc` can never reach callers as a misleading id of `0`.
     *
     * @param array<string, string> $params
     */
    private static function assertNumericParameters(array $params): void
    {
        foreach (self::NUMERIC_KEYS as $key) {
            $value = $params[$key] ?? null;
            if ($value !== null && !ctype_digit($value)) {
                self::rejectNonNumeric($key, $value);
            }
        }

        foreach (self::SIGNED_KEYS as $key) {
            $value = $params[$key] ?? null;
            if ($value !== null && preg_match('/^-?\d+\z/', $value) !== 1) {
                self::rejectNonNumeric($key, $value);
            }
        }
    }

    /**
     * @throws MalformedGraphicsException
     */
    private static function rejectNonNumeric(string $key, string $value): never
    {
        throw new MalformedGraphicsException(Lang::t('graphics.kitty.non_numeric_parameter', [
            'key' => $key,
            'value' => $value,
        ]));
    }

    public function action(): string
    {
        return $this->params['a'] ?? 't';
    }

    public function id(): ?int
    {
        return isset($this->params['i']) ? (int) $this->params['i'] : null;
    }

    public function number(): ?int
    {
        return isset($this->params['I']) ? (int) $this->params['I'] : null;
    }

    /** Declared width in character cells (`c`). */
    public function cols(): ?int
    {
        return isset($this->params['c']) ? (int) $this->params['c'] : null;
    }

    /** Declared height in character cells (`r`). */
    public function rows(): ?int
    {
        return isset($this->params['r']) ? (int) $this->params['r'] : null;
    }

    /** Declared pixel width (`s`). */
    public function pixelWidth(): ?int
    {
        return isset($this->params['s']) ? (int) $this->params['s'] : null;
    }

    /** Declared pixel height (`v`). */
    public function pixelHeight(): ?int
    {
        return isset($this->params['v']) ? (int) $this->params['v'] : null;
    }

    public function offsetX(): ?int
    {
        return isset($this->params['x']) ? (int) $this->params['x'] : null;
    }

    public function offsetY(): ?int
    {
        return isset($this->params['y']) ? (int) $this->params['y'] : null;
    }

    /**
     * The `z` stacking index — deliberately NOT a compression flag.
     *
     * The kitty spec types `z` as a signed integer index (negative values are
     * legal), and candy-mosaic's `KittyOptions::withZIndex()` emits it as such.
     * Upstream carries transmission compression on the separate `o=z` key and
     * SugarCraft signals it with `f=1`, so nothing here reads `z` as a hint to
     * inflate.
     */
    public function zIndex(): ?int
    {
        return isset($this->params['z']) ? (int) $this->params['z'] : null;
    }

    /** The raw `f` transmission-format value, or null when the sender omitted it. */
    public function format(): ?string
    {
        return $this->params['f'] ?? null;
    }

    /** Whether the payload was transmitted zlib-compressed (`f=1`) and already inflated here. */
    public function compressed(): bool
    {
        return ($this->params['f'] ?? null) === self::FORMAT_ZLIB;
    }

    /**
     * Whether the payload is a PNG delivered untransformed — `f=100` (the
     * upstream code) or `f=12` (the synonym accepted alongside it), both listed in
     * {@see PNG_PASSTHROUGH_FORMATS}.
     */
    public function pngPassthrough(): bool
    {
        return in_array($this->params['f'] ?? '', self::PNG_PASSTHROUGH_FORMATS, true);
    }

    /**
     * The reassembled, decoded payload bytes for this transmit — already
     * zlib-inflated for an `f=1` image and empty for a data-less placement.
     * Unlike {@see png()} this never throws on an empty payload.
     */
    public function rawPayload(): string
    {
        return $this->payload;
    }

    /**
     * Whether this transmit carries image data at all (placements do not).
     */
    public function hasPayload(): bool
    {
        return $this->payload !== '';
    }

    /**
     * The image payload bytes for this transmit: a PNG for a PNG-format
     * (`f=100`/`f=12`) transmit or an inflated `f=1` one, raw pixel data for an
     * `f=24`/`f=32` transmit.
     *
     * @throws MalformedGraphicsException when the transmit is a data-less placement
     */
    public function png(): string
    {
        if ($this->payload === '') {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.no_payload', ['id' => $this->id() ?? 0]));
        }

        return $this->payload;
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Decode the PNG payload into a GD image and report its real pixel size.
     *
     * @return array{int, int}
     */
    public function pixelDimensions(): array
    {
        $info = @getimagesizefromstring($this->png());
        if ($info === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.payload_not_image'));
        }

        return [$info[0], $info[1]];
    }

    public function toGdImage(): \GdImage
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException(Lang::t('graphics.gd.unavailable'));
        }

        $image = imagecreatefromstring($this->png());
        if (!$image instanceof \GdImage) {
            throw new MalformedGraphicsException(Lang::t('graphics.kitty.payload_not_image'));
        }

        return $image;
    }
}
