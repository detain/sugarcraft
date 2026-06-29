<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Hyperlink\Hyperlink;

/**
 * Routes OSC dispatch payloads to the right ScreenHandler slot.
 *
 * Recognised commands:
 *
 * - `0`, `1`, `2`  set window title (icon name folds into the same slot)
 * - `4`            palette set: `OSC 4 ; idx ; rgb:RRRR/GGGG/BBBB [; idx ; rgb:...]`
 * - `8`            hyperlink open / close: `OSC 8 ; params ; URI` (empty URI closes)
 * - `52`           clipboard write or read request
 *
 * Other OSC commands are ignored. Malformed payloads (missing fields,
 * unrecognised color spec) silently no-op rather than throw — matches
 * upstream xterm behavior on bad OSC data.
 *
 * SECURITY NOTE: Window titles (0/1/2), hyperlink URIs (8), and clipboard
 * payloads (52) come from untrusted program output and are stored verbatim.
 * Consumers MUST sanitize/escape these values before display or use to
 * prevent injection attacks. See individual method docblocks for details.
 */
final class OscHandler
{
    public function apply(string $data, ScreenHandler $h): void
    {
        $semi = strpos($data, ';');
        if ($semi === false) {
            $cmd = ctype_digit($data) ? (int) $data : -1;
            $rest = '';
        } else {
            $head = substr($data, 0, $semi);
            $cmd = ctype_digit($head) ? (int) $head : -1;
            $rest = substr($data, $semi + 1);
        }

        match ($cmd) {
            // Window title from untrusted program output — consumers MUST
            // escape appropriately (e.g. HTML-entity encode) before display.
            0, 1, 2 => $h->windowTitle = $rest,
            4 => $this->setPalette($rest, $h),
            8 => $this->setHyperlink($rest, $h),
            52 => $this->clipboard($rest, $h),
            default => null,
        };
    }

    private function setPalette(string $rest, ScreenHandler $h): void
    {
        $parts = explode(';', $rest);
        $count = count($parts);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            if (!ctype_digit($parts[$i])) {
                continue;
            }
            $idx = (int) $parts[$i];
            $color = $this->parseColor($parts[$i + 1]);
            if ($color !== null && $idx >= 0 && $idx <= 255) {
                $h->palette[$idx] = $color;
            }
        }
    }

    private function parseColor(string $spec): ?Color
    {
        // xterm rgb form: rgb:RRRR/GGGG/BBBB (each component 1-4 hex digits, MSB-aligned)
        if (preg_match('/^rgb:([0-9a-fA-F]+)\/([0-9a-fA-F]+)\/([0-9a-fA-F]+)$/', $spec, $m)) {
            return Color::truecolor(
                $this->scaleHexComponent($m[1]),
                $this->scaleHexComponent($m[2]),
                $this->scaleHexComponent($m[3]),
            );
        }
        // CSS-style: #RRGGBB
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $spec, $m)) {
            return Color::truecolor(hexdec($m[1]), hexdec($m[2]), hexdec($m[3]));
        }
        return null;
    }

    /**
     * Scale an N-hex-digit value to 8 bits by taking the high byte.
     * 'ff' → 0xff; 'ffff' → 0xff; 'f' → 0xf0 (left-pad).
     */
    private function scaleHexComponent(string $hex): int
    {
        $width = strlen($hex);
        if ($width === 0) {
            return 0;
        }
        $val = (int) hexdec($hex);
        if ($width === 1) {
            return ($val << 4) & 0xFF;
        }
        if ($width === 2) {
            return $val & 0xFF;
        }
        // Right-shift to keep the most-significant byte.
        return ($val >> (4 * ($width - 2))) & 0xFF;
    }

    /**
     * OSC 8 hyperlink open / close: `OSC 8 ; params ; URI` (empty URI closes).
     *
     * NOTE: The URI is stored verbatim. It may contain arbitrary schemes
     * including `javascript:`, `file://`, or other potentially dangerous URIs.
     * Downstream renderers MUST sanitize/whitelist before use (e.g. strip
     * javascript: and other executable schemes) and MUST escape properly
     * before display to prevent injection.
     */
    private function setHyperlink(string $rest, ScreenHandler $h): void
    {
        $semi = strpos($rest, ';');
        if ($semi === false) {
            $h->currentHyperlink = null;
            return;
        }
        $params = substr($rest, 0, $semi);
        $uri = substr($rest, $semi + 1);
        if ($uri === '') {
            $h->currentHyperlink = null;
            return;
        }
        $id = '';
        if ($params !== '' && preg_match('/(?:^|:)id=([^:]*)/', $params, $m)) {
            $id = $m[1];
        }
        $h->currentHyperlink = new Hyperlink($id, $uri);
    }

    /**
     * OSC 52 clipboard write or read request.
     *
     * NOTE: The payload is stored verbatim as base64 from untrusted program
     * output. Consumers MUST validate the length and verify the base64
     * encoding before `base64_decode()` and MUST treat the decoded content
     * as untrusted (it may contain malicious data injected by the remote
     * program). The selection field is also untrusted input.
     */
    private function clipboard(string $rest, ScreenHandler $h): void
    {
        $semi = strpos($rest, ';');
        if ($semi === false) {
            return;
        }
        $selection = substr($rest, 0, $semi);
        $payload = substr($rest, $semi + 1);
        if ($payload === '?') {
            $h->clipboardEvents[] = ['kind' => 'read', 'selection' => $selection];
            return;
        }
        $h->clipboardEvents[] = [
            'kind' => 'write',
            'selection' => $selection,
            'payload' => $payload,
        ];
    }
}
