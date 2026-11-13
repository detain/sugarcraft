<?php

declare(strict_types=1);

namespace SugarCraft\GitServer;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-git-server.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'gitserver'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * Voice standard for catalogue copy (operator-facing, every locale):
 * - Errors and refusals are plain third-person statements of what did not
 *   happen and why ("--admin requires an account name"), capitalised, no
 *   trailing full stop; `error()` in bin/ prepends the program prefix, so a
 *   value never bakes `candy-git-server:` in itself.
 * - Informational lines state the outcome in lower case ("note: ...",
 *   "warning: ...") or as a bare fact ("Persisted to {path}").
 * - Command names, flags, env vars and paths are code, never translated;
 *   help renders them in a fixed ASCII column so a CJK description cannot
 *   misalign the table.
 * - Every locale (en + 16 translations incl. zh-tw) carries exactly the
 *   English key set with the same placeholders; LocaleParityTest enforces it.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'gitserver';
    protected const DIR = __DIR__ . '/../lang';
}
