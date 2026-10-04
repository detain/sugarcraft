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
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundStopOutcome;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;

/**
 * `bg.*` (Appendix O §6.3): the workspace's background (`/bg`) sessions —
 * list them, read one's output from an offset, stop one. Spawning one from a
 * client, and re-adopting those a previous server left running, arrive with
 * roadmap O-4b.
 */
final class BgMethods
{
    private const MAX_OUTPUT_BYTES = 262_144;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('bg.list', Scope::Read, 'The background sessions this server supervises.', self::list(...)));
        $registry->add(MethodSpec::new('bg.output', Scope::Read, 'A background session\'s output from an offset.', self::output(...)));
        $registry->add(MethodSpec::new('bg.stop', Scope::Write, 'Stop a background session.', self::stop(...), true));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $supervisor = $call->server->hub()->workspace()->backgroundSupervisor;
        $sessions = $supervisor?->getActiveSessions() ?? [];

        return ['items' => \array_values(\array_map(static function (BackgroundSession $session): array {
            $row = $session->toArray();
            unset($row['output']);

            return $row;
        }, $sessions))];
    }

    /** @return array<string, mixed> */
    private static function output(CallContext $call, Params $params): array
    {
        $session = self::session($call, $params->string('bgId', 128));
        $offset = $params->int('offset', 0, 0);
        $limit = $params->int('limit', self::MAX_OUTPUT_BYTES, 1, self::MAX_OUTPUT_BYTES);
        $text = \substr($session->output, $offset, $limit);

        return [
            'bgId' => $session->id,
            'offset' => $offset,
            'text' => \mb_strcut($text, 0, \strlen($text), 'UTF-8'),
            'total' => \strlen($session->output),
            'status' => $session->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private static function stop(CallContext $call, Params $params): array
    {
        $bgId = $params->string('bgId', 128);
        $outcome = self::supervisor($call)->stopSession($bgId);
        if ($outcome === BackgroundStopOutcome::UnknownSession) {
            throw RpcError::notFound(\sprintf('no background session %s', $bgId), 'bg_not_found');
        }

        return ['bgId' => $bgId, 'outcome' => $outcome->value];
    }

    private static function session(CallContext $call, string $bgId): BackgroundSession
    {
        return self::supervisor($call)->getSession($bgId) ?? throw RpcError::notFound(\sprintf('no background session %s', $bgId), 'bg_not_found');
    }

    private static function supervisor(CallContext $call): BackgroundSupervisor
    {
        return $call->server->hub()->workspace()->backgroundSupervisor
            ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this workspace supervises no background sessions', 'bg_unavailable');
    }
}
