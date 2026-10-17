<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Internal sentinel produced by {@see Cmd::exec()}. Carries the
 * external-process spec the Program will run with TTY suspended,
 * plus an optional callback the runtime invokes once the child
 * exits — the callback can shape the resulting {@see Msg\ExecMsg}
 * (e.g. produce a typed Msg the model recognises).
 *
 * Models never construct this directly; use {@see Cmd::exec()}.
 */
final class ExecRequest implements Msg
{
    /**
     * @param string|list<string>             $command         shell string or argv list
     * @param ?\Closure(int $exit, string $out, string $err, ?\Throwable $error): ?Msg $onComplete
     *        runs after the child exits; return a Msg to dispatch in
     *        place of (or alongside) the default `ExecMsg`. Pass null
     *        for the default behaviour.
     */
    public function __construct(
        public readonly string|array $command,
        public readonly bool $captureOutput = false,
        public readonly ?\Closure $onComplete = null,
    ) {
    }
}
