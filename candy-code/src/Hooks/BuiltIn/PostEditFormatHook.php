<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks\BuiltIn;

use SugarCraft\Code\Format\FormatRunner;
use SugarCraft\Code\Hooks\BoundedHookInterface;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Support\HookContextFiles;
use SugarCraft\Code\Tools\ReadLedger;

/**
 * Formats the file a `Write` or `Edit` (or each file an `ApplyPatch`) just
 * changed with the user's `formatCommands` map ({@see FormatRunner}), and —
 * the half that needs the launch, not just the config — RECORDS the rewritten
 * file into the session's {@see ReadLedger}, so the formatter's own change
 * never reads back as "somebody edited this behind the model's back".
 *
 * WHY BEFORE THE LINT. Registered ahead of
 * {@see PostEditLintHook} in {@see \SugarCraft\Code\Cli\Bootstrap::hooks()}:
 * a linter run against unformatted bytes can flag a style violation the
 * formatter was about to erase, and the chain hands the model both notes for
 * the price of one — the lint sees exactly what now stands on disk.
 *
 * IT NEVER REFUSES, and it has the lint's stronger reason for it: the bytes
 * are already on disk either way, and this hook is the only surface that can
 * tell the model its file moved under itself. Every outcome is an ALLOW with
 * a note — a kept rewrite, a formatter that failed, a rewrite the mutate
 * guard rolled back.
 *
 * THE LEDGER MAY BE NULL. The hook records through the launch's ONE ledger
 * ({@see \SugarCraft\Code\Cli\Bootstrap::readLedger()}, the same instance the
 * tool build carries); an embedder that constructs the hook without one
 * formats without recording — the file is still correct, only the staleness
 * check has to rediscover it. Null-tolerant on purpose: the chain must not
 * need a tool build to run a format.
 *
 * BOUNDED, so the chain's shared deadline counts it — its run is charged
 * against the chain budget exactly as the lint hook's is.
 *
 * Registered by {@see \SugarCraft\Code\Cli\Bootstrap::hooks()}, and only when
 * `formatCommands` names at least one command: the map has no built-in
 * default, so with none configured there is nothing for the hook to do.
 */
final readonly class PostEditFormatHook implements BoundedHookInterface
{
    /** Stable, so a hook file cannot register over it (`loadEntries()` refuses a taken event+name). */
    public const NAME = 'post-edit-format';

    public function __construct(
        private FormatRunner $runner,
        private ?ReadLedger $ledger = null,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PostToolUse;
    }

    /** The tools that change a file's contents ({@see EditedFiles}). */
    public function matcher(): string
    {
        return '^(Write|Edit|ApplyPatch)$';
    }

    /** The runner this hook formats with — for a caller that reads the chain back. */
    public function runner(): FormatRunner
    {
        return $this->runner;
    }

    /** The ledger kept current through formatter rewrites, or null (embedders). */
    public function ledger(): ?ReadLedger
    {
        return $this->ledger;
    }

    public function timeoutSeconds(): float
    {
        return $this->runner->timeoutSeconds();
    }

    public function withTimeoutSeconds(float $seconds): self
    {
        return new self($this->runner->withTimeout(min($this->runner->timeoutSeconds(), $seconds)), $this->ledger);
    }

    /**
     * Format each changed file ({@see EditedFiles}); record each KEPT rewrite
     * into the ledger; return the notes — or a bare ALLOW when there is
     * nothing to format or the formatter left every file alone.
     *
     * A failed edit is formatted too, exactly as the lint hook reasons: the
     * file holds what it held before, and that is the text the next attempt
     * edits. A restore needs no recording — the bytes are back to what the
     * ledger already holds for a file the tool wrote this very call.
     */
    public function execute(HookContext $context): HookResult
    {
        $rendered = [];
        foreach (EditedFiles::of($context) as $path) {
            $report = $this->runner->format($path, $context->projectRoot);
            if ($report === null) {
                continue;
            }

            if ($report->changed) {
                // The formatter's rewrite is now the model's own change: the
                // re-hash in record() means the file does not flag as
                // externally modified on the next call. The window between
                // the rewrite and this line is the run itself.
                $this->ledger?->record($report->absolute, null);
            }

            if ($report->needsNote()) {
                $rendered[] = $report->render();
            }
        }
        if ($rendered === []) {
            return HookResult::allow();
        }

        return HookResult::allow(
            '',
            HookContextFiles::bound(implode("\n\n", $rendered), HookResult::MAX_ADDITIONAL_CONTEXT_BYTES),
        );
    }
}
