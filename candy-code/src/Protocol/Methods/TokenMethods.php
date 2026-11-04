<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Server\Auth\ViewerTokens;

/**
 * Viewer-token grants over the wire (X-DESIGN1 §2.1, finding G4-G; the handler
 * chain the G4-E rider spec pins into the schema).
 *
 * THREE METHODS, the lifecycle's three moves — all ADMIN scope, which is the
 * exclusion that makes the grant read-only by construction: a viewer client
 * carries only the scopes its own grant lists ({@see ViewerTokens::SCOPES} is
 * `['read']`), so the scope wall refuses every viewer token operation before a
 * handler runs. A viewer can never mint, enumerate, or cut the grants that
 * gate it.
 *  - `token.grant` — mint one grant; the raw `cv1_…` token is in THIS answer
 *    and nowhere else, ever — `token.list` reads from the hashed at-rest store
 *    and no other surface echoes it (§2.1 law 2).
 *  - `token.list` — the grants in force: id, label, scopes, session pins and
 *    the two stamps. The hash never leaves the store; the token was never
 *    kept there to begin with.
 *  - `token.revoke` — cut one grant by id. An unknown id and an already-cut
 *    one answer identically ({@see ViewerTokens::revoke()} is a plain bool on
 *    purpose): the wire carries no oracle for which ids ever were.
 *
 * Rotation is NOT a fourth method — it stays a local act
 * (`candy-code auth rotate-token`), and the store revokes every outstanding
 * viewer grant as it happens (§2.1 law 3).
 *
 * The store is reached the way every other workspace service is — through the
 * locator — because `Dispatcher` handlers have no view into the HTTP layer's
 * {@see \SugarCraft\Code\Server\Auth\AuthContext}. The serve path registers
 * one here; hosts without it (the cross-root workspace host, a bare test
 * fixture) refuse {@see ErrorCode::UnsupportedInServer} rather than pretend.
 */
final class TokenMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('token.grant', Scope::Admin, 'Mint one viewer-token grant; the answer carries the raw token exactly once.', self::grant(...), true));
        $registry->add(MethodSpec::new('token.list', Scope::Admin, 'List the viewer-token grants in force — never the hashes, never a token.', self::list(...)));
        $registry->add(MethodSpec::new('token.revoke', Scope::Admin, 'Revoke one viewer-token grant by id; unknown and already-revoked answer alike.', self::revoke(...), true));
    }

    /** @return array<string, mixed> */
    private static function grant(CallContext $call, Params $params): array
    {
        $tokens = self::store($call);

        $label = $params->string('label', ViewerTokens::LABEL_MAX);
        $scopes = $params->strings('scopes', 8, 32);
        if ($scopes === []) {
            throw RpcError::invalidParams('at least one scope is required', 'invalid_scopes');
        }

        foreach ($scopes as $scope) {
            if (!\in_array($scope, ViewerTokens::SCOPES, true)) {
                throw RpcError::invalidParams('viewer scopes are read-only in v1', 'scope_not_supported');
            }
        }

        $days = $params->int('days', ViewerTokens::DEFAULT_DAYS, 1, ViewerTokens::MAX_DAYS);
        $sessionIds = $params->has('sessionIds') ? $params->strings('sessionIds') : null;

        try {
            $grant = $tokens->grant($label, $sessionIds, $days);
        } catch (\InvalidArgumentException $e) {
            throw RpcError::invalidParams($e->getMessage(), 'invalid_grant');
        } catch (\RuntimeException $e) {
            // The store's two refusals are the cap and a failed persist — one
            // reads to the operator as "revoke one first", the other as
            // "nothing was minted". Both messages are the store's own.
            if (str_contains($e->getMessage(), 'cap')) {
                throw RpcError::of(ErrorCode::Conflict, $e->getMessage(), 'grant_cap_reached');
            }

            throw RpcError::of(ErrorCode::Internal, $e->getMessage(), 'token_store_unavailable');
        }

        return [
            'id' => $grant['id'],
            'token' => $grant['token'],
            'label' => $grant['label'],
            'scopes' => $grant['scopes'],
            'createdAt' => self::stamp($grant['createdAt']),
            'expiresAt' => self::stamp($grant['expiresAt']),
        ];
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $tokens = self::store($call);

        $rows = [];
        foreach ($tokens->list() as $grant) {
            $row = [
                'id' => $grant['id'],
                'label' => $grant['label'],
                'scopes' => $grant['scopes'],
                'createdAt' => self::stamp($grant['createdAt']),
                'expiresAt' => self::stamp($grant['expiresAt']),
            ];

            // An unpinned grant carries the key as null in the store; the
            // result spec simply omits it — nothing on the wire guesses at a
            // pin that was never set.
            if ($grant['sessionIds'] !== null) {
                $row['sessionIds'] = $grant['sessionIds'];
            }

            $rows[] = $row;
        }

        return ['tokens' => $rows];
    }

    /** @return array<string, mixed> */
    private static function revoke(CallContext $call, Params $params): array
    {
        $tokens = self::store($call);

        $id = $params->string('id', 64);
        if (!$tokens->revoke($id)) {
            throw RpcError::notFound('no viewer grant matches that id', 'token_not_found');
        }

        return ['revoked' => true];
    }

    /**
     * The store the serve path registered, or a refusal that says this host
     * keeps no viewer grants at all (the BgMethods `bg_unavailable` shape).
     */
    private static function store(CallContext $call): ViewerTokens
    {
        return $call->server->hub()->workspace()->service(ViewerTokens::class)
            ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this server supervises no viewer token store', 'tokens_unavailable');
    }

    private static function stamp(float $seconds): string
    {
        return date(\DateTimeInterface::ATOM, (int) $seconds);
    }
}
