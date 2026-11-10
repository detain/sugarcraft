<?php

/**
 * Bundle-local autoloader — registers ONLY the bundle's own namespace.
 *
 * The entrypoint is require_once'd by the P1 BundleChannelLoader INSIDE the
 * candy-code process, where the host vendor autoloader already serves every
 * sugarcraft/React/Ratchet class. What the host cannot know about is this
 * bundle's own subtree, so this file maps it relative to itself — which is
 * exactly the property a marketplace-installed bundle needs: after
 * `bundles install` the whole directory lands under
 * `~/.candy-code/bundles/candy-msteams/` and keeps resolving, with no
 * sibling-relative guesses at the host vendor.
 */

declare(strict_types=1);

if (\defined('CANDY_MSTEAMS_AUTOLOADED')) {
    return;
}
\define('CANDY_MSTEAMS_AUTOLOADED', true);

\spl_autoload_register(static function (string $class): void {
    $prefix = 'CandyCode\\Bundles\\MsTeams\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $relative = \substr($class, \strlen($prefix));
    $file = __DIR__ . '/' . \str_replace('\\', '/', $relative) . '.php';
    if (\is_file($file)) {
        require_once $file;
    }
});
