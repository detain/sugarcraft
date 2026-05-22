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
        if ($byte !== '' && ord($byte) >= 0x20 && ord($byte) <= 0x7E) {
            $this->csi->printable($byte);
        }
    }

    public function execute(int $byte): void
    {
        match ($byte) {
            0x09 => $this->csi->cht(1),
            0x0D => null,
            0x08 => $this->csi->cub(1),
            default => null,
        };
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $finalChar = chr($final);
        $count = ($params[0] ?? -1) === -1 ? 1 : max(1, $params[0]);

        match ($finalChar) {
            'A' => $this->csi->cuu($count),
            'B' => $this->csi->cud($count),
            'C' => $this->csi->cuf($count),
            'D' => $this->csi->cub($count),
            'H', 'f' => $this->csi->cup(
                ($params[0] ?? -1) === -1 ? 1 : ($params[0] ?? 1),
                ($params[1] ?? $params[0] ?? -1) === -1 ? 1 : ($params[1] ?? 1),
            ),
            'm' => $this->csi->sgr($params),
            'J' => $this->csi->ed($params[0] ?? 0),
            'K' => $this->csi->el($params[0] ?? 0),
            'r' => $this->csi->decstbm(
                ($params[0] ?? -1) === -1 ? 1 : $params[0],
                ($params[1] ?? -1) === -1 ? $this->csi->gridRows() : $params[1],
            ),
            'h' => $this->csi->decset($params[0] ?? 0, $prefix),
            'l' => $this->csi->decrst($params[0] ?? 0, $prefix),
            'g' => $this->csi->tbc($params[0] ?? 0),
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
