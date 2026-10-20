<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Codec;

/**
 * F-16: Kitty graphics APC decoder.
 *
 * Wire form (APC `ESC _` … `ESC \`), payload handed over by
 * {@see \SugarCraft\AnsiParser\Parser\Handler::sosPmApcDispatch()} with
 * `kind === 'apc'`:
 *
 *   G <key>=<value>,<key>=<value>,… ; <base64 payload>
 *
 * The old inspector labelled every `G` APC by byte count only ("kitty
 * graphics (N bytes)"), which cannot distinguish a transmit frame from a
 * placement/delete control frame or say what was transmitted. This decoder
 * exposes the control pairs and the geometry/size labels:
 *
 * - `s`/`v`  source width/height in PIXELS (kitty spec names);
 * - `c`/`r`  target width/height in terminal CELLS;
 * - `w`/`h`  accepted as non-standard aliases some peers emit
 *            ({@see width()}/{@see height()} resolve s → w → c and v → h → r);
 * - `x`/`y`  cell offsets, `z` the z-index layer;
 * - payload byte count ({@see payloadSize()}) — the only label the inspector
 *   used to print.
 *
 * The emitter side is sugar-core's `Ansi::kittyGraphicsBegin()` /
 * `kittyGraphicsControl()` (comma-joined `key=value`); this decoder is
 * deliberately lenient (unknown keys are kept in {@see control()}, malformed
 * tokens are skipped) because APC payloads are attacker-controlled.
 */
final class KittyApc
{
    /**
     * @param array<string, string> $control Decoded control pairs, in wire order.
     */
    private function __construct(
        private readonly array $control,
        private readonly string $payload,
    ) {
    }

    /**
     * Decode an APC payload. Returns null when the payload is not a kitty
     * graphics frame (it must open with the `G` introducer).
     */
    public static function parse(string $data): ?self
    {
        if ($data === '' || $data[0] !== 'G') {
            return null;
        }

        $rest = substr($data, 1);
        $separator = strpos($rest, ';');
        if ($separator === false) {
            // Control-only frame without the payload separator — tolerated.
            $controlData = $rest;
            $payload = '';
        } else {
            $controlData = substr($rest, 0, $separator);
            $payload = substr($rest, $separator + 1);
        }

        $control = [];
        foreach (explode(',', $controlData) as $token) {
            if ($token === '') {
                continue;
            }
            $eq = strpos($token, '=');
            if ($eq === false || $eq === 0) {
                continue; // Not a key=value pair — skip, never throw on wire data.
            }
            $control[substr($token, 0, $eq)] = substr($token, $eq + 1);
        }

        return new self($control, $payload);
    }

    /** @return array<string, string> Raw control pairs, in wire order. */
    public function control(): array
    {
        return $this->control;
    }

    public function get(string $key): ?string
    {
        return $this->control[$key] ?? null;
    }

    /** Action: T=transmit, p=place, d=delete, …; '' when absent. */
    public function action(): string
    {
        return $this->control['a'] ?? '';
    }

    /** Image id (`i`); 0 when absent (kitty treats 0 as "no id"). */
    public function imageId(): int
    {
        return $this->intValue('i') ?? 0;
    }

    /** Transmission format (`f`): 24=RGB, 32=RGBA, 100=PNG, …; null when absent. */
    public function format(): ?int
    {
        return $this->intValue('f');
    }

    /** Source width in pixels (`s`). */
    public function sourceWidth(): ?int
    {
        return $this->intValue('s');
    }

    /** Source height in pixels (`v`). */
    public function sourceHeight(): ?int
    {
        return $this->intValue('v');
    }

    /** Target width in terminal cells (`c`). */
    public function columns(): ?int
    {
        return $this->intValue('c');
    }

    /** Target height in terminal cells (`r`). */
    public function rows(): ?int
    {
        return $this->intValue('r');
    }

    /** Horizontal cell offset (`x`). */
    public function x(): ?int
    {
        return $this->intValue('x');
    }

    /** Vertical cell offset (`y`). */
    public function y(): ?int
    {
        return $this->intValue('y');
    }

    /** Z-index layer (`z`). */
    public function z(): ?int
    {
        return $this->intValue('z');
    }

    /** More chunks follow (`m`): 1 while a transmission is open, 0 on the last. */
    public function more(): ?int
    {
        return $this->intValue('m');
    }

    public function isChunked(): bool
    {
        return $this->more() === 1;
    }

    /**
     * Best-known pixel width: `s`, then the non-standard `w` alias, then the
     * cell-width `c` (callers that need the unit should read the explicit
     * accessors instead).
     */
    public function width(): ?int
    {
        return $this->sourceWidth() ?? $this->intValue('w') ?? $this->columns();
    }

    /**
     * Best-known pixel height: `v`, then the non-standard `h` alias, then the
     * cell-height `r`.
     */
    public function height(): ?int
    {
        return $this->sourceHeight() ?? $this->intValue('h') ?? $this->rows();
    }

    /** Base64 payload that followed the `;`. */
    public function payload(): string
    {
        return $this->payload;
    }

    /** Payload byte count — the label the old inspector printed alone. */
    public function payloadSize(): int
    {
        return \strlen($this->payload);
    }

    /**
     * One-line, control-byte-safe summary: action/id, geometry (pixels when
     * `s`/`v` are present, cells when only `c`/`r` are), offsets, layer and
     * payload size.
     */
    public function describe(): string
    {
        $parts = ['kitty graphics'];
        if ($this->action() !== '') {
            $parts[] = 'a=' . $this->action();
        }
        $id = $this->intValue('i');
        if ($id !== null) {
            $parts[] = 'i=' . $id;
        }
        if ($this->sourceWidth() !== null || $this->sourceHeight() !== null) {
            $parts[] = ($this->sourceWidth() ?? 0) . 'x' . ($this->sourceHeight() ?? 0) . 'px';
        } elseif ($this->columns() !== null || $this->rows() !== null) {
            $parts[] = ($this->columns() ?? 0) . 'x' . ($this->rows() ?? 0) . ' cells';
        } elseif ($this->intValue('w') !== null || $this->intValue('h') !== null) {
            $parts[] = ($this->intValue('w') ?? 0) . 'x' . ($this->intValue('h') ?? 0);
        }
        foreach (['x', 'y', 'z'] as $key) {
            $value = $this->intValue($key);
            if ($value !== null) {
                $parts[] = $key . '=' . $value;
            }
        }
        $more = $this->more();
        if ($more !== null) {
            $parts[] = 'm=' . $more;
        }
        $parts[] = '(' . $this->payloadSize() . ' bytes)';

        return implode(' ', $parts);
    }

    private function intValue(string $key): ?int
    {
        $raw = $this->control[$key] ?? null;
        if ($raw === null || $raw === '' || preg_match('/^-?\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }
}
