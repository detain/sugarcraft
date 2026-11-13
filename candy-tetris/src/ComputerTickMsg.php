<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Msg;

/**
 * The AI's own clock (candy-tetris-007/-085): the computer advances one
 * planned input per ComputerTickMsg, scheduled at the COMPUTER board's
 * gravity interval — no longer throttled to the human's GravityMsg chain,
 * so a slow human level cannot slow the opponent and a sprint does not
 * outrun it either.
 *
 * Mirrors the GravityMsg generation-tag law: the scheduled chain epoch is
 * embedded in the message and VsGame drops arrivals from a superseded
 * computer chain (restarts bump it, so a dead chain's in-flight tick can
 * never step the fresh pair).
 */
final class ComputerTickMsg implements Msg
{
    public function __construct(public readonly int $chain = 0)
    {
    }
}
