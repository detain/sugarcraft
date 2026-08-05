<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\ToolCall;

/**
 * PermissionGate evaluates every ToolCall against the active PermissionMode
 * and returns a PermissionDecision (Allow / Deny / Ask).
 *
 * PermissionRule matching is glob-style: Bash(composer update *), Read(./.env), mcp__git__*
 * A rule matches when the ToolCall name matches the pattern portion.
 *
 * Four modes are implemented here (P2B.S2 + P2B.S3):
 * - Default:     reads silently; writes/networking always Ask
 * - AcceptEdits: scoped filesystem writes auto-Allow; everything else Ask
 * - Plan:        all writes Deny; reads Allow
 * - Auto:        everything runs gated by SafetyClassifier; 3-strike / 20-total circuit breaker
 *
 * @see PermissionMode for the full set of modes
 */
final class PermissionGate
{
    /**
     * Filesystem operations that AcceptEdits may auto-approve when scoped to the working directory.
     */
    private const SCOPED_WRITE_TOOLS = ['mkdir', 'touch', 'mv', 'cp', 'rm', 'rmdir'];

    /**
     * Circuit-breaker thresholds for Auto mode.
     */
    private const STRIKE_THRESHOLD = 3;
    private const TOTAL_BLOCK_THRESHOLD = 20;

    // -------------------------------------------------------------------------
    // Auto-mode circuit breaker state
    // -------------------------------------------------------------------------
    private int $consecutiveBlocks = 0;
    private int $totalBlocks = 0;
    private ?string $lastBlockedCategory = null;

    public function __construct(
        private readonly PermissionMode $mode,
        /** @var PermissionRule[] */
        private readonly array $rules = [],
        private readonly ?SafetyClassifier $classifier = null,
    ) {}

    /**
     * Evaluate a tool call and return the permission decision for the current mode.
     */
    public function evaluate(ToolCall $call): PermissionDecision
    {
        // 1. Check explicit rules first (highest priority)
        $ruleDecision = $this->evaluateRules($call);
        if ($ruleDecision !== null) {
            return $ruleDecision;
        }

        // 2. Mode-specific logic
        return match ($this->mode) {
            PermissionMode::Default => $this->evaluateDefault($call),
            PermissionMode::AcceptEdits => $this->evaluateAcceptEdits($call),
            PermissionMode::Plan => $this->evaluatePlan($call),
            PermissionMode::Auto => $this->evaluateAuto($call),
            // P2B.S4 handles DontAsk and BypassPermissions
            PermissionMode::DontAsk,
            PermissionMode::BypassPermissions => PermissionDecision::Ask,
        };
    }

    /**
     * Check rules in order; first match wins.
     */
    private function evaluateRules(ToolCall $call): ?PermissionDecision
    {
        foreach ($this->rules as $rule) {
            if ($this->ruleMatches($rule, $call)) {
                return $this->actionToDecision($rule->action);
            }
        }
        return null;
    }

    /**
     * Glob-match a rule pattern against a tool call name.
     * Supports: Bash* (prefix match), Bash (exact match).
     */
    private function ruleMatches(PermissionRule $rule, ToolCall $call): bool
    {
        $pattern = $rule->pattern;

        // Glob wildcard: match tool name by prefix
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($call->name, $prefix);
        }

