<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

/**
 * The shared reader behind the `channels.<platform>` policy trees.
 *
 * §10 ruling D6: channel policy rides the USER tier only — the same law
 * {@see TrustedBundleHooks::trustedBundleChannelNames()} enforces for hook
 * wiring, restated here so a project checkout can never widen a channel's
 * reach (a cloned repo naming `channels.msteams.groups` would otherwise
 * hand its conversations to whatever bundles the user trusts). The D6
 * stripping happens one floor down in
 * {@see Bootstrap::readUserConfigWithoutProjectTier()}; this class is the
 * single place that shape is consumed, which is why both platform readers
 * delegate here rather than each re-deriving the merge.
 *
 * NEVER validates, NEVER throws: these maps are consulted at channel start
 * INSIDE an in-process bundle, where every fallback announces itself on the
 * bundle's own log line and an unusable transport still arrives at the
 * registry's start-failed refusal. `[]` when absent keeps the whole feature
 * dark — the bootstrap launch path must survive a garbage settings value
 * for a feature the user never enabled.
 */
final class ChannelsSettings
{
    /**
     * The user-tier policy map for one `channels.<platform>` key.
     *
     * @return array<string,mixed>
     */
    public static function userTierMap(string $key): array
    {
        $tree = Bootstrap::readUserConfigWithoutProjectTier()[$key] ?? null;

        return is_array($tree) ? $tree : [];
    }
}
