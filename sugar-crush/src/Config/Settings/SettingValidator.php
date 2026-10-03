<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * One check a candidate value must pass before the settings editor will write
 * it, so a UI save can never produce a file the next launch refuses or
 * silently ignores.
 *
 * NULL IS ALWAYS ACCEPTED. `null` is how a key is spelled "unset" (a reset
 * deletes the key rather than writing a default), and every reader in `src/`
 * already treats absence as "use the shipped default" — so a validator that
 * refused null would refuse the reset button.
 */
interface SettingValidator
{
    /**
     * @param mixed $value the candidate value for the key being validated
     * @param array<string, mixed> $all every candidate value, by key — what a
     *        cross-field check such as {@see Validator\ThresholdOrderValidator}
     *        reads; single-field validators ignore it
     * @return string|null an English reason the value is refused, or null when it passes
     */
    public function validate(mixed $value, array $all = []): ?string;
}
