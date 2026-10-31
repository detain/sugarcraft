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
use SugarCraft\Code\Server\Push\PushSubscription;
use SugarCraft\Code\Server\Push\PushSubscriptionStore;
use SugarCraft\Code\Server\Push\WebPushSender;
use SugarCraft\Code\Lang;

/**
 * Web Push registration (C-WEB-5.d): the three RPCs that turn a browser's
 * `PushSubscription` into a server-side row, and the VAPID public key the
 * browser needs to create one. The transport behind them — RFC 8291 cipher,
 * RFC 8292 VAPID, allowlisted dialing — was welded by C-WEB-5.c; this class
 * is its first production consumer.
 *
 * THE ALLOWLIST DOOR IS THE FIRST WORD. `push.subscribe` refuses an endpoint
 * whose host is not a known push service BEFORE storing it, re-reading the
 * sender's own static policy ({@see WebPushSender::endpointAllowed}) — no
 * second validator, and no junk row that a later trigger would redial and
 * re-refuse forever. {@see PushSubscription::new} has already refused every
 * shape law (https, no credentials, default port, pchar path/query, key
 * sizes) by construction; the allowlist is the policy layer on top, and the
 * SSRF address door stays single-sourced inside the sender at dial time.
 *
 * WRITE SCOPE OWNS THE WRITES. Subscribe and unsubscribe are write-scope
 * methods because a stored endpoint is a standing instruction to contact a
 * browser on this server's behalf; the key read (`push.vapidKey`) is a plain
 * read — the public half of a pair the server would happily paste in its own
 * docs. Rows belong to the CALLER's principal class, so unsubscribe can
 * only ever drop rows the same class stored (see the store).
 */
final class PushMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('push.vapidKey', Scope::Read, 'The VAPID public key (RFC 8292, base64url of the 65-octet P-256 point) this server signs push messages with — the value the browser passes to PushManager.subscribe(). The private half never leaves the server.', self::vapidKey(...)));
        $registry->add(MethodSpec::new('push.subscribe', Scope::Write, 'Store this browser\'s push endpoint so the server can notify it when a question is waiting or a turn finished while no client follows the session. The endpoint must be an https URL at a known push service (FCM, Mozilla, Apple, Microsoft); anything else is refused and nothing is stored. Keys arrive as the browser reports them (p256dh: base64url P-256 point, auth: base64url 16 octets). name opts the row into carrying the session title in the payload — WITHOUT it every notification stays redacted generic text.', self::subscribe(...), true));
        $registry->add(MethodSpec::new('push.unsubscribe', Scope::Write, 'Drop the stored row for this push endpoint, when this client\'s principal class owns it; an unknown or foreign-owned endpoint answers false.', self::unsubscribe(...), true));
    }

    /** @return array<string, mixed> */
    private static function vapidKey(CallContext $call, Params $params): array
    {
        return ['publicKey' => $call->server->pushVapidPublicKey()];
    }

    /** @return array<string, mixed> */
    private static function subscribe(CallContext $call, Params $params): array
    {
        $endpoint = $params->string('endpoint', 4096);
        $keys = $params->object('keys');
        $p256dh = $keys['p256dh'] ?? null;
        $auth = $keys['auth'] ?? null;
        if (!\is_string($p256dh) || !\is_string($auth)) {
            throw RpcError::invalidParams('keys.p256dh and keys.auth must both be strings');
        }
        $withName = $params->bool('name', false);

        $refusal = PushSubscription::endpointRefusal($endpoint);
        if ($refusal !== null) {
            throw RpcError::of(ErrorCode::InvalidParams, $refusal, 'endpoint_refused');
        }
        if (!WebPushSender::endpointAllowed((string) parse_url($endpoint, PHP_URL_HOST))) {
            throw RpcError::of(
                ErrorCode::InvalidParams,
                Lang::t('serve.push.endpoint_denied', ['host' => (string) parse_url($endpoint, PHP_URL_HOST)]),
                'endpoint_denied',
            );
        }
        try {
            $subscription = PushSubscription::new($endpoint, $p256dh, $auth);
        } catch (\InvalidArgumentException $shape) {
            throw RpcError::of(ErrorCode::InvalidParams, $shape->getMessage(), 'endpoint_refused');
        }

        if (!$call->server->pushStore()->put($subscription, $call->client->principal(), $withName)) {
            throw RpcError::of(ErrorCode::Internal, 'the subscription could not be persisted', 'push_store_unavailable');
        }

        return ['subscribed' => true, 'keyHash' => PushSubscriptionStore::keyHash($endpoint)];
    }

    /** @return array<string, mixed> */
    private static function unsubscribe(CallContext $call, Params $params): array
    {
        $endpoint = $params->string('endpoint', 4096);

        return ['unsubscribed' => $call->server->pushStore()->remove($endpoint, $call->client->principal())];
    }
}
