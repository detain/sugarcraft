<?php

declare(strict_types=1);

namespace CandyCore\Bits\Cursor;

use CandyCore\Core\Msg;

/** Cursor blink pulse for the cursor with id {@see $id}. */
final class BlinkMsg implements Msg
{
    public function __construct(public readonly int $id) {}
}
