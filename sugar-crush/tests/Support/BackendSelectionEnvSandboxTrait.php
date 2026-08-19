<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Clears the whole backend-selection environment chain for the duration of a
 * test, and puts it back afterwards.
 *
 * WHY THIS IS A TRAIT AND NOT A COPIED FOREACH. Nine test classes already carry
 * their own inline list of these variables. When
 * `$SUGARCRUSH_BACKEND_CMD_STREAM` was added, six OTHER classes turned out to
 * clear nothing at all — and a full `vendor/bin/phpunit` with either shell-out
 * variable merely EXPORTED in the developer's shell produced 1 error and 10
 * failures across exactly those six, because `Bootstrap::backend()` then returns
 * a `CommandBackend`/`StreamingCommandBackend` where the test asserted an
 * `EngineBackend`. The fragility predates the streaming variable (it reproduces
 * identically with the older one), but the variable doubled the number of
 * exports that trip it, and the README now tells users to set the new one.
 *
 * A tenth hand-written list would be the same defect one variable later, so the
 * list lives here ONCE. {@see CHAIN} is the thing to extend the day a sixth
 * variable joins the chain; every consumer picks it up for free.
 *
 * This does NOT redirect `HOME` — a persisted `provider` key in
 * `~/.sugar-crush/config.json` is tier 4 of the same chain and is the other half
 * of the sandbox. Use {@see HomeSandboxTrait} for that; classes that need both
 * use both.
 */
trait BackendSelectionEnvSandboxTrait
{
    /**
     * Every environment variable `Bootstrap`'s backend selection consults.
     *
     * In tier order (see `Bootstrap::backend()`): `SUGARCRUSH_PROVIDER` selects
     * the engine path, the two `*_BACKEND_CMD*` variables select the two
     * shell-out protocols, and `SUGARCRUSH_MODEL` overrides the model on
     * whichever path was chosen — so an ambient one changes the label a
     * selection reports even when it cannot change the selection itself.
     *
     * @var list<string>
     */
    private const CHAIN = [
        'SUGARCRUSH_PROVIDER',
        'SUGARCRUSH_BACKEND_CMD',
        'SUGARCRUSH_BACKEND_CMD_STREAM',
        'SUGARCRUSH_MODEL',
    ];

    /** @var array<string, string|false> */
    private array $backendSelectionEnvOriginal = [];

    private bool $backendSelectionEnvActive = false;

    /**
     * Unset every variable in {@see CHAIN}, remembering what was there.
     *
     * Idempotent: a second call inside one test does not overwrite the
     * remembered values, so a test may clear, set one deliberately, and still
     * get one clean restore.
     */
    protected function clearBackendSelectionEnv(): void
    {
        if (!$this->backendSelectionEnvActive) {
            foreach (self::CHAIN as $var) {
                $this->backendSelectionEnvOriginal[$var] = getenv($var);
            }
            $this->backendSelectionEnvActive = true;
        }

        foreach (self::CHAIN as $var) {
            putenv($var);
        }
    }

    /**
     * Restore the process's real values. Safe to call when nothing was ever
     * cleared, so a tearDown() need not guard it.
     */
    protected function restoreBackendSelectionEnv(): void
    {
        if (!$this->backendSelectionEnvActive) {
            return;
        }

        foreach ($this->backendSelectionEnvOriginal as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }

        $this->backendSelectionEnvActive = false;
    }
}
