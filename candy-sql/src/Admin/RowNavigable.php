<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

/**
 * Marker for admin pages that own row-level j/k/Up/Down navigation.
 *
 * App-level keys are decided in App::handleAdminKey before the page's own
 * update() ever sees them. Pages listed here take precedence for j, k and
 * the arrow keys — their cursor lives inside the table, and intercepting
 * those keys for the sidebar made their navigation handlers dead code
 * (docs-review-1023 M8). Pages without this marker (ServerStatus,
 * Dashboard) keep the arrow-key sidebar walk; digit keys, q, p and r stay
 * app-global for every page.
 */
interface RowNavigable
{
}
