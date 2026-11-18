<?php

declare(strict_types=1);

namespace SugarCraft\Core\Undo;

/**
 * A destructive action waiting for a single-keystroke confirmation.
 *
 * The gate is the one candy-files applies to delete, copy and move: the
 * action is armed with a prompt, the next key either confirms it or cancels
 * it, and nothing else happens in between. `y` always confirms, and so does
 * the affirmative word the active locale teaches (German `j`, Russian `д`,
 * Turkish `e`). Every other rune cancels, which is the safe default for a
 * prompt the user did not read closely.
 *
 * The class holds the gate, not the screen. The application keeps its own
 * "which dialog is open" state, and an armed action is the value that state
 * carries while the prompt is up. Keeping it immutable means a prompt can be
 * re-rendered or dropped without the confirmation logic being touched.
 *
 * Mirrors the single-key confirm of candy-files' `Manager` (the M-7 rule that
 * only a printable keystroke can answer it).
 */
final class ArmedAction
{
    /**
     * The keystroke that always confirms, whatever the locale.
     */
    public const AFFIRMATIVE = 'y';

    private function __construct(
        public readonly string $kind,
        public readonly string $prompt,
        public readonly array $payload,
        public readonly string $localeAffirmative,
    ) {
    }

    /**
     * @param string               $kind        Application-defined action name, such as "delete".
     * @param string               $prompt      The text shown while the gate is armed.
     * @param array<string, mixed> $payload     What the action will do when confirmed.
     * @param string               $localeAffirmative  Extra confirming word for the active locale.
     *                                          The word is lower-cased before matching,
     *                                          so `J` and `j` configure the same key. The
     *                                          typed keystroke is compared as typed, so a
     *                                          capital `J` does not confirm, as with `Y`.
     *                                          An empty string adds nothing.
     */
    public static function new(
        string $kind,
        string $prompt,
        array $payload = [],
        string $localeAffirmative = '',
    ): self {
        return new self($kind, $prompt, $payload, $localeAffirmative);
    }

    /**
     * Whether a keystroke confirms the action. Anything else cancels.
     *
     * The comparison is against the rune as typed for `y`, and against the
     * lower-cased locale word, so a capital `Y` does not confirm. That matches
     * candy-files, which has always required the lowercase form.
     */
    public function confirms(string $rune): bool
    {
        if ($rune === '') {
            return false;
        }

        if ($rune === self::AFFIRMATIVE) {
            return true;
        }

        return $this->localeAffirmative !== ''
            && $rune === mb_strtolower($this->localeAffirmative);
    }
}
