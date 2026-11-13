<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-sql.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'sql'` namespace baked in (matching NAMESPACE below — the old docblock
 * named a namespace this class never registered). Translated strings live in
 * {@see ../lang/en.php}.
 *
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'sql';
    protected const DIR = __DIR__ . '/../lang';

    /**
     * Localized display text for a metric caption. Captions are English
     * identity tokens (counter ids, panel compositions and sampler series key
     * on them), so the catalogs keep them verbatim and only the pixel-drawing
     * call sites route through here; a caption with no `caption.*` entry
     * (SQL verbs such as SELECT) renders as written.
     */
    public static function caption(string $english): string
    {
        $key = 'caption.' . strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $english), '_'));
        $text = static::t($key);

        // The registry's last resort is the namespaced raw key.
        return $text === $key || $text === static::NAMESPACE . '.' . $key ? $english : $text;
    }
}
