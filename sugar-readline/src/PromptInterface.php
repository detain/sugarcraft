<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * The contract Readline's pump drives a prompt through (finding 038).
 *
 * Before this seam `run(object $prompt)` duck-typed everything, and every
 * missing method failed SILENTLY: no `view()` never painted, no
 * `handleKey()` dropped controls, no `isSubmitted()`/`isAborted()` left
 * the loop idling forever on an injected driver. `run()` now checks the
 * four required methods at the boundary and throws naming the missing
 * one(s).
 *
 * What lives OUTSIDE the required set, on purpose:
 *  - `handleChar(string): ...` — the free-text arm. TextPrompt,
 *    TextareaPrompt and the list prompts take it; ConfirmationPrompt
 *    deliberately does not (its y/n answer rides `handleKey`, and adding
 *    the char arm would make the pump route 'y' away from the flip path).
 *    The pump keeps its is_callable seam for that arm.
 *  - `insertPastedText(string): ...` — the paste seam (finding 028); same
 *    optional treatment.
 *  - The final-answer accessor is shaped per prompt: `value(): string`
 *    (TextPrompt/TextareaPrompt), `result(): bool` (ConfirmationPrompt),
 *    `selectedValue(): ?string` (SelectionPrompt), `selectedValues():
 *    list<string>` (MultiSelectPrompt). Readline only returns the prompt;
 *    hosts read their own answer shape.
 *  - The width surface is its own optional contract: {@see SizingInterface}.
 *
 * All five shipped prompts implement this interface; the repaint
 * climb/clear arithmetic assumes `view()` never emits a trailing newline.
 */
interface PromptInterface
{
    /** The complete current frame body (rows separated by "\n", no trailing "\n"). */
    public function view(): string;

    /** Interpret a normalized symbolic key name (e.g. 'enter', 'up', 'ctrl_r'). */
    public function handleKey(string $key): static;

    /** True once the prompt holds a final accepted answer. */
    public function isSubmitted(): bool;

    /** True once the user cancelled (ctrl_c / esc gesture). */
    public function isAborted(): bool;
}
