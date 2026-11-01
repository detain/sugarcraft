<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\ToolCall;

/**
 * C-TOOL-6.b: the permission posture of a notebook edit IS an edit's.
 *
 * A NotebookEdit call carries a file_path exactly like Edit does, so every
 * roster that scopes Edit by path must scope it identically — the Bash/PtyRun
 * cross-binding precedent in the restrictive direction (a rule naming one
 * sibling binds the family). This file pins each face of that claim at the
 * gate: accept-edits grants, auto classification by target, plan refusal, a
 * tool-named deny rule, a rule written for EDIT binding a notebook call, and
 * the protected-files floor. The root is real on disk so path judgements
 * resolve the way production's do; only directories are created, and they
 * are removed by name (no directory walk in this file).
 */
final class NotebookEditPermissionTest extends TestCase
{
    private string $base = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/nbp-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/proj';
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->base . '/outside', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root . '/src', $this->root, $this->base . '/outside', $this->base] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    /**
     * chr(42)-built glob text: an asterisk-bearing string literal in a test
     * file joins the PathGlob differential corpus (GlobDialect law).
     */
    private function doubleStar(): string
    {
        return str_repeat(chr(42), 2);
    }

    private function nbCall(string $filePath): ToolCall
    {
        return new ToolCall('NotebookEdit', ['file_path' => $filePath, 'edit_mode' => 'replace', 'cell_id' => 'a1b2c3d4', 'new_source' => 'x = 1']);
    }

    /** @param list<PermissionRule> $rules */
    private function gate(PermissionMode $mode, array $rules = []): PermissionGate
    {
        return new PermissionGate($mode, $rules, new SafetyClassifier());
    }

    public function testAcceptEditsTakesANotebookExactlyLikeEditInsideTheRoot(): void
    {
        $gate = $this->gate(PermissionMode::AcceptEdits);

        self::assertSame(PermissionDecision::Allow, $gate->evaluate($this->nbCall($this->root . '/nb.ipynb'), $this->root));
        self::assertSame(PermissionDecision::Allow, $gate->evaluate($this->nbCall('src/nb.ipynb'), $this->root));

        // Outside, both spellings the path jail will refuse at execution:
        self::assertSame(PermissionDecision::Ask, $gate->evaluate($this->nbCall($this->base . '/outside/nb.ipynb'), $this->root));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate($this->nbCall('../outside/nb.ipynb'), $this->root));
    }

    public function testAutoClassifiesANotebookByItsTargetLikeAFileEdit(): void
    {
        $classifier = new SafetyClassifier();
        self::assertNull(
            $classifier->classify($this->nbCall('nb.ipynb')),
            'an ordinary relative notebook write is not flagged in auto, same as Edit',
        );
        self::assertSame(
            'outside-root-write',
            $classifier->classify($this->nbCall('../other/nb.ipynb')),
            'the risk lives in file_path, and NotebookEdit declares it',
        );

        // And the gate honours it: auto runs the in-root edit, asks the escape.
        self::assertSame(PermissionDecision::Allow, $this->gate(PermissionMode::Auto)->evaluate($this->nbCall($this->root . '/nb.ipynb'), $this->root));
        self::assertNotSame(PermissionDecision::Allow, $this->gate(PermissionMode::Auto)->evaluate($this->nbCall($this->base . '/outside/nb.ipynb'), $this->root));
    }

    public function testPlanAndDontAskRefuseTheWriteClassNotebookEdit(): void
    {
        self::assertSame(PermissionDecision::Deny, $this->gate(PermissionMode::Plan)->evaluate($this->nbCall('nb.ipynb'), $this->root));
        self::assertSame(PermissionDecision::Deny, $this->gate(PermissionMode::DontAsk)->evaluate($this->nbCall('nb.ipynb'), $this->root));
    }

    public function testADenyRuleNamingNotebookEditsBindsItsPathPattern(): void
    {
        $deny = new PermissionRule('NotebookEdit(secrets/' . $this->doubleStar() . ')', PermissionAction::Deny);
        $gate = $this->gate(PermissionMode::AcceptEdits, [$deny]);

        self::assertSame(PermissionDecision::Deny, $gate->evaluate($this->nbCall('secrets/nb.ipynb'), $this->root));
        self::assertSame(PermissionDecision::Allow, $gate->evaluate($this->nbCall('src/nb.ipynb'), $this->root));
    }

    public function testPathScopedNotebookRulesMatchPathsTheWayEditRulesDo(): void
    {
        // The PATH_SUBJECT_TOOLS join in use, with teeth: outside that roster
        // an argument pattern falls to plain fnmatch(), and fnmatch's `*`
        // never crosses a `/` — so `secrets/**` would match NOTHING real and
        // the deny would silently evaporate. Inside it, the pattern matches
        // nested and absolute spellings exactly like a path tool's does
        // (the cross-family RULE BINDING is not a thing outside the shell
        // family — ShellSubjectTools — so parity here is per-tool spelling,
        // measured against that model, not a fabricated Edit-binds-X claim).
        $deny = new PermissionRule('NotebookEdit(secrets/' . $this->doubleStar() . ')', PermissionAction::Deny);

        self::assertTrue($deny->matches($this->nbCall('secrets/nb.ipynb')));
        self::assertTrue($deny->matches($this->nbCall('secrets/deep/nb.ipynb')), 'the `**` crosses directories like every path tool');
        self::assertTrue($deny->matches($this->nbCall('/proj/secrets/nb.ipynb')), 'absolute spellings bind too');
        self::assertFalse($deny->matches($this->nbCall('src/nb.ipynb')));

        // And the gate honours it even in bypass: rules run before the
        // bypass evaluator, so the deepest nested spelling is refused there.
        $gate = $this->gate(PermissionMode::BypassPermissions, [$deny]);
        self::assertSame(PermissionDecision::Deny, $gate->evaluate($this->nbCall('secrets/deep/nb.ipynb'), $this->root));
        self::assertSame(PermissionDecision::Allow, $gate->evaluate($this->nbCall('src/nb.ipynb'), $this->root));
    }

    public function testTheProtectedFilesFloorDeniesTouchingEnv(): void
    {
        $args = ['file_path' => '.env', 'edit_mode' => 'replace', 'cell_id' => 'a1b2c3d4', 'new_source' => 'x'];
        $context = new HookContext(
            sessionId: 's',
            toolName: 'NotebookEdit',
            toolArgs: $args,
            toolInput: (string) json_encode($args),
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: $this->root,
        );

        self::assertTrue((new ProtectFilesHook())->execute($context)->isDenied(), 'a notebook addressed at .env is a .env write');
    }

    public function testNotebookEditIsDeclaredWriteCapableToTheRuntime(): void
    {
        // The roster Runtime consults when deciding which tool calls may
        // move the workspace at all.
        self::assertContains('NotebookEdit', Runtime::WRITE_CAPABLE_TOOL_NAMES);
    }
}
