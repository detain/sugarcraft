<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Support;

/**
 * Single source of truth for the printed version.
 *
 * `candy-codetime --version` renders this constant; bump it in lockstep
 * with the release tag, never fork the literal into bin/ or the docs.
 */
final class Version
{
    public const CURRENT = '0.1.0';

    private function __construct()
    {
    }
}
