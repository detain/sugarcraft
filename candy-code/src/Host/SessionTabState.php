<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Tui\SessionTab;
use SugarCraft\Code\Tui\SessionTabs;

/**
 * The session-tab surface of the TUI, as a value object.
 *
 * These two fields lived directly on Chat (ISS-102); v4 split-plan S3 lifts
 * them out so the tab strip's data has one home while Chat keeps only the
 * thin {@see \SugarCraft\Code\Chat::openTabs()} delegate its renderer calls
 * and the {@see \SugarCraft\Code\Tui\SessionTabs} navigation model stays the
 * library widget it already was.
 *
 * Both fields are the Chat-era fields, byte for byte in meaning:
 *
 *   - `tabs` — the tabs the user has OPENED this run (ISS-102): the session
 *     tab strip renders these, not the whole store listing, and every route
 *     that puts a session in front of the user (picker Enter, tab click,
 *     Ctrl+Tab, palette New session, a background handoff landing) opens or
 *     focuses its tab here, so the active session is always exactly one
 *     active tab;
 *   - `lastClick` — the last click on a session tab, `[tab id, microtime]`,
 *     so a second click on the same tab within Chat's
 *     {@see \SugarCraft\Code\Chat::TAB_DOUBLE_CLICK_SECONDS} reads as a
 *     double-click. It is kept beside the tab model because sugar-mouse's
 *     `ClickResult` carries a zone and a button, never a click count.
 *
 * The active session's ID, name and lazy row minter deliberately did NOT
 * join this object: they are threaded in from the launch host
 * (`Bootstrap` passes `currentSessionId:` / `currentSessionName:` /
 * `sessionMint:`), a different lifetime than the tab strip's — the ID names
 * which tab is on screen for history and persistence, not which tabs exist.
 *
 * Immutability mirrors {@see \SugarCraft\Code\Permissions\PermissionFlow}:
 * public readonly promoted properties, and {@see with()} honours the
 * Chat-era mutate() change keys verbatim through {@see OVERRIDE_KEYS} so
 * every `mutate(['openTabs' => ...])` / `mutate(['lastTabClick' => ...])`
 * write relocated without a spelling change; an unknown key is ignored,
 * exactly as Chat's carry map ignored a non-parameter key before it widened.
 *
 * Mirrors no upstream file: opencode keeps opened tabs in its client store,
 * charmbracelet has no session tab strip.
 */
final class SessionTabState
{
    /**
     * Chat-era mutate() change key => this object's property. The parse
     * boundary between "a change naming the tab strip" and "a change naming
     * a Chat field"; kept as data so the rename lives in one readable place.
     *
     * @var array<string, string>
     */
    public const OVERRIDE_KEYS = [
        'openTabs' => 'tabs',
        'lastTabClick' => 'lastClick',
    ];

    /**
     * @param SessionTabs $tabs The tabs OPENED this run, with the active
     *        session as exactly one active tab (ISS-102).
     * @param ?array{0: string, 1: float} $lastClick `[tab id, microtime]` of
     *        the previous tab click, or null when no click is recent enough
     *        to matter.
     */
    public function __construct(
        public readonly SessionTabs $tabs = new SessionTabs(),
        public readonly ?array $lastClick = null,
    ) {
    }

    /**
     * The one-tab launch state (ISS-102): a fresh run has exactly the tab it
     * opened, not a tab per store row. With no session at all, the library
     * default stands.
     */
    public static function forLaunch(?string $sessionId, ?string $name): self
    {
        if ($sessionId === null) {
            return new self();
        }

        $tab = new SessionTab(id: $sessionId, sessionName: $name ?? '', isActive: true);

        return new self(tabs: new SessionTabs([$tab->id => $tab], [$tab->id]));
    }

    /**
     * This state with a fresh tab click memo — the write the double-click
     * detector makes when a tab is selected.
     *
     * @param ?array{0: string, 1: float} $pair
     */
    public function withClick(?array $pair): self
    {
        return new self(...array_merge(get_object_vars($this), ['lastClick' => $pair]));
    }

    /**
     * Apply tab-surface overrides to a copy of this state.
     *
     * @param array<string, mixed> $overrides Chat-era change keys (see
     *        {@see OVERRIDE_KEYS}); keys naming anything else are ignored.
     */
    public function with(array $overrides): self
    {
        $mapped = [];
        foreach (self::OVERRIDE_KEYS as $changeKey => $property) {
            if (\array_key_exists($changeKey, $overrides)) {
                $mapped[$property] = $overrides[$changeKey];
            }
        }

        return new self(...array_merge(get_object_vars($this), $mapped));
    }
}
