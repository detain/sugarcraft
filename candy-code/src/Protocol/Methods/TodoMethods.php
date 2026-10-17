<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\Scope;

/**
 * `todo.get` (Appendix O §6.3): the session's todo list as its `Todo` tool
 * last wrote it (roadmap 3.C) — `{content, status}` items in order, empty
 * when the agent has kept none.
 */
final class TodoMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('todo.get', Scope::Read, 'A session\'s todo list, as its Todo tool last wrote it.', self::get(...)));
    }

    /** @return array{items: list<array{content: string, status: string}>} */
    private static function get(CallContext $call, Params $params): array
    {
        return ['items' => $call->host(SessionMethods::sessionId($params))->todos()->toArray()];
    }
}
