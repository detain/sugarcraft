<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

/**
 * Adapter bridging the Parser's {@see Handler} interface to
 * {@see CsiHandler} + {@see OscHandler} for the vcr renderer path.
 *
 * Translates parse events into handler method calls.
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
        // Accept any character except C0 controls (0x00-0x1F) and DEL (0x7F).
        // This passes through Latin-1 printables (0xA0-0xFF) and all valid
        // UTF-8 lead bytes (>= 0xC2) for multi-byte runes.
        if ($byte !== '' && ord($byte) >= 0x20) {
            $this->csi->printable($rune);
        }
    }

    public function execute(int $byte): void
    {
        match ($byte) {
            0x09 => $this->csi->cht(1),
            0x0A, 0x0B, 0x0C => $this->csi->lf(), // LF, VT, FF all advance cursor down
            0x0D => $this->csi->cr(),
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
            'H', 'f' => $this->csi->cup(
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
            default => null,
        };
    }

    public function escDispatch(int $final, int $intermediate): void
    {
    }

    public function oscDispatch(string $data): void
    {
        if (preg_match('/^([0-2]);(.+)$/', $data, $m)) {
            $this->osc->title($m[2]);
        }
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
    }
}
