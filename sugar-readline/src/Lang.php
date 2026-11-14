<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-readline.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'readline'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}; lookup follows the AGENTS.md law (exact locale
 * → base language → en → raw key), so an unknown key surfaces visibly
 * instead of silently rendering empty.
 *
 * Chrome strings only: prompts are data-driven, and user text (labels,
 * choices, validators' own messages) is NEVER routed here — see the
 * README "Internationalization" section.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'readline';
    protected const DIR = __DIR__ . '/../lang';
}
