<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Core\Msg;

/**
 * Settlement envelope of a form-level async validation round
 * (sugar-forms-020): {@see Form::update()} dispatched the batch on the
 * Enter/submit path, and this message carries the verdicts back when every
 * per-field {@see AsyncValidatable::validateAsync()} promise has settled.
 *
 * The `seq` generation guard mirrors the pendingAsyncSeq discipline the
 * async-suggestions twins already carry: a verdict from a superseded round
 * (a submit attempted, the form edited, another submit attempted) is
 * dropped at the Form door, never applied to state it no longer describes.
 *
 * Mirrors no upstream symbol: huh validates synchronously only (SugarCraft
 * extension, plan 5.2 shape).
 */
final readonly class FormAsyncValidatedMsg implements Msg
{
    /**
     * @param array<string,string> $errors fieldKey => blocking verdict (only failures present)
     * @param int                  $seq    the round this verdict belongs to
     */
    public function __construct(
        public array $errors,
        public int $seq,
    ) {
    }
}
