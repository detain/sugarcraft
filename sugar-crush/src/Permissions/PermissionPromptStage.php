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
 * session-long grant (it went through a confirm box for a while; the arm rule
 * alone is what stops it now). This enum is the state that stops it — a prompt only
 * answers to a letter while it is {@see Armed}, and one non-answer keystroke
 * takes that away.
 *
 * A single enum rather than a pair of booleans because the states are
 * mutually exclusive by construction: `armed && editing` is not a state
 * the prompt has, and two flags would let it be built.
 */
enum PermissionPromptStage: string
{
    /**
     * The prompt is listening: `y`/`n`/`a`/Escape answer it, `e` edits what
     * `a` would remember.
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
     * `e` was pressed at an armed prompt whose `a` would remember something:
     * the user is editing WHAT it remembers (`sed * | sort | uniq`) in the
     * draft box, prefilled with the suggestion.
     *
     * Enter saves it as the session grant and allows the call — unless it is
     * not a pattern for this tool that covers this very call, when the modal
     * says why and the editor stays open; Escape goes back to {@see Armed}
     * and puts the draft that was in the box back. (`a` itself answers at
     * once: the confirm that used to follow it is gone — the `a` row names
     * the scope before the key is pressed.)
     */
    case EditingScope = 'editing-scope';

    /**
     * `r` was pressed at an armed prompt: the user is typing a note for the
     * refusal (roadmap 1.C-3 / R-KEYBIND, "type a rejection note").
     *
     * The note is typed into the draft box, so every key but Enter and Escape
     * edits it; Enter refuses the call with the note as the feedback the
     * model reads, and Escape goes back to {@see Armed} leaving the text in
     * the box.
     */
    case WritingNote = 'writing-note';
}
