<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Acp;

use SugarCraft\Crush\Permissions\PermissionReply;

/**
 * A turn's permission question as the Agent Client Protocol asks it, and the
 * editor's answer as the session takes it (roadmap 5.9-1, Appendix O §6.12).
 *
 * THE QUESTION. A `permission.requested` event (the {@see
 * \SugarCraft\Crush\Backend\PendingAsk} the turn's child is blocked on) becomes
 * an agent→client `session/request_permission` REQUEST: the tool call it is
 * about (`toolCall`, a `tool_call_update` with the call's title, kind,
 * locations and arguments) and one option per answer the question offers —
 * `once` → `allow_once`, `always` → `allow_always` (offered only where the
 * gate alone asked, as in the TUI's modal), `reject` → `reject_once`. The
 * option ids are our reply values, so the answer needs no lookup table.
 *
 * THE ANSWER. `{"outcome": {"outcome": "selected", "optionId": …}}` is that
 * reply; `{"outcome": {"outcome": "cancelled"}}` — what a client answers for
 * every open question when it cancels the prompt — and anything malformed or
 * an error response are a reject: an answer that cannot be read must never
 * run the call.
 *
 * No blocking anywhere: the request goes out, the turn's child waits on its
 * own frame channel, and the reply is applied whenever the editor sends it
 * ({@see \SugarCraft\Crush\Host\SessionHost::answer()}), with every other
 * message still being read meanwhile.
 */
final class AcpPermissionBridge
{
    public const METHOD = 'session/request_permission';

    /** ACP's option kinds, by the reply each one gives. */
    private const KINDS = [
        'once' => 'allow_once',
        'always' => 'allow_always',
        'reject' => 'reject_once',
    ];

    private const NAMES = [
        'once' => 'Allow once',
        'always' => 'Always allow',
        'reject' => 'Reject',
    ];

    private function __construct()
    {
    }

    /**
     * The `session/request_permission` params for the question a
     * `permission.requested` event's $data describes.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function request(string $sessionId, array $data, AcpUpdateMapper $mapper): array
    {
        $tool = (string) ($data['tool'] ?? '');
        $arguments = \is_array($data['arguments'] ?? null) ? $data['arguments'] : [];
        $toolCall = $mapper->toolCall((string) ($data['toolCallId'] ?? ''), $tool, $arguments, AcpUpdateMapper::STATUS_PENDING);
        unset($toolCall['sessionUpdate']);

        $options = [];
        foreach (self::offered($data['options'] ?? null) as $reply) {
            $name = self::NAMES[$reply];
            if ($reply === 'always' && \is_string($data['alwaysScope']['tool'] ?? null)) {
                $name .= ' ' . $data['alwaysScope']['tool'] . ' this session';
            }
            $options[] = ['optionId' => $reply, 'name' => $name, 'kind' => self::KINDS[$reply]];
        }

        $reason = \is_string($data['reason'] ?? null) ? trim($data['reason']) : '';

        return [
            'sessionId' => $sessionId,
            'toolCall' => $toolCall,
            'options' => $options,
        ] + ($reason === '' ? [] : ['_meta' => ['sugarcrush' => ['reason' => $reason]]]);
    }

    /**
     * The reply a `session/request_permission` result gives; a reject for a
     * cancelled, malformed or unknown answer.
     */
    public static function reply(mixed $result): PermissionReply
    {
        $outcome = \is_array($result) ? ($result['outcome'] ?? null) : null;
        if (!\is_array($outcome) || ($outcome['outcome'] ?? null) !== 'selected' || !\is_string($outcome['optionId'] ?? null)) {
            return PermissionReply::Reject;
        }

        return PermissionReply::tryFrom($outcome['optionId']) ?? PermissionReply::Reject;
    }

    /** Whether $result is the client's "the prompt was cancelled" answer. */
    public static function isCancelled(mixed $result): bool
    {
        return \is_array($result) && \is_array($result['outcome'] ?? null) && ($result['outcome']['outcome'] ?? null) === 'cancelled';
    }

    /**
     * The replies offered, in a stable order, `reject` always among them —
     * refusing must always be on offer.
     *
     * @return list<string>
     */
    private static function offered(mixed $options): array
    {
        $offered = \is_array($options) ? $options : [];
        $replies = [];
        foreach (array_keys(self::KINDS) as $reply) {
            if (\in_array($reply, $offered, true) || $reply === 'reject') {
                $replies[] = $reply;
            }
        }

        return $replies;
    }
}
