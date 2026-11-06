<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

/**
 * The `--session-id` pre-allocation family of D1 (RS-DRIVER a): the
 * launch-context registration delegates and the store-collision pre-flight
 * that `Bootstrap::sessionLaunchError()` consults on the new-session leg.
 *
 * Extracted from `Bootstrap` under the size ratchet's M6 ruling path when
 * the driver weld's D1 pick pushed the file past its ceiling — the behavior
 * is byte-for-byte what that lane shipped, relocated here so `Bootstrap.php`
 * sits under the ratchet again (the collision strings' StderrEmitterCensus
 * MESSAGE_SHAPES credit moved with them). `Bootstrap` is its only consumer;
 * the refusals stay raw-English strings RETURNED for failUsage to print —
 * the same stderr-alone decision (usage error, no session exists yet to name
 * it in a transcript) as the resume-not-found legs beside which they first
 * lived. Unset is byte-identical to the pre-D1 funnel: every method here
 * answers null or delegates when no preset was registered.
 */
trait CarriesSessionLaunchPreset
{
    /**
     * Delegates to {@see LaunchContext::useSessionLaunchPresetId()} — D1
     * (RS-DRIVER a): `--session-id` registers on the same launch-context
     * seam as the mode/target pair, so the one funnel every session-opening
     * surface reads is also the one that sees the preset.
     */
    public static function useSessionLaunchPresetId(?string $sessionId): void
    {
        LaunchContext::useSessionLaunchPresetId($sessionId);
    }

    /** The id `--session-id` pre-allocated for this launch, if any. */
    public static function sessionLaunchPresetId(): ?string
    {
        return LaunchContext::sessionLaunchPresetId();
    }

    /**
     * Why the pre-allocated id cannot be minted this launch, or null when it
     * can (D1, RS-DRIVER a): a pre-allocated id that already names a stored
     * row is a usage error, not a silent share-the-address — the driver
     * asked for THIS lineage address and something else owns it. Judged on
     * the new-session leg only: the parser refused `--session-id` beside
     * --continue/--resume/--incognito, and an attach launch answers from the
     * server's own id ({@see \SugarCraft\Code\Host\RemoteSessionHost::forLaunch()}
     * returns above the local store entirely), so elsewhere the preset could
     * only be inert.
     */
    private static function sessionPresetCollisionError(): ?string
    {
        $preset = LaunchContext::sessionLaunchPresetId();
        if ($preset === null || LaunchContext::sessionLaunchMode() !== 'new' || LaunchContext::incognito()) {
            return null;
        }

        try {
            $clash = self::sessionStore(false)->session($preset);
        } catch (\Throwable $e) {
            return 'candy-code: --session-id: cannot open the session store: ' . $e->getMessage();
        }

        if ($clash !== null) {
            return 'candy-code: --session-id ' . $preset . ': a session with that id is already stored; to reopen that session use --resume=' . $preset;
        }

        return null;
    }
}
