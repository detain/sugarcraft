<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\BoundedHookInterface;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Lint\LintRunner;
use SugarCraft\Crush\Support\HookContextFiles;

/**
 * Lints the file a `Write` or `Edit` (or each file an `ApplyPatch`) just changed and tells the model what the
 * linter found — Aider's post-edit lint loop (step 3.E), with `php -l` on by
 * default and the user-tier `lintCommands` map for everything else
 * ({@see LintRunner}).
 *
 * WHY A `PostToolUse` HOOK. Both live tool paths already append a permitting
 * `PostToolUse` chain's `additionalContext` to the tool result the model
 * reads — {@see \SugarCraft\Crush\Runtime::settle()} on the engine path,
 * {@see \SugarCraft\Crush\Chat::applyPostToolUse()} on the sync one — so a
 * hook reaches both with no change to either, and the report lands on the
 * very result of the edit that caused it, where the model looks next.
 *
 * IT NEVER REFUSES. A lint is advice about the file, not a verdict on the
 * call: the edit has already happened, and withholding its output (what a
 * `PostToolUse` refusal does) would hide the one thing the model needs to fix
 * the error. Every outcome — errors, a linter that would not start, a linter
 * that ran out of time — is an ALLOW, with a note when there is something to
 * say and none when the file is clean.
 *
 * BOUNDED, so the chain's shared deadline counts it
 * ({@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()}): its run is
 * charged against the chain budget like a script hook's, and a chain that has
 * less time left hands it a shorter bound rather than letting the lint
 * outlive the chain.
 *
 * Registered by {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()}, not
 * {@see \SugarCraft\Crush\Hooks\HookManager::registerBuiltIns()}: it needs the
 * user's `lintCommands`, and the registrar has no configuration to read.
 */
final readonly class PostEditLintHook implements BoundedHookInterface
{
    /** Stable, so a hook file cannot register over it (`loadEntries()` refuses a taken event+name). */
    public const NAME = 'post-edit-lint';

    public function __construct(
        private LintRunner $runner,
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

    /** The runner this hook lints with — for a caller that reads the chain back. */
    public function runner(): LintRunner
    {
        return $this->runner;
    }

    public function timeoutSeconds(): float
    {
        return $this->runner->timeoutSeconds();
    }

    public function withTimeoutSeconds(float $seconds): self
    {
        return new self($this->runner->withTimeout(min($this->runner->timeoutSeconds(), $seconds)));
    }

    /**
     * Lint each changed file ({@see EditedFiles}) and return the reports as
     * the note — or a bare ALLOW when there is nothing to lint or nothing wrong.
     *
     * A failed edit is linted too: the file then holds what it held before,
     * and that is exactly the text the model's next attempt edits.
     */
    public function execute(HookContext $context): HookResult
    {
        $rendered = [];
        foreach (EditedFiles::of($context) as $path) {
            $report = $this->runner->lint($path, $context->projectRoot);
            if ($report !== null && !$report->passed()) {
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
