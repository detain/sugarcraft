<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Context passed to a hook execution.
 *
 * `$heartbeat`, when set, is the turn's "still alive" beat: a hook that waits
 * a long time in the turn's own process (the forked turn child, whose parent
 * kills a turn silent for `turnIdleTimeoutSeconds`) calls it while it waits —
 * {@see BuiltIn\AutoTestHook} hands it to the test run. It is the caller's
 * to throttle and to bind to its process ({@see HookManager::stop()}).
 *
 * `$toolFailed` says the call this context observes ENDED IN ERROR. Only the
 * PostToolUse half can carry it (the tool has to have run to fail), and it
 * exists for the hooks that WRITE: a refused `Edit`/`Write` left the user's
 * bytes on disk untouched, and a post-edit formatter that "helpfully"
 * reformatted them would rewrite the user's file as a side effect of a call
 * that did nothing — {@see BuiltIn\PostEditFormatHook} stands down on the bit.
 * Read-only hooks (lint, diagnostics, audit) keep observing failed calls: the
 * file holds what it holds, and that is the picture the next attempt edits.
 */
final readonly class HookContext
{
    use Mutable;

    /**
     * @param (\Closure(): void)|null $heartbeat
     */
    public function __construct(
        public string $sessionId,
        public string $toolName,
        public array $toolArgs,
        public string $toolInput,
        public string $toolOutput,
        public string $model,
        public string $provider,
        public string $projectRoot,
        public ?\Closure $heartbeat = null,
        public bool $toolFailed = false,
    ) {}

    /** The same context carrying $heartbeat ({@see $heartbeat}). */
    public function withHeartbeat(?\Closure $heartbeat): self
    {
        return $this->mutate(['heartbeat' => $heartbeat]);
    }

    /** The same call marked failed ({@see $toolFailed}) — or cleared. */
    public function withToolFailed(bool $failed): self
    {
        return $this->mutate(['toolFailed' => $failed]);
    }

    public function withToolInput(string $input): self
    {
        return $this->mutate(['toolInput' => $input]);
    }

    /**
     * The same call, with a MODIFY hook's rewritten arguments in place of the
     * originals — BOTH halves at once.
     *
     * Deliberately not {@see withToolInput()}: that one moves the JSON text
     * only and leaves `$toolArgs` still describing the arguments the rewrite
     * replaced. Every built-in guard and
     * {@see BuiltIn\PermissionGateHook} reads `$toolArgs`, so a re-scan built
     * on `withToolInput()` alone would re-judge the OLD call and report a
     * verdict on arguments that are never going to run.
     *
     * @param array<string, mixed> $args   the decoded rewritten arguments
     * @param string               $input  the hook's own JSON text for them,
     *                                     kept verbatim rather than
     *                                     re-encoded so what a downstream
     *                                     consumer decodes is byte-identical
     *                                     to what the hook emitted
     */
    public function withRewrittenArgs(array $args, string $input): self
    {
        return $this->mutate(['toolArgs' => $args, 'toolInput' => $input]);
    }

    public function withToolOutput(string $output): self
    {
        return $this->mutate(['toolOutput' => $output]);
    }
}
