<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * Adapter bridging the Parser's {@see Handler} interface to
 * {@see CsiHandler} + {@see OscHandler} for the vcr renderer path.
 *
 * Translates parse events into handler method calls.
 *
 * Mirrors charmbracelet/x/ansi.HandlerAdapter
 */
final class HandlerAdapter implements Handler
{
    public function __construct(
        private CsiHandler $csi,
        private OscHandler $osc,
    ) {
    }

    public function printChar(string $rune): void
    {
        $byte = $rune[0] ?? '';
        // Forward printable ASCII OR any valid UTF-8 lead byte (>= 0xC2).
        // Drop C0 (< 0x20) and C1 (0x80-0xBF continuation) control bytes.
        if ($byte !== '' && ord($byte) >= 0x20) {
            $this->csi->printable($rune);
        }
    }

    public function execute(int $byte): void
    {
        match ($byte) {
            0x09 => $this->csi->cht(1),
            0x0A => $this->csi->lf(),  // LF — line feed
            0x0D => $this->csi->cr(),  // CR — carriage return
            0x08 => $this->csi->cub(1),
            default => null,
        };
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $finalChar = chr($final);
        $p0 = (int) ($params[0] ?? -1);
        $p1 = (int) ($params[1] ?? -1);
        $count = $p0 === -1 ? 1 : max(1, $p0);

        match ($finalChar) {
            'A' => $this->csi->cuu($count),
            'B' => $this->csi->cud($count),
            'C' => $this->csi->cuf($count),
            'D' => $this->csi->cub($count),
            'H' => $this->csi->cup(
                $p0 === -1 ? 1 : $p0,
                $p1 === -1 ? 1 : $p1,
            ),
            'f' => $this->csi->hvp(
                $p0 === -1 ? 1 : $p0,
                $p1 === -1 ? 1 : $p1,
            ),
            'm' => $this->csi->sgr($params),
            'J' => $this->csi->ed($p0 === -1 ? 0 : $p0),
            'K' => $this->csi->el($p0 === -1 ? 0 : $p0),
            'r' => $this->csi->decstbm(
                $p0 === -1 ? 1 : $p0,
                $p1 === -1 ? $this->csi->gridRows() : $p1,
            ),
            'h' => $this->csi->decset($p0 === -1 ? 0 : $p0, $prefix),
            'l' => $this->csi->decrst($p0 === -1 ? 0 : $p0, $prefix),
            'g' => $this->csi->tbc($p0 === -1 ? 0 : $p0),
            'Z' => $this->csi->cbt($count),
            'I' => $this->csi->cht($count),
            'S' => $this->csi->su($count),
            'T' => $this->csi->sd($count),
            'L' => $this->csi->il($count),
            'M' => $this->csi->dl($count),
            '@' => $this->csi->ich($count),
            'P' => $this->csi->dch($count),
            'b' => $this->csi->rep($count),
            's' => $this->csi->scosc(),
            'u' => $this->csi->scorc(),
            default => null,
        };
    }

    public function escDispatch(int $final, int $intermediate): void
    {
    }

    public function oscDispatch(string $data): void
    {
        // OSC 8 — hyperlink: `ESC ] 8 ; params ; uri ST`.
        // `params` is a colon-separated key=value list (only `id=` is defined);
        // `uri` is everything after the second `;` and may be empty (closes the
        // hyperlink). The /s flag lets the URI span any byte the parser buffered.
        if (preg_match('/^8;([^;]*);(.*)$/s', $data, $m)) {
            $this->osc->hyperlink($m[2], $this->parseHyperlinkId($m[1]));
            return;
        }

        // OSC 0/1/2 — window / icon title.
        // .* allows an empty payload (e.g. "2;" = clear title).
        if (preg_match('/^([0-2]);(.*)$/', $data, $m)) {
            $this->osc->title($m[2]);
        }
    }

    /**
     * Extract the `id=` value from an OSC 8 params field. The field is a
     * colon-separated list of key=value pairs; every key other than `id`
     * is ignored. Returns '' when no id is present.
     */
    private function parseHyperlinkId(string $params): string
    {
        if ($params === '') {
            return '';
        }
        foreach (explode(':', $params) as $pair) {
            if (str_starts_with($pair, 'id=')) {
                return substr($pair, 3);
            }
        }
        return '';
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
    }
}
