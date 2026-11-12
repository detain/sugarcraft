<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\RelaysPermissionAsks;
use SugarCraft\Code\Tools\Tool;

/**
 * Can a sub-agent run over this tool set put a permission question? The test
 * that decides whether a `Workflow`-tool stage agent may leave the turn's
 * process (workflow-update P2-1).
 *
 * WHY IT MATTERS. A question reaches the user only through the approver of
 * the process that built it — the turn child's
 * {@see \SugarCraft\Code\Backend\ChildChannel} — so an agent forked below the
 * turn has every `Ask` refused (`GRANDCHILD_REFUSAL`). An agent whose tools
 * cannot ask loses nothing by forking and gains real concurrency; an agent
 * that might ask must stay in the turn's process, where its question is put.
 *
 * WHAT "CANNOT ASK" IS, AND WHY IT IS THIS NARROW. Every effective tool is in
 * the catalog's {@see ToolPermissionClass::Read} class — the list the
 * permission gate itself reads for "read-only", which it answers `Allow` in
 * every mode — and no configured `Ask` rule names any of them (a rule is the
 * one way to make the gate ask about a read). {@see StructuredOutputTool} is
 * free: it is the run's answer and bypasses the gate by instance. Everything
 * else is presumed to ask:
 *
 *  - `ParallelSafe` is NOT the test: `WebFetch` is parallel-safe and asks
 *    under `default`.
 *  - A gate verdict of `Allow` would not be either, even if the gate had a
 *    query for one (it has only {@see PermissionGate::refuses()}, which
 *    answers Deny): the built-in `ProtectFilesHook` asks about policy-file
 *    writes on its own, in every mode, after the gate allowed them.
 *  - A tool that relays asks of its own ({@see RelaysPermissionAsks}) or
 *    delegates ({@see DelegatesToEngine}) asks by construction.
 *
 * WHAT IT CANNOT SEE: a user `PreToolUse` script hook that answers `ask` for a
 * read. Such a question in a forked agent fails CLOSED — the call is refused
 * with the grandchild reason, the agent reads the refusal and carries on —
 * never runs unasked.
 */
final class AskFreeGrant
{
    /**
     * Why a run over $tools could ask, or null when it cannot.
     *
     * @param list<Tool>            $tools the run's EFFECTIVE tools — what
     *                                     will actually be on its wire
     * @param list<?PermissionGate> $gates every gate whose rules judge the
     *                                     run's calls (null entries skipped)
     */
    public static function askRisk(array $tools, array $gates): ?string
    {
        $readClass = ToolCatalog::namesOf(ToolPermissionClass::Read);
        $asking = [];
        $reads = [];
        foreach ($tools as $tool) {
            if ($tool instanceof StructuredOutputTool) {
                continue;
            }
            $name = $tool->name();
            if ($tool instanceof DelegatesToEngine
                || $tool instanceof RelaysPermissionAsks
                || !\in_array($name, $readClass, true)
            ) {
                $asking[] = $name;
                continue;
            }
            $reads[] = $name;
        }

        if ($asking !== []) {
            $asking = array_values(array_unique($asking));

            return 'may ask: ' . self::list($asking);
        }

        foreach ($gates as $gate) {
            if ($gate === null) {
                continue;
            }
            foreach ($gate->rules() as $rule) {
                if ($rule->action !== PermissionAction::Ask) {
                    continue;
                }
                foreach ($reads as $name) {
                    if (PermissionRule::matchesToolName($rule->toolNamePattern(), $name)) {
                        return sprintf('may ask: an Ask rule "%s" covers %s', $rule->pattern, $name);
                    }
                }
            }
        }

        return null;
    }

    /** @param list<string> $names */
    private static function list(array $names): string
    {
        $shown = \array_slice($names, 0, 4);
        $more = \count($names) - \count($shown);

        return implode(', ', $shown) . ($more > 0 ? sprintf(' and %d more', $more) : '');
    }
}
