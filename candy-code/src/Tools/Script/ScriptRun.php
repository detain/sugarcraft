<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Script;

/**
 * The outcome of one {@see ScriptRunner::run()} — every fact the run produced,
 * and nothing else.
 *
 * This is a value object, not a status code with strings bolted on. The bridge
 * can cut a stream at a cap, refuse a call over budget, or die at the wall
 * clock, and each of those must be REPORTABLE rather than inferable from an
 * absence: a cap breach is a named entry in {@see $capBreaches}, every bridged
 * tool call keeps its row in {@see $calls}, and the tail that survives a cap
 * is exactly the bytes the design promises, never a silent middle slice.
 *
 * The runner holds no policy, so this DTO carries no verdict vocabulary of its
 * own either: a permission refusal performed by the caller's dispatch arrives
 * as an ordinary `error` row (the dispatch reports it through `isError`),
 * while `denied` is reserved for calls the runner itself refused to forward —
 * the only refusals a policy-free engine can make are its own budgets.
 */
final class ScriptRun
{
    /** The call reached dispatch and dispatch answered without error. */
    public const OUTCOME_OK = 'ok';
    /** The call reached dispatch and the result carried an error. */
    public const OUTCOME_ERROR = 'error';
    /** The runner refused to forward the call (its own budget). */
    public const OUTCOME_DENIED = 'denied';

    /**
     * @param list<array{name: string, outcome: self::OUTCOME_*, bytes: int}> $calls
     *        the ledger, in arrival order: one row per `call` frame the child
     *        sent, `bytes` counting the request and response frames together
     * @param array<string, int> $capBreaches cap name => bytes dropped or
     *        calls refused under it (`stdout`, `stderr`, `calls`, `response`,
     *        `return`, `frame`); a breach with a byte value says how much
     *        was lost
     * @param string[] $notes non-cap disclosures that must survive: why the
     *        return value is absent when a return frame did arrive, and the
     *        runner's own observations that fit no other field
     */
    public function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $exitCode,
        public readonly mixed $return,
        public readonly array $calls,
        public readonly bool $timedOut,
        public readonly ?string $refusal,
        public readonly array $capBreaches = [],
        public readonly array $notes = [],
    ) {
    }

    /**
     * A run that never spawned: $reason names what stopped it, verbatim.
     *
     * The exit code is `-1`, NOT `0` — a refusal is the loudest shape this
     * type can take, and a caller that forgets to read {@see $refusal} must
     * still see the difference from a successful run.
     */
    public static function refused(string $reason): self
    {
        return new self(
            stdout: '',
            stderr: '',
            exitCode: -1,
            return: null,
            calls: [],
            timedOut: false,
            refusal: $reason,
        );
    }
}
