<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

/**
 * Model interface for embedding QuickPick in a Bubble-Tea-style application.
 *
 * Implement this to create a component that holds a QuickPick and responds
 * to messages (keystrokes) by updating its state.
 */
interface Model
{
    /**
     * Handle a message and return the updated model.
     *
     * @param QuickPick $hermit  The current QuickPick state
     * @param string $msg     A key event string (e.g. "enter", "ctrl+c", "backspace")
     * @return Model  A new model instance (may be $this for same-state)
     */
    public function update(QuickPick $hermit, string $msg): Model;

    /**
     * Render the view given the current QuickPick state.
     *
     * @param QuickPick $hermit  The current QuickPick state
     * @return string  The rendered view string
     */
    public function view(QuickPick $hermit): string;
}
