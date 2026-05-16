<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * Factory for opening master/slave PTY pairs and reporting platform
 * capabilities.
 *
 * @see portable-pty.PtySystem
 */
interface PtySystem
{
    /**
     * Open a fresh master/slave PTY pair.
     *
     * @see creack/pty.Open()
     * @see portable-pty.PtySystem.open()
     */
    public function open(int $cols = 80, int $rows = 24): PtyPair;

    /**
     * Return platform capability flags.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;
}
