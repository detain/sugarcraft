<?php

declare(strict_types=1);

namespace SugarCraft\Layout;

/**
 * Simplex tableau cell for the Cassowary algorithm.
 */
final class Tableau
{
    /** @var array<string, array<string, float>> Row-indexed: rowVar -> colVar -> coeff */
    public array $rows = [];

    /** @var array<string, float> Basic variable values (b constants) */
    public array $b = [];

    /** @var array<string, array<string, float>> Column index (colVar -> rowVars with non-zero) */
    public array $colIndex = [];

    /** @var array<string, bool> */
    public array $externalVars = [];

    /** @var array<string, bool> */
    public array $slackVars = [];

    /** @var array<string, bool> */
    public array $artificialVars = [];

    public int $nextSlackVar = 0;
    public int $nextArtificialVar = 0;

    public function __construct()
    {
    }
}
