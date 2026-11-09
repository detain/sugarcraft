<?php

declare(strict_types=1);

namespace ChannelBridge;

/**
 * The key grammar (§9.1): every conversation the bridge binds carries
 * `<platform>:<account>:<channel-or-thread-id>` — and the `dmScope` ladder
 * decides which DMs COLLAPSE onto one session.
 *
 * Discord's thread model gives the ladder its third rung for free: a thread
 * IS a channel (its id is the key), so `per-channel-peer` maps naturally
 * (each thread is its own channel). Teams keys by conversation id with the
 * peer appended by scope.
 *
 * Pure functions; the SQLite side stores the verdict.
 */
final class Keys
{
    public const DM_MAIN = 'main';
    public const DM_PER_PEER = 'per-peer';
    public const DM_PER_CHANNEL_PEER = 'per-channel-peer';
    public const DM_PER_ACCOUNT_CHANNEL_PEER = 'per-account-channel-peer';

    public const SCOPES = [self::DM_MAIN, self::DM_PER_PEER, self::DM_PER_CHANNEL_PEER, self::DM_PER_ACCOUNT_CHANNEL_PEER];

    /**
     * The binding key for a DM per the §9.1 scope ladder.
     */
    public static function dmKey(string $platform, string $account, string $peerId, string $channelId, string $scope): string
    {
        switch ($scope) {
            case self::DM_MAIN:
                return "{$platform}:{$account}:dm:main";
            case self::DM_PER_CHANNEL_PEER:
                return "{$platform}:{$account}:dm:{$channelId}:{$peerId}";
            case self::DM_PER_ACCOUNT_CHANNEL_PEER:
                return "{$platform}:{$account}:dm:acc:{$channelId}:{$peerId}";
            case self::DM_PER_PEER:
            default:
                return "{$platform}:{$account}:dm:{$peerId}";
        }
    }

    /** A guild channel / thread key (Discord: a thread id is a channel id). */
    public static function channelKey(string $platform, string $account, string $channelOrThreadId): string
    {
        return "{$platform}:{$account}:{$channelOrThreadId}";
    }

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

    /**
     * Bot-loop protection (§7.3): a message whose author is our own
     * application id is NEVER input — we echo into that channel ourselves.
     * `allowBots` gates everything else: a foreign bot's message is noise
     * unless the operator opened the door, and even then the pairing floor
     * (no creds for strangers) still applies.
     */
    public static function ignoreAuthor(bool $isOurMessage, bool $authorIsBot, bool $allowBots): bool
    {
        if ($isOurMessage) {
            return true;
        }

        return $authorIsBot && !$allowBots;
    }
}
