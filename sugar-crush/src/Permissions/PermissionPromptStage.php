<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * How far along a blocking permission prompt is at answering itself.
 *
 * A prompt is a full modal that owns the keyboard, so while it is up EVERY
 * printable rune reaches {@see \SugarCraft\Crush\Chat::handlePermissionKey()}
 * and nothing types. That made an ordinary slash command an answer: `/agents`
 * typed at a prompt used to hit `a` on its second keystroke and write a
 * session-long grant. This enum is the state that stops it — a prompt only
 * answers to a letter while it is {@see Armed}, and one non-answer keystroke
 * takes that away.
 *
 * A single enum rather than a pair of booleans because the three states are
 * mutually exclusive by construction: `armed && confirming` is not a state
 * the prompt has, and two flags would let it be built.
 */
enum PermissionPromptStage: string
{
    /**
     * The prompt is listening: `y`/`n`/`a`/Escape answer it.
     *
     * Every newly-raised prompt starts here — including each queued ask a
     * previous answer promotes ({@see \SugarCraft\Crush\Chat::answerPermission()}
     * re-enters through `requestPermission()`, which arms afresh), so a second
     * question is as answerable as the first rather than inheriting the state
     * the user left the first one in.
     */
    case Armed = 'armed';

    /**
     * A key that is not an answer has been pressed, so the answer keys are
     * inert until Enter re-arms.
     *
     * This is the whole fix: the user who is typing a message or a command is
     * not answering, and the first keystroke that proves it takes the answer
     * keys away for the rest of the burst.
     */
    case Disarmed = 'disarmed';

    /**
     * `a` was pressed at an armed prompt and the session-wide grant is waiting
     * on a second, deliberate `y`.
     *
     * `Always` is the only reply that outlives the call it answers, so it is
     * the only one that costs a confirm. `n`/Escape cancel back to
     * {@see Armed}; any other key cancels back to {@see Disarmed}.
     */
    case ConfirmingAlways = 'confirming-always';
}
