<?php

declare(strict_types=1);

namespace SugarCraft\Code\App\Tabs;

use SugarCraft\Core\Model;
use SugarCraft\Code\Tui\SessionTab;
use SugarCraft\Code\Tui\SessionTabs;

/**
 * The session deck: tab ledger, parked models, and per-tab attention.
 *
 * Sits on top of the {@see SessionTabs} renderer-state model and owns
 * the two things it was never given: a KEY SPACE of its own, and the
 * parked models behind the tabs. Deck keys are minted here and are
 * stable for the life of the tab; the underlying session id can change
 * under a tab (lazy mint, `/branch`) and only ever moves through
 * {@see rekey()}, which is why nothing in this class trusts a session
 * id as an identity.
 *
 * The detach/reattach/summary mutators on SessionTabs existed with no
 * production caller before this class; parking a model here drives
 * them, so a tab is never marked detached without its model leaving the
 * foreground, and never reattached without coming back active.
 *
 * Immutable, like every other member of the Elm chain: mutators return
 * new decks.
 */
final class SessionDeck
{
    /** No background activity worth reporting on this tab. */
    public const ATTENTION_IDLE = 'idle';

    /** The parked model on this tab has work in flight. */
    public const ATTENTION_WORKING = 'working';

    /** The parked model on this tab is waiting for a permission answer. */
    public const ATTENTION_WAITING = 'waiting';

    /** The tab settled while parked; the user has not seen it yet. */
    public const ATTENTION_UNSEEN = 'unseen';

    /** The tab's most recent background turn ended in failure. */
    public const ATTENTION_FAILED = 'failed';

    /** @var list<string> The attention vocabulary, in display order. */
    public const ATTENTION_STATES = [
        self::ATTENTION_IDLE,
        self::ATTENTION_WORKING,
        self::ATTENTION_WAITING,
        self::ATTENTION_UNSEEN,
        self::ATTENTION_FAILED,
    ];

    /**
     * @param SessionTabs $tabs Tab ledger carrying every deck key as its tab id.
     * @param array<string, Model> $parked Models of tabs out of the foreground, keyed by deck key.
     * @param array<string, string> $sessionOf Current session id per deck key.
     */
    private function __construct(
        private readonly SessionTabs $tabs,
        private readonly array $parked = [],
        private readonly array $sessionOf = [],
    ) {
    }

    /**
     * Mint the deck with its one launch tab.
     *
     * The tab key is chosen by the caller (the App) and survives every
     * later rekey of the session behind it. SessionTabs' own default
     * constructor mints a random-id tab the deck could never name, so
     * the deck is always seeded through here instead.
     */
    public static function forLaunch(string $tabKey, string $sessionId, string $sessionName): self
    {
        $tab = new SessionTab(id: $tabKey, sessionName: $sessionName, isActive: true);

        return new self(
            tabs: new SessionTabs([$tabKey => $tab], [$tabKey]),
            sessionOf: [$tabKey => $sessionId],
        );
    }

    /**
     * Return the tab ledger for the renderer.
     */
    public function tabs(): SessionTabs
    {
        return $this->tabs;
    }

