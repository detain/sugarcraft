<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use SugarCraft\Plugins\Support\PromptFence as PluginPromptFence;

/**
 * Host name for the fence-escape authority, which now lives in sugar-plugins.
 *
 * WHY A DELEGATE. The roster, the `<` rewrite, the chat-template control-token
 * defang and the Unicode Tags strip are shared with the channel bundles
 * (candy-discord, candy-msteams), which must neutralise untrusted text with the
 * same rules. Keeping one implementation in `SugarCraft\Plugins\Support\PromptFence`
 * means the host and the bundles cannot drift apart. The host name stays so no
 * call site changes. The design rationale (why `<` and not removal, why byte-
 * oriented patterns, why the Tags block is deleted) is documented on the lib class.
 *
 * SkillPathNudge::HEADER is not used by the escape itself, so the lib needs no
 * host reference; it is named in the lib's docblock only.
 */
final class PromptFence
{
    private function __construct()
    {
    }

    /**
     * The escaped roster, for tests and for any later guard that must know
     * exactly what this authority neutralises.
     *
     * @return list<string>
     */
    public static function tags(): array
    {
        return PluginPromptFence::tags();
    }

    /**
     * $payload with every Unicode tag character (U+E0000-U+E007F) deleted, and
     * the number deleted in $removed.
     */
    public static function stripUnicodeTags(string $payload, ?int &$removed = null): string
    {
        return PluginPromptFence::stripUnicodeTags($payload, $removed);
    }

    /**
     * $payload with every roster tag, chat-template control-token opener and
     * Unicode tag character neutralised, ready for a fence boundary.
     */
    public static function escape(string $payload): string
    {
        return PluginPromptFence::escape($payload);
    }
}
