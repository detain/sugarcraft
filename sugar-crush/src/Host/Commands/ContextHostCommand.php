<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\ReportsPromptSections;
use SugarCraft\Crush\Commands\ContextCommand;
use SugarCraft\Crush\Context\ContextBreakdown;

/**
 * `/context` (and `/tokens`): where the next request's context window goes —
 * the system prompt per layer, the tool schemas, the history, the largest
 * messages, the cache-hit share (roadmap 5.6) and the cache breaks (3.B-5). Read-only and local: it
 * measures in this process, sends nothing and calls no model. The report
 * itself is {@see ContextCommand}'s; this is its session half (roadmap O-2h).
 *
 * The history figure is the driver's own token estimate
 * ({@see CommandContext::$contextTokens}) — the status bar's — so the two
 * surfaces cannot disagree. The prompt layers come from a backend that
 * assembles its own prompt ({@see ReportsPromptSections}); the tool schemas
 * from an engine backend's tool list. Either may be unknown, and the report
 * then says so instead of printing a zero.
 */
final class ContextHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $sections = null;
        if ($context->backend instanceof ReportsPromptSections) {
            try {
                $sections = $context->backend->promptSectionSizes();
            } catch (\Throwable) {
                // A layer that fails to build here fails the next turn too and
                // is reported there; this read-only panel just says "not measured".
                $sections = null;
            }
        }
        $tools = $context->backend instanceof EngineBackend ? $context->backend->tools() : null;

        $breakdown = ContextBreakdown::measure(
            $context->history,
            $sections,
            $tools,
            (int) $context->contextTokenLimit,
            (int) $context->contextTokens,
        )
            // Roadmap 5.6 remainder: what the session's ledger prunes out.
            ->withPruning($context->contextLedger(), $context->history);
        if ($context->backend instanceof EngineBackend) {
            // Roadmap 3.B-5: the session's prompt-cache breaks, off the
            // watch every clone of the backend shares.
            $breakdown = $breakdown->withCacheBreaks($context->backend->cacheBreaks(), $context->backend->lastCacheBreak());
        }

        return CommandResult::reply($text, (new ContextCommand($breakdown))->report());
    }
}
