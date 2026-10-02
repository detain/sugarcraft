<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Validation for model-supplied values that {@see GitCommandHandlers} places
 * into a git argv.
 *
 * WHY THIS EXISTS (audit GIT-1): every handler argument arrives from the
 * model, and git parses any argv element that starts with `-` as an option
 * wherever options are still being read. Measured before this class:
 * `gitShow(ref: '--output=<file>')` ran `git show --format=… --no-patch
 * --output=<file>` and WROTE the file, turning a read-only tool that a rules
 * file may auto-allow into an arbitrary file write; `gitAdd(['-A'])`,
 * `gitRevert('--abort')` and friends had the same shape. `proc_open()` with an
 * argv array already rules out shell injection, so option position is the
 * whole remaining attack surface — and closing it takes two layers, because
 * neither is sufficient alone:
 *
 *  - REFUSAL here, for every value used where git expects a ref, a name or a
 *    key. No process is spawned for a refused value, which is the only layer
 *    that holds for commands whose parser does not honour a terminator
 *    (`git checkout`, `git reset`, git-lfs, git-flow — see the handlers).
 *  - A TERMINATOR (`--end-of-options` / `--`) at the call site wherever git
 *    honours one, so a value that ever slips past validation is still parsed
 *    as an operand rather than an option.
 *
 * Every method answers with an error string (refused) or null (accepted) so
 * the handlers can surface it as a {@see GitOperationResult::failure()}.
 */
final class GitArgument
{
    /**
     * Refuse a value git would parse as an option.
     *
     * @param string $label Human name of the argument, e.g. "Ref", used in the error.
     */
    public static function optionError(string $value, string $label): ?string
    {
        if (str_starts_with($value, '-')) {
            return "{$label} '{$value}' must not start with '-': git would parse it as an option";
        }

        return null;
    }

    /**
     * Refuse a branch name that `git check-ref-format --branch` would refuse.
     *
     * Implemented in PHP rather than by spawning `git check-ref-format`
     * because the rules are fixed and documented (git-check-ref-format(1)),
     * and a validator that needs a child process is one more process a
     * refused value would get to start. The leading-`-` rule is the
     * `--branch` mode's own addition and doubles as the option-injection
     * guard.
     *
     * @param string $label Human name of the argument, e.g. "Branch name".
     */
    public static function branchNameError(string $name, string $label = 'Branch name'): ?string
    {
        $optionError = self::optionError($name, $label);
        if ($optionError !== null) {
            return $optionError;
        }

        $invalid = "{$label} '{$name}' is not a valid git branch name";

        if ($name === '' || $name === '@') {
            return $invalid;
        }

        // ASCII control characters, DEL, space, and the characters git
        // reserves for revision syntax (~ ^ :), globbing (? * [) and
        // escaping (\).
        if (preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $name) === 1) {
            return $invalid;
        }

        if (
            str_contains($name, '..')
            || str_contains($name, '@{')
            || str_contains($name, '//')
            || str_starts_with($name, '/')
            || str_ends_with($name, '/')
            || str_ends_with($name, '.')
        ) {
            return $invalid;
        }

        foreach (explode('/', $name) as $component) {
            if (str_starts_with($component, '.') || str_ends_with($component, '.lock')) {
                return $invalid;
            }
        }

        return null;
    }
}
