<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The reserved bridge session-name grammar, enforced at every LOCAL rename
 * surface (m54, reviews-1111).
 *
 * `agent:<agentId>:<channel>:<kind>:<id>` is the admission handle
 * {@see \SugarCraft\Code\Channels\ChannelToolPolicyService()} keys per-channel
 * tool policy by. A name minted through that grammar by a bridge is admitted
 * with a policy entry; a name REACHED by rename carries no admission, so the
 * policy service's fail-closed `refusingAll` branch silently strips every
 * tool from the session. Renaming INTO the grammar is therefore refused, the
 * same refusal the wire edge applies to `session.rename` (the constants and
 * the `CallContext`-aware arm live on
 * {@see \SugarCraft\Code\Protocol\Methods\SessionMethods} for the RPC side;
 * this class is the store-side twin for the TUI `/rename`, the session
 * picker, the CLI `session rename` verb and the automatic titler, none of
 * which have a CallContext to allow-list a minter against).
 *
 * The refusal message is byte-identical to the wire-side text on purpose:
 * one grammar, one sentence, whichever door the name tried.
 */
final class ReservedSessionGrammar
{
    /** Prefix of the reserved bridge session-name grammar. */
    public const PREFIX = 'agent:';

    /** The refusal sentence, shared verbatim with the wire-side arm. */
    public static function refusalMessage(): string
    {
        return 'the "agent:" session-name prefix is reserved for bridge channel policy';
    }

    public static function isReserved(string $name): bool
    {
        return \str_starts_with($name, self::PREFIX);
    }

    /**
     * @throws \InvalidArgumentException when $name collides with the grammar
     */
    public static function refuse(string $name): void
    {
        if (self::isReserved($name)) {
            throw new \InvalidArgumentException(self::refusalMessage());
        }
    }
}
