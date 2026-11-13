<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-async.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * 'async' namespace baked in. This library's messages are English literals
 * at their sites; the catalogue in ../lang/en.php is empty scaffolding, not
 * a live translation set. Until a wiring decision lands, every Lang::t()
 * call falls through the base chain (exact → base → en → raw key) and
 * returns the namespaced key itself.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'async';
    protected const DIR = __DIR__ . '/../lang';
}
