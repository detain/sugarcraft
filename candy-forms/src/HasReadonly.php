<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Mixin trait for {@see Field} implementations: read-only mode. A read-only
 * field still takes part in the form (focus, Tab navigation, render, value
 * reporting) but refuses the keys that would change its value, mirroring the
 * keepFilter precedent in {@see \SugarCraft\Forms\ItemList\ItemList} — the
 * control keeps walking, only the mutation door closes (E736 5.10, round 89;
 * upstream bubbles/huh has no readonly mode, so this is a SugarCraft-native
 * extension).
 *
 * The flag lives OUTSIDE each Field's private constructor — the same sanctioned
 * slot + carrier pattern as {@see HasHideFunc}: every rebuild path of every
 * label-trait class already funnels through `carryNonCtorState()`, so the
 * shared carrier gains one leg and the flag survives `mutate()`, focus/blur,
 * validation recompute and keystroke rebuilds without touching a single
 * constructor signature. The TraitStateCarryFamilyTest census lists the
 * non-ctor slots EXACTLY, so a future fifth slot reds there first.
 *
 * Two refusal shapes:
 *  - value editors (Input, Text, Slider, Color, Date, Confirm, FilePicker)
 *    refuse every KeyMsg — their arrows adjust values, not a cursor;
 *    `consumes()` releases its claims so the form navigates over them.
 *  - pickers (Select, MultiSelect) keep the cursor-motion keys per the
 *    ruling (navigation accepted, mutating keys refused): {@see
 *    isReadonlyNavigationKey()} classifies exactly the motion arms their
 *    own update() matches — Space/Enter toggle, '/'-filter and typed text
 *    stay refused.
 *
 * Render affordance: {@see readonlyTitleSuffix()} appends the Lang-keyed
 * `(read-only)` marker to the title line while the flag is on; the default
 * (`readonly === false`) renders byte-identical to the pre-5.10 widget.
 */
trait HasReadonly
{
    private bool $readonly = false;

    /**
     * Flip read-only mode. Like HasHideFunc's `withHideFunc`, the clone +
     * direct write is the trait's sanctioned pattern: the write lands on the
     * fresh clone before it is returned, so no other reference can observe a
     * half-mutated object.
     */
    public function withReadonly(bool $on = true): static
    {
        $clone           = clone $this;
        $clone->readonly = $on;
        return $clone;
    }

    /** True while the field refuses mutating input. */
    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    /**
     * Suffix appended to the rendered title line — a leading space plus the
     * Lang `field.readonly` marker while read-only, and the empty string
     * otherwise (the byte-identity door for every existing view pin).
     */
    protected function readonlyTitleSuffix(): string
    {
        return $this->readonly ? ' ' . Lang::t('field.readonly') : '';
    }

    /**
     * Cursor-motion keys accepted while a picker is read-only: the exact
     * motion set ItemList's own arms and MultiSelect's match consume —
     * Up/Down/Home/End/PageUp/PageDown and their vim runes j k g G.
     * Nothing here changes a value; toggle and filter keys deliberately
     * fall through to "not navigation".
     */
    public static function isReadonlyNavigationKey(Msg $msg): bool
    {
        if (!$msg instanceof KeyMsg) {
            return false;
        }
        if (match ($msg->type) {
            KeyType::Up, KeyType::Down, KeyType::Home, KeyType::End,
            KeyType::PageUp, KeyType::PageDown => true,
            default => false,
        }) {
            return true;
        }
        return $msg->type === KeyType::Char
            && !$msg->ctrl
            && in_array($msg->rune, ['j', 'k', 'g', 'G'], true);
    }
}
