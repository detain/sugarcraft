<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ToolDeclaration;
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
 * WHAT "CANNOT ASK" IS. Every effective tool is one no judging gate could
 * answer `Ask` for — {@see PermissionGate::askReason()}, asked of the tool's
 * declaration under the gate's own mode, rules, "always" answers and turn
 * grants. So a read-class tool under any mode, a `Bash` the session allows
 * by a name-only `Allow Bash` rule, or anything at all under
 * `bypass-permissions` is free; a `Bash` under `default` is not. When the
 * agent's own `tools:` grant scopes a tool (`Bash(vendor/bin/phpunit *)`),
 * the scope is what is judged — the grant hook refuses every call outside
 * it, so only calls inside it could be asked. {@see StructuredOutputTool} is
 * free: it is the run's answer and bypasses the gate by instance. A tool
 * that relays asks of its own ({@see RelaysPermissionAsks}) or delegates
 * ({@see DelegatesToEngine}) asks by construction. With no gate to consult
 * at all the old floor holds: only the catalog's {@see ToolPermissionClass::Read}
 * tools are free.
 *
 * WHAT IT CANNOT SEE: a hook that asks on its own — the built-in
 * `ProtectFilesHook`'s policy-file writes (in every mode, after the gate
 * allowed the call), a user `PreToolUse` script that answers `ask`. Such a
 * question in a forked agent fails CLOSED — the call is refused with the
 * grandchild reason, the agent reads the refusal and carries on — and never
 * runs unasked.
 */
final class AskFreeGrant
{
    /**
     * Why a run over $tools could ask, or null when it cannot.
     *
     * @param list<Tool>            $tools        the run's EFFECTIVE tools —
     *                                            what will actually be on its wire
     * @param list<?PermissionGate> $gates        every gate whose rules judge the
     *                                            run's calls (null entries skipped)
     * @param list<mixed>           $declarations the agent's own `tools:` grant
     *                                            as written (`Bash(git *)`, `Read`);
     *                                            empty when it declared none
     */
    public static function askRisk(array $tools, array $gates, array $declarations = []): ?string
    {
        $gates = array_values(array_filter($gates, static fn (?PermissionGate $g): bool => $g !== null));
        $readClass = ToolCatalog::namesOf(ToolPermissionClass::Read);
        $asking = [];
        $because = null;

        foreach ($tools as $tool) {
            if ($tool instanceof StructuredOutputTool) {
                continue;
            }
            $name = $tool->name();
            if ($tool instanceof DelegatesToEngine || $tool instanceof RelaysPermissionAsks) {
                $asking[] = $name;
                continue;
            }
            if ($gates === []) {
                if (!\in_array($name, $readClass, true)) {
                    $asking[] = $name;
                }
                continue;
            }

            $reason = self::firstAskReason($name, $gates, $declarations);
            if ($reason !== null) {
                $asking[] = $name;
                $because ??= $reason;
            }
        }

        if ($asking === []) {
            return null;
        }

        // A rule is worth naming — it is the one thing the user wrote that
        // keeps the agent inline; a mode's ask is implied by the tool name.
        if ($because !== null && str_starts_with($because, 'an Ask rule')) {
            return 'may ask: ' . $because;
        }

        return 'may ask: ' . self::list(array_values(array_unique($asking)));
    }

    /**
     * @param list<PermissionGate> $gates
     * @param list<mixed>          $declarations
     */
    private static function firstAskReason(string $name, array $gates, array $declarations): ?string
    {
        foreach (self::judgedDeclarations($name, $declarations) as $declaration) {
            foreach ($gates as $gate) {
                $reason = $gate->askReason(new ToolDeclaration($declaration));
                if ($reason !== null) {
                    return $reason;
                }
            }
        }

        return null;
    }

    /**
     * What a gate is asked about $name: the grant's scopes for it when every
     * grant entry naming it is scoped, otherwise the bare name. A bare entry
     * (or none — a tool the grant does not name reached the wire some other
     * way) admits every call, so only the bare name speaks for it. A
     * malformed entry is never trusted to narrow anything.
     *
     * @param list<mixed> $declarations
     * @return list<string>
     */
    private static function judgedDeclarations(string $name, array $declarations): array
    {
        $scoped = [];
        foreach ($declarations as $declaration) {
            if (!\is_string($declaration) || !PermissionRule::isWellFormedPattern($declaration)) {
                continue;
            }
            $rule = new PermissionRule($declaration, PermissionAction::Allow);
            if (!PermissionRule::matchesToolName($rule->toolNamePattern(), $name)) {
                continue;
            }
            $scope = $rule->argumentPattern();
            if ($scope === null) {
                return [$name];
            }
            $scoped[] = $name . '(' . $scope . ')';
        }

        return $scoped === [] ? [$name] : array_values(array_unique($scoped));
    }

    /** @param list<string> $names */
    private static function list(array $names): string
    {
        $shown = \array_slice($names, 0, 4);
        $more = \count($names) - \count($shown);

        return implode(', ', $shown) . ($more > 0 ? sprintf(' and %d more', $more) : '');
    }
}
