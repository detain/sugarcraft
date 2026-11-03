<?php

declare(strict_types=1);

namespace SugarCraft\Metrics;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-metrics.
 *
 * Extends the shared {@see BaseLang} namespace-scoped wrapper (which
 * in turn drives the {@see \SugarCraft\Core\I18n\T} registry) with the
 * `'metrics'` namespace baked in. Translated strings live in
 * `lang/*.php` beside the package root.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'metrics';
    protected const DIR = __DIR__ . '/../lang';
}
