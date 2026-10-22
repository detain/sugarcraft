<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Session\TitleSource;

/**
 * The Chat-side half of switching sessions: what a switch releases, what the
 * store says about the session coming forward, and the mutate() payload a
 * resume writes — as a stateless collaborator, per v4 split-plan S3.
 *
 * Every method is the Chat-era method moved byte-for-byte in behavior:
 *
 *   - {@see resets()} is {@see \SugarCraft\Code\Chat} 's
 *     `sessionChangeResets()` — the fields tied to the conversation being
 *     left, released whenever a different session comes to the front;
 *   - {@see storedName()} / {@see storedTitleSource()} are its
 *     `storedSessionName()` / `storedTitleSource()` readers over the store;
 *   - {@see resumed()} is the whole change map
 *     {@see \SugarCraft\Code\Chat::switchToSession()} spread into its
 *     mutate() — so the switch route and the launcher's `--resume` cannot
 *     drift from what a tab click already does.
 *
 * It holds nothing: each call receives the store (and the tab state, for
 * {@see resumed()}) it needs, so no lifecycle is added to the switch — the
 * lock family, which mutates lock handles held by identity, deliberately
 * stayed on Chat (v4 S3 disclosed residual).
 *
 * Mirrors no upstream file: opencode resumes through its server API,
 * charmbracelet has no multi-session tab strip.
 */
final class SessionSwitch
{
    /**
     * The state that belongs to the conversation being LEFT, reset whenever a
     * different session is put in front of the user: Chat's
     * {@see \SugarCraft\Code\Chat::switchToSession()} (picker Enter, tab
     * click, Ctrl+Tab), its palette New session, the lock re-take and a
     * background handoff landing. Shared so the routes cannot drift apart
     * again - the breaker reset was once present on `/clear` alone
     * (audit 15b-06).
     *
     * - `pendingCompactionId`: a `/compact` issued against the old transcript
     *   must not rewrite the new one when its summary lands.
     * - `pendingIdleCompaction`: the same rule for the CTX-5 idle fold —
     *   its summaries were written about the session being left behind.
     * - `queuedPrompts`: typed for the old conversation, not this one.
     * - `scrollOffset`: indexes into the transcript that went away.
     * - `consecutiveRefillCompactions`: the thrash breaker counts refills of ONE
     *   context - the same reason Chat's
     *   {@see \SugarCraft\Code\Chat::handleClearCommand()} resets it. Carried
     *   across, a tripped run in session A refused the first prompt of session B
     *   through the thrash-breaker refusal before B had compacted once.
     * - `lastActivityAt`: when the user last prompted the OLD session. Read
     *   against the new session's size it would judge B idle (or active) on A's
     *   clock; null is "idleness unknown", which the idle-compaction policy
     *   never prompts on - the same state a fresh launch or `--continue`
     *   starts in. The next real prompt stamps it again.
     *
     * @return array<string, mixed>
     */
    public static function resets(): array
    {
        return [
            'pendingCompactionId' => null,
            'pendingIdleCompaction' => null,
            'queuedPrompts' => [],
            'scrollOffset' => 0,
            'consecutiveRefillCompactions' => 0,
            'lastActivityAt' => null,
        ];
    }

    /**
     * The name the store holds for $sessionId, or null when it has none.
     */
    public static function storedName(
        SessionStore|EnhancedSessionStore|null $store,
        string $sessionId,
    ): ?string {
        if ($store === null) {
            return null;
        }

        $row = $store->getSession($sessionId);
        $stored = \is_array($row) ? (string) ($row['name'] ?? '') : '';

        return $stored !== '' ? $stored : null;
    }

    /**
     * Who set the name the store holds for $sessionId (its `title_source`),
     * or null when the row records none.
     */
    public static function storedTitleSource(
        SessionStore|EnhancedSessionStore|null $store,
        string $sessionId,
    ): ?TitleSource {
        $row = $store?->getSession($sessionId);

        return \is_array($row) ? TitleSource::fromStored($row['title_source'] ?? null) : null;
    }

    /**
     * The change map {@see \SugarCraft\Code\Chat::switchToSession()} mutates
     * with: resets + the new session's identity, tab, mint abandonment,
     * title source, and its transcript with the "resumed" notice appended.
     *
     * @param list<Message> $loadedHistory the switched-away-from transcript
     *        flush and new-session load already performed by the caller
     * @param SessionStore|EnhancedSessionStore|null $store the session catalog
     * @return array<string, mixed>
     */
    public static function resumed(
        array $loadedHistory,
        SessionTabState $tabs,
        SessionStore|EnhancedSessionStore|null $store,
        string $sessionId,
        ?string $name,
    ): array {
        return [
            ...self::resets(),
            'currentSessionId' => $sessionId,
            'currentSessionName' => $name,
            // ISS-102: wherever a session comes to the front, its tab opens.
            'openTabs' => $tabs->opening($sessionId, $name),
            // ISS-102: leaving the launched session abandons its
            // pending mint — an unspoken launch must never materialize.
            'sessionMint' => null,
            // P-A4: who named the resumed session, from its row — so an
            // auto-titled name stays replaceable and a user's stays latched.
            'currentSessionTitleSource' => $name === null ? null : self::storedTitleSource($store, $sessionId),
            'history' => [...$loadedHistory, Message::notice(
                Lang::t('chat.session.resumed', ['session' => $name ?? $sessionId]),
            )],
        ];
    }
}
