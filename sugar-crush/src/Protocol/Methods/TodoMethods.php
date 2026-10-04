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

/**
 * `todo.get` (Appendix O §6.3): the name is reserved in v1 so a client can
 * probe for it, and answers `-32030` (`todo_unavailable`) until a session
 * keeps a todo list (roadmap 3.C, the Todo tool). The protocol is additive
 * within a major, so filling it in later breaks no client.
 */
final class TodoMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('todo.get', Scope::Read, 'A session\'s todo list (reserved; answers todo_unavailable until sessions keep one).', self::get(...)));
    }

    private static function get(CallContext $call, Params $params): never
    {
        SessionMethods::sessionId($params);

        throw RpcError::of(ErrorCode::UnsupportedInServer, 'sessions keep no todo list yet', 'todo_unavailable');
    }
}
