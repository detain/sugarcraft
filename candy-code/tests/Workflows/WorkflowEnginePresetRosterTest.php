<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * wf-impl C5 (C4 cross-track): the deferred agent roster `preset:` resolves
 * against — Bootstrap's `/workflow` engine carries one, it is built only on
 * demand, and the names it yields feed every `WorkflowLinter::lint()` caller.
 */
final class WorkflowEnginePresetRosterTest extends TestCase
{
    public function testTheLaunchedWorkflowEngineKnowsTheBuiltInPresets(): void
    {
        $root = sys_get_temp_dir() . '/wf-preset-roster-' . bin2hex(random_bytes(4));
        mkdir($root, 0700, true);
        try {
            $names = Bootstrap::workflowEngine($root, new PermissionGate(PermissionMode::Default))->presetNames();
        } finally {
            @rmdir($root);
        }

        self::assertIsArray($names);
        self::assertContains('reviewer', $names);
        self::assertContains('coder', $names);
    }

    public function testTheRosterSourceIsCalledOnceAndOnlyOnDemand(): void
    {
        $calls = 0;
        $engine = new WorkflowEngine(new WorkflowRegistry(sys_get_temp_dir() . '/wf-preset-roster-unused'));
        $engine->setAgentRosterSource(static function () use (&$calls): array {
            $calls++;

            return [
                new Agent('zeta', 'z', '', 'm', 'p', [], [], [], true),
                new Agent('alpha', 'a', '', 'm', 'p', [], [], [], true),
            ];
        });

        self::assertSame(0, $calls, 'wiring a source reads nothing');
        self::assertSame(['alpha', 'zeta'], $engine->presetNames());
        self::assertSame(['alpha', 'zeta'], $engine->presetNames());
        self::assertSame(1, $calls);
    }

    public function testAThrowingSourceLeavesNoRoster(): void
    {
        $engine = new WorkflowEngine(new WorkflowRegistry(sys_get_temp_dir() . '/wf-preset-roster-unused'));
        $engine->setAgentRosterSource(static fn (): array => throw new \RuntimeException('no home'));

        self::assertNull($engine->presetNames());
    }

    public function testPresetsNamedByWalksEveryTaskSurface(): void
    {
        $registry = new WorkflowRegistry(sys_get_temp_dir() . '/wf-preset-roster-unused');
        $workflow = $registry->fromYamlString(
            "stages:\n"
            . "  - name: plain\n    prompt: a\n"
            . "  - name: fan\n    parallel: true\n    agents:\n      - {type: x, prompt: b, preset: reviewer}\n"
            . "  - name: check\n    type: verification\n    task: {prompt: c}\n    verifier: {prompt: 'judge {{prevResult}}', preset: tester}\n",
        );

        self::assertEqualsCanonicalizing(['reviewer', 'tester'], WorkflowEngine::presetsNamedBy($workflow));
        self::assertSame([], WorkflowEngine::presetsNamedBy($registry->fromYamlString("stages:\n  - name: a\n    prompt: x\n")));
    }
}
