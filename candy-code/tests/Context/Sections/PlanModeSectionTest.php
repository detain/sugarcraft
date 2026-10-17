<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context\Sections;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\EnvironmentBlock;
use SugarCraft\Code\Context\PromptFence;
use SugarCraft\Code\Context\Sections\PlanModeSection;
use SugarCraft\Code\Context\Stability;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tests\Prompt\PromptFixture;

/**
 * Roadmap 5.7-1: the plan-mode contract is in the prompt exactly while the
 * turn's gate is in `plan`, directly ahead of `<env>`, and says what the gate
 * enforces.
 */
final class PlanModeSectionTest extends TestCase
{
    private ?PromptFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->destroy();
        $this->fixture = null;
    }

    public function testTheSectionIsAPerTurnSystemReminder(): void
    {
        $section = new PlanModeSection();

        self::assertSame('<system-reminder>', $section->fence());
        self::assertSame(Stability::PerTurn, $section->stability());
        self::assertStringStartsWith("<system-reminder>\n# Plan mode", $section->render());
        self::assertStringEndsWith('</system-reminder>', $section->render());
        self::assertContains('system-reminder', PromptFence::tags(), 'the fence must be one a repository byte cannot forge');
    }

    public function testItNamesThePlansDirectoryTheGateAllowsAndTheWayOut(): void
    {
        $body = (new PlanModeSection())->render();

        self::assertStringContainsString('`' . PermissionGate::PLANS_DIR . '/`', $body);
        self::assertStringContainsString('Alt+M', $body);
        self::assertStringContainsString(\SugarCraft\Code\Tools\BuiltIn\PlanExitTool::NAME, $body, 'plan mode names its own way out (roadmap 5.7-2)');
        self::assertStringContainsString(\SugarCraft\Code\Tools\BuiltIn\AskUserTool::NAME, $body);
        self::assertStringContainsString('The tool list is the same in every mode', $body);
    }

    public function testOnlyAPlanModeGateAddsTheSlotAndItSitsDirectlyAheadOfEnv(): void
    {
        foreach (PermissionMode::cases() as $mode) {
            $sections = $this->sectionsUnder($mode);
            $plan = array_values(array_filter($sections, static fn (object $s): bool => $s instanceof PlanModeSection));

            if ($mode !== PermissionMode::Plan) {
                self::assertSame([], $plan, "{$mode->value} must not carry the plan-mode contract");

                continue;
            }

            self::assertCount(1, $plan);
            $count = \count($sections);
            self::assertInstanceOf(PlanModeSection::class, $sections[$count - 2]);
            self::assertInstanceOf(EnvironmentBlock::class, $sections[$count - 1]);
        }
    }

    public function testNoGateAddsNoSlot(): void
    {
        $sections = $this->sectionsUnder(null);

        self::assertSame([], array_filter($sections, static fn (object $s): bool => $s instanceof PlanModeSection));
    }

    /** @return list<object> */
    private function sectionsUnder(?PermissionMode $mode): array
    {
        $this->fixture ??= new PromptFixture();
        $app = $this->fixture->app();
        $hooks = new HookManager(new HookRegistry());
        if ($mode !== null) {
            $hooks->register(new PermissionGateHook(new PermissionGate($mode)));
        }

        $runtime = new Runtime(
            $app->provider,
            $hooks,
            new EnvironmentBlock($this->fixture->root(), $app->model, new DateTimeImmutable('2026-01-15 12:00:00 UTC'), 'linux'),
        );

        $sections = \Closure::bind(
            static fn (Runtime $runtime, object $app): array => $runtime->systemPromptSections($app),
            null,
            Runtime::class,
        );

        return $sections($runtime, $app);
    }
}
