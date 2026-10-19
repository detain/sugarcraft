<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\ContextCommand;
use SugarCraft\Code\Host\ContextSources;

/**
 * `/context` (and `/tokens`): where the next request's context window goes —
 * the system prompt per layer, the instruction documents it splices (by name
 * and size, roadmap VIS-2a), the tool schemas, the history, the largest
 * messages, the cache-hit share (roadmap 5.6) and the cache breaks (3.B-5). Read-only and local: it
 * measures in this process, sends nothing and calls no model. The report
 * itself is {@see ContextCommand}'s; this is its session half (roadmap O-2h).
 *
 * The measurement itself lives in {@see ContextSources::measure()} — the SAME
 * call `context.sources` answers from (WEB-3a), so the panel and the wire can
 * never fork it. The history figure is the driver's own token estimate
 * ({@see CommandContext::$contextTokens}) — the status bar's — so the two
 * surfaces cannot disagree. The prompt layers come from a backend that
 * assembles its own prompt ({@see \SugarCraft\Code\Backend\ReportsPromptSections});
 * the tool schemas from an engine backend's tool list. Either may be unknown,
 * and the report then says so instead of printing a zero.
 */
final class ContextHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $breakdown = ContextSources::measure($context, self::instructionFiles($context->root));

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
        return ContextSources::priceInstructionFiles($root);
    }
}
