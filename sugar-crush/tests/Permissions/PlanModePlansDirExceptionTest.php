<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\ToolDeclaration;
use SugarCraft\Crush\ToolCall;

/**
 * Roadmap 5.7-1: `plan` mode's one write — a Markdown plan directly in
 * {@see PermissionGate::PLANS_DIR} — and everything that only looks like one.
 */
final class PlanModePlansDirExceptionTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/crush_plansdir_' . bin2hex(random_bytes(5));
        mkdir($this->root . '/' . PermissionGate::PLANS_DIR, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function planFiles(): array
    {
        return [
            'Write, relative' => ['Write', '.sugar-crush/plans/retry.md'],
            'Write, dot-relative' => ['Write', './.sugar-crush/plans/retry.md'],
            'Edit, relative' => ['Edit', '.sugar-crush/plans/retry.md'],
            'upper-case extension' => ['Write', '.sugar-crush/plans/RETRY.MD'],
            'dot-dot that stays inside' => ['Write', 'src/../.sugar-crush/plans/retry.md'],
        ];
    }

    #[DataProvider('planFiles')]
    public function testAMarkdownPlanInThePlansDirectoryIsAllowed(string $tool, string $path): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall($tool, ['file_path' => $path]), $this->root));
        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall($tool, ['file_path' => $path])), 'and without a root, by spelling');
    }

    public function testAnAbsolutePathIntoThePlansDirectoryIsAllowedWithARoot(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);
        $path = realpath($this->root) . '/' . PermissionGate::PLANS_DIR . '/retry.md';

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Write', ['file_path' => $path]), $this->root));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['file_path' => $path])), 'without a root an absolute path proves nothing');
    }

    /** @return array<string, array{0: mixed}> */
    public static function notPlanFiles(): array
    {
        return [
            'not Markdown' => ['.sugar-crush/plans/run.sh'],
            'nested' => ['.sugar-crush/plans/sub/retry.md'],
            'the directory itself' => ['.sugar-crush/plans'],
            'a bare extension' => ['.sugar-crush/plans/.md'],
            'a sibling directory' => ['.sugar-crush/plansx/retry.md'],
            'policy file next door' => ['.sugar-crush/settings.json'],
            'escapes the root' => ['../.sugar-crush/plans/retry.md'],
            'home-relative' => ['~/.sugar-crush/plans/retry.md'],
            'ordinary file' => ['src/retry.md'],
            'not a string' => [['.sugar-crush/plans/retry.md']],
            'NUL byte' => [".sugar-crush/plans/retry.md\0.php"],
        ];
    }

    #[DataProvider('notPlanFiles')]
    public function testEveryOtherWriteStaysDenied(mixed $path): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['file_path' => $path]), $this->root));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['file_path' => $path])));
    }

    public function testAPlansDirectoryLinkedOutOfTheProjectIsNotThePlansDirectory(): void
    {
        $outside = sys_get_temp_dir() . '/crush_plansdir_out_' . bin2hex(random_bytes(5));
        mkdir($outside, 0o700, true);

        try {
            rmdir($this->root . '/' . PermissionGate::PLANS_DIR);
            symlink($outside, $this->root . '/' . PermissionGate::PLANS_DIR);
            $gate = new PermissionGate(PermissionMode::Plan);

            self::assertSame(
                PermissionDecision::Deny,
                $gate->evaluate(new ToolCall('Write', ['file_path' => '.sugar-crush/plans/retry.md']), $this->root),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function testOnlyPlanModeGrantsIt(): void
    {
        $call = new ToolCall('Write', ['file_path' => '.sugar-crush/plans/retry.md']);

        self::assertSame(PermissionDecision::Ask, (new PermissionGate(PermissionMode::Default))->evaluate($call, $this->root));
        self::assertSame(PermissionDecision::Ask, (new PermissionGate(PermissionMode::AcceptEdits))->evaluate($call, $this->root));
        self::assertSame(PermissionDecision::Deny, (new PermissionGate(PermissionMode::DontAsk))->evaluate($call, $this->root));
    }

    public function testADenyRuleStillOutranksTheException(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan, [new PermissionRule('Write', PermissionAction::Deny)]);

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Write', ['file_path' => '.sugar-crush/plans/retry.md']), $this->root),
        );
    }

    public function testAWriteDeclarationIsStillRefusedUnderPlan(): void
    {
        // A declaration carries no path, so it is never the plan file.
        self::assertTrue((new PermissionGate(PermissionMode::Plan))->refuses(new ToolDeclaration('Write')));
    }

    public function testWithModeKeepsRulesAndGrantsAndRemembersTheModeItLeft(): void
    {
        $rule = new PermissionRule('Bash(rm *)', PermissionAction::Deny);
        $grant = new PermissionRule('WebFetch', PermissionAction::Allow);
        $gate = (new PermissionGate(PermissionMode::AcceptEdits, [$rule], null, 'the built-in default'))
            ->withSessionRules([$grant]);

        $plan = $gate->withMode(PermissionMode::Plan, 'Alt+M, this session');

        self::assertSame(PermissionMode::Plan, $plan->mode());
        self::assertSame(PermissionMode::AcceptEdits, $plan->toggledFrom());
        self::assertSame([$rule], $plan->rules());
        self::assertSame([$grant], $plan->sessionRules());
        self::assertSame('Alt+M, this session', $plan->modeSource());
        self::assertSame(PermissionMode::AcceptEdits, $gate->mode(), 'the gate it was made from is untouched');
        self::assertNull($gate->toggledFrom());
    }
}
