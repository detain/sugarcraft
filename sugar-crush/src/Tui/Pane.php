<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Focus\FocusRing;

/**
 * Pane types for the SugarCrush TUI layout.
 *
 * Mirrors charmbracelet/crush TUI pane enumeration.
 *
 * @internal
 */
enum Pane: string
{
    case Chat = 'chat';
    case Input = 'input';
    case Skills = 'skills';
    case Agents = 'agents';
    case Files = 'files';
    case Tools = 'tools';
    case Settings = 'settings';
    case Help = 'help';
    case Menu = 'menu';

    /**
     * The panes Tab walks, in display order — the ring, and only the ring.
     *
     * This is SIX of the nine cases, and the three that are missing are
     * missing for a reason the history below records. Kept public so the
     * traversal order can be asserted against the tab strip that advertises
     * it; the two lists are declared separately (this one, and
     * `MenuBar::PANE_TABS`, which is private) and nothing but a test can stop
     * them drifting apart.
     *
     * @return list<self>
     */
    public static function tabCycle(): array
    {
        return [
            self::Chat,
            self::Files,
            self::Tools,
            self::Skills,
            self::Agents,
            self::Settings,
        ];
    }

    /**
     * The tab cycle as a {@see FocusRing}.
     *
     * `candy-focus` exists for exactly this and is dependency-free, so the
     * traversal — including the wrap at both ends, which is the part a
     * hand-rolled `match` chain gets subtly wrong — is the library's problem
     * rather than this enum's. Built per call because a PHP enum may not hold
     * state of any kind, static properties included; six ids is not worth a
     * cache.
     *
     * `ofStrict()` rather than `of()`: a duplicate id would silently shorten
     * the cycle, and this list is edited by hand every time a pane gains or
     * loses a renderer.
     */
    private static function ring(): FocusRing
    {
        return FocusRing::ofStrict(
            ...array_map(static fn(self $pane): string => $pane->value, self::tabCycle()),
        );
    }

    /**
     * Returns the next pane in the cycling order.
     *
     * Cycle: Chat → Files → Tools → Skills → Agents → Settings → Chat.
     *
     * @see step() for the fold-back rule and the history behind it.
     */
    public function next(): self
    {
        return $this->step(true);
    }

    /**
     * Returns the previous pane in the cycling order — Shift+Tab.
     *
     * Cycle: Chat → Settings → Agents → Skills → Tools → Files → Chat.
     *
     * Tab could only ever walk forward, so escaping a pane you overshot meant
     * five more presses. The reverse walk is {@see FocusRing::previous()}, so
     * this direction is the library's traversal rather than a second `match`
     * chain written out backwards — which is the version that goes stale when
     * a pane joins the strip and only one of the two chains is updated.
     *
     * @see step() for the fold-back rule and the history behind it.
     */
    public function previous(): self
    {
        return $this->step(false);
    }

    /**
     * Walk the ring one step, or fold back to Chat when off it.
     *
     * **The fold-back is deliberate and is not what "not in the ring" means.**
     * Tab used to walk all nine cases, so it stopped on Input, Settings and
     * Help — none of which appeared in the tab strip and none of which had a
     * renderer, leaving the user parked on a pane the UI never offered and
     * that drew nothing. Settings has since rejoined the strip, because it now
     * HAS a renderer ({@see \SugarCraft\Crush\Tui\Components\SettingsPane});
     * the reason it was excluded no longer holds. Input, Help and Menu still
     * draw nothing, so they stay off the strip.
     *
     * A six-id ring on its own expresses only "Input is not a member". It says
     * nothing about what Tab does while the user is somehow ON Input, and the
     * ring cannot be asked — `focus('input')` is a documented no-op for an
     * unregistered id, so handing it through unguarded would leave the ring
     * focused on whatever it was focused on last (position 0, Chat, on a ring
     * built fresh each call) and turn a real behaviour into an accident of
     * construction order. The guard below states it instead: off the ring, both
     * directions land on Chat.
     *
     * BOTH directions, symmetrically, and that is the decision worth naming.
     * The alternative — Shift+Tab from Input landing on Settings, the member
     * before Chat — is defensible as "the exact inverse of next()", but the
     * inverse property is unavailable here whatever we choose: fold-back is not
     * injective (three panes map onto Chat), so `X->next()->previous() === X`
     * cannot hold for them under any rule. It holds over the ring's six
     * members and only there, which is the domain the tests state it over.
     * Given that, the tie is broken by what fold-back is FOR: the user is
     * stranded on a pane that renders nothing and is pressing Tab to get out.
     * Chat is the anchor that always draws, so it is the right answer to
     * "get me out of here" regardless of which way they reached for it.
     */
    private function step(bool $forward): self
    {
        $ring = self::ring();

        if (!$ring->has($this->value)) {
            return self::Chat;
        }

        $moved = $forward
            ? $ring->focus($this->value)->next()
            : $ring->focus($this->value)->previous();

        // current() is nullable only for an empty ring, which ofStrict() above
        // cannot produce; Chat is the same anchor the fold-back uses.
        return self::tryFrom($moved->current() ?? '') ?? self::Chat;
    }

    /**
     * Returns a human-readable label for the pane.
     */
    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Input => 'Input',
            self::Skills => 'Skills',
            self::Agents => 'Agents',
            self::Files => 'Files',
            self::Tools => 'Tools',
            self::Settings => 'Settings',
            self::Help => 'Help',
            self::Menu => 'Menu',
        };
    }
}
