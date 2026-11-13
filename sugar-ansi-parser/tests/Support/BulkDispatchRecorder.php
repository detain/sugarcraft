<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests\Support;

use SugarCraft\AnsiParser\Parser\BulkPrintHandler;

/**
 * The same recorder, additionally opted into the {@see BulkPrintHandler}
 * capability. Extending {@see DispatchRecorder} (rather than duplicating it)
 * is what makes the differential meaningful: both handlers run identical
 * formatting code, so any difference between their `calls` logs can only come
 * from the parser — not from two fixtures drifting apart (house precedent:
 * {@see CapableDispatchRecorder} for the subparams push).
 *
 * `printRun()` appends the raw arrival to {@see $runs} (for boundary
 * assertions) AND normalizes it into the classic per-char `print:<hex>` lines
 * so `calls` stays directly comparable with a plain DispatchRecorder.
 */
final class BulkDispatchRecorder extends DispatchRecorder implements BulkPrintHandler
{
    /** @var list<string> every printRun() arrival, in order, un-expanded */
    public array $runs = [];

    public function printRun(string $run): void
    {
        $this->runs[] = $run;
        foreach (str_split($run) as $byte) {
            $this->emit('print:' . bin2hex($byte));
        }
    }
}
