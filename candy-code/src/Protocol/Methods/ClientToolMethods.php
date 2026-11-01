<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Host\ClientTools\ClientToolProxy;
use SugarCraft\Code\Host\ClientTools\ClientToolRegistry;
use SugarCraft\Code\Host\ClientTools\ClientToolSpec;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;

/**
 * `tools.client.*` (C-TOOL-6.i): the wire door for client-hosted tools.
 *
 * A connected client that holds the `Write` scope may announce tools whose
 * bodies run in the CLIENT itself; from the next turn the model sees each one
 * as a `client__<name>` proxy whose call is relayed back over this same
 * connection ({@see \SugarCraft\Code\Host\ClientTools\ClientToolProxy} and the
 * `tool.client.call` event). Registration is per connection: the spec set
 * lives and dies with the socket, and a disconnect fails whatever calls were
 * still open rather than leaving the model waiting on a client that is gone.
 *
 * The whole surface sits behind one server setting, `server.clientTools`,
 * default off — turning this door on is the operator's deliberate act, not
 * something a session or a cloned repository can ask for. While it is off
 * both methods answer `client_tools_disabled`.
 *
 * Why these names carry THREE dots: the family is the `tools` namespace with
 * a `client` sub-namespace, mirroring the `tool.*`/`tools.*` split already on
 * the wire, and the proxy names the model sees (`client__*`) stay a different
 * shape so nothing here can be confused with a built-in tool.
 */
final class ClientToolMethods
{
    /** Longest accepted answer body, in bytes — the same 256 KiB the transcript truncates at. */
    public const MAX_RESULT_BYTES = 262_144;

    /** How many announcements one registration may carry (the registry's own bound, mirrored for the wire message). */
    public const MAX_TOOLS_PER_REGISTRATION = ClientToolRegistry::MAX_TOOLS_PER_CLIENT;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new(
            'tools.client.register',
            Scope::Write,
            'Announce client-hosted tools for this connection; an empty list withdraws them.',
            self::announce(...),
            sideEffects: true,
        ));
        $registry->add(MethodSpec::new(
            'tools.client.result',
            Scope::Write,
            'Answer one tool.client.call with the client\'s result.',
            self::answer(...),
            sideEffects: true,
        ));
    }

    /** @return array<string, mixed> */
    private static function announce(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));
        self::requireDoor($call);

        $announced = $params->raw('tools');
        if (!\is_array($announced) || !\array_is_list($announced)) {
            // The empty list is legal and IS a registration: it withdraws the
            // client's whole set under a fresh id.
            throw RpcError::invalidParams('tools must be a list of tool announcements');
        }

        $specs = [];
        foreach ($announced as $entry) {
            if (!\is_array($entry)) {
                throw RpcError::invalidParams('each tool announcement must be an object');
            }
            // The spec validates itself; the verdict word (bad_name, bad_schema,
            // ...) is the answer — the same single source the engine-side parser
            // uses, so a wire refusal and a constructed-spec refusal cannot
            // disagree about what is well-formed.
            $verdict = ClientToolSpec::refusal($entry);
            if ($verdict !== null) {
                throw RpcError::invalidParams(\sprintf(
                    'tool announcement rejected: %s (name, description, inputSchema with type "object", and an optional timeoutSeconds of %d..%d are what a tool is)',
                    $verdict,
                    ClientToolSpec::MIN_TIMEOUT_SECONDS,
                    ClientToolSpec::MAX_TIMEOUT_SECONDS,
                ));
            }
            /** @var array<array-key, mixed> $entry */
            $specs[] = ClientToolSpec::fromDecoded($entry);
        }

        try {
            $registrationId = $host->registerClientTools($call->client->id(), $specs);
        } catch (\InvalidArgumentException $e) {
            // Registry verdicts: bad_client_key / too_many_tools / duplicate_name
            // / name_conflict. All are the CALLER's announcement being bad, so
            // they surface as invalidParams, never as an internal error.
            throw RpcError::invalidParams(\sprintf('registration refused: %s', $e->getMessage()));
        }

        $names = [];
        foreach ($specs as $spec) {
            $names[] = ClientToolProxy::NAME_PREFIX . $spec->name;
        }

        return [
            'registrationId' => $registrationId,
            'names' => $names,
            // Turn tool rosters freeze when the turn is built; announcing
            // mid-turn cannot change the model's hands until the next one.
            'effective' => 'next_turn',
        ];
    }

    /** @return array<string, mixed> */
    private static function answer(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));
        self::requireDoor($call);

        // A stale, foreign, or already-settled call answers accepted:false —
        // NOT an error naming what it matched against, because a late answer
        // is ordinary on a flaky socket and the reply's bytes are free to the
        // sender (the callId is a hash; which registration lived under it is
        // not the answerer's business).
        $accepted = $host->resolveClientCall(
            $params->string('callId', 64),
            $params->string('registrationId', 64),
            $params->string('content', self::MAX_RESULT_BYTES),
            $params->bool('isError', false),
        );

        return ['accepted' => $accepted];
    }

    /**
     * The one setting that arms this whole surface. `server.clientTools` is a
     * launch-read setting (restart class), so this guard's guarantee is about
     * the launch: a server started without the door open never answers these
     * methods, whatever these handlers grow into later. Off answers the one
     * kind `client_tools_disabled` for registration AND result alike — a
     * client must not be able to settle a call through a door it could not
     * have registered under.
     *
     * @throws RpcError
     */
    private static function requireDoor(CallContext $call): void
    {
        if (!$call->server->config()->clientTools) {
            throw RpcError::of(
                ErrorCode::Forbidden,
                'this server has client-hosted tools disabled (server.clientTools)',
                'client_tools_disabled',
            );
        }
    }
}
