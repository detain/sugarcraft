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
use SugarCraft\Crush\ToolCall;

/**
 * Why Chat's own two gate calls pass no project root (audit F-J3-rem(b),
 * closed as moot): both judge a `Bash` call — the `!` shell escape and a
 * command file's `` !`…` `` form — and for a `Bash` call the root changes
 * nothing. {@see PermissionRule} reads it only for path subjects
 * (Read/Edit/Write/Glob/Grep/Lsp), accept-edits only for Edit/Write,
 * {@see SafetyClassifier} only for Edit/Write, and the scoped-write
 * (mkdir/touch/rmdir) check takes no root at all.
 *
 * This pins that across every mode and the rule kinds a root could matter to,
 * over a real tree with a symlink. If a future evaluator starts reading the
 * root for a shell command, this goes red and Chat's calls must pass
 * `projectRoot()` like the main loop does.
 */
final class ChatBashGateRootTest extends TestCase
{
    private const COMMANDS = ['ls', 'cat notes', 'cat secret.txt', 'rm -rf notes', 'mkdir src/new', 'touch ../outside', 'git push'];

    private string $root = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/sc_chat_bash_root_' . bin2hex(random_bytes(6));
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
    }

    private function decide(PermissionGate $gate, ToolCall $call, ?string $root): PermissionDecision
    {
        return (new \ReflectionMethod(PermissionGate::class, 'decide'))->invoke($gate, $call, false, true, $root);
    }

    public function testABashCallsVerdictIsTheSameWithAndWithoutAProjectRoot(): void
    {
        $checked = 0;
        foreach ($this->ruleSets() as $label => $rules) {
            foreach (PermissionMode::cases() as $mode) {
                foreach (self::COMMANDS as $command) {
                    $call = new ToolCall('Bash', ['command' => $command]);
                    $gate = new PermissionGate($mode, $rules, new SafetyClassifier());

                    $this->assertSame(
                        $this->decide($gate, $call, null),
                        $this->decide($gate, $call, $this->root),
                        "{$label} / {$mode->value} / `{$command}`: a root changed a Bash verdict, so Chat's root-less gate calls now need one",
                    );
                    ++$checked;
                }
            }
        }

        $this->assertSame(\count($this->ruleSets()) * \count(PermissionMode::cases()) * \count(self::COMMANDS), $checked);
    }

    /**
     * The control that keeps the matrix honest: the same tree and a path rule
     * DO answer differently for a Read once the root is supplied, so the
     * fixture can see a root when one matters.
     */
    public function testTheRootDoesMatterForAPathSubject(): void
    {
        $gate = new PermissionGate(
            PermissionMode::BypassPermissions,
            [new PermissionRule('Read(secret.txt)', PermissionAction::Deny)],
            new SafetyClassifier(),
        );
        $call = new ToolCall('Read', ['file_path' => 'notes']);

        $this->assertNotSame(PermissionDecision::Deny, $this->decide($gate, $call, null), 'lexically `notes` is not `secret.txt`');
        $this->assertSame(PermissionDecision::Deny, $this->decide($gate, $call, $this->root), 'under the root the symlink is followed to the denied file');
    }

    /** @return array<string, list<PermissionRule>> */
    private function ruleSets(): array
    {
        $abs = $this->root . '/secret.txt';

        return [
            'no rules' => [],
            'path rules' => [
                new PermissionRule("Read({$abs})", PermissionAction::Deny),
                new PermissionRule('Read(notes)', PermissionAction::Ask),
                new PermissionRule('Write(src/*)', PermissionAction::Allow),
            ],
            'shell rules' => [
                new PermissionRule('Bash(rm *)', PermissionAction::Deny),
                new PermissionRule('Bash(cat notes)', PermissionAction::Ask),
                new PermissionRule("Bash(cat {$abs})", PermissionAction::Deny),
                new PermissionRule('Bash(ls)', PermissionAction::Allow),
            ],
        ];
    }
}
