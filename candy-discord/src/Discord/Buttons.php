<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Discord;

use SugarCraft\Code\Lang;

/**
 * Approval UI (§7.5). A `permission.requested` becomes a message whose
 * components-v2 buttons answer the ask: **Approve once**, **Always** (the
 * `alwaysScope` is what the server cascades), **Deny**.
 *
 * Single-use discipline: the DURABLE answer is on the serve side —
 * `permission.respond` is first-valid-answer-wins, so a pressed-twice button
 * is harmless (the second press gets `-32009 already_resolved`, surfaced as
 * the "already answered" edit that retires the components). The approver
 * identity is bound by the INTERACTION itself (Discord authenticates who
 * pressed); the bridge filters that id against the configured approver
 * roster (§9.6's binding law) before answering at all.
 *
 * TTL (§7.5): the ask lives 30 min server-side (`permission.pending` after
 * a restart re-presents the open ones); a stale button answers nothing —
 * the reply lands as `already_resolved` or a not-found — and the edit
 * retires it with a "question expired" line.
 */
final class Buttons
{
    // Component-type numbers from the components-v2 roster. These are the
    // one family of constants the bridge cannot exercise in this sandbox
    // (no Discord creds here): the P4 live-verification step checks them
    // against a real interaction and the self-check pins only OUR shapes.
    public const COMPONENT_CONTAINER = 17;
    public const COMPONENT_TEXT_DISPLAY = 10;
    public const COMPONENT_ACTION_ROW = 19;
    public const COMPONENT_BUTTON = 2;

    public const BUTTON_STYLE_PRIMARY = 1;
    public const BUTTON_STYLE_SUCCESS = 5;
    public const BUTTON_STYLE_DANGER = 4;

    public const INTERACTION_TYPE_BUTTON = 3;
    public const INTERACTION_TYPE_SLASH = 2;

    public const RESPONSE_TYPE_MESSAGE = 4;
    public const RESPONSE_TYPE_UPDATE_MESSAGE = 7;

    public const FLAG_EPHEMERAL = 64;
    public const FLAG_COMPONENTS_V2 = 32768;
    public const FLAG_SUPPRESS_EMBEDS = 4;

    public const PREFIX = 'ap';

    /** Verdict words (Discord side) → the `permission.respond` reply enum. */
    public const VERDICTS = [
        'once' => 'once',
        'always' => 'always',
        'deny' => 'reject',
    ];

    /**
     * The message payload that asks for an approval.
     *
     * §7.9 posture: when an approver roster is configured, each button
     * carries a components-v2 `allowed_users` list so Discord itself
     * greys the buttons for everyone else (the bridge-side answer gate in
     * {@see ConversationRouter::answerApproval()} — configured roster,
     * per-ask nonce, conversation binding — is the wall; the platform
     * filter is a courtesy, not the wall).
     * The per-button field spelling joins the offline-unverifiable family
     * disclosed at the top of this class; P4 live-check covers it.
     *
     * @param array<string,mixed> $ask        a `permission.requested` data object
     * @param list<string>        $allowedUsers Discord user ids, empty = everyone
     * @param string              $nonce      per-ask secret from the router's
     *                                        presentApproval; the button face
     *                                        of the answer gate requires it
     *                                        (C-1) — a copied or guessed
     *                                        custom_id without it is refused
     *
     * @return array<string,mixed>
     */
    public static function approvalPayload(array $ask, string $summary, array $allowedUsers = [], string $nonce = ''): array
    {
        $askId = (string) ($ask['askId'] ?? '');
        $tool = (string) ($ask['tool'] ?? 'tool');

        $buttons = [];
        foreach (['once' => ['channels.discord.button.approve_once', self::BUTTON_STYLE_SUCCESS], 'always' => ['channels.discord.button.always_allow', self::BUTTON_STYLE_PRIMARY], 'deny' => ['channels.discord.button.deny', self::BUTTON_STYLE_DANGER]] as $verdict => $spec) {
            [$labelKey, $style] = $spec;
            $button = [
                'type' => self::COMPONENT_BUTTON,
                'style' => $style,
                'label' => Lang::t($labelKey),
                'custom_id' => self::PREFIX . ':' . $askId . ':' . $verdict . ($nonce === '' ? '' : ':' . $nonce),
            ];
            if ($allowedUsers !== []) {
                $button['allowed_users'] = \array_map(
                    static fn (string $id): array => ['type' => 1, 'id' => $id],
                    \array_values($allowedUsers),
                );
            }
            $buttons[] = $button;
        }

        $container = [
            'type' => self::COMPONENT_CONTAINER,
            'components' => [
                ['type' => self::COMPONENT_TEXT_DISPLAY, 'content' => $summary],
                ['type' => self::COMPONENT_ACTION_ROW, 'components' => $buttons],
            ],
            'layout_metadata' => ['type' => 0, 'direction' => 0, 'sizing' => ['type' => 0], 'spacing' => 8],
            'spoiler' => false,
        ];

        return [
            'content' => Lang::t('channels.discord.approval.asked', ['tool' => $tool]),
            'components' => [$container],
            'flags' => self::FLAG_COMPONENTS_V2,
            'allowed_mentions' => ['parse' => []],
        ];
    }

    /** The edit that retires an answered (or expired) approval message. */
    public static function retiredPayload(string $line): array
    {
        return [
            'content' => $line,
            'components' => [],
            'flags' => self::FLAG_COMPONENTS_V2,
        ];
    }

    /**
     * The per-ask nonce (C-1) rides as a fourth segment; a three-segment
     * custom id (a card presented before the nonce law, or a hand-rolled
     * forgery) parses with an EMPTY nonce and the router's button face
     * refuses it fail-closed — old cards answer only through /approve.
     *
     * @param array<string,mixed> $interaction an INTERACTION_CREATE dispatch
     *
     * @return array{askId: string, verdict: string, nonce: string}|null
     */
    public static function parseButton(array $interaction): ?array
    {
        if ((int) ($interaction['type'] ?? 0) !== self::INTERACTION_TYPE_BUTTON) {
            return null;
        }
        $customId = (string) ($interaction['data']['custom_id'] ?? '');
        $parts = \explode(':', $customId, 4);
        if (\count($parts) < 3 || $parts[0] !== self::PREFIX) {
            return null;
        }
        $verdict = self::VERDICTS[$parts[2]] ?? null;
        if ($verdict === null || $parts[1] === '') {
            return null;
        }

        return ['askId' => $parts[1], 'verdict' => $verdict, 'nonce' => $parts[3] ?? ''];
    }

    /** Same-chat text fallback (§7.5): `/approve <askId> [once|always|deny]`. */
    public static function parseTextApprove(string $text): ?array
    {
        if (\preg_match('#^/approve\s+(\S+)(?:\s+(once|always|deny))?$#i', \trim($text), $m) !== 1) {
            return null;
        }
        $verdict = self::VERDICTS[\strtolower($m[2] ?? 'once')] ?? null;

        return $verdict === null ? null : ['askId' => $m[1], 'verdict' => $verdict];
    }
}
