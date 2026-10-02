<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\Permissions\ToolDeclaration;

/**
 * {@see PermissionGate::refuses()} takes no project root, and this pins WHY it
 * needs none (audit F-J3-rem(a)).
 *
 * F-J3 listed `refuses()` beside the root-less call sites as still matching
 * "lexically". A declaration has no path to spell, though: the decision the
 * method reads is the one {@see PermissionGate}'s shared path would give WITH a
 * root, for every mode, tool and rule kind — including path-scoped rules over
 * a real tree with a symlink, which are the only rules a root changes. If a
 * future evaluator starts reading the root for a declaration, this goes red
 * and `refuses()` has to grow the parameter the main-loop `evaluate()` has.
 */
final class PermissionGateDeclarationRootTest extends TestCase
{
    private const TOOLS = ['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep', 'WebFetch', 'Task', 'mcp__git__push'];

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir() . '/sc_decl_root_' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0o700, true);
        file_put_contents($dir . '/secret.txt', 'x');
        symlink('secret.txt', $dir . '/notes');
        $this->root = (string) realpath($dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/notes');
        @unlink($this->root . '/secret.txt');
        @rmdir($this->root . '/src');
        @rmdir($this->root);

        parent::tearDown();
    }

    public function testADeclarationsVerdictIsTheSameWithAProjectRoot(): void
    {
        $decide = new \ReflectionMethod(PermissionGate::class, 'decide');
        $checked = 0;

        foreach ($this->ruleSets() as $label => $rules) {
            foreach (PermissionMode::cases() as $mode) {
                $gate = new PermissionGate($mode, $rules, new SafetyClassifier());

                foreach (self::TOOLS as $tool) {
                    $call = (new ToolDeclaration($tool))->asNamedCallForGateOnly();
                    $rooted = $decide->invoke($gate, $call, false, false, $this->root);
                    $this->assertInstanceOf(PermissionDecision::class, $rooted);

                    $this->assertSame(
                        $rooted === PermissionDecision::Deny,
                        $gate->refuses(new ToolDeclaration($tool)),
                        "{$label} / {$mode->value} / {$tool}: a root changed a declaration's verdict, so refuses() now needs one",
                    );
                    ++$checked;
                }
            }
        }

        $this->assertSame(\count($this->ruleSets()) * \count(PermissionMode::cases()) * \count(self::TOOLS), $checked);
    }

    /**
     * The control that keeps the matrix above honest: a NAME rule does refuse
     * a declaration, so the comparison is not vacuously all-false.
     */
    public function testANameRuleStillRefusesTheDeclaration(): void
    {
        $gate = new PermissionGate(
            PermissionMode::BypassPermissions,
            [new PermissionRule('Read', PermissionAction::Deny)],
        );

        $this->assertTrue($gate->refuses(new ToolDeclaration('Read')));
    }

    /** @return array<string, list<PermissionRule>> */
    private function ruleSets(): array
    {
        $abs = $this->root . '/secret.txt';

        return [
            'no rules' => [],
            'absolute path deny' => [
                new PermissionRule("Read({$abs})", PermissionAction::Deny),
                new PermissionRule("Write({$abs})", PermissionAction::Deny),
                new PermissionRule("Edit({$abs})", PermissionAction::Deny),
            ],
            'relative path ask + symlink' => [
                new PermissionRule('Read(notes)', PermissionAction::Ask),
                new PermissionRule('Write(src/*)', PermissionAction::Allow),
            ],
            'name deny' => [
                new PermissionRule('Bash', PermissionAction::Deny),
                new PermissionRule('mcp__git__*', PermissionAction::Deny),
            ],
            'shell + domain' => [
                new PermissionRule('Bash(rm *)', PermissionAction::Deny),
                new PermissionRule('WebFetch(domain:example.com)', PermissionAction::Allow),
            ],
        ];
    }
}
