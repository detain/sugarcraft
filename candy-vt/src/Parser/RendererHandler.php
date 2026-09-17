<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\SubparamsAwareHandler;

/**
 * Renderer-path {@see Handler} that finally routes ESC bytes to the grid.
 *
 * candy-ansi's {@see HandlerAdapter} — the shared bridge from the parser to a
 * {@see CsiHandlerImpl} + OSC handler pair — implements `escDispatch()` as an
 * empty no-op (candy-ansi/src/Parser/HandlerAdapter.php:128-130). Every
 * two-byte escape therefore vanished on the vcr renderer path: `ESC 7`/`ESC 8`
 * (DECSC/DECRC), `ESC D`/`E`/`M` (IND/NEL/RI), `ESC H` (HTS), `ESC c` (RIS)
 * and the whole `ESC # …` line-rendition family never reached the cells, even
 * though the emulator handles all of them.
 *
 * Rather than edit candy-ansi (shared with a concurrent workstream), the fix
 * lives entirely in candy-vt: this decorator reuses the adapter for every
 * event it already understands (print/execute/CSI/OSC/DCS/SOS-PM-APC) and
 * takes over only `escDispatch()`, forwarding each recognised escape to a
 * concrete {@see CsiHandlerImpl} method. The two engines now agree on the ESC
 * roster the renderer can express; the remaining DEC semantics that need
 * charsets/modes/scrollback stay emulator-only as catalogued.
 *
 * Mirrors charmbracelet/x/vt ESC dispatch table (renderer subset).
 */
final class RendererHandler implements SubparamsAwareHandler
{
    public function __construct(
        private readonly CsiHandlerImpl $csi,
        private readonly HandlerAdapter $delegate,
    ) {
    }

    /**
     * The parser pushes the ECMA-48 colon continuation flags here — this
     * decorator, not the wrapped {@see HandlerAdapter}, is the object handed to
     * {@see \SugarCraft\Ansi\Parser\Parser::__construct()}, so it is the only
     * handler the capability check can see. Forward them down to the concrete
     * {@see CsiHandlerImpl} whose `sgr()` needs them to tell `CSI 4 : 3 m`
     * (curly underline) from `CSI 4 ; 3 m` (underline + italic): the parser
     * calls this immediately before the same-call-chain `csiDispatch()`.
     *
     * @param list<bool> $subparams
     */
    public function setSubparams(array $subparams): void
    {
        $this->csi->setSubparams($subparams);
    }

    public function printChar(string $rune): void
    {
        $this->delegate->printChar($rune);
    }

    public function execute(int $byte): void
    {
        $this->delegate->execute($byte);
    }

    /**
     * @param list<int> $params
     */
    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->delegate->csiDispatch($final, $params, $prefix, $intermediate);
    }

    public function oscDispatch(string $data): void
    {
        $this->delegate->oscDispatch($data);
    }

    /**
     * @param list<int> $params
     */
    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->delegate->dcsDispatch($final, $params, $prefix, $intermediate, $data);
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->delegate->sosPmApcDispatch($kind, $data);
    }

    /**
     * The one event the shared adapter dropped on the floor.
     *
     * @param int $final        byte following ESC (and following `#` for the DEC hash family)
     * @param int $intermediate intermediate byte 0x20-0x2F; 0 if none
     */
    public function escDispatch(int $final, int $intermediate): void
    {
        if ($intermediate === 0x23 /* '#' */) {
            // DEC "hash" family: DECDHL (`ESC # 3`/`ESC # 4`), DECSWL (`ESC # 5`)
            // and DECDWL (`ESC # 6`). Any other `#` final has no renderer
            // meaning (the emulator treats them as charset designators to ignore).
            $this->csi->escLineRendition($final);

            return;
        }

        if ($intermediate !== 0) {
            // Charset designators and friends: no renderer counterpart.
            return;
        }

        match ($final) {
            0x37 /* '7' */ => $this->csi->escSaveCursor(),
            0x38 /* '8' */ => $this->csi->escRestoreCursor(),
            0x44 /* 'D' */ => $this->csi->escIndex(),
            0x45 /* 'E' */ => $this->csi->escNextLine(),
            0x48 /* 'H' */ => $this->csi->escSetTabStop(),
            0x4D /* 'M' */ => $this->csi->escReverseIndex(),
            0x63 /* 'c' */ => $this->csi->escResetToInitialState(),
            default => null,
        };
    }
}
