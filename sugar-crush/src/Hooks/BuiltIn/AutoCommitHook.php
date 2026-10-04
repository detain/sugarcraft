<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\BoundedHookInterface;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Workspace\AutoCommitter;
use SugarCraft\Crush\Workspace\CommitMessageWriter;

/**
 * `autoCommit: edit` (step 3.G): every `Write`/`Edit` that changed a file is
 * committed as it lands — the user's own earlier changes to that file first,
 * in a commit of their own — and the model is told the commit it made.
 *
 * The subject is the call's own `description` ("Rename the legacy config
 * helper"), which the model already wrote: asking the title model per edit
 * would be a model call inside the tool chain, slowing every edit and billed
 * nowhere. `turn` mode, committed once per turn by
 * {@see \SugarCraft\Crush\Chat}, is the one that asks it.
 *
 * IT NEVER REFUSES. The edit has happened; a commit that fails (a pre-commit
 * hook rejected it, nothing to keep the user's changes apart with) leaves the
 * file uncommitted and says so on the result. The user's hooks always run —
 * see {@see AutoCommitter} for why there is no `--no-verify`.
 *
 * BOUNDED by {@see AutoCommitter::COMMIT_TIMEOUT_SECONDS} — the user's
 * pre-commit hooks run inside it — or less when the chain has less left.
 *
 * Registered by {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} only when the
 * user set `autoCommit` to `edit`.
 */
final readonly class AutoCommitHook implements BoundedHookInterface
{
    /** Stable, so a hook file cannot register over it. */
    public const NAME = 'auto-commit';

    /**
     * @param \Closure(string): ?array<string, mixed> $checkpoint the workspace
     *        checkpoint taken before the session's current turn, by session id —
     *        what the user's files were before the model touched them
     */
    public function __construct(
        private AutoCommitter $committer,
        private \Closure $checkpoint,
        private float $timeout = AutoCommitter::COMMIT_TIMEOUT_SECONDS,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PostToolUse;
    }

    /** The two tools that change a file's contents; each names it in `file_path`. */
    public function matcher(): string
    {
        return '^(Write|Edit)$';
    }

    public function timeoutSeconds(): float
    {
        return $this->timeout;
    }

    public function withTimeoutSeconds(float $seconds): self
    {
        return new self($this->committer, $this->checkpoint, max(0.001, min($this->timeout, $seconds)));
    }

    public function execute(HookContext $context): HookResult
    {
        $path = $context->toolArgs['file_path'] ?? null;
        if (!\is_string($path) || trim($path) === '' || str_contains($path, "\0") || $context->projectRoot === '') {
            return HookResult::allow();
        }
        // Jailed as the edit was: a refused edit outside the workspace is
        // never committed.
        $path = PathJail::resolve($context->projectRoot, $path);
        if ($path === null || ToolOutputSpill::readablePath($path) !== null) {
            return HookResult::allow();
        }

        $description = \is_string($context->toolArgs['description'] ?? null) ? $context->toolArgs['description'] : '';
        $committer = $this->committer->withSessionId($context->sessionId);

        try {
            $workspace = ($this->checkpoint)($context->sessionId);
            $outcome = $committer->commitEdit(
                $path,
                static fn (string $relative): string => CommitMessageWriter::fromDescription($description, [$relative]),
                \is_array($workspace) ? $workspace : null,
            );
        } catch (\Throwable $e) {
            return HookResult::allow('', 'Auto-commit skipped: ' . $e->getMessage());
        }

        if ($outcome === null) {
            return HookResult::allow();
        }
        if (!$outcome['ok']) {
            return HookResult::allow('', 'Auto-commit skipped for ' . implode(', ', $outcome['paths']) . ': ' . $outcome['reason'] . '. The change is in the file, uncommitted.');
        }

        $first = $outcome['snapshot'] !== null
            ? ' The user\'s earlier uncommitted changes to it were committed first, in ' . substr($outcome['snapshot'], 0, 7) . '.'
            : '';

        return HookResult::allow(
            '',
            'Auto-committed ' . substr((string) $outcome['sha'], 0, 7) . ': ' . $outcome['subject'] . '.' . $first,
        );
    }
}
