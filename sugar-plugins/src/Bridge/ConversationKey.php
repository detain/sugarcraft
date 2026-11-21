<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

/**
 * The shared conversation-key shape: "<platform>:<account>:<rest>". The rest
 * is platform-specific (a channel id, a DM ladder rung), so only the first
 * two segments are read here. A key with fewer segments yields empty strings
 * for the missing ones and never throws.
 */
final class ConversationKey
{
    /** @return array{platform: string, account: string, rest: string} */
    public static function parse(string $key): array
    {
        $parts = \explode(':', $key, 3);

        return [
            'platform' => $parts[0] ?? '',
            'account' => $parts[1] ?? '',
            'rest' => $parts[2] ?? '',
        ];
    }
}
