<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;

/**
 * `tool.output` (Appendix O §6.1, §6.3): a tool call's full output, in
 * slices, for a client whose `tool.finished` event carried it truncated
 * (`truncated: true` past 256 KiB).
 */
final class ToolMethods
{
    public const MAX_SLICE_BYTES = 1_048_576;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('tool.output', Scope::Read, 'A finished tool call\'s full output, from an offset.', self::output(...)));
    }

    /** @return array<string, mixed> */
    private static function output(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));
        $toolCallId = $params->string('toolCallId', 256);
        $offset = $params->int('offset', 0, 0);
        $limit = $params->int('limit', self::MAX_SLICE_BYTES, 1, self::MAX_SLICE_BYTES);

        $history = $host->history();
        for ($i = \count($history) - 1; $i >= 0; $i--) {
            $row = $history[$i];
            if (!$row instanceof Message) {
                continue;
            }
            foreach ($row->toolResults as $result) {
                if ($result->id !== $toolCallId) {
                    continue;
                }
                $content = $result->isError() ? (string) $result->error : $result->result;
                $slice = \mb_strcut($content, $offset, $limit, 'UTF-8');

                return [
                    'toolCallId' => $toolCallId,
                    'offset' => $offset,
                    'content' => $slice,
                    'total' => \strlen($content),
                    'isError' => $result->isError(),
                    'more' => $offset + \strlen($slice) < \strlen($content),
                ];
            }
        }

        throw RpcError::notFound(\sprintf('no finished tool call %s in this session', $toolCallId), 'tool_call_not_found');
    }
}
