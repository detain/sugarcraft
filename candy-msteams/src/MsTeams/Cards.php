<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

/**
 * Approval cards — the Teams dialect of §8.4. An adaptive card carries the
 * ask (tool, command summary, session) and one Action.Submit per verdict;
 * Teams delivers the action's `data` back inside the `value` of a message
 * activity addressed to the bot, which the webhook unpacks and answers
 * through the router's roster wall. The roster riding the card is a
 * COURTESY display only — enforcement lives in ConversationRouter's
 * approver check, because a hand-crafted activity can name anyone.
 *
 * Retiring a card REPLACES it with a plain one-line card (§8.4's
 * answered-elsewhere posture): no dead buttons that would only collect a
 * refused second answer.
 *
 * The text fallback — `/approve <askId> [once|always|deny]` — is parsed
 * here too, so both answer paths share one verdict vocabulary, and the
 * fallback parser is the same one the router's `approve` command uses.
 *
 * Pure payload builders; no I/O, so the §8.5 shape law is testable offline.
 */
final class Cards
{
    /** Text/command vocabulary → serve verdict (§9.6: once|always|reject). */
    public const VERDICTS = ['once' => 'once', 'always' => 'always', 'deny' => 'reject'];

    /** Action.Submit data key that marks an approval answer. */
    public const DATA_KEY = 'candyApproval';

    public const CARD_SCHEMA = 'http://adaptivecards.io/schemas/adaptive-card.json';

    /**
     * The approval card for one ask. $ask is the permission.requested
     * payload; $summary is the pre-truncated single-line command digest the
     * caller built; $roster lists the configured approvers (courtesy text).
     *
     * @param array<string, mixed> $ask
     * @param list<string>         $roster
     *
     * @return array<string, mixed> the adaptive-card content object
     */
    public static function approvalCard(array $ask, string $summary, array $roster): array
    {
        $askId = (string) ($ask['askId'] ?? $ask['id'] ?? '');
        $body = [
            ['type' => 'TextBlock', 'weight' => 'Bolder', 'size' => 'Medium', 'text' => 'candy-code approval'],
            ['type' => 'TextBlock', 'wrap' => true, 'text' => $summary],
        ];
        if ($roster !== []) {
            $body[] = ['type' => 'TextBlock', 'isSubtle' => true, 'wrap' => true, 'text' => 'Approvers: ' . implode(', ', $roster)];
        }

        $actions = [];
        foreach (['once' => 'Allow once', 'always' => 'Allow always', 'deny' => 'Deny'] as $word => $title) {
            $actions[] = [
                'type' => 'Action.Submit',
                'title' => $title,
                'data' => [self::DATA_KEY => $askId, 'verdict' => $word],
            ];
        }

        return [
            '$schema' => self::CARD_SCHEMA,
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => $body,
            'actions' => $actions,
        ];
    }

    /** Wrap a card into the message-activity attachment envelope (§8.4). */
    public static function cardActivity(string $fromId, array $card): array
    {
        return [
            'type' => 'message',
            'from' => ['id' => $fromId],
            'attachmentLayout' => 'robust',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => $card,
            ]],
        ];
    }

    /**
     * Parse a same-chat approval answer. Returns null when the message is
     * not an approval line at all; on a hit, the ask id and the serve-side
     * verdict (default `once` on a bare /approve, same as the Discord
     * bundle's text fallback).
     *
     * @return array{askId: string, verdict: string}|null
     */
    public static function parseTextApprove(string $text): ?array
    {
        if (preg_match('#^/approve\s+(\S+)(?:\s+(once|always|deny))?$#i', trim($text), $m) !== 1) {
            return null;
        }

        return ['askId' => $m[1], 'verdict' => self::VERDICTS[strtolower($m[2] ?? 'once')] ?? 'once'];
    }

    /**
     * Extract an approval answer from a message activity's `value` object
     * (the Action.Submit data Teams echoes back). Null for ordinary posts.
     *
     * @param array<string, mixed> $value
     *
     * @return array{askId: string, verdict: string}|null
     */
    public static function parseSubmitValue(array $value): ?array
    {
        $askId = $value[self::DATA_KEY] ?? null;
        if (!\is_string($askId) || $askId === '') {
            return null;
        }
        $word = \strtolower((string) ($value['verdict'] ?? 'once'));

        return ['askId' => $askId, 'verdict' => self::VERDICTS[$word] ?? 'once'];
    }

    /**
     * The retired one-line card replacing a resolved ask; no actions remain,
     * so the answered card cannot collect a second click.
     */
    public static function retiredCard(string $line): array
    {
        return [
            '$schema' => self::CARD_SCHEMA,
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => [['type' => 'TextBlock', 'wrap' => true, 'isSubtle' => true, 'text' => $line]],
        ];
    }
}
