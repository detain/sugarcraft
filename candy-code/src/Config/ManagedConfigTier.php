<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

/**
 * The managed (machine-wide) settings tier: its read seam, its pin-only
 * aiming, and the layer it hands the merged-config walk.
 *
 * Extracted from `Bootstrap` under the size ratchet's M6 ruling path when
 * the G1-L7 lane brought the managed tier into the CLI and pushed the file
 * past its ceiling — the behavior is byte-for-byte the seam that lane
 * shipped, relocated here so `Bootstrap.php` sits under the ratchet again.
 * `Bootstrap` is its only consumer; the static override lives here, which
 * is also where the ConfigWriteProducer census expects a reader-only family
 * to live: nothing in this trait can write.
 */
trait ManagedConfigTier
{
    /** Backing store for {@see systemConfigPath()}; pins reset it to null. */
    private static ?string $systemConfigPathOverride = null;

    /**
     * The managed tier's read target — {@see \SugarCraft\Code\Config\LayeredSettings::SYSTEM_CONFIG_PATH}
     * unless a pin has aimed it elsewhere through {@see useSystemConfigPath()}.
     * `--config` does NOT move this path: that override replaces layer 4 and
     * nothing else, and the settings editor's doors never consult this method
     * at all — no write seam in `src/` resolves to the managed path, which
     * {@see \SugarCraft\Code\Tests\Config\ConfigWriteProducerDocumentationDriftTest}
     * pins as structure.
     */
    public static function systemConfigPath(): string
    {
        return self::$systemConfigPathOverride ?? LayeredSettings::SYSTEM_CONFIG_PATH;
    }

    /**
     * Aim the managed-tier READ at another path, for pins; null (or blank,
     * same law as {@see useProjectRootForSettings()}'s blank refusal) restores
     * the fixed `/etc/candy-code/config.json`. Deliberately not an
     * environment variable — see {@see \SugarCraft\Code\Config\LayeredSettings::SYSTEM_CONFIG_PATH}
     * for why a movable managed path is the tier's own defeat. Not stored
     * through {@see LaunchContext}: this is a test seam, not a launch flag,
     * and keeping it here says so.
     */
    public static function useSystemConfigPath(?string $path): void
    {
        self::$systemConfigPathOverride = $path === null || trim($path) === '' ? null : $path;
    }

    /**
     * THE MANAGED TIER SITS ON TOP OF THE SESSION TIER (G1-L7, CCOPS-6): the
     * read-only machine-wide file ({@see \SugarCraft\Code\Config\LayeredSettings::systemLayer()},
     * aimed by {@see systemConfigPath()}) outranks the session tier as well —
     * its whole purpose is a control the person being managed cannot undo by
     * re-choosing in the settings view — while the environment and flags still
     * outrank it, for exactly the stated SessionSettings reason. It carries
     * only {@see \SugarCraft\Code\Config\LayeredSettings::LAYERED_KEYS}, so no
     * unlayered permission key reaches a reader through it, and like the
     * session tier it is laid on the merged result, not handed to
     * {@see LayeredSettings::merge()}. Both halves of the tool-removal diff
     * get it too, so a managed narrowing can never read as a project-tier
     * removal — and can never be blamed on one.
     *
     * @return array<string, mixed>
     */
    public static function managedLayer(): array
    {
        return LayeredSettings::systemLayer(self::systemConfigPath());
    }
}
