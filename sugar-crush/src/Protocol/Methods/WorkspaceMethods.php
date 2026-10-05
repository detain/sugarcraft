<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Server\Workspace\Gateway;

/**
 * `workspace.list|open|close` (roadmap O-7): the methods
 * {@see Gateway} answers itself, in front of the server's own dispatcher.
 *
 * Registered here so they sit in the protocol's one roster — `server.hello`'s
 * `features.methods`, the schema, docs/SERVER.md's method table — like every
 * other method. The gateway intercepts them before the dispatcher ever sees
 * them, so a handler below runs only in a process with no gateway in front
 * (a workspace host child, an embedder's bare dispatcher): there is no other
 * root to list, open or close, and the call says so rather than pretending.
 */
final class WorkspaceMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('workspace.list', Scope::Read, 'The project roots this server serves: its own, and each running workspace host.', self::unrouted(...)));
        $registry->add(MethodSpec::new('workspace.open', Scope::Write, 'Start the workspace host for another project root, or find it running.', self::unrouted(...), true));
        $registry->add(MethodSpec::new('workspace.close', Scope::Write, 'Stop a workspace host; refused while one of its turns runs, unless force.', self::unrouted(...), true));
    }

    private static function unrouted(CallContext $call, Params $params): never
    {
        throw RpcError::of(ErrorCode::UnsupportedInServer, 'workspace methods are answered by the serve gateway; this process serves one root', 'no_gateway');
    }
}
