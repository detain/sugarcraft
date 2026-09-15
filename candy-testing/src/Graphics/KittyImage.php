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
 * Mirrors charmbracelet/kitty `GLTP` transmit semantics (inverse).
 */
final class KittyImage
{
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
            if ($value !== null && preg_match('/^-?\d+$/', $value) !== 1) {
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

    public function zIndex(): ?int
    {
        return isset($this->params['z']) ? (int) $this->params['z'] : null;
    }

    /** `f=1` transmits zlib-compressed data; `f=100` signals PNG passthrough. */
    public function compressed(): bool
    {
        return ($this->params['f'] ?? null) === '1';
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
     * The PNG bytes for this image.
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
