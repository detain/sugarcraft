<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Process;

/**
 * candy-scriptui re-export of the candy-pty process contract so
 * {@see \SugarCraft\ScriptUi\Model\SpinModel} and all internal callers
 * can type against a single interface.
 *
 * @see \SugarCraft\Pty\Contract\Process for the canonical contract.
 */
interface Process extends \SugarCraft\Pty\Contract\Process
{
}
