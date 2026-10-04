<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * What a principal may do over the wire (Appendix O §6.2 `principal.scopes`,
 * §6.7). The owner — every credential this server mints today — holds all
 * four; a read-only viewer token, when one exists, holds `read` alone.
 */
enum Scope: string
{
    case Read = 'read';
    case Write = 'write';
    case Approve = 'approve';
    case Admin = 'admin';

    /** @return list<string> every scope, as `server.hello` advertises them */
    public static function owner(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
