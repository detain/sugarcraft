<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\ToolCall;

/**
 * Roadmap 3.I-3: an `ApplyPatch` call is judged over EVERY path its patch
 * touches — by the gate in `accept-edits`, `auto` and `plan`, and by
 * `protect-files` — and a patch that does not parse fails closed.
 */
final class ApplyPatchPermissionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/crush_patchgate_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root . '/src');
        @rmdir($this->root);
    }

    public function testAcceptEditsGrantsOnlyWhenEveryPathIsInside(): void
    {
        $gate = new PermissionGate(PermissionMode::AcceptEdits);

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::call(['src/a.php', 'src/b.php']), $this->root));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(self::call(['src/a.php', '../outside.php']), $this->root));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(self::call(['src/a.php', '.git/config']), $this->root));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(new ToolCall('ApplyPatch', ['patch' => 'not a patch']), $this->root));
    }

    public function testAutoClassifiesByTheWorstPath(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::call(['src/a.php']), $this->root));
        // A protected path is a security finding: asked about (roadmap 5.11-2).
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(self::call(['src/a.php', '.sugar-crush/x.md']), $this->root));
        self::assertStringContainsString('protected-path-write', (string) $gate->lastAutoReason());
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::call(['/etc/hosts', '.git/x']), $this->root));
        self::assertSame('outside-root-write', $gate->autoBreaker()['lastBlockedCategory']);
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('ApplyPatch', ['patch' => '']), $this->root));
    }

    public function testPlanAllowsOnlyAPatchOfPlanFiles(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::call(['.sugar-crush/plans/a.md', '.sugar-crush/plans/b.md']), $this->root));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::call(['.sugar-crush/plans/a.md', 'src/a.php']), $this->root));
    }

    public function testDefaultAsksAndDontAskDenies(): void
    {
        self::assertSame(PermissionDecision::Ask, (new PermissionGate(PermissionMode::Default))->evaluate(self::call(['src/a.php'])));
        self::assertSame(PermissionDecision::Deny, (new PermissionGate(PermissionMode::DontAsk))->evaluate(self::call(['src/a.php'])));
    }

    public function testProtectFilesSeesEveryPathInThePatch(): void
    {
        $hook = new ProtectFilesHook();
        self::assertMatchesRegularExpression('/' . $hook->matcher() . '/i', 'ApplyPatch');

        self::assertTrue($hook->execute(self::context(self::patch(['src/a.php', 'src/b.php'])))->isAllowed());
        self::assertFalse($hook->execute(self::context(self::patch(['src/a.php', 'config/.env'])))->isAllowed(), 'a secret among ordinary files refuses the call');
        self::assertTrue($hook->execute(self::context(self::patch(['src/a.php', '.mcp.json'])))->isAsk(), 'a policy file asks');
        self::assertFalse($hook->execute(self::context("*** Begin Patch\n*** Rename File: .env\n*** End Patch"))->isAllowed(), 'an unparseable patch is judged as its raw text');
    }

    public function testAPathDenyOnEditRefusesAPatchThatTouchesThatFile(): void
    {
        // The W10 integration's security fix: before it, `Deny Edit(.env)`
        // matched no `ApplyPatch` call at all, so the patch tool was a second
        // door to the file the user had denied.
        $gate = new PermissionGate(PermissionMode::BypassPermissions, [new PermissionRule('Edit(.env)', PermissionAction::Deny)]);

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::call(['src/a.php']), $this->root));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::call(['.env']), $this->root));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::call(['src/a.php', './.env']), $this->root), 'any one path decides a restrictive rule');
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::call(['config/.env']), $this->root), 'a relative restrictive pattern matches at any depth, as for Edit');
        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('ApplyPatch', ['patch' => "*** Begin Patch\n*** Update File: src/a.php\n*** Move to: .env\n@@\n-x\n+y\n*** End Patch"]), $this->root),
            'a move destination is a path the patch writes',
        );
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('ApplyPatch', ['patch' => 'not a patch']), $this->root), 'paths that do not parse fail closed');
    }

    public function testWriteRulesBindAPatchTooNameOnlyOrByPath(): void
    {
        $ask = new PermissionGate(PermissionMode::BypassPermissions, [new PermissionRule('Write(dist/*)', PermissionAction::Ask)]);
        self::assertSame(PermissionDecision::Ask, $ask->evaluate(self::call(['src/a.php', 'dist/app.js']), $this->root));
        self::assertSame(PermissionDecision::Allow, $ask->evaluate(self::call(['src/a.php']), $this->root));

        $deny = new PermissionGate(PermissionMode::BypassPermissions, [new PermissionRule('Write', PermissionAction::Deny)]);
        self::assertSame(PermissionDecision::Deny, $deny->evaluate(self::call(['src/a.php']), $this->root));
        self::assertTrue($deny->refuses(new \SugarCraft\Crush\Permissions\ToolDeclaration('ApplyPatch')), 'a name-only Write deny refuses the declaration as well');
    }

    public function testAnEditAllowGrantsNoPatchButAnApplyPatchAllowGrantsWhenEveryPathMatches(): void
    {
        $edit = new PermissionGate(PermissionMode::Default, [new PermissionRule('Edit(src/*)', PermissionAction::Allow)]);
        self::assertSame(PermissionDecision::Ask, $edit->evaluate(self::call(['src/a.php']), $this->root), 'a patch can also delete and move, which Edit cannot');

        $patch = new PermissionGate(PermissionMode::Default, [new PermissionRule('ApplyPatch(src/*)', PermissionAction::Allow)]);
        self::assertSame(PermissionDecision::Allow, $patch->evaluate(self::call(['src/a.php', 'src/b.php']), $this->root));
        self::assertSame(PermissionDecision::Ask, $patch->evaluate(self::call(['src/a.php', 'README.md']), $this->root), 'one path outside the grant leaves the call to the mode');
        self::assertSame(PermissionDecision::Ask, $patch->evaluate(new ToolCall('ApplyPatch', ['patch' => 'not a patch']), $this->root), 'a grant never fires on paths nobody could read');
    }

    public function testTheSafetyClassifierJudgesAPatchByItsWorstPath(): void
    {
        $classifier = new SafetyClassifier();

        self::assertNull($classifier->classify(self::call(['src/a.php']), $this->root));
        self::assertSame(SafetyClassifier::CATEGORY_PROTECTED_PATH_WRITE, $classifier->classify(self::call(['src/a.php', '.git/hooks/pre-commit']), $this->root));
        self::assertSame(SafetyClassifier::CATEGORY_OUTSIDE_ROOT_WRITE, $classifier->classify(self::call(['.git/x', '../out.php']), $this->root));
        self::assertSame(SafetyClassifier::CATEGORY_OUTSIDE_ROOT_WRITE, $classifier->classify(new ToolCall('ApplyPatch', ['patch' => 'nope']), $this->root));
    }

    public function testRuntimeCountsItAsAWrite(): void
    {
        self::assertContains('ApplyPatch', Runtime::WRITE_CAPABLE_TOOL_NAMES);
    }

    /** @param list<string> $paths */
    private static function call(array $paths): ToolCall
    {
        return new ToolCall('ApplyPatch', ['patch' => self::patch($paths)]);
    }

    /** @param list<string> $paths */
    private static function patch(array $paths): string
    {
        $body = '';
        foreach ($paths as $path) {
            $body .= "*** Add File: {$path}\n+x\n";
        }

        return "*** Begin Patch\n{$body}*** End Patch";
    }

    private static function context(string $patch): HookContext
    {
        $args = ['patch' => $patch];

        return new HookContext(
            sessionId: 's',
            toolName: 'ApplyPatch',
            toolArgs: $args,
            toolInput: (string) json_encode($args),
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: '/tmp/test-project',
        );
    }
}
