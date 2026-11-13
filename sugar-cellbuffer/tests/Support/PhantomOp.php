<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests\Support;

use SugarCraft\CellBuffer\Diff\DiffOp;

/**
 * A seventh op type the encoder has never heard of — stands in for the
 * future DiffOp subclass that a silent `return ''` dispatch tail would
 * drop from the byte stream (sugar-cellbuffer-025's hazard shape).
 *
 * Lives OUTSIDE src/Diff/ on purpose: a class placed in the diff namespace
 * would tempt the encoder's cascade to claim it (the refusal is a roster
 * guarantee, not a location guarantee).
 */
final class PhantomOp extends DiffOp
{
}
