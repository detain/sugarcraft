<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Chat;

/**
 * The `--agent <name>` launch primary-agent family of C3 (RS-AGENT): the
 * launch-context registration delegate and the buildChat-tail application
 * hook that routes the preset through Chat's own switchPrimaryAgent travel.
 *
 * Extracted from `Bootstrap` under the size ratchet's M6 ruling path — the
 * behavior is byte-for-byte what the wf/preset lane shipped, relocated here
 * so `Bootstrap.php` sits under the ratchet again. `Bootstrap` is its only
 * consumer, and the family is deliberately SEPARATE from
 * {@see CarriesSessionLaunchPreset}: that one is the D1 session-id shelf and
 * this one is the agent shelf LaunchContext stores beside the model and
 * permission registers. Unset is byte-identical to the no-flag launch: the
 * hook returns the same Chat it was handed when no preset was registered.
 */
trait CarriesAgentLaunchPreset
{
    /**
     * Register `--agent <name>` for this process — delegates to {@see
     * LaunchContext::useAgentPreset()}, the launch-context shelf where the
     * store lives beside the model and permission registers (C3, RS-AGENT).
     */
    public static function useAgentPreset(?string $name): void
    {
        LaunchContext::useAgentPreset($name);
    }

    /**
     * Apply the registered `--agent <name>` preset to a launch Chat (C3,
     * RS-AGENT). `--agent <name>` applies the ONE primary-agent switch Chat
     * already travels (switchPrimaryAgent) at launch, so the flag and
     * `/agent use <name>` and Alt+A speak identically — same roster, same
     * narrow-only apply, same refusal rows for an unknown name. Applied
     * after withLaunchNotices so the switch's confirmation/refusal row lands
     * BELOW the launch warnings on the first frame, and before the lock so
     * the lock stays the bottom row. The switch is session-scoped by
     * construction (Chat's mutate choke clears it on a session change), so a
     * refused launch just keeps the default agent — there is nothing to
     * unwind. Tab chats never take this: the flag is the launch window's,
     * and buildChat's tab path returns before this tail (it reaches the
     * switch mid-session like any other conversation).
     */
    private static function appliedLaunchAgentPreset(Chat $chat): Chat
    {
        $agentPreset = LaunchContext::agentPresetOverride();

        return $agentPreset === null ? $chat : $chat->withLaunchPrimaryAgent($agentPreset);
    }
}