    /**
     * @return list<string> Deck keys in tab order.
     */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->tabs->tabs() as $tab) {
            $keys[] = $tab->id;
        }

        return $keys;
    }

    /**
     * Return the number of open tabs.
     */
    public function count(): int
    {
        return $this->tabs->count();
    }

    /**
     * Return whether the deck carries this tab key.
     */
    public function has(string $tabKey): bool
    {
        foreach ($this->keys() as $key) {
            if ($key === $tabKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the key of the visible tab, or null when none is active.
     */
    public function activeKey(): ?string
    {
        return $this->tabs->activeTab()?->id;
    }

    /**
     * Return the session id currently behind a deck key.
     */
    public function sessionOf(string $tabKey): ?string
    {
        return $this->sessionOf[$tabKey] ?? null;
    }

    /**
     * Return the parked model behind a tab key, if the tab is parked.
     */
    public function model(string $tabKey): ?Model
    {
        return $this->parked[$tabKey] ?? null;
    }

    /**
     * Open a tab under $tabKey and make it visible.
     *
     * Re-opening a live key is refused as a no-op — the tab id space is
     * the deck's identity, so an existing key is never second-tab'd and
     * SessionTabs' append-unconditionally shape is guarded here rather
     * than at each caller.
     *
     * @return self
     */
    public function open(string $tabKey, string $sessionId, string $sessionName): self
    {
        if ($this->has($tabKey)) {
            return $this;
        }

        return $this->mutate([
            'tabs' => $this->tabs->openTab($tabKey, $sessionName),
            'sessionOf' => [...$this->sessionOf, $tabKey => $sessionId],
        ]);
    }

    /**
     * Close a tab and hand back its parked model for disposal.
     *
     * The deck's last tab is refused: the result is the same instance
     * with a null model, mirroring the invariant SessionTabs already
     * carries so the two can never disagree.
     *
     * @return array{0: self, 1: ?Model}
     */
    public function close(string $tabKey): array
    {
        if (!$this->has($tabKey) || $this->count() <= 1) {
            return [$this, null];
        }

        $model = $this->parked[$tabKey] ?? null;

        $parked = $this->parked;
        unset($parked[$tabKey]);
        $sessionOf = $this->sessionOf;
        unset($sessionOf[$tabKey]);

        return [
            $this->mutate([
                'tabs' => $this->tabs->closeTab($tabKey),
                'parked' => $parked,
                'sessionOf' => $sessionOf,
            ]),
            $model,
        ];
    }

    /**
     * Park $model as the tab leaves the foreground.
     *
     * The tab is marked detached on the ledger in the same step — one
     * call, no way to leave a model parked behind a tab the renderer
     * still shows as attached.
     */
    public function park(string $tabKey, Model $model): self
    {
        if (!$this->has($tabKey)) {
            return $this;
        }

        return $this->mutate([
            'tabs' => $this->tabs->detachTab($tabKey),
            'parked' => [...$this->parked, $tabKey => $model],
        ]);
    }

    /**
     * Take the parked model back out, reattaching and activating the tab.
     *
     * @return array{0: self, 1: ?Model} Null model when the key was never parked.
     */
    public function unpark(string $tabKey): array
    {
        $model = $this->parked[$tabKey] ?? null;

        $parked = $this->parked;
        unset($parked[$tabKey]);

        $deck = $this->mutate([
            'tabs' => $this->tabs->reattachTab($tabKey)->setActiveTab($tabKey),
            'parked' => $parked,
        ]);

        return [$deck, $model];
    }

    /**
     * Move the visible tab by a signed number of steps, wrapping.
     *
     * The deck walks with the same ring the ledger's own Ctrl+Tab keys
     * drive, so a cycle here and a keystroke there can never disagree;
     * magnitudes beyond one take repeated single steps because the ring
     * is per-keystroke.
     */
    public function cycle(int $delta): self
    {
        if ($delta === 0) {
            return $this;
        }

        $step = $delta > 0 ? SessionTabs::CTRL_TAB : SessionTabs::CTRL_SHIFT_TAB;
        $deck = $this;

        for ($i = abs($delta); $i > 0; $i--) {
            $next = $deck->tabs->handleKey($step);
            if ($next === null) {
                break;
            }
            $deck = $deck->mutate(['tabs' => $next]);
        }

        return $deck;
    }

    /**
     * Record what a parked tab should advertise on the tab strip.
     *
     * $attention must come from {@see ATTENTION_STATES}; validation is
     * the caller's (the App derives it from model state, never from
     * user input), so this stays a pure write onto the dormant ledger
     * mutator.
     */
    public function attention(string $tabKey, string $attention): self
    {
        return $this->mutate(['tabs' => $this->tabs->updateTabAttention($tabKey, $attention)]);
    }

    /**
     * Update the agent summary line shown under a tab.
     */
    public function summary(string $tabKey, string $summary): self
    {
        return $this->mutate(['tabs' => $this->tabs->updateTabSummary($tabKey, $summary)]);
    }

    /**
     * Point a deck key at a new session id without disturbing the tab.
     *
     * This is the ONLY way the session behind a tab moves: lazily
     * minted sessions get their real id late, and `/branch` swaps the
     * id under a still-open tab. The ledger key, parked model, and
     * attention all survive untouched. Unknown keys are refused — a
     * rekey must never resurrect a closed tab.
     */
    public function rekey(string $tabKey, string $sessionId): self
    {
        if (!$this->has($tabKey)) {
            return $this;
        }

        return $this->mutate(['sessionOf' => [...$this->sessionOf, $tabKey => $sessionId]]);
    }

    /**
     * Build a new deck from existing state with the given changes merged in.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge([
            'tabs' => $this->tabs,
            'parked' => $this->parked,
            'sessionOf' => $this->sessionOf,
        ], $changes));
    }
}