        return $call->name === $pattern;
    }

    private function actionToDecision(PermissionAction $action): PermissionDecision
    {
        return match ($action) {
            PermissionAction::Allow => PermissionDecision::Allow,
            PermissionAction::Deny => PermissionDecision::Deny,
            PermissionAction::Ask => PermissionDecision::Ask,
        };
    }

    // -------------------------------------------------------------------------
    // Mode evaluators
    // -------------------------------------------------------------------------

    /**
     * Default: reads are silent, everything else prompts.
     */
    private function evaluateDefault(ToolCall $call): PermissionDecision
    {
        if ($this->isReadOnlyTool($call)) {
            return PermissionDecision::Allow;
        }
        return PermissionDecision::Ask;
    }

    /**
     * AcceptEdits: scoped filesystem writes auto-approve; protected paths still prompt.
     */
    private function evaluateAcceptEdits(ToolCall $call): PermissionDecision
    {
        // Reads always allow in AcceptEdits
        if ($this->isReadOnlyTool($call)) {
            return PermissionDecision::Allow;
        }

        // Scoped filesystem writes (mkdir, touch, mv, cp, rm, rmdir) auto-allow
        if ($this->isScopedWriteTool($call)) {
            return PermissionDecision::Allow;
        }

        // Everything else (network, shell commands, non-scoped writes) asks
        return PermissionDecision::Ask;
    }

    /**
     * Auto: everything runs gated by SafetyClassifier; circuit breaker triggers Ask after
     * 3 consecutive blocks of the same category OR 20 total blocks in the session.
     *
     * @see SafetyClassifier for the 13 dangerous-action categories.
     */
    private function evaluateAuto(ToolCall $call): PermissionDecision
    {
        // SafetyClassifier is the gatekeeper for Auto mode
        if ($this->classifier === null) {
            // No classifier available — behave like BypassPermissions: allow everything
            return PermissionDecision::Allow;
        }

        $category = $this->classifier->classify($call);

        // Action is safe — reset counters and allow
        if ($category === null) {
            $this->consecutiveBlocks = 0;
            $this->lastBlockedCategory = null;
            return PermissionDecision::Allow;
        }

        // Dangerous action blocked — update circuit breaker state
        if ($category === $this->lastBlockedCategory) {
            ++$this->consecutiveBlocks;
        } else {
            $this->consecutiveBlocks = 1;
            $this->lastBlockedCategory = $category;
        }
        ++$this->totalBlocks;

        // Circuit breaker thresholds
        if ($this->consecutiveBlocks >= self::STRIKE_THRESHOLD) {
            return PermissionDecision::Ask;
        }
        if ($this->totalBlocks >= self::TOTAL_BLOCK_THRESHOLD) {
            return PermissionDecision::Ask;
        }

        return PermissionDecision::Deny;
    }

    /**
     * Plan: reads/shell exploration freely, but no edits land until approved.
     */
    private function evaluatePlan(ToolCall $call): PermissionDecision
    {
        // Reads are always allowed in Plan mode
        if ($this->isReadOnlyTool($call)) {
            return PermissionDecision::Allow;
        }

        // Bash commands are allowed for exploration (e.g. git log, find, grep)
        // but denied when they redirect output (writing to files)
        if ($call->name === 'Bash') {
            if ($this->isBashWriteCommand($call)) {
                return PermissionDecision::Deny;
            }
            return PermissionDecision::Allow;
        }

        // All other writes are denied in Plan mode — nothing edits until the plan is approved
        if ($this->isWriteTool($call)) {
            return PermissionDecision::Deny;
        }

        // Default: ask
        return PermissionDecision::Ask;
    }

    // -------------------------------------------------------------------------
    // Tool classifiers
    // -------------------------------------------------------------------------

    private function isReadOnlyTool(ToolCall $call): bool
    {
        return in_array($call->name, ['Read', 'Grep', 'Glob', 'Find'], true);
    }

    private function isWriteTool(ToolCall $call): bool
    {
        return in_array($call->name, ['Edit', 'Write', 'Bash', 'McpTool'], true);
    }

    /**
     * Detect if a Bash command writes to files via shell redirection or tee.
     */
    private function isBashWriteCommand(ToolCall $call): bool
    {
        $args = $call->arguments;

        if (!isset($args['command']) || !is_string($args['command'])) {
            return false;
        }

        $cmd = $args['command'];

        // File redirection operators: > >> | tee
        return (bool) preg_match('/\s+>\s+/', $cmd)
            || (bool) preg_match('/\s+>>\s+/', $cmd)
            || (bool) preg_match('/\|\s*tee(\s+|$)/', $cmd);
    }

    private function isScopedWriteTool(ToolCall $call): bool
    {
        // AcceptEdits allows safe filesystem primitives scoped to the working directory
        if (!in_array($call->name, self::SCOPED_WRITE_TOOLS, true)) {
            return false;
        }

        // Extract the target path from arguments
        $path = $this->extractPathArgument($call);
        if ($path === null) {
            return false;
        }

        // Relative paths are considered scoped to the working directory
        return !$this->isAbsolutePath($path);
    }

    private function extractPathArgument(ToolCall $call): ?string
    {
        $args = $call->arguments;

        // Most filesystem tools use 'path' or 'file_path'
        foreach (['path', 'file_path', 'target', 'source', 'destination'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                return $args[$key];
            }
        }

        // Bash commands embed the path in the command string
        if ($call->name === 'Bash' && isset($args['command'])) {
            return $this->extractPathFromBashCommand($args['command']);
        }

        return null;
    }

    private function extractPathFromBashCommand(string $command): ?string
    {
        // Extract first path-like token from the command
        if (preg_match('/\s+([.\/~][^\s;|`$(){}[\]]*)/', $command, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '~');
    }
}
