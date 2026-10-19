<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\ReportsPromptSections;
use SugarCraft\Code\Commands\ContextCommand;
use SugarCraft\Code\Context\ContextBreakdown;

/**
 * `/context` (and `/tokens`): where the next request's context window goes —
 * the system prompt per layer, the instruction documents it splices (by name
 * and size, roadmap VIS-2a), the tool schemas, the history, the largest
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
            instructionFiles: self::instructionFiles($context->root),
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

    /**
     * The instruction documents this session's prompt splices, as names and
     * sizes only (roadmap VIS-2a): {@see \SugarCraft\Code\Cli\Bootstrap::instructionLoader()}
     * is the memoized instance the system prompt itself is built from, so the
     * panel reads the loader's own record — {@see \SugarCraft\Code\Context\InstructionFileLoader::loadDocuments()}
     * for the repository-shipped files, {@see \SugarCraft\Code\Context\InstructionFileLoader::loadPersonal()}
     * for the operator's own (kept apart there precisely because provenance
     * frames it differently), and {@see \SugarCraft\Code\Context\InstructionFileLoader::refusedPaths()}
     * for what was not read and why. A loader that cannot be built leaves the
     * figure null and the report says "not measured" — the same unknown-honesty
     * rule every other part of the panel follows.
     *
     * @return array{files: list<array{path: string, bytes: int, personal: bool}>, refused: list<array{path: string, reason: string}>}|null
     */
    private static function instructionFiles(?string $root): ?array
    {
        try {
            $loader = \SugarCraft\Code\Cli\Bootstrap::instructionLoader($root);
            $files = [];
            foreach ($loader->loadDocuments() as $doc) {
                $files[] = ['path' => $doc['path'], 'bytes' => $doc['bytes'], 'personal' => false];
            }
            foreach ($loader->loadPersonal() as $doc) {
                $files[] = ['path' => $doc['path'], 'bytes' => $doc['bytes'], 'personal' => true];
            }
            $refused = [];
            foreach ($loader->refusedPaths() as $path => $reason) {
                $refused[] = ['path' => (string) $path, 'reason' => (string) $reason];
            }

            return ['files' => $files, 'refused' => $refused];
        } catch (\Throwable) {
            return null;
        }
    }
}
