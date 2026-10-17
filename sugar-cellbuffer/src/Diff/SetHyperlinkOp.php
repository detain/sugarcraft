<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

use SugarCraft\CellBuffer\Hyperlink;

/**
 * Open or close an OSC 8 hyperlink at the current cursor position.
 *
 * When $hyperlink is non-null, emits the OSC 8 opening sequence
 * with url and optional id.
 *
 * When $hyperlink is null, emits the OSC 8 closing sequence.
 *
 * @readonly
 */
final class SetHyperlinkOp extends DiffOp
{
    public function __construct(
        public readonly ?Hyperlink $hyperlink,
    ) {}
}
