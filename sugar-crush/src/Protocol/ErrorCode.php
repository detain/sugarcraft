<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * The `sugarcrush.v1` error codes (Appendix O §6.2).
 *
 * The JSON-RPC 2.0 reserved range for the transport's own failures, and the
 * `-320xx` server range for this protocol's. A client branches on the
 * `data.kind` string an error carries, never on its message; {@see kind()} is
 * the kind an error of this code carries when nothing more specific applies.
 */
enum ErrorCode: int
{
    case ParseError = -32700;
    case InvalidRequest = -32600;
    case MethodNotFound = -32601;
    case InvalidParams = -32602;
    case Unauthorized = -32001;
    case NotInitialized = -32002;
    case Forbidden = -32003;
    case NotFound = -32004;
    case Conflict = -32009;
    case Busy = -32010;
    case RateLimited = -32011;
    case PermissionModeRefused = -32020;
    case UnsupportedInServer = -32030;
    case Internal = -32099;

    /** The default `data.kind` of an error with this code. */
    public function kind(): string
    {
        return match ($this) {
            self::ParseError => 'parse_error',
            self::InvalidRequest => 'invalid_request',
            self::MethodNotFound => 'method_not_found',
            self::InvalidParams => 'invalid_params',
            self::Unauthorized => 'unauthorized',
            self::NotInitialized => 'not_initialized',
            self::Forbidden => 'forbidden',
            self::NotFound => 'not_found',
            self::Conflict => 'conflict',
            self::Busy => 'busy',
            self::RateLimited => 'rate_limited',
            self::PermissionModeRefused => 'permission_mode_refused',
            self::UnsupportedInServer => 'unsupported_in_server',
            self::Internal => 'internal',
        };
    }

    /** Whether a client may simply retry a request that failed with this code. */
    public function isRetryable(): bool
    {
        return $this === self::Busy || $this === self::RateLimited;
    }
}
