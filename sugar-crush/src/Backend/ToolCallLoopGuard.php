<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

/**
 * The repeat-call loop guard: one ledger per agentic turn that notices the
 * model calling the SAME tool with the SAME arguments and getting the SAME
 * result back, and escalates — a warning on the 3rd identical call, a refusal
 * from the 5th, and the end of the turn on the 8th.
 *
 * WHY IT EXISTS: the step ceiling went from 8 to 1000 (WAVE_PLAN_2 §5
 * "maxToolSteps"), and the only other brake on a runaway turn is the spend
 * cap — which never trips on a self-hosted SGLang endpoint, because that
 * provider reports $0 for every call. A model stuck re-reading one file would
 * otherwise burn a thousand provider round-trips before anything stopped it.
 *
 * WHAT "IDENTICAL" MEANS: tool name + canonical arguments (every string-keyed
 * map sorted by key, recursively, so `{"a":1,"b":2}` and `{"b":2,"a":1}` are
 * one call; lists keep their order because order is meaning there) + a hash of
 * the result. The result is in the key on purpose: re-running `git status`
 * while a build writes files, or polling a job until it finishes, is the SAME
 * call producing NEW information — legitimate, and never counted. So a call's
 * run of identical repeats resets to 1 whenever its result changes.
 *
 * Counting is per signature across the whole turn, not "consecutive calls
 * only": an A, B, A, B ping-pong is two loops interleaved and must be caught
 * as such, not hidden by the alternation.
 *
 * HOW IT IS DRIVEN, and why not from {@see \SugarCraft\Crush\Runtime}: the
 * guard sits on the hook chain ({@see \SugarCraft\Crush\Hooks\BuiltIn\RepeatCallGuardHook}
 * and {@see \SugarCraft\Crush\Hooks\BuiltIn\RepeatCallCountHook}),
 * which both of Runtime's dispatch paths already gate on. {@see beforeCall()}
 * is the PreToolUse half (refuse), {@see afterCall()} the PostToolUse half
 * (count + warn), and {@see EngineBackend::runTurn()} reads {@see endsTurn()}
 * at each step boundary. A refusal happens BEFORE the result exists, so it is
 * judged on the run so far: a call whose last four identical runs all
 * returned the same bytes is refused the fifth time, and the refused attempts
 * keep counting toward the turn-ending eighth.
 *
 * A concurrent batch is gated whole in Runtime's phase 1 before any member
 * runs, so five identical calls in ONE assistant message are all admitted (and
 * the 3rd-5th carry the warning); the refusal lands on the next step's
 * repeats. That is the hook chain's own ordering contract, not a gap here.
 *
 * Mutable on purpose and turn-scoped: the ledger is state that lives exactly
 * one turn, the way {@see \SugarCraft\Crush\Runtime}'s per-turn Task grant memo
 * does. A new turn is a new plan and starts from an empty ledger.
 */
final class ToolCallLoopGuard
{
    /** The identical call that earns a warning appended to its result. */
    public const WARN_AT = 3;

    /** The identical call that is refused instead of run. */
    public const REFUSE_AT = 5;

    /** The identical call (refused attempts included) that ends the turn. */
    public const END_TURN_AT = 8;

    /**
     * signature => the result hash of the current run and how many identical
     * calls that run holds (refused attempts included).
     *
     * @var array<string, array{result: string, count: int, tool: string}>
     */
    private array $ledger = [];

    /** The tool whose repeats ended the turn, or null while the turn may go on. */
    private ?string $endedBy = null;

    public static function new(): self
    {
        return new self();
    }

    /**
     * The PreToolUse half: null to let the call run, or the refusal text to
     * deny it with.
     *
     * Does not count an admitted call — {@see afterCall()} does, once the
     * result exists — so a rewriting hook chain re-scanning the same call
     * (see {@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()}) cannot
     * count it twice. A refusal IS counted here, because a refused call never
     * reaches PostToolUse.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function beforeCall(string $toolName, array $arguments): ?string
    {
        $signature = self::signature($toolName, $arguments);
        $entry = $this->ledger[$signature] ?? null;

        if ($entry === null || $entry['count'] < self::REFUSE_AT - 1) {
            return null;
        }

        $attempt = ++$this->ledger[$signature]['count'];

        if ($attempt >= self::END_TURN_AT) {
            $this->endedBy ??= $toolName;

            return sprintf(
                'Repeat-call loop guard: this is identical call #%d to %s (same arguments, same result every time it ran). '
                . 'The turn is being ended; tools are disabled for the summary that follows.',
                $attempt,
                $toolName,
            );
        }

        return sprintf(
            'Repeat-call loop guard: refused identical call #%d to %s — the last %d identical calls returned the same result, '
            . 'so running it again cannot tell you anything new. Change the arguments or take a different approach; '
            . 'identical call #%d ends the turn.',
            $attempt,
            $toolName,
            self::REFUSE_AT - 1,
            self::END_TURN_AT,
        );
    }

    /**
     * The PostToolUse half: count the call that ran, and answer the warning to
     * append to its result (or '' for none).
     *
     * @param array<array-key, mixed> $arguments
     */
    public function afterCall(string $toolName, array $arguments, string $output): string
    {
        $signature = self::signature($toolName, $arguments);
        $result = hash('xxh128', $output);
        $entry = $this->ledger[$signature] ?? null;

        if ($entry === null || $entry['result'] !== $result) {
            $this->ledger[$signature] = ['result' => $result, 'count' => 1, 'tool' => $toolName];

            return '';
        }

        $count = ++$this->ledger[$signature]['count'];

        if ($count < self::WARN_AT) {
            return '';
        }

        return sprintf(
            '[Repeat-call loop guard: this is identical call #%d to %s with the same arguments, and it returned the same result as before. '
            . 'Repeating it will not produce anything new — change approach. Identical call #%d is refused; #%d ends the turn.]',
            $count,
            $toolName,
            self::REFUSE_AT,
            self::END_TURN_AT,
        );
    }

    /** Whether a call reached {@see END_TURN_AT} this turn. */
    public function endsTurn(): bool
    {
        return $this->endedBy !== null;
    }

    /** The tool whose repeats ended the turn, or null. */
    public function endedBy(): ?string
    {
        return $this->endedBy;
    }

    /**
     * Tool name + canonical arguments, hashed so a ledger over a long turn of
     * large arguments (a Write's whole file body) stays small.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function signature(string $toolName, array $arguments): string
    {
        $encoded = json_encode(
            self::canonical($arguments),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return hash('xxh128', $toolName . "\0" . (string) $encoded);
    }

    /**
     * Sort every string-keyed map by key, recursively; leave lists in order.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
